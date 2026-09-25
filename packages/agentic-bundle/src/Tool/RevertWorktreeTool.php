<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Application\Tool\ContextualTool;
use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\AgenticBundle\Sandbox\HostGit;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;

/**
 * Throws away what the agent changed in its worktree since its last commit — the "revert" of the
 * Mikado method, once a naive attempt showed its prerequisites.
 *
 * On the host ({@see HostGit}): inside the sandbox the git directory is read-only, so a
 * `git restore` there fails on the index lock. Only ever in the conversation's own worktree: in the
 * project itself, it would throw away the human's work.
 */
final readonly class RevertWorktreeTool implements ContextualTool
{
    public function __construct(private Workspaces $workspaces)
    {
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            'revert_worktree',
            'Throws away every uncommitted change of your worktree — edits and new files; ignored files stay. The Mikado revert: once a naive attempt showed its prerequisites and they are noted, go back to your last commit before working on one.',
            ToolEffect::Write,
            ['type' => 'object', 'properties' => new \stdClass()],
        );
    }

    public function __invoke(array $arguments): string
    {
        return $this->revert(null);
    }

    public function inContext(array $arguments, ToolContext $context): string
    {
        return $this->revert($context->workspace);
    }

    private function revert(?string $workspace): string
    {
        if (null !== $refusal = HostGit::refusal($this->workspaces, $workspace)) {
            return $refusal;
        }

        HostGit::run((string) $workspace, ['reset', '--hard', '--quiet', 'HEAD']);
        // `:/`: the whole worktree, even from the project's subdirectory.
        HostGit::run((string) $workspace, ['clean', '-d', '--force', '--quiet', '--', ':/']);

        return 'Reverted: the worktree is back to its last commit.';
    }
}
