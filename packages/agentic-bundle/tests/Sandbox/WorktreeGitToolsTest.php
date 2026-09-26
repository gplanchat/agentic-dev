<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Sandbox;

use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\HostGit;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Sandbox\Worktrees;
use Gplanchat\AgenticBundle\Tool\CommitWorktreeTool;
use Gplanchat\AgenticBundle\Tool\RevertWorktreeTool;
use Gplanchat\AgenticBundle\Tool\WorktreeDiffTool;
use Gplanchat\AgenticBundle\Sandbox\WorkingTreeChanges;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(RevertWorktreeTool::class)]
#[CoversClass(CommitWorktreeTool::class)]
#[CoversClass(HostGit::class)]
#[CoversClass(WorktreeDiffTool::class)]
final class WorktreeGitToolsTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        // Under /tmp, outside any repository: a reset gone wrong must not land in this one.
        $this->project = sys_get_temp_dir().'/agentic.revert-test-'.getmypid();
        $filesystem = new Filesystem();
        $filesystem->remove($this->project);
        $filesystem->dumpFile($this->project.'/src/Code.php', '<?php // committed');
        $filesystem->dumpFile($this->project.'/.gitignore', "/vendor/\n/.worktrees/\n");
        $filesystem->dumpFile($this->project.'/vendor/autoload.php', '<?php // installed');
        $this->git($this->project, 'init', '-q', '-b', 'main');
        $this->git($this->project, 'add', '.');
        $this->git($this->project, '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-q', '-m', 'init');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testTheWorktreeGoesBackToItsLastCommitIgnoredFilesKept(): void
    {
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e');
        $worktrees->ensure($path);
        file_put_contents($path.'/src/Code.php', '<?php // the naive attempt');
        (new Filesystem())->dumpFile($path.'/src/New.php', '<?php // new');
        (new Filesystem())->dumpFile($path.'/vendor/cache.php', '<?php // ignored');

        $answer = (new RevertWorktreeTool(new Workspaces(new Bubblewrap($this->project), $worktrees)))->inContext([], new ToolContext('c1', $path));

        self::assertSame('Reverted: the worktree is back to its last commit.', $answer);
        self::assertEquals(['type' => 'object', 'properties' => new \stdClass()], (new RevertWorktreeTool(new Workspaces(new Bubblewrap($this->project))))->definition()->parameters, 'No argument: the worktree is the conversation\'s.');
        self::assertSame('<?php // committed', file_get_contents($path.'/src/Code.php'));
        self::assertFileDoesNotExist($path.'/src/New.php');
        self::assertFileExists($path.'/vendor/cache.php');
    }

    public function testTheProjectItselfIsNeverReverted(): void
    {
        file_put_contents($this->project.'/src/Code.php', '<?php // the human\'s work');
        $tool = new RevertWorktreeTool(new Workspaces(new Bubblewrap($this->project), new Worktrees($this->project)));

        self::assertStringStartsWith('Nothing done: this conversation works in the project itself', $tool->inContext([], new ToolContext('c1', $this->project)));
        self::assertStringStartsWith('Nothing done: this conversation works in the project itself', $tool->inContext([], new ToolContext('c1')));
        self::assertStringStartsWith('Nothing done: this conversation works in the project itself', $tool([]));
        self::assertStringStartsWith('Nothing done: this conversation works in the project itself', (new RevertWorktreeTool(new Workspaces(new Bubblewrap($this->project))))->inContext([], new ToolContext('c1', $this->project)));
        self::assertSame('<?php // the human\'s work', file_get_contents($this->project.'/src/Code.php'));
    }

    /**
     * A relative hooks path — Husky's — or fsmonitor command resolves in the worktree, where the
     * agent writes: what it put there must not run on the host.
     */
    public function testNoHookTheAgentWroteRunsOnTheHost(): void
    {
        $this->git($this->project, 'config', 'core.hooksPath', '.hooks');
        $this->git($this->project, 'config', 'core.fsmonitor', '.hooks/fsmonitor');
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e');
        $worktrees->ensure($path);
        (new Filesystem())->dumpFile($path.'/.hooks/post-index-change', "#!/bin/sh\ntouch ".escapeshellarg($this->project.'/pwned')."\n");
        chmod($path.'/.hooks/post-index-change', 0o755);
        (new Filesystem())->dumpFile($path.'/.hooks/fsmonitor', "#!/bin/sh\ntouch ".escapeshellarg($this->project.'/pwned-fsmonitor')."\n");
        chmod($path.'/.hooks/fsmonitor', 0o755);
        file_put_contents($path.'/src/Code.php', '<?php // the naive attempt');

        (new RevertWorktreeTool(new Workspaces(new Bubblewrap($this->project), $worktrees)))->inContext([], new ToolContext('c1', $path));

        self::assertFileDoesNotExist($this->project.'/pwned');
        self::assertFileDoesNotExist($this->project.'/pwned-fsmonitor');
    }

    public function testAWorktreeNotCutYetHasNothingToRevert(): void
    {
        $worktrees = new Worktrees($this->project);
        $tool = new RevertWorktreeTool(new Workspaces(new Bubblewrap($this->project), $worktrees));

        self::assertSame('Nothing done: the worktree does not exist yet.', $tool->inContext([], new ToolContext('c1', $worktrees->pathFor('3f2a9c1e'))));
    }

    public function testAGitFailureIsAnInfrastructureFailure(): void
    {
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e');
        $worktrees->ensure($path);
        file_put_contents($path.'/.git', 'gitdir: /nonexistent');

        try {
            (new RevertWorktreeTool(new Workspaces(new Bubblewrap($this->project), $worktrees)))->inContext([], new ToolContext('c1', $path));
            self::fail('A failing git was taken for a revert.');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith(\sprintf('git reset failed in %s: fatal:', $path), $e->getMessage());
            self::assertStringEndsNotWith("\n", $e->getMessage());
        }
    }

    /**
     * The Mikado loop: a leaf done and committed survives the revert of the next naive attempt.
     */
    public function testACommittedLeafSurvivesTheNextRevert(): void
    {
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e');
        $worktrees->ensure($path);
        // The human signs their commits: the agent's are not theirs, and must not wait on a key.
        $this->git($this->project, 'config', 'commit.gpgsign', 'true');
        $workspaces = new Workspaces(new Bubblewrap($this->project), $worktrees);
        $commit = new CommitWorktreeTool($workspaces);
        file_put_contents($path.'/src/Code.php', '<?php // the leaf');
        (new Filesystem())->dumpFile($path.'/src/Leaf.php', '<?php // new');

        $answer = $commit->inContext(['message' => 'Extract the port'], new ToolContext('c1', $path));

        self::assertMatchesRegularExpression('#^Committed [0-9a-f]{7,} on agentic/agentic-3f2a9c1e\.$#', $answer);
        self::assertSame("agentic <agentic@localhost> Extract the port\n", $this->git($path, 'log', '-1', '--format=%an <%ae> %s'));
        // The sandbox's placeholder, which this project does not ignore, stays out of the commit.
        self::assertSame("?? .env.local\n", $this->git($path, 'status', '--porcelain'), 'Edits and new files alike — not the placeholder.');
        self::assertSame('Nothing to commit: the worktree is as its last commit.', $commit->inContext(['message' => 'Again'], new ToolContext('c2', $path)));

        file_put_contents($path.'/src/Code.php', '<?php // the naive attempt');
        (new RevertWorktreeTool($workspaces))->inContext([], new ToolContext('c3', $path));

        self::assertSame('<?php // the leaf', file_get_contents($path.'/src/Code.php'));
        self::assertFileExists($path.'/src/Leaf.php');
        self::assertSame('<?php // committed', file_get_contents($this->project.'/src/Code.php'), 'The project\'s branch is not touched.');
    }

    public function testACommitNeedsAMessageAndItsOwnWorktree(): void
    {
        $commit = new CommitWorktreeTool(new Workspaces(new Bubblewrap($this->project), new Worktrees($this->project)));

        self::assertSame('A commit needs a message.', $commit->inContext(['message' => ' '], new ToolContext('c1', $this->project)));
        self::assertStringStartsWith('Nothing done: this conversation works in the project itself', $commit->inContext(['message' => 'M'], new ToolContext('c1', $this->project)));
        self::assertStringStartsWith('Nothing done: this conversation works in the project itself', $commit(['message' => 'M']));
        self::assertSame(['type' => 'object', 'properties' => [
            'message' => ['type' => 'string', 'description' => 'The commit message: what changed and why.'],
            'closes' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The work ticket this commit finishes.'],
        ], 'required' => ['message']], $commit->definition()->parameters);
    }

    /**
     * EWA-002 § 6: the work ticket closes with its code, when the platform reads the merge.
     */
    public function testTheCommitThatFinishesAWorkTicketClosesIt(): void
    {
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e');
        $worktrees->ensure($path);
        $commit = new CommitWorktreeTool(new Workspaces(new Bubblewrap($this->project), $worktrees));
        file_put_contents($path.'/src/Code.php', '<?php // done');

        self::assertSame('"closes" must be a ticket number.', $commit->inContext(['message' => 'M', 'closes' => 'x'], new ToolContext('c1', $path)));
        self::assertSame('"closes" must be a ticket number.', $commit->inContext(['message' => 'M', 'closes' => 0], new ToolContext('c1', $path)));
        $commit->inContext(['message' => 'feat(tickets): the adapter', 'closes' => '1'], new ToolContext('c1', $path));

        self::assertSame("feat(tickets): the adapter\n\nCloses #1", trim($this->git($path, 'log', '-1', '--format=%B')));
    }

    public function testNoCommitHookTheAgentWroteRunsOnTheHost(): void
    {
        $this->git($this->project, 'config', 'core.hooksPath', '.hooks');
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e');
        $worktrees->ensure($path);
        (new Filesystem())->dumpFile($path.'/.hooks/pre-commit', "#!/bin/sh\ntouch ".escapeshellarg($this->project.'/pwned')."\n");
        chmod($path.'/.hooks/pre-commit', 0o755);

        (new CommitWorktreeTool(new Workspaces(new Bubblewrap($this->project), $worktrees)))->inContext(['message' => 'M'], new ToolContext('c1', $path));

        self::assertFileDoesNotExist($this->project.'/pwned');
    }

    public function testTheVerifierSeesWhatTheWorktreeChangedSinceItLeftTheProject(): void
    {
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e');
        $worktrees->ensure($path);
        $workspaces = new Workspaces(new Bubblewrap($this->project), $worktrees);
        file_put_contents($path.'/src/Code.php', '<?php // the leaf');
        (new CommitWorktreeTool($workspaces))->inContext(['message' => 'feat: the leaf', 'closes' => 7], new ToolContext('c1', $path));
        file_put_contents($path.'/src/Code.php', '<?php // not committed yet');
        (new Filesystem())->dumpFile($path.'/src/New.php', '<?php // new');
        // The project moves on after the worktree left it: that is not the worktree's work.
        (new Filesystem())->dumpFile($this->project.'/src/Later.php', '<?php // later');
        $this->git($this->project, 'add', '.');
        $this->git($this->project, '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-q', '-m', 'later');

        $seen = (new WorktreeDiffTool($workspaces))->inContext([], new ToolContext('c2', $path));

        self::assertMatchesRegularExpression("#^Since [0-9a-f]{7}:\n\nCommits:\n[0-9a-f]{7,} feat: the leaf\n\nCloses \#7\n\nDiff:\ndiff --git a/src/Code.php b/src/Code.php\n#", $seen);
        self::assertStringContainsString("-<?php // committed\n\\ No newline at end of file\n+<?php // not committed yet", $seen, 'Since the project, committed and not: the leaf is in neither side.');
        self::assertStringEndsWith("yet\n\\ No newline at end of file\n\nNew files, not committed:\nsrc/New.php", $seen, 'Not the sandbox\'s placeholder.');
        self::assertStringNotContainsString('Later.php', $seen);
        self::assertStringStartsWith('Nothing done: this conversation works in the project itself', (new WorktreeDiffTool($workspaces))([]));
        self::assertEquals(['type' => 'object', 'properties' => new \stdClass()], (new WorktreeDiffTool($workspaces))->definition()->parameters);
    }

    public function testNothingChangedSaysSo(): void
    {
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e');
        $worktrees->ensure($path);

        self::assertStringStartsWith(\sprintf('Since %s:', substr(trim($this->git($this->project, 'rev-parse', 'HEAD')), 0, 7)), (new WorktreeDiffTool(new Workspaces(new Bubblewrap($this->project), $worktrees)))->inContext([], new ToolContext('c1', $path)));
        self::assertMatchesRegularExpression("#^Since [0-9a-f]{7}:\n\nCommits:\n\(none\)\n\nDiff:\n\(none\)\n\nNew files, not committed:\n\(none\)$#", (new WorktreeDiffTool(new Workspaces(new Bubblewrap($this->project), $worktrees)))->inContext([], new ToolContext('c1', $path)));
    }

    public function testALongDiffIsCutAndNoDriverTheAgentChoseRuns(): void
    {
        $this->git($this->project, 'config', 'diff.evil.command', 'touch '.$this->project.'/pwned-command');
        $this->git($this->project, 'config', 'diff.evil.textconv', 'touch '.$this->project.'/pwned-textconv; cat');
        $worktrees = new Worktrees($this->project);
        $path = $worktrees->pathFor('3f2a9c1e');
        $worktrees->ensure($path);
        file_put_contents($path.'/.gitattributes', "*.php diff=evil\n");
        file_put_contents($path.'/src/Code.php', implode("\n", range(1, 500)));

        $seen = (new WorktreeDiffTool(new Workspaces(new Bubblewrap($this->project), $worktrees)))->inContext([], new ToolContext('c1', $path));

        self::assertFileDoesNotExist($this->project.'/pwned-command');
        self::assertFileDoesNotExist($this->project.'/pwned-textconv');
        self::assertMatchesRegularExpression('#\n\[… \d+ more lines of diff …\]\n\nNew files, not committed:#', $seen);
        self::assertCount(WorkingTreeChanges::MAX_LINES + 1, explode("\n", explode("\n\nNew files", explode("Diff:\n", $seen)[1])[0]));
    }

    private function git(string $cwd, string ...$arguments): string
    {
        return (new Process(['git', '-C', $cwd, ...$arguments]))->mustRun()->getOutput();
    }
}
