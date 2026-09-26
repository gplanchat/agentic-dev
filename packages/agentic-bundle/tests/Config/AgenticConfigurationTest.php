<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Config;

use Gplanchat\AgenticBundle\AgenticBundle;
use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\AgenticBundle\Chat\DurableConversations;
use Gplanchat\AgenticBundle\Console\ChatCommand;
use Gplanchat\AgenticBundle\Project\Project;
use Gplanchat\AgenticBundle\Project\ProjectLoader;
use Gplanchat\AgenticBundle\Project\TrustStore;
use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Skill\Skills;
use Gplanchat\AgenticBundle\Skill\SkillTool;
use Gplanchat\AgenticBundle\Ticket\TicketOperation;
use Gplanchat\AgenticBundle\Ticket\TicketTool;
use Gplanchat\AgenticBundle\Tool\CommitWorktreeTool;
use Gplanchat\AgenticBundle\Tool\EditFileTool;
use Gplanchat\AgenticBundle\Tool\ReadFileTool;
use Gplanchat\AgenticBundle\Tool\RevertWorktreeTool;
use Gplanchat\AgenticBundle\Tool\RunChecksTool;
use Gplanchat\AgenticBundle\Tool\RunCommandTool;
use Gplanchat\AgenticBundle\Tool\WorktreeDiffTool;
use Gplanchat\AgenticBundle\Tui\ChatScreen;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The bundle's configuration read and wired here, with no kernel: the test that asserts a setting is
 * the one that runs the code reading it — what mutation testing needs to hold the two together.
 */
final class AgenticConfigurationTest extends TestCase
{
    public function testATurnMakesFortyToolCallsUnlessToldOtherwise(): void
    {
        self::assertSame(40, $this->conversationOptions([])['maxToolCalls']);
        self::assertSame(7, $this->conversationOptions(['max_tool_calls' => 7])['maxToolCalls']);
    }

    public function testATurnMakesAtLeastOneToolCall(): void
    {
        self::assertSame(1, $this->conversationOptions(['max_tool_calls' => 1])['maxToolCalls']);

        $this->expectException(InvalidConfigurationException::class);
        $this->conversationOptions(['max_tool_calls' => 0]);
    }

    public function testTheModelReachesTheConversations(): void
    {
        self::assertSame('mistral-small-latest', $this->conversationOptions([])['model']);
        self::assertSame('devstral-latest', $this->conversationOptions(['model' => 'devstral-latest'])['model']);
    }

    /**
     * The project is read when the agent starts, from where it starts — and only then: lazy, so the
     * chat can ask for the approval of a project file before anything reads it.
     */
    public function testTheProjectIsReadAtRuntimeFromTheLaunchDirectory(): void
    {
        $container = $this->container([]);

        self::assertSame('%env(default:kernel.project_dir:AGENTIC_WORKSPACE)%', $container->getParameter('agentic.project_root'));
        $project = $container->getDefinition(Project::class);
        self::assertTrue($project->isLazy());
        self::assertSame(['%agentic.project_root%'], $project->getArguments());
        self::assertSame(['%kernel.project_dir%/var/agentic-trust.json'], $container->getDefinition(TrustStore::class)->getArguments(), 'Approvals live with the installation, not in the project.');

        $chat = $container->getDefinition(ChatCommand::class)->getArguments();
        self::assertSame([Conversations::class, ChatScreen::class, ProjectLoader::class, TrustStore::class], array_map(strval(...), \array_slice($chat, 0, 4)));
        self::assertSame('%agentic.project_root%', $chat[4]);
    }

