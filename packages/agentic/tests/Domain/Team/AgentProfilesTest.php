<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Team;

use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Team\AgentProfile;
use Gplanchat\Agentic\Domain\Team\AgentProfiles;
use Gplanchat\Agentic\Domain\Team\DelegateTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentProfile::class)]
#[CoversClass(AgentProfiles::class)]
#[CoversClass(DelegateTool::class)]
final class AgentProfilesTest extends TestCase
{
    public function testTheWireSurvivesTheRoundTrip(): void
    {
        $wire = ['sorter' => [
            'description' => 'Sorts and summarises',
            'prompt' => 'You sort.',
            'model' => 'ministral-3b-latest',
            'ceiling' => 'standard',
            'tools' => ['weather', 'read_*'],
            'max_turns' => 3,
        ]];

        self::assertSame($wire, AgentProfiles::fromWire($wire)->toWire());
    }

    public function testAProfileAllowsOnlyTheToolsItPatternsMatch(): void
    {
        $profile = AgentProfile::fromWire('reader', ['tools' => ['read_*', 'weather']]);

        self::assertTrue($profile->allows('read_file'));
        self::assertTrue($profile->allows('weather'));
        self::assertFalse($profile->allows('send_email'));
        self::assertFalse(AgentProfile::fromWire('mute', [])->allows('weather'), 'No pattern, no tool.');
    }

    /**
     * A missing ceiling is `standard`, not `auto`: a profile written in haste must not be the way
     * around the guard.
     */
    public function testTheDefaultCeilingIsTheStrictestUsefulOne(): void
    {
        self::assertSame(AgentMode::Standard, AgentProfile::fromWire('x', [])->ceiling);
        self::assertSame(AgentMode::Standard, AgentProfile::fromWire('x', ['ceiling' => 'nonsense'])->ceiling);
        self::assertSame(AgentMode::Auto, AgentProfile::fromWire('x', ['ceiling' => 'auto'])->ceiling);
    }

    public function testTheDelegateToolOffersTheDeclaredAgentsToTheModel(): void
    {
        $schema = DelegateTool::definition(AgentProfiles::fromWire([
            'sorter' => ['description' => 'Sorts and summarises'],
            'writer' => ['description' => 'Drafts'],
        ]))->parameters;

        self::assertSame(['sorter', 'writer'], $schema['properties']['agent']['enum']);
        self::assertStringContainsString('Sorts and summarises', $schema['properties']['agent']['description']);
        self::assertSame([], DelegateTool::definition()->parameters['properties']['agent']['enum'], 'No application, no agent.');
    }
}
