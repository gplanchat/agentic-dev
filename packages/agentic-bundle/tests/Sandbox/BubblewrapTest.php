<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Sandbox;

use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Tool\RunCommandTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The real bwrap, on a real folder: isolation is not proven with a double.
 */
#[CoversClass(Bubblewrap::class)]
#[CoversClass(RunCommandTool::class)]
final class BubblewrapTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        // Under the project, not under /tmp: the sandbox mounts a /tmp of its own.
        $this->workspace = \dirname(__DIR__, 2).'/var/sandbox-test';
        (new Filesystem())->remove($this->workspace);
        mkdir($this->workspace.'/src', 0o777, true);
        mkdir($this->workspace.'/.git');
        file_put_contents($this->workspace.'/.env.local', "MISTRAL_API_KEY=project-secret\n");
        file_put_contents($this->workspace.'/.env.dev.local', "MISTRAL_API_KEY=dev-secret\n");
        file_put_contents($this->workspace.'/keep', 'not to be erased');

        if (null !== $problem = (new Bubblewrap($this->workspace))->problem()) {
            self::markTestSkipped($problem);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workspace);
    }

    public function testACommandRunsInTheWorkspaceAndReportsItsExitCode(): void
    {
        self::assertSame("Exit code: 0\nhello\n", $this->sandboxed('echo hello'));
        self::assertStringStartsWith('Exit code: 1', $this->sandboxed('false'));

        $this->sandboxed('touch created-in-the-sandbox');
        self::assertFileExists($this->workspace.'/created-in-the-sandbox', 'The workspace is mounted writable.');
    }

    /**
     * What the allowlist sees is what runs: `;` chains nothing.
     */
    public function testThereIsNoShell(): void
    {
        $this->sandboxed('ls keep; rm -rf keep');
        $this->sandboxed('echo $(rm keep)');

        self::assertFileExists($this->workspace.'/keep');
    }

    public function testTheRestOfTheDiskIsInvisible(): void
    {
        // The parents of the workspace exist, empty: only the path that leads to it is visible.
        $home = (string) getenv('HOME');
        $onTheWay = explode('/', ltrim(substr($this->workspace, \strlen($home)), '/'))[0];
        self::assertSame("Exit code: 0\n{$onTheWay}\n", $this->sandboxed('ls -A '.$home));

        self::assertStringNotContainsString('Exit code: 0', $this->sandboxed('cat '.\dirname($this->workspace, 2).'/composer.json'));
    }

    public function testSecretsAndTheJournalAreMaskedAndGitIsReadOnly(): void
    {
        self::assertStringNotContainsString('project-secret', $this->sandboxed('cat .env.local'));
        self::assertStringNotContainsString('dev-secret', $this->sandboxed('cat .env.dev.local'));
        self::assertStringNotContainsString('Exit code: 0', $this->sandboxed('touch .git/hook-put-there-by-the-agent'));
        self::assertFileDoesNotExist($this->workspace.'/.git/hook-put-there-by-the-agent');
    }

    /**
     * In a worktree, `.git` is a file: rewritten, its `gitdir:` line would send the human's next
     * `git status` towards a configuration put there by the agent.
     */
    public function testAGitFileAndTheAgentSettingsAreReadOnlyToo(): void
    {
        rmdir($this->workspace.'/.git');
        file_put_contents($this->workspace.'/.git', "gitdir: elsewhere\n");
        mkdir($this->workspace.'/.claude');

        $this->sandboxed('truncate -s 0 .git');
        self::assertStringNotContainsString('Exit code: 0', $this->sandboxed('touch .claude/settings.json'));
        self::assertSame("gitdir: elsewhere\n", file_get_contents($this->workspace.'/.git'));
    }

    public function testTheEnvironmentIsClearedAndTheNetworkCut(): void
    {
        putenv('AGENTIC_LEAK=key-of-the-interface');
        try {
            self::assertStringNotContainsString('key-of-the-interface', $this->sandboxed('env'));
        } finally {
            putenv('AGENTIC_LEAK');
        }

        self::assertStringNotContainsString('Exit code: 0', $this->sandboxed('getent hosts example.com'));
    }

    public function testATimeoutAndALongOutputComeBackAsResults(): void
    {
        self::assertStringStartsWith('Interrupted after 1 s.', (new Bubblewrap($this->workspace, timeoutSeconds: 1.0))->run('sleep 5', $this->workspace));

        $long = $this->sandboxed('seq 1 20000');
        self::assertStringContainsString('bytes omitted', $long);
        self::assertStringEndsWith("20000\n", $long, 'The tail of the output is kept: the errors are there.');
    }

    public function testTheToolRefusesAWorkingDirectoryOutsideTheWorkspace(): void
    {
        symlink(\dirname($this->workspace), $this->workspace.'/src/outside');
        $tool = new RunCommandTool(new Workspaces(new Bubblewrap($this->workspace)));

        self::assertStringStartsWith('Directory refused', $tool(['command' => 'ls', 'cwd' => '..']));
        self::assertStringStartsWith('Directory refused', $tool(['command' => 'ls', 'cwd' => 'src/outside']));
        self::assertStringStartsWith('Exit code: 0', $tool(['command' => 'ls', 'cwd' => 'src']));
    }

    public function testAMissingBinaryIsReportedWithInstallInstructions(): void
    {
        $sandbox = new Bubblewrap($this->workspace, binary: 'bwrap-not-found');

        self::assertStringContainsString('apt install bubblewrap', (string) $sandbox->problem());
        self::assertSame($sandbox->problem(), $sandbox->run('echo hello', $this->workspace), 'Handed back to the model, not thrown: an exception would be retried.');
    }

    private function sandboxed(string $command): string
    {
        return (new Bubblewrap($this->workspace))->run($command, $this->workspace);
    }
}
