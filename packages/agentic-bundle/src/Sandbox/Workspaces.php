<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Sandbox;

/**
 * The workspace of a conversation, and the file operations inside it.
 *
 * With worktrees on, the conversation's worktree — created on first use — and what it borrows
 * read-only; without, the configured workspace.
 *
 * **File operations run inside the sandbox too**, as a small PHP script under bwrap. The file tools
 * then see exactly what `run_command` sees: the workspace writable, `vendor/` and `.git` read-only,
 * secrets masked — one policy, not a second one written in PHP on the host. The script resolves
 * every path (`realpath`, links included) and refuses what leaves the workspace or falls under a
 * masked pattern: a write there would land in a tmpfs and vanish, a read would return `/dev/null`.
 */
final readonly class Workspaces
{
    /** What `read_file` hands back at most: under the sandbox's output cap, so it never cuts lines. */
    public const READ_BUDGET_BYTES = 12_000;

    public const READ_DEFAULT_LINES = 400;

    public function __construct(
        private Bubblewrap $sandbox,
        private ?Worktrees $worktrees = null,
    ) {
    }

    public function sandbox(): Bubblewrap
    {
        return $this->sandbox;
    }

    /**
     * @param string|null $workspace the conversation's worktree, from its start payload
     *
     * @return array{0: string, 1: array<string, string>} the writable root, and the read-only mounts
     *
     * @throws \RuntimeException when git cannot create the worktree: an infrastructure failure
     */
    public function open(?string $workspace): array
    {
        if (null === $workspace || null === $this->worktrees) {
            return [$this->sandbox->workspace(), []];
        }

        $this->worktrees->ensure($workspace);

        return [$workspace, $this->worktrees->readOnlyMounts($workspace)];
    }

    /**
     * Runs a file operation in the sandbox of the workspace.
     *
     * @param array<string, mixed> $request `op` (`read` or `edit`), `path`, and the operation's fields
     *
     * @return string what the model reads back — a failure too, as a sentence: nothing here is worth
     *                a retry
     */
    public function file(array $request, ?string $workspace): string
    {
        [$root, $readOnly] = $this->open($workspace);

        // The interface's own binary when the sandbox can see it — the bare `php` may be older.
        $php = str_starts_with(\PHP_BINARY, '/usr/') ? \PHP_BINARY : 'php';

        [$exitCode, $output, $errors] = $this->sandbox->execute(
            [$php, '-n', '-r', self::SCRIPT, '--'],
            $root,
            $root,
            $readOnly,
            json_encode([
                ...$request,
                'root' => $root,
                'hidden' => $this->sandbox->hidden(),
                'budget' => self::READ_BUDGET_BYTES,
            ], \JSON_THROW_ON_ERROR),
        );

        return 0 === $exitCode ? $output : ('' !== trim($errors) ? trim($errors) : \sprintf('The file operation failed (exit code %d).', (int) $exitCode));
    }

    /**
     * Runs under the sandbox's `php`, whatever its version: nothing newer than PHP 7.4 in it.
     *
     * The request comes on standard input, as JSON: nothing the model wrote reaches the command line.
     */
    private const SCRIPT = <<<'PHP'
        $in = json_decode(stream_get_contents(STDIN), true);
        $root = $in['root'];
        function fail($message) { fwrite(STDERR, $message); exit(1); }
        function relative($root, $path) { return $path === $root ? '.' : substr($path, strlen($root) + 1); }

        // The nearest existing ancestor is resolved — links and `..` included —, the rest must be
        // plain names: a file to create cannot hide an escape in its missing directories.
        $asked = (string) ($in['path'] ?? '');
        $target = '' !== $asked && '/' === $asked[0] ? $asked : $root.'/'.$asked;
        $missing = [];
        $probe = rtrim($target, '/');
        while ('' !== $probe && !file_exists($probe)) {
            // In the sandbox, a link to what is not mounted dangles: outside, as far as the agent goes.
            if (is_link($probe)) { fail(sprintf('"%s" points outside the workspace, or to nothing.', $asked)); }
            array_unshift($missing, basename($probe));
            $probe = dirname($probe);
        }
        $real = realpath('' === $probe ? '/' : $probe);
        foreach ($missing as $name) {
            if ('.' === $name || '..' === $name || '' === $name) { fail(sprintf('"%s" does not exist.', $asked)); }
        }
        $path = $real.([] === $missing ? '' : '/'.implode('/', $missing));
        if (false === $real || ($path !== $root && 0 !== strpos($path, $root.'/'))) {
            fail(sprintf('"%s" is outside the workspace.', $asked));
        }
        if ($path !== $root) {
            $segments = explode('/', relative($root, $path));
            foreach ($in['hidden'] as $pattern) {
                $pattern = trim($pattern, '/');
                $depth = count(explode('/', $pattern));
                if (count($segments) >= $depth && fnmatch($pattern, implode('/', array_slice($segments, 0, $depth)), FNM_PATHNAME)) {
                    fail(sprintf('"%s" is masked by the sandbox (%s): it cannot be read or written.', relative($root, $path), $pattern));
                }
            }
        }
        $shown = relative($root, $path);

        if ('read' === $in['op']) {
            if (is_dir($path)) {
                $entries = array_values(array_diff(scandir($path), ['.', '..']));
                foreach ($entries as $entry) { echo $entry, is_dir($path.'/'.$entry) ? '/' : '', "\n"; }
                if ([] === $entries) { echo "(empty directory)\n"; }
                exit(0);
            }
            if (!file_exists($path)) { fail(sprintf('"%s" does not exist.', $shown)); }
            $handle = @fopen($path, 'r');
            if (false === $handle) { fail(sprintf('"%s" cannot be read.', $shown)); }
            if (false !== strpos((string) fread($handle, 8192), "\0")) { fail(sprintf('"%s" is a binary file.', $shown)); }
            rewind($handle);
            $offset = max(1, (int) ($in['offset'] ?? 1));
            $limit = max(1, (int) ($in['limit'] ?? 400));
            $number = 0; $shownLines = 0; $bytes = 0; $last = $offset - 1;
            while (false !== ($line = fgets($handle))) {
                ++$number;
                if ($number < $offset || $shownLines >= $limit || $bytes >= $in['budget']) { continue; }
                $line = rtrim($line, "\r\n");
                if (strlen($line) > 2000) { $line = substr($line, 0, 2000).' [… line cut at 2000 bytes]'; }
                $out = sprintf("%6d\t%s\n", $number, $line);
                echo $out;
                $bytes += strlen($out); ++$shownLines; $last = $number;
            }
            if (0 === $number) { echo "(empty file)\n"; }
            elseif ($offset > $number) { fail(sprintf('"%s" has %d lines: offset %d is past its end.', $shown, $number, $offset)); }
            elseif ($last < $number) { printf("[… %d more lines — read on with offset=%d]\n", $number - $last, $last + 1); }
            exit(0);
        }

        if ('edit' === $in['op']) {
            $old = (string) ($in['old_string'] ?? '');
            $new = (string) ($in['new_string'] ?? '');
            if (!file_exists($path)) {
                if ('' !== $old) { fail(sprintf('"%s" does not exist. To create it, pass an empty old_string.', $shown)); }
                if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0777, true)) { fail(sprintf('Cannot create "%s": read-only.', $shown)); }
                if (false === @file_put_contents($path, $new)) { fail(sprintf('Cannot create "%s": read-only.', $shown)); }
                printf("Created %s (%d lines).\n", $shown, '' === $new ? 0 : substr_count($new, "\n") + ("\n" === substr($new, -1) ? 0 : 1));
                exit(0);
            }
            if (!is_file($path)) { fail(sprintf('"%s" is not a file.', $shown)); }
            if ('' === $old) { fail(sprintf('"%s" already exists: pass the exact text to replace as old_string.', $shown)); }
            if ($old === $new) { fail('old_string and new_string are identical: nothing to change.'); }
            $content = file_get_contents($path);
            $count = substr_count($content, $old);
            if (0 === $count) { fail(sprintf('old_string is not in "%s". It must be the exact text of the file — without the line numbers read_file prints —, whitespace included.', $shown)); }
            if ($count > 1 && empty($in['replace_all'])) { fail(sprintf('old_string is in "%s" %d times: add surrounding lines to make it unique, or set replace_all.', $shown, $count)); }
            $content = empty($in['replace_all']) ? substr_replace($content, $new, strpos($content, $old), strlen($old)) : str_replace($old, $new, $content);
            if (false === @file_put_contents($path, $content)) { fail(sprintf('Cannot write "%s": read-only.', $shown)); }
            printf("Edited %s: %d replacement%s.\n", $shown, empty($in['replace_all']) ? 1 : $count, (empty($in['replace_all']) ? 1 : $count) > 1 ? 's' : '');
            exit(0);
        }

        fail('Unknown file operation.');
        PHP;
}
