<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Sandbox;

use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a command inside a bubblewrap sandbox: the workspace mounted writable, `/usr` and `/etc`
 * read-only, nothing else from the disk — neither `$HOME` nor the neighbouring folders —, no
 * network, an emptied environment.
 *
 * **No shell.** The command is split like a terminal line (quotes included), then passed as is to
 * `execve`: `;`, `|`, `$(…)` or `*` stay literal arguments. That is what makes an allowlist of
 * commands readable — what it sees is what runs.
 *
 * Inside the workspace, two exceptions:
 * - the masked paths (`.env.local` and its key, `var/` and the agent journal), `glob` patterns: a
 *   file is covered over by `/dev/null`, a folder by a tmpfs;
 * - `.git`, `.claude` and `.agentic` read-only: a `.git/config`, a hook, an agent setting or a
 *   project configuration written here would run — or be obeyed — **on the host**, at the human's
 *   next command. `.agentic` is the sharpest of the three: it declares the commands `run_checks`
 *   runs without approval, so an agent able to write it would be writing its own guard. `.git` is a
 *   file in a worktree: it is protected as well, otherwise its `gitdir:` line would point wherever
 *   the agent wants.
 *
 * **What the sandbox does not do:** make safe what the agent writes into the workspace. A
 * `composer.json`, a `vendor/bin/*`, a test bootstrap changed there will run on the host when the
 * human launches them. Hence a worktree per conversation ({@see Worktrees}): the writable
 * workspace is not the project, and what the agent did is a diff to review before it is merged.
 *
 * ponytail: `Process::wait()` blocks the event loop — the screen freezes for as long as the
 * command takes, bounded by the timeout. `amphp/process` would make it non-blocking, like the HTTP
 * client.
 */
final class Bubblewrap
{
    /** What the model reads back of an output: the head and the tail, where the errors are. */
    public const MAX_OUTPUT_BYTES = 16_384;

    private const INSTALL = 'Install bubblewrap: `sudo apt install bubblewrap` (Debian, Ubuntu) or `sudo dnf install bubblewrap` (Fedora) — https://github.com/containers/bubblewrap';

    /** The system, read-only — the `/lib*` links included: without them, no dynamic loader. */
    private const SYSTEM = [
        '--ro-bind', '/usr', '/usr',
        '--symlink', 'usr/bin', '/bin',
        '--symlink', 'usr/sbin', '/sbin',
        '--symlink', 'usr/lib', '/lib',
        '--symlink', 'usr/lib64', '/lib64',
        '--ro-bind', '/etc', '/etc',
    ];

    /**
     * Mounted read-only: what the host executes, or obeys, without being asked to. `glob` patterns,
     * so a nested one is covered too — this is a monorepo, and `bin/agentic` launched from a package
     * makes that package's `.agentic` the one that is read.
     *
     * A pattern matching nothing mounts nothing; {@see Worktrees::PREPARED_DIRECTORIES} is what
     * makes the ones that matter exist, since a missing path cannot be mounted over.
     */
    private const READ_ONLY = ['.git', '.claude', '.agentic', '*/.agentic', '*/*/.agentic'];

    /** `false`: not probed yet. */
    private string|null|false $problem = false;

    /**
     * @param list<string> $hidden `glob` patterns relative to the workspace, masked if they exist
     */
    private readonly string $workspace;

    public function __construct(
        string $workspace,
        private readonly array $hidden = ['.env.local', '.env.*.local', 'var'],
        private readonly float $timeoutSeconds = 120.0,
        private readonly string $binary = 'bwrap',
    ) {
        // The real path: it is the one bwrap mounts and the one the tool compares against.
        $this->workspace = realpath($workspace) ?: $workspace;
    }

    public function workspace(): string
    {
        return $this->workspace;
    }

    /**
     * @return list<string> `glob` patterns relative to a workspace, masked inside the sandbox
     */
    public function hidden(): array
    {
        return $this->hidden;
    }

    /**
     * Why the sandbox cannot serve, or `null` if it works.
     *
     * Probed by **launching** bwrap, once per process: an installed bwrap can be refused by the
     * kernel (Ubuntu ≥ 24.04 restricts unprivileged user namespaces), and only a try tells.
     */
    public function problem(): ?string
    {
        if (false !== $this->problem) {
            return $this->problem;
        }

        $probe = new Process([$this->binary, ...self::SYSTEM, '--unshare-all', '--', '/usr/bin/true']);
        $probe->setTimeout(10);
        try {
            $probe->run();
        } catch (\Throwable $failure) {
            return $this->problem = \sprintf("bubblewrap does not answer (%s).\n%s", $failure->getMessage(), self::INSTALL);
        }

        return $this->problem = match (true) {
            $probe->isSuccessful() => null,
            127 === $probe->getExitCode() => "bubblewrap (bwrap) cannot be found: run_command is disabled.\n".self::INSTALL,
            default => \sprintf(
                "bubblewrap is installed but refuses to start: %s\nOn Ubuntu ≥ 24.04, it is the restriction on user namespaces (kernel.apparmor_restrict_unprivileged_userns): bwrap needs its AppArmor profile.",
                trim($probe->getErrorOutput()) ?: 'code '.$probe->getExitCode(),
            ),
        };
    }

