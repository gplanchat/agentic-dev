<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Sandbox;

use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Sandbox\Worktrees;
use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\AgenticBundle\Tool\RunCommandTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(Worktrees::class)]
final class WorktreesTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = \dirname(__DIR__, 2).'/var/worktrees-test';
        $filesystem = new Filesystem();
        $filesystem->remove($this->project);
        $filesystem->dumpFile($this->project.'/src/Code.php', '<?php // committed');
        $filesystem->dumpFile($this->project.'/.gitignore', "/vendor/\n/packages/*/vendor/\n/.worktrees/\n");
        $filesystem->dumpFile($this->project.'/packages/lib/composer.json', '{}');
        $filesystem->dumpFile($this->project.'/vendor/autoload.php', '<?php // installed');
        $filesystem->dumpFile($this->project.'/packages/lib/vendor/autoload.php', '<?php // installed');
        $this->git('init', '-q', '-b', 'main');
        $this->git('add', '.');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-q', '-m', 'init');
        // Uncommitted: stays out of the worktree, which starts from HEAD.
        $filesystem->dumpFile($this->project.'/src/Draft.php', '<?php // not committed');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testAWorktreeIsCutFromHeadOnItsOwnBranch(): void
    {
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e-0000-4000-8000-000000000000');

        self::assertSame($this->project.'/.worktrees/agentic-3f2a9c1e', $path);
        self::assertDirectoryDoesNotExist($path, 'Nothing is created before a command needs it.');

        $worktrees->ensure($path);
        $worktrees->ensure($path);

        self::assertFileExists($path.'/src/Code.php');
        self::assertFileDoesNotExist($path.'/src/Draft.php');
        self::assertStringContainsString('agentic/agentic-3f2a9c1e', $this->git('branch', '--list', 'agentic/*'));
    }

    public function testTheWorktreeBorrowsGitAndTheDependenciesReadOnly(): void
    {
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e');
        $worktrees->ensure($path);

        self::assertSame([
            $this->project.'/.git' => $this->project.'/.git',
            $this->project.'/vendor' => $path.'/vendor',
            $this->project.'/packages/lib/vendor' => $path.'/packages/lib/vendor',
        ], $worktrees->readOnlyMounts($path));
        self::assertDirectoryExists($path.'/packages/lib/vendor', 'The mount point is ready.');
    }

    /**
     * The whole chain: the command runs in the worktree, git and the dependencies work there, and the
     * project is left alone.
     */
    public function testACommandRunsInTheConversationWorktree(): void
    {
        $sandbox = new Bubblewrap($this->project);
        if (null !== $problem = $sandbox->problem()) {
            self::markTestSkipped($problem);
        }
        // The borrowed autoloader says where it lives: the worktree, not the project.
        file_put_contents($this->project.'/vendor/autoload.php', '<?php echo dirname(__DIR__), "\n";');
        $worktrees = new Worktrees($this->project);
        $tool = new RunCommandTool(new Workspaces($sandbox, $worktrees));
        $path = $worktrees->pathFor('3f2a9c1e');

        self::assertSame("Exit code: 0\n{$path}\n", $tool->inContext(['command' => 'php vendor/autoload.php'], new ToolContext('test-call', $path)));
        self::assertStringStartsWith('Exit code: 0', $tool->inContext(['command' => 'git status --short'], new ToolContext('test-call', $path)));

        $tool->inContext(['command' => 'touch src/Written.php'], new ToolContext('test-call', $path));
        self::assertFileExists($path.'/src/Written.php');
        self::assertFileDoesNotExist($this->project.'/src/Written.php', 'The project is not the workspace.');

        self::assertStringStartsNotWith('Exit code: 0', $tool->inContext(['command' => 'touch vendor/planted.php'], new ToolContext('test-call', $path)), 'Borrowed read-only.');
        self::assertStringStartsNotWith('Exit code: 0', $tool->inContext(['command' => 'ls '.$this->project.'/src'], new ToolContext('test-call', $path)), 'The project itself is not mounted.');

        // The project configuration declares the commands run_checks runs WITHOUT approval: an
        // agent able to write it would be writing its own guard. It is protected even when the
        // project had none — a repository adopting agentic has no `.agentic/` yet, which is exactly
        // when the hole would be open.
        // `mkdir -p` first, on purpose: without it `touch` would fail for want of a parent and the
        // assertion would pass whether or not anything protects the directory.
        $tool->inContext(['command' => 'mkdir -p .agentic'], new ToolContext('test-call', $path));
        $tool->inContext(['command' => 'touch .agentic/config.yaml'], new ToolContext('test-call', $path));
        self::assertFileDoesNotExist($path.'/.agentic/config.yaml', 'The agent wrote its own guard.');

        // And a nested one: this is a monorepo, and `bin/agentic` launched from a package makes
        // that package's `.agentic` the one that is read.
        $tool->inContext(['command' => 'mkdir -p packages/inner/.agentic'], new ToolContext('test-call', $path));
        $tool->inContext(['command' => 'touch packages/inner/.agentic/config.yaml'], new ToolContext('test-call', $path));
        self::assertFileDoesNotExist($path.'/packages/inner/.agentic/config.yaml', 'A nested guard was writable.');

        // Ignored by git, run by the host: in a fresh worktree too, the agent cannot plant them.
        $tool->inContext(['command' => 'touch .claude/settings.json'], new ToolContext('test-call', $path));
        $tool->inContext(['command' => 'mkdir -p var/cache/dev'], new ToolContext('test-call', $path));
        $tool->inContext(['command' => 'cp src/Written.php .env.local'], new ToolContext('test-call', $path));
        self::assertFileDoesNotExist($path.'/.claude/settings.json');
        self::assertDirectoryDoesNotExist($path.'/var/cache');
        self::assertSame('', file_get_contents($path.'/.env.local'));
    }

    public function testOnlyItsOwnWorktreesAreAccepted(): void
    {
        $worktrees = new Worktrees($this->project);

        self::assertTrue($worktrees->owns($this->project.'/.worktrees/agentic-3f2a9c1e'));
        self::assertFalse($worktrees->owns($this->project));
        self::assertFalse($worktrees->owns($this->project.'/.worktrees/agentic-x/../../..'));
        self::assertFalse($worktrees->owns('/elsewhere/.worktrees/agentic-3f2a9c1e'));

        $this->expectException(\InvalidArgumentException::class);
        $worktrees->ensure($this->project.'/src');
    }

    private function git(string ...$arguments): string
    {
        $git = new Process(['git', '-C', $this->project, ...$arguments]);
        $git->mustRun();

        return $git->getOutput();
    }
}
