<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\AgenticBundle\Project\ProjectLoader;
use Gplanchat\AgenticBundle\Project\TrustStore;
use Gplanchat\AgenticBundle\Tui\ChatScreen;
use Gplanchat\AgenticBundle\Tui\SlashCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * The skills of a project that names its ticket tracker: offered as a tool, run from a command that
 * sends their request in the user's name, and backed by the verifier seat.
 */
final class SkillCommandsTest extends KernelTestCase
{
    private string $project;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $this->project = \dirname(__DIR__, 2).'/var/skilled-project';
        (new Filesystem())->remove($this->project);
        (new Filesystem())->dumpFile($this->project.'/.agentic/config.yaml', "tickets: {forge: github, repository: acme/app}\n");
        $_SERVER['AGENTIC_WORKSPACE'] = $_ENV['AGENTIC_WORKSPACE'] = $this->project;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['AGENTIC_WORKSPACE'], $_ENV['AGENTIC_WORKSPACE']);
        parent::tearDown();
        (new Filesystem())->remove($this->project);
        is_file($trust = __DIR__.'/var/agentic-trust.json') && unlink($trust);
    }

    public function testASkillCommandSendsItsRequestAndTheSkillAndItsSeatAreThere(): void
    {
        $this->approve();
        $conversations = $this->conversations();
        $id = $conversations->start();
        $transcript = $conversations->transcript($id);
        $commands = new SlashCommands($conversations);

        self::assertContains('skill', array_map(static fn ($tool): string => $tool->name, iterator_to_array($transcript->tools, false)));
        $verifier = $transcript->profiles->find('verifier');
        self::assertSame(AgentMode::Plan, $verifier?->ceiling);
        self::assertSame(['ticket_read', 'worktree_diff', 'read_file'], $verifier->tools);

        self::assertSame('Use the skill `statut`.', $commands->run($id, '/statut')->send);
        self::assertSame('Use the skill `cadrer`: tickets in the chat, for everyone.', $commands->run($id, '/cadrer  tickets in the chat, for everyone ')->send);
        self::assertSame('Use the skill `continuer`: #45.', $commands->run($id, '/continuer #45')->send);
        self::assertSame('Use the skill `revue`.', $commands->run($id, '/revue')->send);
        self::assertSame('', $commands->run($id, '/revue')->notice);
        self::assertSame('Unknown command: /statuts. /help for the list.', $commands->run($id, '/statuts')->notice);
        self::assertStringContainsString('/continuer  delivers one work ticket, Mikado and TDD: /continuer [#ticket]', $commands->run($id, '/help')->notice);
    }

    public function testTypedInTheScreenTheRequestGoesOutAsAMessage(): void
    {
        $this->approve();
        $container = self::getContainer();
        $terminal = new VirtualTerminal(100, 30);
        $screen = $container->get(ChatScreen::class);
        self::assertInstanceOf(ChatScreen::class, $screen);
        $view = $screen->open($this->conversations()->start(), $terminal);
        $view->tui->start();
        $view->refresh();
        $view->tui->tick();

        foreach (mb_str_split("/statut\r") as $key) {
            $terminal->simulateInput($key);
        }
        $view->refresh();
        $view->tui->tick();

        self::assertSame('Use the skill `statut`.', $this->conversations()->transcript($view->conversation)->userMessages()[0] ?? null);
    }

    public function testWithoutATrackerTheCommandSaysWhy(): void
    {
        $conversations = $this->conversations();
        $id = $conversations->start();

        $outcome = (new SlashCommands($conversations))->run($id, '/continuer');

        self::assertTrue($outcome->error);
        self::assertNull($outcome->send);
        self::assertStringStartsWith('The skills work on the project\'s tickets, and this conversation has none', $outcome->notice);
    }

    private function approve(): void
    {
        $loader = self::getContainer()->get(ProjectLoader::class);
        self::assertInstanceOf(ProjectLoader::class, $loader);
        $pending = $loader->pending($this->project);
        self::assertNotNull($pending);
        (new TrustStore(__DIR__.'/var/agentic-trust.json'))->trust($pending);
        self::ensureKernelShutdown();
    }

    private function conversations(): Conversations
    {
        $conversations = self::getContainer()->get(Conversations::class);
        self::assertInstanceOf(Conversations::class, $conversations);

        return $conversations;
    }
}
