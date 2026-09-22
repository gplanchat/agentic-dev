<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Controller;

use Gplanchat\Agentic\Application\Help\CommandCatalog;
use Gplanchat\Agentic\Application\Help\CommandSummary;
use Gplanchat\Agentic\Application\Help\ListCommands;
use Gplanchat\AgenticBundle\Controller\HelpController;
use PHPUnit\Framework\TestCase;

final class HelpControllerTest extends TestCase
{
    public function testDescriptionsAreEscaped(): void
    {
        $catalog = new class implements CommandCatalog {
            public function all(): iterable
            {
                yield new CommandSummary('help', '<script>x</script>');
            }
        };

        $html = (string) (new HelpController(new ListCommands($catalog)))()->getContent();

        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
