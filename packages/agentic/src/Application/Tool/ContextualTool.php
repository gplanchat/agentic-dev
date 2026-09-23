<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Tool;

/**
 * A tool that needs to know more about the call than the arguments the model wrote: where to act,
 * for whom, and which call this is.
 *
 * `__invoke()` stays the plain form — a tool nobody hands a context to acts with none. The context
 * comes from the conversation, so it reaches the tool through the activity that runs it, never
 * through the model.
 */
interface ContextualTool extends AgentTool
{
    /**
     * @param array<string, mixed> $arguments what the model asked for, nothing guarantees the schema
     */
    public function inContext(array $arguments, ToolContext $context): string;
}
