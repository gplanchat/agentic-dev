<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Sandbox;

use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Sandbox\Worktrees;
use Gplanchat\AgenticBundle\Tool\EditFileTool;
use Gplanchat\AgenticBundle\Tool\ReadFileTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * read_file and edit_file, in the worktree of a real repository, through the real sandbox.
 */
#[CoversClass(Workspaces::class)]
#[CoversClass(ReadFileTool::class)]
#[CoversClass(EditFileTool::class)]
final class FileToolsTest extends TestCase
{
    private string $project;
    private string $worktree;
    private ReadFileTool $read;
    private EditFileTool $edit;

    protected function setUp(): void
    {
        $this->project = \dirname(__DIR__, 2).'/var/file-tools-test';
        $filesystem = new Filesystem();
        $filesystem->remove($this->project);
        $filesystem->dumpFile($this->project.'/src/Code.php', "<?php\n\nfunction greet(): string\n{\n    return 'hello';\n}\n");
        $filesystem->dumpFile($this->project.'/src/Twice.php', "a = 1;\na = 1;\n");
        $filesystem->dumpFile($this->project.'/src/Long.txt', implode("\n", range(1, 1000))."\n");
        $filesystem->dumpFile($this->project.'/.gitignore', "/vendor/\n/.worktrees/\n/var/\n/.env.local\n");
        $filesystem->dumpFile($this->project.'/vendor/lib/Library.php', "<?php // borrowed\n");
        $filesystem->dumpFile($this->project.'/.env.local', "MISTRAL_API_KEY=project-secret\n");
        $this->git('init', '-q', '-b', 'main');
        $this->git('add', '.');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-q', '-m', 'init');

        $sandbox = new Bubblewrap($this->project);
        if (null !== $problem = $sandbox->problem()) {
            self::markTestSkipped($problem);
        }
        $worktrees = new Worktrees($this->project);
        $workspaces = new Workspaces($sandbox, $worktrees);
        $this->worktree = $worktrees->pathFor('f11e7001');
        $this->read = new ReadFileTool($workspaces);
        $this->edit = new EditFileTool($workspaces);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testAFileIsReadWithLineNumbersAndAHintToReadOn(): void
    {
        self::assertSame("     4\t{\n     5\t    return 'hello';\n[… 1 more lines — read on with offset=6]\n", $this->readFile(['path' => 'src/Code.php', 'offset' => 4, 'limit' => 2]));

        $long = $this->readFile(['path' => 'src/Long.txt']);
        self::assertStringStartsWith("     1\t1\n", $long);
        self::assertStringEndsWith("[… 600 more lines — read on with offset=401]\n", $long);
        self::assertStringContainsString('past its end', $this->readFile(['path' => 'src/Long.txt', 'offset' => 5000]));
    }

    public function testADirectoryIsListedAndTheBorrowedDependenciesAreReadable(): void
    {
        self::assertSame("Code.php\nLong.txt\nTwice.php\n", $this->readFile(['path' => 'src']));
        self::assertStringContainsString('// borrowed', $this->readFile(['path' => 'vendor/lib/Library.php']));
    }

    public function testAnEditReplacesAUniqueStringInTheWorktreeOnly(): void
    {
        self::assertSame("Edited src/Code.php: 1 replacement.\n", $this->editFile(['path' => 'src/Code.php', 'old_string' => "'hello'", 'new_string' => "'bonjour'"]));

        self::assertStringContainsString("'bonjour'", (string) file_get_contents($this->worktree.'/src/Code.php'));
        self::assertStringContainsString("'hello'", (string) file_get_contents($this->project.'/src/Code.php'), 'The project is not the workspace.');
    }

    public function testAnEditMustBeExactAndUnambiguous(): void
    {
        self::assertStringContainsString('2 times', $this->editFile(['path' => 'src/Twice.php', 'old_string' => 'a = 1;', 'new_string' => 'a = 2;']));
        self::assertStringContainsString('is not in', $this->editFile(['path' => 'src/Code.php', 'old_string' => "5\t    return", 'new_string' => 'x']));
        self::assertSame("Edited src/Twice.php: 2 replacements.\n", $this->editFile(['path' => 'src/Twice.php', 'old_string' => 'a = 1;', 'new_string' => 'a = 2;', 'replace_all' => true]));
        self::assertStringContainsString('already exists', $this->editFile(['path' => 'src/Code.php', 'old_string' => '', 'new_string' => 'x']));
    }

    public function testAnEmptyOldStringCreatesAFileAndItsDirectories(): void
    {
        self::assertSame("Created src/New/Thing.php (2 lines).\n", $this->editFile(['path' => 'src/New/Thing.php', 'old_string' => '', 'new_string' => "<?php\n// new\n"]));
        self::assertFileExists($this->worktree.'/src/New/Thing.php');
        self::assertStringContainsString('does not exist', $this->editFile(['path' => 'src/Missing.php', 'old_string' => 'x', 'new_string' => 'y']));
    }

    /**
     * `..`, absolute paths and links: whatever resolves outside the workspace is refused, read or write.
     */
    public function testNothingOutsideTheWorkspaceIsReachable(): void
    {
        $this->readFile(['path' => '.']); // creates the worktree
        symlink($this->project.'/src', $this->worktree.'/to-project');
        symlink((string) getenv('HOME'), $this->worktree.'/to-home');

        foreach (['../../src/Code.php', $this->project.'/src/Code.php', '/etc/passwd', 'to-project/Code.php', 'to-home'] as $path) {
            self::assertStringContainsString('outside the workspace', $this->readFile(['path' => $path]), $path);
        }
        // `..` through a directory that does not exist resolves to nothing: refused before any check.
        self::assertStringContainsString('does not exist', $this->editFile(['path' => 'src/New/../../../x', 'old_string' => '', 'new_string' => 'x']));
        self::assertStringContainsString('outside the workspace', $this->editFile(['path' => 'to-project/Code.php', 'old_string' => "'hello'", 'new_string' => "'pwned'"]));
        self::assertStringContainsString('outside the workspace', $this->editFile(['path' => 'to-project/Planted.php', 'old_string' => '', 'new_string' => 'x']));
        self::assertStringContainsString("'hello'", (string) file_get_contents($this->project.'/src/Code.php'));
        self::assertFileDoesNotExist($this->project.'/src/Planted.php');
    }

    /**
     * What the host runs on its own stays read-only; what is masked can be neither read nor written
     * — a write into a tmpfs would report success, then vanish.
     */
    public function testReadOnlyAndMaskedPathsAreRefused(): void
    {
        self::assertStringContainsString('read-only', $this->editFile(['path' => '.git', 'old_string' => 'gitdir', 'new_string' => 'x']));
        foreach (['.claude/settings.json', 'vendor/planted.php'] as $path) {
            self::assertStringContainsString('read-only', $this->editFile(['path' => $path, 'old_string' => '', 'new_string' => 'x']), $path);
        }
        self::assertStringContainsString('read-only', $this->editFile(['path' => 'vendor/lib/Library.php', 'old_string' => 'borrowed', 'new_string' => 'x']));

        self::assertStringContainsString('masked by the sandbox', $this->editFile(['path' => 'var/cache/x.php', 'old_string' => '', 'new_string' => 'x']));
        self::assertStringContainsString('masked by the sandbox', $this->readFile(['path' => '.env.local']));
        self::assertDirectoryDoesNotExist($this->worktree.'/var/cache');
    }

    /**
     * Without worktrees, the project is the workspace: its secrets stay masked all the same.
     */
    public function testWithoutAWorktreeTheProjectSecretsStayMasked(): void
    {
        $read = new ReadFileTool(new Workspaces(new Bubblewrap($this->project)));

        self::assertStringContainsString('masked by the sandbox', $read(['path' => '.env.local']));
        self::assertStringContainsString("return 'hello'", $read(['path' => 'src/Code.php']));
    }

    public function testAMissingSandboxIsReportedNotThrown(): void
    {
        $read = new ReadFileTool(new Workspaces(new Bubblewrap($this->project, binary: 'bwrap-introuvable')));

        self::assertStringContainsString('apt install bubblewrap', $read(['path' => 'src/Code.php']));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function readFile(array $arguments): string
    {
        return $this->read->inWorkspace($arguments, $this->worktree);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function editFile(array $arguments): string
    {
        return $this->edit->inWorkspace($arguments, $this->worktree);
    }

    private function git(string ...$arguments): void
    {
        (new Process(['git', '-C', $this->project, ...$arguments]))->mustRun();
    }
}
