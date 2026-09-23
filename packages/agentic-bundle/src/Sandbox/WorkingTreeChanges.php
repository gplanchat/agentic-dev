<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Sandbox;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * What a command changed in a workspace: the files as git sees them — untracked ones included,
 * ignored ones left out — before it ran, then after, and the diff of the two.
 *
 * On the host, outside the sandbox, like the worktree itself. Nothing of the repository is touched:
 * the snapshots go to a throwaway index and a throwaway object directory, the repository's objects
 * are only read.
 */
final class WorkingTreeChanges
{
    /** Lines of diff kept: it goes to the model, and a formatter run on the whole tree is long. */
    public const MAX_LINES = 400;

    private function __construct(
        private readonly string $root,
        private readonly string $scratch,
        private readonly string $objects,
        private readonly string $before,
    ) {
    }

    /**
     * @return self|null `null` outside a git repository, or when git cannot take the snapshot:
     *                   the command runs all the same, only without its diff
     */
    public static function before(string $root): ?self
    {
        $git = new Process(['git', '-C', $root, 'rev-parse', '--path-format=absolute', '--git-path', 'index', '--git-common-dir']);
        $git->run();
        if (!$git->isSuccessful()) {
            return null;
        }
        [$index, $common] = explode("\n", trim($git->getOutput()));

        $scratch = (new Filesystem())->tempnam(sys_get_temp_dir(), 'agentic-snapshot-');
        (new Filesystem())->remove($scratch);
        (new Filesystem())->mkdir($scratch.'/objects');
        // The worktree's own index as a start: git then rehashes only the files whose stat changed.
        if (is_file($index)) {
            (new Filesystem())->copy($index, $scratch.'/index');
        }

        $changes = new self($root, $scratch, $common.'/objects', '');
        $tree = $changes->snapshot();

        return null === $tree ? $changes->discard() : new self($root, $scratch, $common.'/objects', $tree);
    }

    /**
     * @return string the unified diff of what changed since {@see before()}, relative to the
     *                workspace; empty when nothing did
     */
    public function after(): string
    {
        try {
            $after = $this->snapshot();
            if (null === $after || $after === $this->before) {
                return '';
            }

            $diff = $this->git(['diff', '--no-color', '--no-ext-diff', '--relative', $this->before, $after]);
            $lines = explode("\n", rtrim($diff ?? '', "\n"));

            return \count($lines) <= self::MAX_LINES
                ? implode("\n", $lines)
                : implode("\n", \array_slice($lines, 0, self::MAX_LINES))."\n[… ".(\count($lines) - self::MAX_LINES).' more lines of diff …]';
        } finally {
            $this->discard();
        }
    }

    /**
     * @return string|null the tree of the workspace as it stands
     */
    private function snapshot(): ?string
    {
        if (null === $this->git(['add', '--all', '--', '.'])) {
            return null;
        }

        return null === ($tree = $this->git(['write-tree'])) ? null : trim($tree);
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): ?string
    {
        $git = new Process(['git', '-C', $this->root, ...$arguments], null, [
            'GIT_INDEX_FILE' => $this->scratch.'/index',
            'GIT_OBJECT_DIRECTORY' => $this->scratch.'/objects',
            'GIT_ALTERNATE_OBJECT_DIRECTORIES' => $this->objects,
        ]);
        $git->setTimeout(60);
        $git->run();

        return $git->isSuccessful() ? $git->getOutput() : null;
    }

    private function discard(): null
    {
        (new Filesystem())->remove($this->scratch);

        return null;
    }
}
