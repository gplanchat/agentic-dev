<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Project;

use Gplanchat\AgenticBundle\Project\InvalidProjectConfiguration;
use Gplanchat\AgenticBundle\Project\ProjectLoader;
use Gplanchat\AgenticBundle\Project\TrustStore;
use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\AgenticBundle\Ticket\Forge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The installation's configuration, with an approved project file laid over it — and a file nobody
 * approved, or changed since, left out until someone does.
 */
final class ProjectLoaderTest extends TestCase
{
    private const INSTALLATION = [
        'checks' => ['lint' => ['command' => ['make lint'], 'cwd' => '', 'filter_option' => null, 'timeout_seconds' => 60.0, 'description' => '', 'tests' => '', 'review' => []]],
        'hidden' => ['.env.local', 'var'],
        'shared' => ['vendor'],
        'auto_allow' => ['git status'],
        'worktrees' => true,
    ];

    private string $root;

    private string $trustFile;

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 2).'/var/project-loader-test';
        (new Filesystem())->remove($base);
        $this->root = $base.'/project';
        $this->trustFile = $base.'/installation/trust.json';
        (new Filesystem())->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(\dirname($this->root));
    }

    public function testWithoutAFileTheInstallationSpeaks(): void
    {
        $project = $this->loader()->load($this->root);

        self::assertSame($this->root, $project->root);
        self::assertSame(['lint'], array_keys($project->checks));
        self::assertSame(['.env.local', 'var'], $project->hidden);
        self::assertSame([], $project->toolRules);
        self::assertTrue($project->worktrees);
        self::assertSame($this->root.'/AGENTS.md', $project->instructionsFile);
        self::assertNull($project->file);
    }

    /**
     * Nobody approved it: it waits, and nothing of it is used — not a layer, not a rule.
     */
    public function testAnUnapprovedFileWaitsAndIsNotUsed(): void
    {
        $this->write("checks: {unit: {command: 'make unit'}}\ntool_rules: [{tool: run_command, decision: allow}]\n");
        $loader = $this->loader();

        self::assertNotNull($loader->pending($this->root));
        $project = $loader->load($this->root);
        self::assertSame(['lint'], array_keys($project->checks));
        self::assertSame([], $project->toolRules);
        self::assertNull($project->file);
    }

    /**
     * Approved, it is laid over the installation: layers and rules add up, paths and commands join.
     */
    public function testAnApprovedFileIsLaidOverTheInstallation(): void
    {
        $this->write(<<<'YAML'
            instructions_file: docs/AGENTS.md
            checks:
                unit: {command: 'make unit', review: [lint]}
            tool_rules:
                - {tool: run_command, decision: deny, when: {command: 'rm *'}, modes: [auto]}
            sandbox:
                hidden: [var, secrets]
                shared: [node_modules]
                auto_allow: ['make unit']
                worktrees: false
            YAML);
        $this->approve();

        $loader = $this->loader();
        self::assertNull($loader->pending($this->root));
        $project = $loader->load($this->root);

        self::assertSame(['lint', 'unit'], array_keys($project->checks));
        self::assertSame(['.env.local', 'var', 'secrets'], $project->hidden, 'Joined, never narrowed.');
        self::assertSame(['vendor', 'node_modules'], $project->shared);
        self::assertSame(['git status', 'make unit'], $project->autoAllow);
        self::assertFalse($project->worktrees);
        self::assertSame('run_command', $project->toolRules[0]['tool']);
        self::assertSame(['auto'], $project->toolRules[0]['modes']);
        self::assertSame($this->root.'/docs/AGENTS.md', $project->instructionsFile);
        self::assertNotNull($project->file);
    }

    /**
     * An approval is for the bytes approved: a change — by anyone — asks again.
     */
    public function testAChangedFileAsksAgain(): void
    {
        $this->write("checks: {unit: {command: 'make unit'}}\n");
        $this->approve();
        $this->write("checks: {unit: {command: 'make unit'}}\ntool_rules: [{tool: '*', decision: allow}]\n");

        $loader = $this->loader();
        self::assertNotNull($loader->pending($this->root));
        self::assertSame([], $loader->load($this->root)->toolRules);
    }

    public function testAReviewNamingNoLayerIsRefused(): void
    {
        $this->write("checks: {unit: {command: 'make unit', review: [functionnal]}}\n");
        $this->approve();

        $this->expectException(InvalidProjectConfiguration::class);
        $this->expectExceptionMessageMatches('#config\.yaml.*"unit".*functionnal#');
        $this->loader()->load($this->root);
    }

    public function testNoInstructionsFileIsNone(): void
    {
        $this->write("instructions_file: '  '\n");
        $this->approve();

        self::assertNull($this->loader()->load($this->root)->instructionsFile);
    }

    public function testTheTicketTrackerIsTheProjects(): void
    {
        self::assertNull($this->loader()->load($this->root)->tickets, 'None said: no ticket tools.');

        $this->write("tickets: {forge: forgejo, repository: acme/app, url: 'https://codeberg.org', labels: {debt: 'dette technique'}}\n");
        $this->approve();
        $tickets = $this->loader()->load($this->root)->tickets;

        self::assertSame(Forge::Forgejo, $tickets?->forge);
        self::assertSame('acme/app', $tickets->repository);
        self::assertSame('https://codeberg.org', $tickets->url);
        self::assertSame('dette technique', $tickets->labels->of(HeadKind::Debt));
        self::assertSame('defect', $tickets->labels->of(HeadKind::Defect));
    }

    public function testAForgejoTrackerWithoutItsUrlIsRefused(): void
    {
        $this->write("tickets: {forge: forgejo, repository: acme/app}\n");
        $this->approve();

        $this->expectException(InvalidProjectConfiguration::class);
        $this->expectExceptionMessageMatches('#config\.yaml: A Forgejo ticket tracker needs the url#');
        $this->loader()->load($this->root);
    }

    public function testALabelTableThatWouldConfuseTwoFamiliesIsRefused(): void
    {
        $this->write("tickets: {forge: github, repository: acme/app, labels: {debt: tech, groundwork: Tech}}\n");
        $this->approve();

        $this->expectException(InvalidProjectConfiguration::class);
        $this->expectExceptionMessageMatches('#config\\.yaml: Two head families share a label#');
        $this->loader()->load($this->root);
    }

    private function loader(): ProjectLoader
    {
        return new ProjectLoader(new TrustStore($this->trustFile), self::INSTALLATION);
    }

    private function write(string $yaml): void
    {
        (new Filesystem())->dumpFile($this->root.'/.agentic/config.yaml', $yaml);
    }

    private function approve(): void
    {
        $file = $this->loader()->pending($this->root);
        self::assertNotNull($file);
        (new TrustStore($this->trustFile))->trust($file);
        self::assertStringContainsString("\n    \"{$file->path}\": {\n", (string) file_get_contents($this->trustFile), 'Readable by whoever opens it.');
    }
}
