<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Sandbox;

use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\WorkingTreeChanges;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Sandbox\Worktrees;
use Gplanchat\AgenticBundle\Tool\RunCommandTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * What a command changed follows its output: the model learns what it touched, the human sees it.
 */
final class WorkingTreeChangesTest extends TestCase
{
    private string $project;

    private RunCommandTool $tool;

    private string $path;

    protected function setUp(): void
    {
        // Outside any repository, under /tmp: inside this one, a path gone wrong — a mutant, a bug —
        // would have git fall back on this repository.
        $this->project = sys_get_temp_dir().'/agentic-changes-test-'.getmypid();
        $filesystem = new Filesystem();
        $filesystem->remove($this->project);
        $filesystem->dumpFile($this->project.'/src/Code.php', "<?php\n// committed\n");
        $filesystem->dumpFile($this->project.'/src/Long.txt', implode("\n", range(1, 500))."\n");
        $filesystem->dumpFile($this->project.'/.gitignore', "/var/\n/.worktrees/\n");
        $this->git('init', '-q', '-b', 'main');
        $this->git('add', '.');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-q', '-m', 'init');

        $sandbox = new Bubblewrap($this->project);
        if (null !== $problem = $sandbox->problem()) {
            self::markTestSkipped($problem);
        }
        $worktrees = new Worktrees($this->project);
        $this->tool = new RunCommandTool(new Workspaces($sandbox, $worktrees));
        $this->path = $worktrees->pathFor('c4a9e5d1');

        // A worktree as it is once it has lived a little: files older than its index. Fresh, every
        // file is "racy" — git rehashes it into the throwaway store and never reads an object of
        // the repository, which would hide a snapshot that cannot see them.
        $this->command('ls');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            touch((string) $file, time() - 60);
        }
        (new Process(['git', '-C', $this->path, 'update-index', '-q', '--refresh']))->run();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testWhatACommandChangedFollowsItsOutput(): void
    {
        $result = $this->command('cp src/Code.php src/Copy.php');

        self::assertStringStartsWith('Exit code: 0'.RunCommandTool::CHANGES."diff --git a/src/Copy.php b/src/Copy.php\nnew file mode 100644\n", $result, 'Untracked, and still shown.');
        self::assertStringEndsWith("@@ -0,0 +1,2 @@\n+<?php\n+// committed", $result);
    }

    /**
     * Only what this command did: a change made before it is not its own.
     */
    public function testOnlyTheChangesOfThisCommand(): void
    {
        $this->command('cp src/Code.php src/Copy.php');

        self::assertSame("Exit code: 0\nsrc/Copy.php\n", $this->command('ls src/Copy.php'), 'Nothing changed: the output alone, as before.');
        self::assertStringNotContainsString('src/Copy.php', $this->command('rm src/Code.php'));
    }

    public function testIgnoredFilesAreNotChanges(): void
    {
        self::assertSame("Exit code: 0\n", $this->command('mkdir -p var/cache'));
    }

    public function testALongDiffIsCut(): void
    {
        $result = $this->command('rm src/Long.txt');

        self::assertStringEndsWith("\n[… 106 more lines of diff …]", $result, '500 removed lines and 6 of header, 400 kept.');
        self::assertStringStartsWith("diff --git a/src/Long.txt b/src/Long.txt\ndeleted file mode 100644\n", explode(RunCommandTool::CHANGES, $result)[1], 'Kept from the start.');
        self::assertCount(WorkingTreeChanges::MAX_LINES + 1, explode("\n", explode(RunCommandTool::CHANGES, $result)[1]), 'The lines kept, and the one that says how many were not.');
    }

    /**
     * The snapshots use a throwaway index and object directory: neither the repository's index nor
     * its objects move.
     */
    public function testTheRepositoryIsLeftAlone(): void
    {
        $this->command('ls');
        $index = (string) file_get_contents($this->project.'/.git/worktrees/agentic-c4a9e5d1/index');
        $objects = $this->git('count-objects');

        $this->command('cp src/Code.php src/Copy.php');

        self::assertSame($index, file_get_contents($this->project.'/.git/worktrees/agentic-c4a9e5d1/index'));
        self::assertSame($objects, $this->git('count-objects'));
        self::assertSame([], glob(sys_get_temp_dir().'/agentic-snapshot-*'), 'The throwaway store is thrown away.');
        // `.env.local`: prepared empty with the worktree, for the sandbox to mask.
        self::assertSame("?? .env.local\n?? src/Copy.php\n", (new Process(['git', '-C', $this->path, 'status', '--short']))->mustRun()->getOutput(), 'Still untracked.');
    }

    /**
     * The snapshots run git on the host, in the worktree the agent writes. A relative hooks path —
     * Husky's — or fsmonitor command resolves there: what the agent put there must not run.
     */
    public function testNoHookTheAgentWroteRunsOnTheHost(): void
    {
        $this->git('config', 'core.hooksPath', '.hooks');
        $this->git('config', 'core.fsmonitor', '.hooks/fsmonitor');
        foreach (['post-index-change', 'fsmonitor'] as $hook) {
            (new Filesystem())->dumpFile($this->path.'/.hooks/'.$hook, "#!/bin/sh\ntouch ".escapeshellarg($this->project.'/pwned-'.$hook)."\n");
            chmod($this->path.'/.hooks/'.$hook, 0o755);
        }

        $this->command('cp src/Code.php src/Copy.php');

        self::assertFileDoesNotExist($this->project.'/pwned-post-index-change');
        self::assertFileDoesNotExist($this->project.'/pwned-fsmonitor');
    }

    public function testOutsideAGitRepositoryTheOutputAlone(): void
    {
        $plain = sys_get_temp_dir().'/agentic-changes-plain-'.getmypid();
        (new Filesystem())->dumpFile($plain.'/a.txt', 'a');
        try {
            self::assertNull(WorkingTreeChanges::before($plain));
            $tool = new RunCommandTool(new Workspaces(new Bubblewrap($plain)));
            self::assertSame("Exit code: 0\n", $tool->inContext(['command' => 'cp a.txt b.txt'], new ToolContext('test-call', null)));
            self::assertFileExists($plain.'/b.txt');
        } finally {
            (new Filesystem())->remove($plain);
        }
    }

    private function command(string $command): string
    {
        return $this->tool->inContext(['command' => $command], new ToolContext('test-call', $this->path));
    }

    private function git(string ...$arguments): string
    {
        return (new Process(['git', '-C', $this->project, ...$arguments]))->mustRun()->getOutput();
    }
}
