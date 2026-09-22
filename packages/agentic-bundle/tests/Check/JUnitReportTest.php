<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Check;

use Gplanchat\AgenticBundle\Check\JUnitReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JUnitReport::class)]
final class JUnitReportTest extends TestCase
{
    /**
     * PHPUnit's shape: suites nested in suites, the cases at the bottom.
     */
    public function testFailuresAreListedWithTheirPlaceAndMessage(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="unit" tests="4" failures="1" errors="1" skipped="1" time="0.5">
                <testsuite name="App\Tests\CartTest" file="/w/tests/CartTest.php">
                  <testcase name="testTotal" class="App\Tests\CartTest" classname="App.Tests.CartTest" file="/w/tests/CartTest.php" line="12" time="0.25">
                    <failure type="PHPUnit\Framework\ExpectationFailedException">App\Tests\CartTest::testTotal
            Failed asserting that 2 is identical to 3.

            /w/tests/CartTest.php:15</failure>
                  </testcase>
                  <testcase name="testEmpty" class="App\Tests\CartTest" file="/w/tests/CartTest.php" line="20" time="0.1"/>
                  <testcase name="testBoom" class="App\Tests\CartTest" file="/w/tests/CartTest.php" line="30" time="0.1">
                    <error type="Error">Call to undefined method App\Cart::nope()</error>
                  </testcase>
                  <testcase name="testLater" class="App\Tests\CartTest" file="/w/tests/CartTest.php" line="40" time="0.05">
                    <skipped/>
                  </testcase>
                </testsuite>
              </testsuite>
            </testsuites>
            XML;

        [$green, $summary] = JUnitReport::summarize($xml, '/w');

        self::assertFalse($green);
        self::assertStringStartsWith('4 tests, 2 failed, 1 skipped, 0.50 s.', $summary);
        self::assertStringContainsString("✗ App\\Tests\\CartTest::testTotal — tests/CartTest.php:12 (failure)\n  App\\Tests\\CartTest::testTotal\n  Failed asserting that 2 is identical to 3.", $summary);
        self::assertStringContainsString('  tests/CartTest.php:15', $summary, 'Paths are relative to the workspace.');
        self::assertStringContainsString("✗ App\\Tests\\CartTest::testBoom — tests/CartTest.php:30 (error)\n  Call to undefined method App\\Cart::nope()", $summary);
    }

    public function testAllPassingIsGreen(): void
    {
        self::assertSame(
            [true, '2 tests, 0 failed, 0.30 s.'],
            JUnitReport::summarize('<testsuites><testsuite><testcase name="a" time="0.1"/><testcase name="b" time="0.2"/></testsuite></testsuites>'),
        );
    }

    public function testManyFailuresAreCountedNotAllShown(): void
    {
        $cases = str_repeat('<testcase name="t" class="C"><failure>boom</failure></testcase>', 13);
        [, $summary] = JUnitReport::summarize("<testsuites><testsuite>{$cases}</testsuite></testsuites>");

        self::assertSame(10, substr_count((string) $summary, '✗ C::t'));
        self::assertStringEndsWith('[… 3 more failures — narrow the run with a filter]', (string) $summary);
    }

    public function testWhatIsNotAReportIsNull(): void
    {
        self::assertNull(JUnitReport::summarize(''));
        self::assertNull(JUnitReport::summarize('PHP Fatal error: nope'));
    }
}
