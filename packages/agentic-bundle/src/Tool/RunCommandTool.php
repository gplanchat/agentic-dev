<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\Agentic\Application\Tool\ContextualTool;
use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\AgenticBundle\Sandbox\WorkingTreeChanges;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;

/**
 * Runs a command inside the sandbox ({@see Bubblewrap}).
 *
 * Classed `external`: outside `auto`, every command asks for approval. In `auto`, only those of
 * the allowlist (`sandbox.auto_allow`) pass — the others ask as well. The guard compares the exact
 * `command` string, the one that will be split then executed without a shell.
 *
 * With worktrees on, the command runs in the conversation's worktree — its path comes from the
 * start payload, never from the model —, created on the first call. Without, in the project.
 *
 * In a git repository, what the command changed follows its output, as a diff
 * ({@see WorkingTreeChanges}): the model learns what its formatter touched, the human sees it.
 */
final readonly class RunCommandTool implements ContextualTool
{
    public const TOOL = 'run_command';

    /** Between the output and the diff of what the command changed. */
    public const CHANGES = "\n\nFiles changed by the command:\n";

    public function __construct(private Workspaces $workspaces)
    {
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            self::TOOL,
            'Runs a command inside a sandbox: the project writable, no network, no access to the '
            .'rest of the disk. No shell: no `;`, no `|`, no `&&`, no redirection, no `*` — one '
            .'command per call. Returns the exit code and the output (truncated beyond 16 KiB), then '
            .'the diff of the files it changed, if any.',
            ToolEffect::External,
            [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string', 'description' => 'The command line, for example `vendor/bin/phpunit --filter Foo`.'],
                    'cwd' => ['type' => 'string', 'description' => 'Directory to run in, relative to the workspace root. Default: the root.'],
                ],
                'required' => ['command'],
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
        // Thrown if git cannot create the worktree: an infrastructure failure, retried like one.
        [$root, $readOnly] = $this->workspaces->open($workspace);
        $cwd = realpath($root.'/'.ltrim((string) ($arguments['cwd'] ?? ''), '/'));

        // realpath resolves `..` and the links: what is left must be under the root.
        if (false === $cwd || !is_dir($cwd) || ($cwd !== $root && !str_starts_with($cwd, $root.'/'))) {
            return \sprintf('Directory refused: "%s" is not a folder of the project.', (string) ($arguments['cwd'] ?? ''));
        }

        $changes = WorkingTreeChanges::before($root);
        $result = $this->workspaces->sandbox()->run((string) ($arguments['command'] ?? ''), $cwd, $root, $readOnly);
        $diff = $changes?->after() ?? '';

        return '' === $diff ? $result : rtrim($result, "\n").self::CHANGES.$diff;
    }
}
