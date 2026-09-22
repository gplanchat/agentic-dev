<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\SymfonyAi;

use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use Symfony\AI\Platform\Tool\Tool;

/**
 * The toolbox is nothing but a **schema registry** any more: execution belongs to
 * {@see DurableToolExecutor}, hence to activities.
 *
 * The schemas are frozen when the workflow is built and travel inside the activity payload.
 * Reading them back from a DI container on replay would make `Runner::exposeTools()` diverge, since
 * it injects them into `$options['tools']` on every turn.
 */
final class SchemaOnlyToolbox implements ToolboxInterface
{
    /** @var Tool[] */
    private readonly array $tools;

    public function __construct(Toolset $tools)
    {
        $this->tools = array_map(
            static fn (ToolDefinition $definition): Tool => new Tool(
                new ExecutionReference(DurableToolExecutor::class, 'execute'),
                $definition->name,
                $definition->description,
                $definition->parameters,
            ),
            $tools->definitions,
        );
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        // `Runner` goes through the ToolExecutor, never through here. Should this path ever open,
        // it would run the tool in workflow code — outside the journal, hence replayed on every
        // resume.
        throw new \LogicException(\sprintf('Execution belongs to %s, not to the toolbox.', DurableToolExecutor::class));
    }
}
