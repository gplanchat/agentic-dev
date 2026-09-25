<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\CurrentPrincipal;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\AgenticBundle\Console\AgenticApplication;
use Gplanchat\AgenticBundle\Project\ProjectLoader;
use Gplanchat\AgenticBundle\Project\TrustStore;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * The agent launched in a project: that directory is the workspace, and its `.agentic/config.*`
 * joins the conversation — once, and only once, a human approved it.
 */
final class ProjectConfigurationTest extends KernelTestCase
{
    private const TICKET_TOOLS = ['ticket_read', 'ticket_open_head', 'ticket_open_work', 'ticket_block', 'ticket_unblock', 'ticket_close'];

    private string $project;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $this->project = \dirname(__DIR__, 2).'/var/launched-project';
        $filesystem = new Filesystem();
        $filesystem->remove($this->project);
        $filesystem->dumpFile($this->project.'/.agentic/config.yaml', <<<'YAML'
            checks:
                project-unit: {command: 'true', tests: tests/Unit}
            tool_rules:
                - {tool: weather, decision: deny, reason: 'The project says no.'}
            sandbox:
                auto_allow: ['make test']
            tickets: {forge: github, repository: acme/app}
            YAML);
        $filesystem->dumpFile($this->project.'/AGENTS.md', 'Write the tests first in this project.');
        $_SERVER['AGENTIC_WORKSPACE'] = $_ENV['AGENTIC_WORKSPACE'] = $this->project;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['AGENTIC_WORKSPACE'], $_ENV['AGENTIC_WORKSPACE']);
        parent::tearDown();
        (new Filesystem())->remove($this->project);
        $trust = self::trustFile();
        is_file($trust) && unlink($trust);
    }

    public function testAnUnapprovedProjectFileIsNotUsed(): void
    {
        [, $transcript] = $this->start();

        self::assertSame(['kernel-unit'], self::layers($transcript), 'The installation\'s layer only.');
        self::assertSame([], array_intersect(self::TICKET_TOOLS, self::tools($transcript)), 'No tracker named: no ticket tools.');
        self::assertContains('revert_worktree', self::tools($transcript));
        self::assertContains('commit_worktree', self::tools($transcript));
        self::assertSame([], array_filter($transcript->rules, static fn ($rule): bool => 'The project says no.' === $rule->reason));
        // Launched from a directory inside this repository: the worktree is the repository's, and the
        // workspace the launch directory within it.
        self::assertMatchesRegularExpression('#/\.worktrees/agentic-[0-9a-f]{8}/packages/agentic-bundle/var/launched-project$#', (string) $transcript->workspace, 'The launch directory is the workspace, approved file or not.');
    }

    public function testAnApprovedProjectFileJoinsTheConversation(): void
    {
        $this->approve();

        [$id, $transcript] = $this->start();

        self::assertSame(['kernel-unit', 'project-unit'], self::layers($transcript));
        self::assertSame(self::TICKET_TOOLS, array_values(array_intersect(self::tools($transcript), self::TICKET_TOOLS)), 'The project names its tracker.');
        $project = array_values(array_filter($transcript->rules, static fn ($rule): bool => 'The project says no.' === $rule->reason));
        self::assertSame('weather', $project[0]->tool ?? null, 'The project\'s rule, next to the installation\'s.');
        $auto = array_values(array_filter($transcript->rules, static fn ($rule): bool => 'run_command' === $rule->tool && [] !== $rule->unless));
        self::assertSame(['git status', 'git status *', 'make test'], $auto[0]->unless['command'] ?? null);

        $prompt = $this->payload($id)['systemPrompt'] ?? '';
        self::assertStringStartsWith(DurableAgentWorkflow::SYSTEM_PROMPT, $prompt, 'The method is added to the prompt, not put in its place.');
        self::assertStringContainsString('Write the tests first in this project.', $prompt);
        self::assertStringContainsString('`project-unit`', $prompt);
    }

    /**
     * A conversation begun in another project is not listed here, and not reopened from here: its
     * workspace, its approved configuration, its worktree all belong to that other directory.
     */
    public function testAConversationOfAnotherProjectStaysThere(): void
    {
        [$here] = $this->start();
        $elsewhere = (string) Uuid::v4();
        $conversations = self::getContainer()->get(Conversations::class);
        $dispatcher = self::getContainer()->get(WorkflowResumeDispatcher::class);
        $principal = self::getContainer()->get(CurrentPrincipal::class);
        self::assertInstanceOf(Conversations::class, $conversations);
        self::assertInstanceOf(WorkflowResumeDispatcher::class, $dispatcher);
        self::assertInstanceOf(CurrentPrincipal::class, $principal);
        $dispatcher->dispatchNewWorkflowRun($elsewhere, DurableAgentWorkflow::class, ['workspace' => '/somewhere/else', 'owner' => $principal()->toWire(), 'idleTimeoutSeconds' => 3600.0]);
        $worker = self::getContainer()->get(InProcessWorker::class);
        self::assertInstanceOf(InProcessWorker::class, $worker);
        $worker->drain();

        // With no workspace — no sandbox, no worktree —, there is nowhere else it could belong.
        $nowhere = (string) Uuid::v4();
        $dispatcher->dispatchNewWorkflowRun($nowhere, DurableAgentWorkflow::class, ['owner' => $principal()->toWire(), 'idleTimeoutSeconds' => 3600.0]);
        $worker->drain();
        self::assertTrue($conversations->belongsHere($nowhere));

        self::assertTrue($conversations->belongsHere($here));
        self::assertFalse($conversations->belongsHere($elsewhere));
        $listed = array_map(static fn ($summary): string => $summary->id, $conversations->recent());
        self::assertContains($here, $listed);
        self::assertNotContains($elsewhere, $listed);

        $application = self::getContainer()->get(AgenticApplication::class);
        self::assertInstanceOf(AgenticApplication::class, $application);
        $chat = new CommandTester($application->find('chat'));
        self::assertSame(1, $chat->execute(['conversation' => $elsewhere]));
        self::assertStringContainsString('belongs to another project (/somewhere/else)', $chat->getDisplay());
    }

    private function approve(): void
    {
        $loader = self::getContainer()->get(ProjectLoader::class);
        self::assertInstanceOf(ProjectLoader::class, $loader);
        $pending = $loader->pending($this->project);
        self::assertNotNull($pending);
        (new TrustStore(self::trustFile()))->trust($pending);
        self::ensureKernelShutdown();
    }

    /**
     * @return array{0: string, 1: Transcript}
     */
    private function start(): array
    {
        $conversations = self::getContainer()->get(Conversations::class);
        self::assertInstanceOf(Conversations::class, $conversations);
        $id = $conversations->start();

        return [$id, $conversations->transcript($id)];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $conversation): array
    {
        $metadata = self::getContainer()->get(WorkflowMetadataStore::class);
        self::assertInstanceOf(WorkflowMetadataStore::class, $metadata);

        return $metadata->get($conversation)['payload'] ?? [];
    }

    /**
     * @return list<string>
     */
    private static function layers(Transcript $transcript): array
    {
        foreach ($transcript->tools as $tool) {
            if ('run_checks' === $tool->name) {
                return $tool->parameters['properties']['layer']['enum'] ?? [];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function tools(Transcript $transcript): array
    {
        return array_map(static fn ($tool): string => $tool->name, iterator_to_array($transcript->tools, false));
    }

    private static function trustFile(): string
    {
        return __DIR__.'/var/agentic-trust.json';
    }
}
