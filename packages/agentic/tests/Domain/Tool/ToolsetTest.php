<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Tool;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Toolset::class)]
#[CoversClass(ToolDefinition::class)]
#[CoversClass(ToolInvocation::class)]
final class ToolsetTest extends TestCase
{
    public function testTheWireSurvivesTheRoundTrip(): void
    {
        $wire = ['save_note' => ['description' => 'Note', 'effect' => 'write', 'parameters' => ['type' => 'object']]];

        self::assertSame($wire, Toolset::fromWire($wire)->toWire());
    }

    /**
     * Un effet non déclaré ou mal orthographié tombe du côté prudent : externe.
     */
    public function testAnUndeclaredEffectIsExternal(): void
    {
        $tools = Toolset::fromWire(['a' => ['description' => ''], 'b' => ['effect' => 'lecture']]);

        self::assertSame(ToolEffect::External, $tools->effectOf('a'));
        self::assertSame(ToolEffect::External, $tools->effectOf('b'));
    }

    public function testAnInvocationWithoutIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ToolInvocation(' ', 'weather');
    }
}
