<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Help;

final readonly class CommandSummary
{
    public function __construct(
        public string $name,
        public string $description,
    ) {
    }
}
