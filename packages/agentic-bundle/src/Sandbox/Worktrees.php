<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Sandbox;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * One git worktree per conversation: the agent writes there, never in the project the human runs.
 *
 * The worktree is a branch (`agentic/<name>`) cut from the project's `HEAD` when the first command
 * needs it. What the agent did is then a diff to review and merge — or a directory to throw away
 * (`git worktree remove`). Uncommitted changes of the project are not in it: it starts from `HEAD`.
 *
 * Its path is decided when the conversation starts and travels in the start payload, hence in the
 * journal: a resumed conversation finds the same worktree, whatever directory the TUI runs from.
 *
 * Two things the worktree does not have, and borrows read-only from the project inside the sandbox:
 * the git common directory (`.git`, which the worktree's `.git` file points to) and the ignored
 * dependency directories (`vendor/`). They are mounted at the worktree's own paths, so Composer's
 * autoloader — which resolves from its own location — loads the worktree's code, not the project's.
 */
final readonly class Worktrees
{
    public const DIRECTORY = '.worktrees';

    /**
     * Created empty with the worktree, so the sandbox's masks and read-only mounts — which only
     * apply to what exists — cover them. Otherwise the agent could create them: ignored by git,
     * invisible in a diff, and run on the host by the first `bin/console` or agent session opened
     * in the worktree.
     */
    private const PREPARED_DIRECTORIES = ['var', '.claude', '.agentic'];

    private const PREPARED_FILES = ['.env.local'];

    private string $project;

    /** The repository the project belongs to: the project itself, or a directory above it. */
    private string $repository;

    /** Where the project sits in the repository — `/packages/lib`, or `` at its root. */
    private string $subdirectory;

    /**
     * @param list<string> $shared     `glob` patterns, relative to the project, of the ignored
     *                                 directories the worktree borrows read-only
     * @param string|null  $repository the root of the git repository; `null`: the project itself
     */
    public function __construct(
        string $project,
        private array $shared = ['vendor', 'packages/*/vendor'],
        ?string $repository = null,
    ) {
        $this->project = realpath($project) ?: $project;
        $this->repository = null === $repository ? $this->project : (realpath($repository) ?: $repository);
        $this->subdirectory = substr($this->project, \strlen($this->repository));
    }

    /**
     * The worktrees of the project the agent is launched in — a repository's root or any directory
     * inside one. `null` outside a git repository: the agent then works in the project itself.
     *
     * @param list<string> $shared
     */
    public static function of(string $project, array $shared): ?self
    {
        $git = new Process(['git', '-C', $project, 'rev-parse', '--show-toplevel']);
        $git->run();

        return $git->isSuccessful() ? new self($project, $shared, trim($git->getOutput())) : null;
    }

    public function project(): string
    {
        return $this->project;
    }

    /**
     * Pure: the conversation only needs to know where its worktree will be.
     *
     * ponytail: eight characters of the conversation id, as the TUI shows them. A collision makes
     * `git worktree add -b` fail on the existing branch — loudly, not by sharing a worktree.
     */
    public function pathFor(string $conversation): string
    {
        return $this->repository.'/'.self::DIRECTORY.'/agentic-'.substr(preg_replace('/[^a-z0-9]/i', '', $conversation) ?? '', 0, 8).$this->subdirectory;
    }

    /**
     * Is this a worktree this class hands out? The path comes from the journal: checked anyway, since
     * whatever it names gets mounted writable.
     */
    public function owns(string $path): bool
    {
        return 1 === preg_match('#^'.preg_quote($this->repository.'/'.self::DIRECTORY.'/', '#').'agentic-[a-z0-9]{1,8}'.preg_quote($this->subdirectory, '#').'$#i', $path);
    }

    /**
     * Creates the worktree if it does not exist yet, on the host — git runs outside the sandbox.
     *
     * @throws \RuntimeException when git refuses: an infrastructure failure, worth a retry
     */
    public function ensure(string $path): void
    {
        if (!$this->owns($path)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an agent worktree of %s.', $path, $this->project));
        }

        $root = $this->rootOf($path);
        if (!is_dir($root)) {
            $git = new Process(['git', '-C', $this->repository, 'worktree', 'add', '-b', 'agentic/'.basename($root), $root, 'HEAD']);
            $git->setTimeout(60);
            $git->run();
            if (!$git->isSuccessful()) {
                throw new \RuntimeException(\sprintf('Could not create the worktree %s: %s', $root, trim($git->getErrorOutput())));
            }
        }
        $this->keepOutOfGit();

        // The mount points of what the worktree borrows. Missing, bwrap would create them itself —
        // here, in the worktree, that is harmless, but explicit is easier to read.
        foreach ([...$this->borrowed($path), ...array_map(static fn (string $directory): string => $path.'/'.$directory, self::PREPARED_DIRECTORIES)] as $target) {
            if (!is_dir($target)) {
                mkdir($target, 0o777, true);
            }
        }
        foreach (self::PREPARED_FILES as $file) {
            if (!file_exists($path.'/'.$file)) {
                touch($path.'/'.$file);
            }
        }
    }

    /**
     * What to mount read-only in the sandbox of a worktree: source → target.
     *
     * @return array<string, string>
     */
    public function readOnlyMounts(string $path): array
    {
        $common = $this->commonDirectory();
        $mounts = [$common => $common];
        // From a subdirectory, git looks up for the worktree's `.git` file: it has to be there.
        if ('' !== $this->subdirectory) {
            $mounts[$this->rootOf($path).'/.git'] = $this->rootOf($path).'/.git';
        }
        foreach ($this->borrowed($path) as $source => $target) {
            $mounts[$source] = $target;
        }

        return $mounts;
    }

    /**
     * The worktree a workspace belongs to: the workspace, less the project's subdirectory.
     */
    private function rootOf(string $path): string
    {
        return substr($path, 0, \strlen($path) - \strlen($this->subdirectory));
    }

    /**
     * The repository's own `.git` — the directory, even when the project is itself a worktree.
     */
    private function commonDirectory(): string
    {
        return trim((new Process(['git', '-C', $this->repository, 'rev-parse', '--path-format=absolute', '--git-common-dir']))->mustRun()->getOutput());
    }

    /**
     * `.worktrees/` kept out of `git status`, through `.git/info/exclude`: local to this clone, and
     * none of the project's own files touched — a `.gitignore` edit would be a change to review.
     */
    private function keepOutOfGit(): void
    {
        $exclude = $this->commonDirectory().'/info/exclude';
        $contents = is_file($exclude) ? (string) file_get_contents($exclude) : '';
        if (!\in_array('/'.self::DIRECTORY.'/', explode("\n", $contents), true)) {
            (new Filesystem())->appendToFile($exclude, ('' === $contents || str_ends_with($contents, "\n") ? '' : "\n").'/'.self::DIRECTORY."/\n");
        }
    }

    /**
     * @return array<string, string> project directory → the same directory in the worktree
     */
    private function borrowed(string $path): array
    {
        $borrowed = [];
        foreach ($this->shared as $pattern) {
            foreach (glob($this->project.'/'.ltrim($pattern, '/'), \GLOB_ONLYDIR | \GLOB_NOSORT) ?: [] as $source) {
                $relative = substr($source, \strlen($this->project) + 1);
                // A package the worktree does not have (added since HEAD): nothing to mount into.
                if (is_dir(\dirname($path.'/'.$relative))) {
                    $borrowed[$source] = $path.'/'.$relative;
                }
            }
        }

        return $borrowed;
    }
}
