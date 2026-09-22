<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\AgenticBundle\Tui\SlashCommands;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les hooks de décision et AGENTS.md, de la configuration jusqu'à l'agent.
 */
final class ProjectHooksTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAgentsMdIsAppendedToTheSystemPromptAtStart(): void
    {
        $container = self::getContainer();
        $id = $container->get(Conversations::class)->start();

        $prompt = (string) ($container->get(WorkflowMetadataStore::class)->get($id)['payload']['systemPrompt'] ?? '');

        self::assertStringContainsString('# Consignes du projet (AGENTS.md)', $prompt);
        self::assertStringContainsString('cite tes sources', $prompt);
    }

    public function testADenyRuleFromTheConfigStopsTheCallAndTheAgentIsTold(): void
    {
        $container = self::getContainer();
        $conversations = $container->get(Conversations::class);
        $worker = $container->get(InProcessWorker::class);

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Quel temps fait-il à Lyon ?');
        $worker->drain();

        $transcript = $conversations->transcript($id);
        self::assertSame([], $transcript->steps, 'L’outil refusé n’a pas été exécuté.');
        $messages = $transcript->messages;
        self::assertStringContainsString('Lyon est hors périmètre.', (string) end($messages)->content);

        // Paris n'est pas visé : la règle est conditionnée à l'argument.
        $conversations->send($id, 'Et à Paris ?');
        $worker->drain();
        self::assertSame('weather', $conversations->transcript($id)->steps[0]->tool);
    }

    public function testToolsShowTheRules(): void
    {
        $container = self::getContainer();
        $id = $container->get(Conversations::class)->start();
        $container->get(InProcessWorker::class)->drain();

        $notice = (new SlashCommands($container->get(Conversations::class)))->run($id, '/tools')->notice;

        self::assertStringContainsString('deny  weather city=Lyon — Lyon est hors périmètre.', $notice);
    }
}
