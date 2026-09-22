<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Sandbox;

use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\Worktrees;
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
        $tool = new RunCommandTool($sandbox, $worktrees);
        $path = $worktrees->pathFor('3f2a9c1e');

        self::assertSame("Exit code: 0\n{$path}\n", $tool->inWorkspace(['command' => 'php vendor/autoload.php'], $path));
        self::assertStringStartsWith('Exit code: 0', $tool->inWorkspace(['command' => 'git status --short'], $path));

        $tool->inWorkspace(['command' => 'touch src/Written.php'], $path);
        self::assertFileExists($path.'/src/Written.php');
        self::assertFileDoesNotExist($this->project.'/src/Written.php', 'The project is not the workspace.');

        self::assertStringStartsNotWith('Exit code: 0', $tool->inWorkspace(['command' => 'touch vendor/planted.php'], $path), 'Borrowed read-only.');
        self::assertStringStartsNotWith('Exit code: 0', $tool->inWorkspace(['command' => 'ls '.$this->project.'/src'], $path), 'The project itself is not mounted.');

        // Ignored by git, run by the host: in a fresh worktree too, the agent cannot plant them.
        $tool->inWorkspace(['command' => 'touch .claude/settings.json'], $path);
        $tool->inWorkspace(['command' => 'mkdir -p var/cache/dev'], $path);
        $tool->inWorkspace(['command' => 'cp src/Written.php .env.local'], $path);
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
