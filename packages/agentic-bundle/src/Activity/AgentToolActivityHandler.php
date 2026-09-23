<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Activity;

use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\Agentic\Domain\Identity\Principal;
use Gplanchat\Agentic\Infrastructure\Durable\Activity\AgentToolActivityInterface;
use Gplanchat\AgenticBundle\Tool\AgentTools;

/**
 * Routes a tool call to its implementation. Every call is an activity: journalled, retried
 * according to its `ActivityOptions`, and never re-executed on replay.
 *
 * This is where the call's context is assembled — the identifier the journal knows, the
 * conversation's workspace, and the principal it runs for. All three arrive from the workflow
 * payload, so a replay hands the tool the same ones.
 */
final readonly class AgentToolActivityHandler implements AgentToolActivityInterface
{
    public function __construct(private AgentTools $tools)
    {
    }

    public function callTool(string $callId, string $name, array $arguments, ?string $workspace = null, array $owner = []): string
    {
        return $this->tools->call($name, $arguments, new ToolContext($callId, $workspace, Principal::fromWire($owner)));
    }
}
