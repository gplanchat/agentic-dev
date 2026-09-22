<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Sandbox;

use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Tool\RunChecksTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * run_checks through the real sandbox: a stand-in checker for the edge cases, and PHPUnit itself.
 */
#[CoversClass(RunChecksTool::class)]
final class RunChecksToolTest extends TestCase
{
    /** Writes a JUnit report to its first argument: red, or green with `--filter`, exit code as asked. */
    private const CHECKER = <<<'PHP'
        <?php
        [, $report] = $argv;
        $filtered = in_array('--filter', $argv, true);
        $exit = (int) (getenv('CHECKER_EXIT') ?: 0);
        $cases = $filtered
            ? '<testcase name="testOne" class="CartTest" time="0.1"/>'
            : '<testcase name="testOne" class="CartTest" time="0.1"/><testcase name="testTwo" class="CartTest" file="'.getcwd().'/CartTest.php" line="7" time="0.1"><failure>Failed asserting that false is true.</failure></testcase>';
        file_put_contents($report, "<testsuites><testsuite>$cases</testsuite></testsuites>");
        echo "checker ran\n";
        exit($filtered ? $exit : 1);
        PHP;

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = \dirname(__DIR__, 2).'/var/run-checks-test';
        $filesystem = new Filesystem();
        $filesystem->remove($this->workspace);
        $filesystem->dumpFile($this->workspace.'/checker.php', self::CHECKER);

        if (null !== $problem = (new Bubblewrap($this->workspace))->problem()) {
            self::markTestSkipped($problem);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workspace);
    }

    public function testARedLayerListsItsFailuresAndAFilterNarrowsIt(): void
    {
        $tool = $this->tool([
            'unit' => ['command' => 'php checker.php {report}', 'filter_option' => '--filter', 'review' => ['functional', 'static']],
            'functional' => ['command' => 'true'],
            'static' => ['command' => 'true'],
        ]);

        $red = $tool(['layer' => 'unit']);
        self::assertStringStartsWith("RED — unit\n2 tests, 1 failed, 0.20 s.\n\n✗ CartTest::testTwo — CartTest.php:7 (failure)\n  Failed asserting that false is true.\n\nTDD: ", $red);
        self::assertStringContainsString('least code that makes it pass', $red, 'The verdict says the next step of the cycle.');

        $green = $tool(['layer' => 'unit', 'filter' => 'testOne']);
        self::assertStringEndsWith("\n\nTDD: green is not done. Review: run `unit` without a filter, then `functional`, then `static`; refactor with the tests green; never weaken a test to make it pass.", $green);
        self::assertStringStartsWith("GREEN — unit (filter: testOne)\n1 test, 0 failed, 0.10 s.", $green);
    }

    public function testWhatTheReportDoesNotSayComesFromTheOutput(): void
    {
        $tool = $this->tool([
            'crash' => ['command' => 'php -r "echo \'PHP Fatal error: nope\';exit(255);"'],
            'warned' => ['command' => 'env CHECKER_EXIT=2 php checker.php {report}', 'filter_option' => '--filter'],
        ]);

        self::assertSame("ERROR — crash: no JUnit report (exit code 255). The end of the output:\nPHP Fatal error: nope", $tool(['layer' => 'crash']));
        self::assertStringStartsWith("RED — warned (filter: testOne): exit code 2 although no test failed.\n1 test, 0 failed", $tool(['layer' => 'warned', 'filter' => 'testOne']));
    }

    public function testTheArgumentsAreClosed(): void
    {
        $tool = $this->tool([
            'unit' => ['command' => 'php checker.php {report}'],
            'elsewhere' => ['command' => 'php checker.php {report}', 'cwd' => '../..'],
        ]);

        self::assertSame('Unknown layer "e2e". Layers: unit, elsewhere.', $tool(['layer' => 'e2e']));
        self::assertSame('The layer "unit" takes no filter: run it whole.', $tool(['layer' => 'unit', 'filter' => 'x']));
        self::assertStringContainsString('misconfigured', $tool(['layer' => 'elsewhere']));
        self::assertSame(['unit', 'elsewhere'], $tool->definition()->parameters['properties']['layer']['enum'] ?? null);
    }

    /**
     * The method the agent starts every conversation with: the layers, where their tests go, the cycle.
     */
    public function testTheMethodNamesTheLayersWhereTheirTestsGoAndTheCycle(): void
    {
        $method = RunChecksTool::method([
            'unit' => ['description' => 'domain logic, in memory', 'tests' => 'tests/Domain, one test class per class', 'review' => ['integration']],
            'integration' => ['description' => 'processes and disk'],
        ]);

        self::assertStringContainsString("- `unit`: domain logic, in memory. Its tests: tests/Domain, one test class per class. Once green, review with: integration\n- `integration`: processes and disk\n", $method);
        self::assertMatchesRegularExpression('/1\. RED .*\n2\. GREEN .*\n3\. REVIEW /', $method);
        self::assertStringContainsString('a test in no layer never runs', $method);
    }

    public function testAReviewCanOnlyNameConfiguredLayers(): void
    {
        $this->expectExceptionMessage('The review of the check layer "unit" names unknown layers: functionnal.');

        $this->tool(['unit' => ['command' => 'true', 'review' => ['functionnal']], 'functional' => ['command' => 'true']]);
    }

    public function testALayerHasItsOwnTimeout(): void
    {
        $tool = $this->tool(['slow' => ['command' => 'sleep 5', 'timeout_seconds' => 1.0]]);

        self::assertStringStartsWith('ERROR — slow: Interrupted after 1 s.', $tool(['layer' => 'slow']));
    }

    /**
     * The real thing: PHPUnit's own JUnit report, from this package's suite.
     */
    public function testPhpUnitReportsAreRead(): void
    {
        $package = \dirname(__DIR__, 2);
        $tool = new RunChecksTool(new Workspaces(new Bubblewrap($package)), [
            'unit' => ['command' => 'php8.4 vendor/bin/phpunit --log-junit {report}', 'cwd' => '', 'filter_option' => '--filter', 'timeout_seconds' => 120.0, 'description' => ''],
        ]);

        self::assertMatchesRegularExpression('/^GREEN — unit \(filter: BananaTest\)\n1 test, 0 failed/', $tool(['layer' => 'unit', 'filter' => 'BananaTest']));
    }

    /**
     * @param array<string, array<string, mixed>> $layers
     */
    private function tool(array $layers): RunChecksTool
    {
        return new RunChecksTool(new Workspaces(new Bubblewrap($this->workspace)), array_map(
            static fn (array $layer): array => $layer + ['cwd' => '', 'filter_option' => null, 'timeout_seconds' => 60.0, 'description' => ''],
            $layers,
        ));
    }
}
