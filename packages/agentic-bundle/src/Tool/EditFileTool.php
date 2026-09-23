<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Application\Tool\ContextualTool;
use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;

/**
 * Replaces an exact piece of a file of the workspace — or creates a file.
 *
 * An exact, unique string rather than a line range or a whole rewrite: the model has to quote what
 * it changes, so an edit made on a stale reading fails instead of landing in the wrong place.
 *
 * Classed `write`: it asks in `standard`, passes in `edition` and `auto`. It writes inside the
 * sandbox — in the conversation's worktree, not in the project.
 *
 * ponytail: a tool call is delivered at least once. An edit that landed but whose result was lost is
 * retried, and then reports that old_string is no longer there — the model reads the file again.
 */
final readonly class EditFileTool implements ContextualTool
{
    public const TOOL = 'edit_file';

    public function __construct(private Workspaces $workspaces)
    {
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            self::TOOL,
            'Replaces old_string with new_string in a file of the workspace. old_string is the exact '
            .'text of the file — whitespace included, without the line numbers read_file prints — and '
            .'must appear exactly once, unless replace_all is set. With an empty old_string, creates '
            .'the file (and its directories) with new_string as content; only if it does not exist yet.',
            ToolEffect::Write,
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Path relative to the workspace root.'],
                    'old_string' => ['type' => 'string', 'description' => 'The exact text to replace; empty to create the file.'],
                    'new_string' => ['type' => 'string', 'description' => 'The text that replaces it.'],
                    'replace_all' => ['type' => 'boolean', 'description' => 'Replace every occurrence. Default: false.'],
                ],
                'required' => ['path', 'old_string', 'new_string'],
            ],
        );
    }

    public function __invoke(array $arguments): string
    {
        return $this->act($arguments, null);
    }

    public function inContext(array $arguments, ToolContext $context): string
    {
        return $this->act($arguments, $context->workspace);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function act(array $arguments, ?string $workspace): string
    {
        return $this->workspaces->file([
            'op' => 'edit',
            'path' => (string) ($arguments['path'] ?? ''),
            'old_string' => (string) ($arguments['old_string'] ?? ''),
            'new_string' => (string) ($arguments['new_string'] ?? ''),
            'replace_all' => true === ($arguments['replace_all'] ?? false),
        ], $workspace);
    }
}
