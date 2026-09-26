<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Application\Tool\AgentTool;

/**
 * A tool the project it runs in may have nothing for: no check layer, no ticket tracker. Not offered
 * then — a tool the model cannot see is one it does not spend a turn reaching for.
 */
interface OfferedTool extends AgentTool
{
    public function isOffered(): bool;
}