    public function testTheSandboxIsWiredOnlyWhenEnabled(): void
    {
        self::assertFalse($this->container([])->hasDefinition(Bubblewrap::class));
        self::assertFalse($this->container([])->hasDefinition(RunChecksTool::class));

        $container = $this->container(['sandbox' => ['enabled' => true, 'timeout_seconds' => 7.0, 'binary' => 'bw']]);
        $sandbox = $container->getDefinition(Bubblewrap::class);
        self::assertTrue($sandbox->isLazy());
        self::assertSame([Project::class, 7.0, 'bw'], array_map(static fn (mixed $argument): mixed => $argument instanceof Reference ? (string) $argument : $argument, $sandbox->getArguments()));
        self::assertTrue($container->getDefinition(Workspaces::class)->isLazy());
        foreach ([ReadFileTool::class, EditFileTool::class, RunCommandTool::class, RunChecksTool::class, RevertWorktreeTool::class, CommitWorktreeTool::class, WorktreeDiffTool::class] as $tool) {
            self::assertArrayHasKey(AgenticBundle::TOOL_TAG, $container->getDefinition($tool)->getTags(), $tool);
        }
    }

    /**
     * One tool per operation, whatever the sandbox: AgentTools offers them when the project names
     * a tracker. The token is the installation's.
     */
    public function testEveryTicketOperationIsATool(): void
    {
        self::assertSame(array_map(static fn (TicketOperation $operation): array => [$operation, 's3cret'], TicketOperation::cases()), $this->ticketTools(['tickets_token' => 's3cret']));
        self::assertSame('', $this->ticketTools([])[0][1] ?? null, 'No token by default.');
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<array{0: mixed, 1: mixed}> each ticket tool's operation and token
     */
    private function ticketTools(array $config): array
    {
        $container = $this->container($config);
        $tools = [];
        foreach (array_keys($container->findTaggedServiceIds(AgenticBundle::TOOL_TAG)) as $id) {
            $definition = $container->getDefinition($id);
            if (TicketTool::class === $definition->getClass()) {
                $tools[] = [$definition->getArgument(0), $definition->getArgument(2)];
            }
        }

        return $tools;
    }

    /**
     * The seat the skills rely on is there by default, and the installation's own replaces it.
     */
    public function testTheVerifierSeatJudgesInPlanModeUnlessTheInstallationSaysOtherwise(): void
    {
        $agents = $this->conversationOptions([])['agents'];
        $prompt = (string) ($agents['verifier']['prompt'] ?? '');
        self::assertSame([
            'description' => 'Judges finished work against its ticket, in a clean context — never the one who made it',
            'prompt' => $prompt,
            'model' => null,
            'ceiling' => 'plan',
            'tools' => ['ticket_read', 'worktree_diff', 'read_file'],
            'max_turns' => 1,
            'roles' => [],
        ], $agents['verifier'] ?? null);
        self::assertStringStartsWith('You judge work you did not make', $prompt);
        self::assertStringEndsWith('a wrong PASS closes a ticket'."\n".'that is not done.', $prompt, 'Trimmed.');

        $container = $this->container([]);
        self::assertArrayHasKey(AgenticBundle::TOOL_TAG, $container->getDefinition(SkillTool::class)->getTags(), 'Offered whatever the sandbox, when the project names a tracker.');
        self::assertSame([Skills::class, 'bundled'], $container->getDefinition(Skills::class)->getFactory());
        self::assertSame([Skills::class, Project::class], array_map(static fn (mixed $argument): string => (string) $argument, $container->getDefinition(SkillTool::class)->getArguments()));

        $own = ['description' => 'Mine', 'prompt' => 'P', 'model' => null, 'ceiling' => 'plan', 'tools' => ['read_file'], 'max_turns' => 2, 'roles' => []];
        $agents = $this->conversationOptions(['agents' => ['verifier' => $own, 'scribe' => $own]])['agents'];
        self::assertSame(['verifier', 'scribe'], array_keys($agents));
        self::assertSame('Mine', $agents['verifier']['description']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new AgenticBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed> the start payload options DurableConversations is given
     */
    private function conversationOptions(array $config): array
    {
        foreach ($this->container($config)->getDefinition(DurableConversations::class)->getArguments() as $argument) {
            if (\is_array($argument) && \array_key_exists('maxToolCalls', $argument)) {
                return $argument;
            }
        }

        self::fail('DurableConversations is given no start payload options.');
    }
}
