<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Application\Tool\ContextualTool;
use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\AgenticBundle\Sandbox\HostGit;
use Gplanchat\AgenticBundle\Sandbox\WorkingTreeChanges;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Sandbox\Worktrees;

/**
 * What the conversation's worktree changed since it left the project's branch: its commits, the diff
 * — committed and not —, the new files. The eyes of a verifier, who must judge the work without
 * having made it: classed `read`, so a delegate at the `plan` ceiling has it.
 *
 * On the host ({@see HostGit}), with no diff driver and no textconv: which of them runs is chosen by
 * `.gitattributes`, and the worktree's are the agent's own.
 */
final readonly class WorktreeDiffTool implements ContextualTool
{
    public function __construct(private Workspaces $workspaces)
    {
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            'worktree_diff',
            'Shows what your worktree changed since it left the project\'s branch: the commits (their messages, "Closes #n" included), the diff of every change — committed or not —, and the new files. To judge work against what its ticket asks.',
            ToolEffect::Read,
            ['type' => 'object', 'properties' => new \stdClass()],
        );
    }

    public function __invoke(array $arguments): string
    {
        return $this->diff(null);
    }

    public function inContext(array $arguments, ToolContext $context): string
    {
        return $this->diff($context->workspace);
    }

    private function diff(?string $workspace): string
    {
        $worktrees = HostGit::worktrees($this->workspaces, $workspace);
        if (\is_string($worktrees)) {
            return $worktrees;
        }
        $project = trim(HostGit::run($worktrees->project(), ['rev-parse', 'HEAD']));
        $base = trim(HostGit::run((string) $workspace, ['merge-base', 'HEAD', $project]));
        $log = trim(HostGit::run((string) $workspace, ['log', '--format=%h %B', $base.'..HEAD']));
        $diff = WorkingTreeChanges::cut(HostGit::run((string) $workspace, ['diff', '--no-color', '--no-ext-diff', '--no-textconv', '--relative', $base]));
        $untracked = array_diff(
            array_filter(explode("\n", HostGit::run((string) $workspace, ['ls-files', '--others', '--exclude-standard']))),
            Worktrees::PREPARED_FILES,
        );

        return implode("\n\n", [
            \sprintf('Since %s:', substr($base, 0, 7)),
            'Commits:'."\n".('' === $log ? '(none)' : $log),
            'Diff:'."\n".('' === $diff ? '(none)' : $diff),
            'New files, not committed:'."\n".([] === $untracked ? '(none)' : implode("\n", $untracked)),
        ]);
    }
}