    /**
     * @param string|null           $workspace the writable directory; `null` = the configured one
     * @param array<string, string> $readOnly  extra mounts, source → target, laid over the workspace
     *
     * @return string what the model reads back: the exit code and the output, capped
     */
    public function run(string $command, string $cwd, ?string $workspace = null, array $readOnly = []): string
    {
        if (null !== $problem = $this->problem()) {
            // Returned, not thrown: an exception would be retried three times to end in a vague failure.
            return $problem;
        }

        // The splitting of a terminal line, without a shell: the Console's own does the job.
        $argv = (new StringInput($command))->getRawTokens();
        if ([] === $argv) {
            return 'Empty command.';
        }

        [$exitCode, $output, $errors] = $this->execute($argv, $cwd, $workspace, $readOnly);

        return null === $exitCode
            ? $errors
            : \sprintf("Exit code: %d\n%s", $exitCode, self::cap(self::plain($output.$errors)));
    }

    /**
     * Runs an argument vector in the sandbox — no splitting, no shell.
     *
     * @param list<string>          $argv
     * @param array<string, string> $readOnly
     * @param float|null            $timeout  seconds; `null` = the configured one
     *
     * @return array{0: int|null, 1: string, 2: string} the exit code — `null` when it did not run to
     *                                                  its end, the reason then in the third —,
     *                                                  standard output, error output
     */
    public function execute(array $argv, string $cwd, ?string $workspace = null, array $readOnly = [], ?string $input = null, ?float $timeout = null): array
    {
        $timeout ??= $this->timeoutSeconds;
        if (null !== $problem = $this->problem()) {
            // Returned, not thrown: an exception would be retried three times to end in a vague failure.
            return [null, '', $problem];
        }

        $process = new Process([...$this->sandbox($cwd, $workspace ?? $this->workspace, $readOnly), '--', ...$argv]);
        $process->setTimeout($timeout);
        $process->setInput($input);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Returned as a result: thrown, it would be retried — three times the same timeout.
            return [null, '', \sprintf("Interrupted after %d s.\n%s", (int) $timeout, self::cap(self::plain($process->getOutput().$process->getErrorOutput())))];
        }

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    /**
     * @param array<string, string> $readOnly
     *
     * @return list<string>
     */
    private function sandbox(string $cwd, string $workspace, array $readOnly): array
    {
        $argv = [
            $this->binary,
            ...self::SYSTEM,
            '--proc', '/proc',
            '--dev', '/dev',
            // Before the workspace: mounted after, this tmpfs would cover it if it lives under /tmp.
            '--tmpfs', '/tmp',
            '--bind', $workspace, $workspace,
        ];

        // After the workspace: a target inside it (a borrowed `vendor/`) covers what is there.
        foreach ($readOnly as $source => $target) {
            array_push($argv, '--ro-bind', $source, $target);
        }

        // Only what exists: a missing mount point, and bwrap would create it — on the host.
        foreach (self::READ_ONLY as $pattern) {
            foreach (glob($workspace.'/'.$pattern, \GLOB_NOSORT) ?: [] as $path) {
                array_push($argv, '--ro-bind', $path, $path);
            }
        }
        foreach ($this->hidden as $pattern) {
            foreach (glob($workspace.'/'.ltrim($pattern, '/'), \GLOB_NOSORT) ?: [] as $path) {
                array_push($argv, ...(is_dir($path) ? ['--tmpfs', $path] : ['--ro-bind', '/dev/null', $path]));
            }
        }

        array_push(
            $argv,
            '--chdir', $cwd,
            '--unshare-all',
            '--die-with-parent',
            '--new-session',
            // The interface environment carries the model key: nothing of it goes through.
            '--clearenv',
            '--setenv', 'PATH', '/usr/local/bin:/usr/bin:/bin',
            '--setenv', 'HOME', '/tmp',
            '--setenv', 'LANG', 'C.UTF-8',
            // Most tools drop their colours on it; the others are cleaned by plain().
            '--setenv', 'NO_COLOR', '1',
        );

        return $argv;
    }

    /**
     * Without the terminal sequences: PHPUnit, for one, forces its colours when its configuration
     * says `colors="true"`, `NO_COLOR` or not. The model would read `[30;42mOK`, and so would the
     * chat, which drops the escape byte but not the rest.
     */
    public static function plain(string $output): string
    {
        return preg_replace('/\e(?:\[[0-?]*[ -\/]*[@-~]|\][^\a\e]*(?:\a|\e\\\\)|[@-Z\\\\-_])/', '', $output) ?? $output;
    }

    private static function cap(string $output): string
    {
        if (\strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        $half = intdiv(self::MAX_OUTPUT_BYTES, 2);

        // mb_strcut never cuts a UTF-8 character in two.
        return mb_strcut($output, 0, $half)
            .\sprintf("\n[… %d bytes omitted …]\n", \strlen($output) - 2 * $half)
            .mb_strcut($output, \strlen($output) - $half);
    }
}
