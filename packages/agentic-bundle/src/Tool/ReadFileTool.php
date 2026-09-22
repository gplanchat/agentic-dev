<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;

/**
 * Reads a file of the workspace, with line numbers — or lists a directory.
 *
 * Classed `read`: it passes in every mode. It reads inside the sandbox, so it sees what
 * `run_command` sees and nothing more — no secret, nothing outside the workspace.
 */
final readonly class ReadFileTool implements WorkspaceTool
{
    public const TOOL = 'read_file';

    public function __construct(private Workspaces $workspaces)
    {
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            self::TOOL,
            'Reads a file of the workspace, each line prefixed with its number and a tab — or lists '
            .'a directory. At most '.Workspaces::READ_DEFAULT_LINES.' lines by default: the end of the '
            .'output says how to read on.',
            ToolEffect::Read,
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Path relative to the workspace root, for example `src/Kernel.php`; `.` for the root.'],
                    'offset' => ['type' => 'integer', 'description' => 'First line to read, from 1. Default: 1.'],
                    'limit' => ['type' => 'integer', 'description' => 'How many lines to read. Default: '.Workspaces::READ_DEFAULT_LINES.'.'],
                ],
                'required' => ['path'],
            ],
        );
    }

    public function __invoke(array $arguments): string
    {
        return $this->inWorkspace($arguments, null);
    }

    public function inWorkspace(array $arguments, ?string $workspace): string
    {
        return $this->workspaces->file([
            'op' => 'read',
            'path' => (string) ($arguments['path'] ?? '.'),
            'offset' => (int) ($arguments['offset'] ?? 1),
            'limit' => (int) ($arguments['limit'] ?? Workspaces::READ_DEFAULT_LINES),
        ], $workspace);
    }
}
