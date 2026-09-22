<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\AgenticBundle\Tui\SlashCommands;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AgentsCommandTest extends KernelTestCase
{
    private Conversations $conversations;
    private SlashCommands $commands;
    private string $id;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->conversations = $container->get(Conversations::class);
        $this->commands = new SlashCommands($this->conversations);
        $this->id = $this->conversations->start();
        $container->get(InProcessWorker::class)->drain();
    }

    public function testAgentsListsWhatTheApplicationDeclares(): void
    {
        $notice = $this->commands->run($this->id, '/agents')->notice;

        self::assertMatchesRegularExpression('/sorter\s+ministral-3b-latest\s+standard\s+weather/', $notice);
        self::assertMatchesRegularExpression('/mailer\s+caller\s+standard\s+send_email/', $notice);
    }

    /**
     * A profile never grants: `mailer` is declared `auto`, but in a `standard` conversation what
     * applies is `standard`. The displayed ceiling is the one that applies, not the one wished for.
     */
    public function testTheCeilingShownIsTheOneThatApplies(): void
    {
        self::assertStringContainsString('at most standard here', $this->commands->run($this->id, '/agents mailer')->notice);

        $this->conversations->setMode($this->id, AgentMode::Auto);
        self::assertStringContainsString('at most auto here', $this->commands->run($this->id, '/agents mailer')->notice);
        self::assertStringContainsString('at most standard here', $this->commands->run($this->id, '/agents sorter')->notice, 'Its own ceiling still binds it.');
    }

    public function testOneAgentShowsItsInstructions(): void
    {
        $notice = $this->commands->run($this->id, '/agents sorter')->notice;

        self::assertStringContainsString('Sorts and summarises', $notice);
        self::assertStringContainsString('You sort, briefly.', $notice);
    }

    public function testAnUnknownAgentIsRefused(): void
    {
        $outcome = $this->commands->run($this->id, '/agents ghost');

        self::assertTrue($outcome->error);
        self::assertStringContainsString('sorter, mailer', $outcome->notice);
    }

    public function testTheProfilesAreFrozenInTheConversation(): void
    {
        self::assertSame(['sorter', 'mailer'], $this->conversations->transcript($this->id)->profiles->names());
    }
}
