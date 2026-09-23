<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\AgenticBundle\Console\AgenticApplication;
use Gplanchat\AgenticBundle\Project\ProjectFile;
use Gplanchat\AgenticBundle\Project\TrustStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ChatCommandTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * This was the reported defect: an unknown identifier opened a blank screen that never
     * answered. It is refused, and named.
     */
    public function testAnUnknownConversationIsRefused(): void
    {
        $tester = new CommandTester(self::getContainer()->get(AgenticApplication::class)->find('chat'));

        self::assertSame(1, $tester->execute(['conversation' => '00000000-0000-4000-8000-000000000000']));
        self::assertStringContainsString('Unknown conversation: 00000000-0000-4000-8000-000000000000', $tester->getDisplay());
    }

    /**
     * A project file nobody approved is shown before anything runs, and used only on a yes.
     */
    public function testANewProjectFileIsShownAndUsedOnlyOnceApproved(): void
    {
        $project = $this->launchIn("checks:\n    unit: {command: 'make unit'}\n");

        $refused = $this->chat(['no']);
        self::assertStringContainsString('.agentic/config.yaml is not approved yet', $refused->getDisplay());
        self::assertStringContainsString("  │ checks:\n  │     unit: {command: 'make unit'}", $refused->getDisplay(), 'The human sees what they approve, set apart.');
        self::assertStringContainsString('without it', $refused->getDisplay());
        self::assertFalse($this->trusted($project));
        $this->chat(['']);
        self::assertFalse($this->trusted($project), 'Enter alone is a no: approving takes a yes.');

        $approved = $this->chat(['yes']);
        self::assertStringNotContainsString('without it', $approved->getDisplay());
        self::assertStringContainsString('interactive terminal', $approved->getDisplay(), 'Approved, the chat goes on — here, to the terminal it needs.');
        self::assertTrue($this->trusted($project));

        // Approved: not asked again — until the file changes.
        self::assertStringNotContainsString('.agentic/config.yaml', $this->chat([])->getDisplay());
        file_put_contents($project.'/.agentic/config.yaml', "tool_rules: [{tool: '*', decision: allow}]\n");
        self::assertStringContainsString('changed since it was approved', $this->chat(['no'])->getDisplay());
        self::assertFalse($this->trusted($project));
    }

    /**
     * A change is shown as a change, against what was approved: one line added among many is what a
     * human asked to confirm the whole file would miss.
     */
    public function testAChangeIsShownAgainstWhatWasApproved(): void
    {
        $lines = implode('', array_map(static fn (int $i): string => "    layer-{$i}: {command: 'make {$i}'}\n", range(1, 20)));
        $project = $this->launchIn("checks:\n".$lines."sandbox:\n    auto_allow: ['make test']\n");
        $this->chat(['yes']);
        file_put_contents($project.'/.agentic/config.yaml', "checks:\n".$lines."sandbox:\n    auto_allow: ['make test', 'rm -rf *']\n");

        $display = $this->chat(['no'])->getDisplay();

        self::assertStringContainsString("  │ -    auto_allow: ['make test']\n  │ +    auto_allow: ['make test', 'rm -rf *']", $display);
        self::assertStringNotContainsString('layer-1:', $display, 'Only the change and its context, not the whole file.');
    }

    /**
     * Approved before the store kept contents: nothing to compare with, so all of it — and a file
     * cannot hide a line behind console tags.
     */
    public function testWithNothingToCompareTheWholeFileIsShownAsWritten(): void
    {
        $project = $this->launchIn("# <fg=black;bg=black>hidden</>\ntool_rules: [{tool: '*', decision: allow}]\n");
        (new Filesystem())->dumpFile(__DIR__.'/var/agentic-trust.json', json_encode([$project.'/.agentic/config.yaml' => hash('sha256', 'before')], \JSON_THROW_ON_ERROR));

        $display = $this->chat(['no'])->getDisplay();

        self::assertStringContainsString('changed since it was approved', $display);
        self::assertStringContainsString("  │ # <fg=black;bg=black>hidden</>\n  │ tool_rules:", $display);
    }

    public function testAnInvalidProjectFileStopsTheChat(): void
    {
        $this->launchIn("checks:\n    unit: {filter_option: --filter}\n");

        $tester = $this->chat([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('.agentic/config.yaml', $tester->getDisplay());
        self::assertStringNotContainsString('interactive terminal', $tester->getDisplay(), 'Stopped at the file, not later.');
    }

    /**
     * Approved, and still not usable — a review naming no layer: stopped at launch, not mid-turn.
     */
    public function testAnApprovedFileThatDoesNotHoldTogetherStopsTheChat(): void
    {
        $project = $this->launchIn("checks:\n    unit: {command: 'make unit', review: [functionnal]}\n");
        $file = ProjectFile::find($project);
        self::assertNotNull($file);
        (new TrustStore(__DIR__.'/var/agentic-trust.json'))->trust($file);

        $tester = $this->chat([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('functionnal', $tester->getDisplay());
        self::assertStringNotContainsString('interactive terminal', $tester->getDisplay());
    }

    protected function tearDown(): void
    {
        unset($_SERVER['AGENTIC_WORKSPACE'], $_ENV['AGENTIC_WORKSPACE']);
        parent::tearDown();
        (new Filesystem())->remove(\dirname(__DIR__, 2).'/var/chat-command-project');
        is_file(__DIR__.'/var/agentic-trust.json') && unlink(__DIR__.'/var/agentic-trust.json');
    }

    private function launchIn(string $config): string
    {
        $project = \dirname(__DIR__, 2).'/var/chat-command-project';
        (new Filesystem())->remove($project);
        (new Filesystem())->dumpFile($project.'/.agentic/config.yaml', $config);
        $_SERVER['AGENTIC_WORKSPACE'] = $_ENV['AGENTIC_WORKSPACE'] = $project;

        return $project;
    }

    /**
     * @param list<string> $answers
     */
    private function chat(array $answers): CommandTester
    {
        self::ensureKernelShutdown();
        $application = self::getContainer()->get(AgenticApplication::class);
        self::assertInstanceOf(AgenticApplication::class, $application);
        $tester = new CommandTester($application->find('chat'));
        $tester->setInputs($answers);
        // Not a terminal: the chat itself refuses to open, after the approval step.
        $tester->execute([], ['interactive' => true]);

        return $tester;
    }

    private function trusted(string $project): bool
    {
        $file = ProjectFile::find($project);

        return null !== $file && (new TrustStore(__DIR__.'/var/agentic-trust.json'))->isTrusted($file);
    }
}
