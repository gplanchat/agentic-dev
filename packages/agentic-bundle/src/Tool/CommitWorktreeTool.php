<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Application\Tool\ContextualTool;
use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\AgenticBundle\Sandbox\HostGit;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Sandbox\Worktrees;

/**
 * Commits everything the agent changed in its worktree, on the worktree's own branch
 * (`agentic/agentic-<id>`) — what keeps a finished step safe from the next revert. The human reviews
 * that branch before taking anything from it. With `closes`, the message closes the work ticket the
 * commit finishes, once merged: EWA-002 § 6 wants the closing to be the platform's.
 *
 * On the host ({@see HostGit}), as the author `agentic`: a commit the agent made does not carry the
 * human's identity, nor their signature.
 */
final readonly class CommitWorktreeTool implements ContextualTool
{
    private const IDENTITY = ['user.name' => 'agentic', 'user.email' => 'agentic@localhost', 'commit.gpgsign' => 'false'];

    public function __construct(private Workspaces $workspaces)
    {
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            'commit_worktree',
            'Commits every change of your worktree — edits and new files — on its own branch, which the human reviews before merging. Commit each green step: a later revert_worktree goes back to this commit. Give closes once the work ticket is done and judged: with nothing else to commit, the commit only closes it. The ticket closes when the branch is merged.',
            ToolEffect::Write,
            ['type' => 'object', 'properties' => [
                'message' => ['type' => 'string', 'description' => 'The commit message: what changed and why.'],
                'closes' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The work ticket this commit finishes.'],
            ], 'required' => ['message']],
        );
    }

    public function __invoke(array $arguments): string
    {
        return $this->commit($arguments, null);
    }

    public function inContext(array $arguments, ToolContext $context): string
    {
        return $this->commit($arguments, $context->workspace);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function commit(array $arguments, ?string $workspace): string
    {
        $message = trim((string) ($arguments['message'] ?? ''));
        if ('' === $message) {
            return 'A commit needs a message.';
        }
        $ticket = null;
        if (isset($arguments['closes'])) {
            $ticket = filter_var($arguments['closes'], \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (false === $ticket) {
                return '"closes" must be a ticket number.';
            }
            // The keyword both forges read, on a line of its own.
            $message .= "\n\nCloses #".$ticket;
        }
        if (\is_string($refusal = HostGit::worktrees($this->workspaces, $workspace))) {
            return $refusal;
        }

        // The sandbox's placeholders are not the agent's work: a project that does not ignore
        // `.env.local` would otherwise start tracking it on the agent's branch.
        $add = ['add', '--all', '--', ':/'];
        foreach (Worktrees::PREPARED_FILES as $placeholder) {
            $add[] = ':(exclude)'.$placeholder;
        }
        HostGit::run((string) $workspace, $add);
        $empty = '' === HostGit::run((string) $workspace, ['diff', '--cached', '--name-only']);
        // Retried after it committed, the call finds nothing staged: done already, not an error.
        // Unless it closes a ticket the last commit does not: the verdict came after the work.
        if ($empty && (null === $ticket || 1 === preg_match('/^Closes #'.$ticket.'$/m', HostGit::run((string) $workspace, ['log', '-1', '--format=%B'])))) {
            return 'Nothing to commit: the worktree is as its last commit.';
        }
        HostGit::run((string) $workspace, ['commit', '--quiet', '--allow-empty', '--message', $message], self::IDENTITY);

        return \sprintf('Committed %s on %s.', trim(HostGit::run((string) $workspace, ['rev-parse', '--short', 'HEAD'])), trim(HostGit::run((string) $workspace, ['branch', '--show-current'])));
    }
}
