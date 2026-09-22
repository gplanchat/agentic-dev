<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Application\Help;

use Gplanchat\Agentic\Application\Help\CommandCatalog;
use Gplanchat\Agentic\Application\Help\CommandSummary;
use Gplanchat\Agentic\Application\Help\ListCommands;
use PHPUnit\Framework\TestCase;

final class ListCommandsTest extends TestCase
{
    public function testCommandsAreSortedByName(): void
    {
        $catalog = new class implements CommandCatalog {
            public function all(): iterable
            {
                yield new CommandSummary('list', 'List commands');
                yield new CommandSummary('help', 'Display help');
            }
        };

        self::assertSame(['help', 'list'], array_map(static fn (CommandSummary $c): string => $c->name, (new ListCommands($catalog))()));
    }
}
