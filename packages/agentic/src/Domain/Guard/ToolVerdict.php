<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

enum ToolVerdict
{
    case Allow;
    case Ask;
    case Deny;
}
