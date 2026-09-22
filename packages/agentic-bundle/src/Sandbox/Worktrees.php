<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Sandbox;

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
    private const PREPARED_DIRECTORIES = ['var', '.claude'];

    private const PREPARED_FILES = ['.env.local'];

    private string $project;

    /**
     * @param list<string> $shared `glob` patterns, relative to the project, of the ignored directories
     *                             the worktree borrows read-only
     */
    public function __construct(
        string $project,
        private array $shared = ['vendor', 'packages/*/vendor'],
    ) {
        $this->project = realpath($project) ?: $project;
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
        return $this->project.'/'.self::DIRECTORY.'/agentic-'.substr(preg_replace('/[^a-z0-9]/i', '', $conversation) ?? '', 0, 8);
    }

    /**
     * Is this a worktree this class hands out? The path comes from the journal: checked anyway, since
     * whatever it names gets mounted writable.
     */
    public function owns(string $path): bool
    {
        return 1 === preg_match('#^'.preg_quote($this->project.'/'.self::DIRECTORY.'/', '#').'agentic-[a-z0-9]{1,8}$#i', $path);
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

        if (!is_dir($path)) {
            $git = new Process(['git', '-C', $this->project, 'worktree', 'add', '-b', 'agentic/'.basename($path), $path, 'HEAD']);
            $git->setTimeout(60);
            $git->run();
            if (!$git->isSuccessful()) {
                throw new \RuntimeException(\sprintf('Could not create the worktree %s: %s', $path, trim($git->getErrorOutput())));
            }
        }

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
        $mounts = [$this->project.'/.git' => $this->project.'/.git'];
        foreach ($this->borrowed($path) as $source => $target) {
            $mounts[$source] = $target;
        }

        return $mounts;
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
