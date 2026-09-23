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
        // Outside any repository, under /tmp: inside this one, a path gone wrong — a mutant, a bug —
        // would have git fall back on this repository, and cut worktrees and branches in it.
        $this->project = sys_get_temp_dir().'/agentic.worktrees-test-'.getmypid();
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
        file_put_contents($this->project.'/.git/info/exclude', 'local-rule');

        $worktrees->ensure($path);
        $worktrees->ensure($path);

        self::assertSame("local-rule\n/.worktrees/\n", file_get_contents($this->project.'/.git/info/exclude'), 'Kept out of git once, on a line of its own.');

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

    /**
     * Launched from a subdirectory: the worktree is the repository's, the workspace the same
     * subdirectory inside it — and git still works there, in the sandbox.
     */
    public function testLaunchedFromASubdirectoryTheWorkspaceIsThatSubdirectory(): void
    {
        $worktrees = Worktrees::of($this->project.'/packages/lib', ['vendor']);
        self::assertNotNull($worktrees);
        $path = $worktrees->pathFor('5ub0d1r0');

        self::assertSame($this->project.'/.worktrees/agentic-5ub0d1r0/packages/lib', $path);
        self::assertTrue($worktrees->owns($path));
        self::assertFalse($worktrees->owns($this->project.'/.worktrees/agentic-5ub0d1r0'), 'Not the whole repository: the subdirectory.');

        file_put_contents($this->project.'/.git/info/exclude', "local-rule\n");
        $worktrees->ensure($path);
        self::assertFileExists($path.'/composer.json');
        self::assertSame("local-rule\n/.worktrees/\n", file_get_contents($this->project.'/.git/info/exclude'), 'Kept out of git, without touching the project\'s files.');
        self::assertSame([
            $this->project.'/.git' => $this->project.'/.git',
            $this->project.'/.worktrees/agentic-5ub0d1r0/.git' => $this->project.'/.worktrees/agentic-5ub0d1r0/.git',
            $this->project.'/packages/lib/vendor' => $path.'/vendor',
        ], $worktrees->readOnlyMounts($path));

        $sandbox = new Bubblewrap($this->project.'/packages/lib');
        if (null !== $problem = $sandbox->problem()) {
            self::markTestSkipped($problem);
        }
        $tool = new RunCommandTool(new Workspaces($sandbox, $worktrees));
        self::assertStringStartsWith('Exit code: 0', $tool->inContext(['command' => 'git status --short'], new ToolContext('test-call', $path)));
        self::assertSame("Exit code: 0\ncomposer.json\nvar\nvendor\n", $tool->inContext(['command' => 'ls'], new ToolContext('test-call', $path)));
    }

    /**
     * A branch of that name already there: git refuses, and the failure says so — an infrastructure
     * failure, retried like one, not a silent empty workspace.
     */
    public function testAWorktreeGitRefusesIsAFailureThatSaysSo(): void
    {
        $this->git('branch', 'agentic/agentic-c0111de0');
        $worktrees = new Worktrees($this->project);

        $this->expectException(\RuntimeException::class);
        // git's own words follow, in the machine's language: only its naming of the branch is checked.
        $this->expectExceptionMessageMatches('#^Could not create the worktree .*/agentic-c0111de0: .*agentic/agentic-c0111de0#s');
        $worktrees->ensure($worktrees->pathFor('c0111de0'));
    }

    public function testOutsideAGitRepositoryThereIsNoWorktree(): void
    {
        // Under /tmp: anywhere in this repository, git would find the repository above.
        $plain = sys_get_temp_dir().'/agentic-not-a-repository-'.getmypid();
        (new Filesystem())->mkdir($plain);
        try {
            self::assertNull(Worktrees::of($plain, ['vendor']));
        } finally {
            (new Filesystem())->remove($plain);
        }
    }

    public function testOnlyItsOwnWorktreesAreAccepted(): void
    {
        $worktrees = new Worktrees($this->project);

        self::assertTrue($worktrees->owns($this->project.'/.worktrees/agentic-3f2a9c1e'));
        self::assertFalse($worktrees->owns($this->project));
        self::assertFalse($worktrees->owns($this->project.'/.worktrees/agentic-x/../../..'));
        self::assertFalse($worktrees->owns('/elsewhere/.worktrees/agentic-3f2a9c1e'));
        self::assertFalse($worktrees->owns(str_replace('agentic.worktrees', 'agenticXworktrees', $this->project).'/.worktrees/agentic-3f2a9c1e'), 'The project path is matched as written, not as a pattern.');

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
