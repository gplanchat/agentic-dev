<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\AgenticBundle\Tui\SlashCommands;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The decision hooks and AGENTS.md, from the configuration all the way to the agent.
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

        self::assertStringContainsString('# Project instructions (AGENTS.md)', $prompt);
        self::assertStringContainsString('cite your sources', $prompt);
    }

    public function testADenyRuleFromTheConfigStopsTheCallAndTheAgentIsTold(): void
    {
        $container = self::getContainer();
        $conversations = $container->get(Conversations::class);
        $worker = $container->get(InProcessWorker::class);

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'What is the weather in Lyon?');
        $worker->drain();

        $transcript = $conversations->transcript($id);
        self::assertSame([], $transcript->steps, 'The denied tool was not executed.');
        $messages = $transcript->messages;
        self::assertStringContainsString('Lyon is out of scope.', (string) end($messages)->content);

        // Paris is not targeted: the rule is conditioned on the argument.
        $conversations->send($id, 'And in Paris?');
        $worker->drain();
        self::assertSame('weather', $conversations->transcript($id)->steps[0]->tool);
    }

    public function testToolsShowTheRules(): void
    {
        $container = self::getContainer();
        $id = $container->get(Conversations::class)->start();
        $container->get(InProcessWorker::class)->drain();

        $notice = (new SlashCommands($container->get(Conversations::class)))->run($id, '/tools')->notice;

        self::assertStringContainsString('deny  weather city=Lyon — Lyon is out of scope.', $notice);
    }
}
