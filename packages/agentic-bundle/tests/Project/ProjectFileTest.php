<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Project;

use Gplanchat\AgenticBundle\Project\InvalidProjectConfiguration;
use Gplanchat\AgenticBundle\Project\ProjectFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectFileTest extends TestCase
{
    private const EXPECTED = [
        'instructions_file' => 'docs/AGENTS.md',
        'checks' => [
            'unit' => [
                'command' => ['vendor/bin/phpunit --testsuite unit --log-junit {report}'],
                'filter_option' => '--filter',
                'tests' => 'tests/Unit',
                'review' => ['static'],
                'cwd' => '',
                'timeout_seconds' => 300.0,
                'description' => '',
            ],
            'static' => [
                'command' => ['vendor/bin/phpstan analyse --error-format=junit', 'vendor/bin/phpunit --testsuite static --log-junit {report}'],
                'cwd' => '',
                'filter_option' => null,
                'timeout_seconds' => 300.0,
                'description' => '',
                'tests' => '',
                'review' => [],
            ],
        ],
        'tool_rules' => [
            ['tool' => 'run_command', 'decision' => 'deny', 'when' => ['command' => 'rm *'], 'reason' => 'no', 'modes' => [], 'unless' => [], 'unless_roles' => []],
        ],
        'sandbox' => [
            'hidden' => ['secrets'],
            'shared' => ['node_modules'],
            'auto_allow' => ['make test'],
            'worktrees' => false,
        ],
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = \dirname(__DIR__, 2).'/var/project-file-test';
        (new Filesystem())->remove($this->root);
        (new Filesystem())->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formats(): iterable
    {
        yield 'yaml' => ['config.yaml', <<<'YAML'
            instructions_file: docs/AGENTS.md
            checks:
                unit:
                    command: vendor/bin/phpunit --testsuite unit --log-junit {report}
                    filter_option: --filter
                    tests: tests/Unit
                    review: [static]
                static:
                    command:
                        - vendor/bin/phpstan analyse --error-format=junit
                        - vendor/bin/phpunit --testsuite static --log-junit {report}
            tool_rules:
                - {tool: run_command, decision: deny, when: {command: 'rm *'}, reason: 'no'}
            sandbox:
                hidden: [secrets]
                shared: [node_modules]
                auto_allow: ['make test']
                worktrees: false
            YAML];

        yield 'yml' => ['config.yml', "instructions_file: docs/AGENTS.md\nchecks:\n    unit: {command: 'vendor/bin/phpunit --testsuite unit --log-junit {report}', filter_option: --filter, tests: tests/Unit, review: [static]}\n    static: {command: ['vendor/bin/phpstan analyse --error-format=junit', 'vendor/bin/phpunit --testsuite static --log-junit {report}']}\ntool_rules: [{tool: run_command, decision: deny, when: {command: 'rm *'}, reason: 'no'}]\nsandbox: {hidden: [secrets], shared: [node_modules], auto_allow: ['make test'], worktrees: false}\n"];

        yield 'toml' => ['config.toml', <<<'TOML'
            instructions_file = "docs/AGENTS.md"

            [checks.unit]
            command = "vendor/bin/phpunit --testsuite unit --log-junit {report}"
            filter_option = "--filter"
            tests = "tests/Unit"
            review = ["static"]

            [checks.static]
            command = ["vendor/bin/phpstan analyse --error-format=junit", "vendor/bin/phpunit --testsuite static --log-junit {report}"]

            [[tool_rules]]
            tool = "run_command"
            decision = "deny"
            when = { command = "rm *" }
            reason = "no"

            [sandbox]
            hidden = ["secrets"]
            shared = ["node_modules"]
            auto_allow = ["make test"]
            worktrees = false
            TOML];

        yield 'json' => ['config.json', json_encode([
            'instructions_file' => 'docs/AGENTS.md',
            'checks' => [
                'unit' => ['command' => 'vendor/bin/phpunit --testsuite unit --log-junit {report}', 'filter_option' => '--filter', 'tests' => 'tests/Unit', 'review' => ['static']],
                'static' => ['command' => ['vendor/bin/phpstan analyse --error-format=junit', 'vendor/bin/phpunit --testsuite static --log-junit {report}']],
            ],
            'tool_rules' => [['tool' => 'run_command', 'decision' => 'deny', 'when' => ['command' => 'rm *'], 'reason' => 'no']],
            'sandbox' => ['hidden' => ['secrets'], 'shared' => ['node_modules'], 'auto_allow' => ['make test'], 'worktrees' => false],
        ], \JSON_THROW_ON_ERROR)];

        yield 'xml' => ['config.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <agentic instructions-file="docs/AGENTS.md">
                <check layer="unit" filter-option="--filter" tests="tests/Unit">
                    <command>vendor/bin/phpunit --testsuite unit --log-junit {report}</command>
                    <review>static</review>
                </check>
                <check layer="static">
                    <command>vendor/bin/phpstan analyse --error-format=junit</command>
                    <command>vendor/bin/phpunit --testsuite static --log-junit {report}</command>
                </check>
                <tool-rule tool="run_command" decision="deny" reason="no">
                    <when argument="command">rm *</when>
                </tool-rule>
                <sandbox worktrees="false">
                    <hide>secrets</hide>
                    <share>node_modules</share>
                    <allow>make test</allow>
                </sandbox>
            </agentic>
            XML];
    }

    /**
     * Four formats, one configuration: the same tree validates them all.
     */
    #[DataProvider('formats')]
    public function testEveryFormatReadsToTheSameSettings(string $name, string $contents): void
    {
        (new Filesystem())->dumpFile($this->root.'/.agentic/'.$name, $contents);

        $file = ProjectFile::find($this->root);

        self::assertNotNull($file);
        self::assertSame($this->root.'/.agentic/'.$name, $file->path);
        self::assertEquals(self::EXPECTED, $file->settings);
        self::assertSame(hash('sha256', $contents), $file->fingerprint());
    }

    /**
     * Several `<when>` and `<unless>` elements fold into their maps; an `argument` key written as such
     * in another format is an argument like any other.
     */
    public function testXmlArgumentsFoldAndOtherFormatsAreLeftAlone(): void
    {
        (new Filesystem())->dumpFile($this->root.'/.agentic/config.xml', <<<'XML'
            <agentic>
                <tool-rule tool="send_email" decision="ask">
                    <when argument="to">*@example.test</when>
                    <when argument="subject">Invoice*</when>
                    <unless argument="to">boss@example.test</unless>
                    <unless argument="to">cfo@example.test</unless>
                </tool-rule>
            </agentic>
            XML);
        $rule = ProjectFile::find($this->root)?->settings['tool_rules'][0] ?? [];
        self::assertSame(['to' => '*@example.test', 'subject' => 'Invoice*'], $rule['when'] ?? null);
        self::assertSame(['to' => ['boss@example.test', 'cfo@example.test']], $rule['unless'] ?? null);

        (new Filesystem())->remove($this->root.'/.agentic');
        (new Filesystem())->dumpFile($this->root.'/.agentic/config.yaml', "tool_rules: [{tool: x, decision: ask, when: {argument: 'a*', to: 'b*'}}]\n");
        self::assertSame(['argument' => 'a*', 'to' => 'b*'], ProjectFile::find($this->root)?->settings['tool_rules'][0]['when'] ?? null);
    }

    public function testNoFileIsNoProjectConfiguration(): void
    {
        self::assertNull(ProjectFile::find($this->root));
        (new Filesystem())->mkdir($this->root.'/.agentic');
        self::assertNull(ProjectFile::find($this->root));
    }

    /**
     * A PHP file would run on the host at launch, before anything is approved: it is not read.
     */
    public function testAPhpConfigurationIsRefused(): void
    {
        (new Filesystem())->dumpFile($this->root.'/.agentic/config.php', '<?php return [];');

        $this->expectException(InvalidProjectConfiguration::class);
        $this->expectExceptionMessage('config.php is not read');
        ProjectFile::find($this->root);
    }

    public function testTwoConfigurationsAreOneTooMany(): void
    {
        (new Filesystem())->dumpFile($this->root.'/.agentic/config.yaml', "checks: {}\n");
        (new Filesystem())->dumpFile($this->root.'/.agentic/config.json', '{}');

        $this->expectException(InvalidProjectConfiguration::class);
        $this->expectExceptionMessage('config.json, config.yaml');
        ProjectFile::find($this->root);
    }

    public function testAnInvalidConfigurationNamesItsFile(): void
    {
        (new Filesystem())->dumpFile($this->root.'/.agentic/config.yaml', "checks:\n    unit: {filter_option: --filter}\n");

        $this->expectException(InvalidProjectConfiguration::class);
        $this->expectExceptionMessageMatches('#\.agentic/config\.yaml.*command#s');
        ProjectFile::find($this->root);
    }

    public function testAnUnreadableFileNamesItsFileToo(): void
    {
        (new Filesystem())->dumpFile($this->root.'/.agentic/config.toml', "checks = [\n");

        $this->expectException(InvalidProjectConfiguration::class);
        $this->expectExceptionMessage('.agentic/config.toml');
        ProjectFile::find($this->root);
    }
}
