<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Check;

use Gplanchat\AgenticBundle\Check\InfectionReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InfectionReport::class)]
final class InfectionReportTest extends TestCase
{
    /**
     * The shape of a real log: a new class tested on one side of its boundary only.
     */
    private const SURVIVOR = <<<'JSON'
        {"stats":{"totalMutantsCount":6,"killedCount":5,"killedByStaticAnalysisCount":0,"notCoveredCount":0,"escapedCount":1,"timeOutCount":0,"msi":83.33},
         "escaped":[{"mutator":{"mutatorName":"GreaterThan","originalFilePath":"/w/src/Domain/Discount.php","originalStartLine":11},
                     "diff":"--- Original\n+++ New\n@@ @@\n {\n     public static function apply(int $amount): int\n     {\n-        return $amount > 100 ? $amount - 10 : $amount;\n+        return $amount >= 100 ? $amount - 10 : $amount;\n     }\n }"}],
         "uncovered":[]}
        JSON;

    public function testASurvivingMutantIsListedWithItsDiff(): void
    {
        [$green, $summary] = InfectionReport::summarize(self::SURVIVOR, '/w') ?? [null, ''];

        self::assertFalse($green);
        self::assertSame(
            "6 mutants, 5 killed, 1 escaped, 0 not covered, MSI 83.33 %.\n\n"
            ."✗ src/Domain/Discount.php:11 — GreaterThan (escaped)\n"
            ."  -        return \$amount > 100 ? \$amount - 10 : \$amount;\n"
            ."  +        return \$amount >= 100 ? \$amount - 10 : \$amount;",
            $summary,
        );
    }

    public function testCodeNoTestRunsIsNotGreen(): void
    {
        [$green, $summary] = InfectionReport::summarize('{"stats":{"totalMutantsCount":2,"killedCount":0,"notCoveredCount":2,"escapedCount":0,"msi":0},"escaped":[],"uncovered":[{"mutator":{"mutatorName":"Plus","originalFilePath":"src/A.php","originalStartLine":3},"diff":"-a + b\n+a - b"}]}') ?? [null, ''];

        self::assertFalse($green);
        self::assertStringStartsWith('2 mutants, 0 killed, 0 escaped, 2 not covered, MSI 0 %.', $summary);
        self::assertStringContainsString('✗ src/A.php:3 — Plus (not covered)', $summary);
    }

    public function testAllKilledIsGreenAndNothingChangedToo(): void
    {
        self::assertSame([true, '3 mutants, 3 killed, 0 escaped, 0 not covered, MSI 100 %.'], InfectionReport::summarize('{"stats":{"totalMutantsCount":3,"killedCount":3,"escapedCount":0,"notCoveredCount":0,"msi":100},"escaped":[],"uncovered":[]}'));
        self::assertSame([true, 'No mutant: nothing changed in the mutated sources since HEAD.'], InfectionReport::summarize('{"stats":{"totalMutantsCount":0},"escaped":[],"uncovered":[]}'));
    }

    /**
     * Missing counts are zero — a log may leave them out —, and numbers may come as strings.
     */
    public function testCountsDefaultToZeroAndAddUp(): void
    {
        self::assertSame([true, 'No mutant: nothing changed in the mutated sources since HEAD.'], InfectionReport::summarize('{"stats":{}}'));
        self::assertSame([true, 'No mutant: nothing changed in the mutated sources since HEAD.'], InfectionReport::summarize('{"stats":{"totalMutantsCount":"0"}}'));
        self::assertSame(
            [true, '4 mutants, 0 killed, 0 escaped, 0 not covered, MSI ? %.'],
            InfectionReport::summarize('{"stats":{"totalMutantsCount":4}}'),
        );
        self::assertSame(
            [true, '5 mutants, 5 killed, 0 escaped, 0 not covered, 1 timed out, MSI 100 %.'],
            InfectionReport::summarize('{"stats":{"totalMutantsCount":"5","killedCount":"3","killedByStaticAnalysisCount":2,"timeOutCount":1,"msi":100},"escaped":[],"uncovered":[]}'),
        );
    }

    public function testTenMutantsAreShownAndTheRestCounted(): void
    {
        $mutant = '{"mutator":{"mutatorName":"Plus","originalFilePath":"src/A.php","originalStartLine":1},"diff":"-a\\n+b"}';
        $log = static fn (int $count): string => '{"stats":{"totalMutantsCount":'.$count.',"escapedCount":'.$count.'},"escaped":['.implode(',', array_fill(0, $count, $mutant)).'],"uncovered":[]}';

        [, $ten] = InfectionReport::summarize($log(10)) ?? [null, ''];
        self::assertSame(10, substr_count($ten, '✗ '));
        self::assertStringNotContainsString('more mutants', $ten);

        [, $eleven] = InfectionReport::summarize($log(11)) ?? [null, ''];
        self::assertSame(10, substr_count($eleven, '✗ '));
        self::assertStringEndsWith("\n\n[… 1 more mutants]", $eleven);
    }

    /**
     * Only the changed lines, trailing blanks off, eight at most.
     */
    public function testTheDiffKeepsItsChangedLinesOnly(): void
    {
        $diff = static fn (int $lines): string => implode('\n', ['--- Original', '+++ New', ' context', ...array_map(static fn (int $i): string => '-old '.$i.'   ', range(1, $lines))]);
        $log = static fn (string $diff): string => '{"stats":{"totalMutantsCount":1,"escapedCount":1},"escaped":[{"mutator":{"mutatorName":"X","originalFilePath":"src/A.php","originalStartLine":1},"diff":"'.$diff.'"}],"uncovered":[]}';

        [, $eight] = InfectionReport::summarize($log($diff(8))) ?? [null, ''];
        self::assertStringEndsWith("  -old 7\n  -old 8", $eight);
        self::assertStringNotContainsString('context', $eight);

        [, $nine] = InfectionReport::summarize($log($diff(9))) ?? [null, ''];
        self::assertStringEndsWith("  -old 8\n  […]", $nine);
    }

    /**
     * The root is stripped from paths under it, and from those only; a mutant without its details
     * still shows, with question marks.
     */
    public function testPathsAreRelativeToTheRootOnlyUnderIt(): void
    {
        $log = '{"stats":{"totalMutantsCount":3,"escapedCount":3},"escaped":['
            .'{"mutator":{"mutatorName":"A","originalFilePath":"/w/src/A.php","originalStartLine":1}},'
            .'{"mutator":{"mutatorName":"B","originalFilePath":"/wx/src/B.php","originalStartLine":2}},'
            .'"broken",'
            .'{"mutator":{"mutatorName":"C","originalFilePath":42,"originalStartLine":3},"diff":null}],"uncovered":[]}';

        [, $summary] = InfectionReport::summarize($log, '/w') ?? [null, ''];
        self::assertStringContainsString('✗ src/A.php:1 — A (escaped)', $summary);
        self::assertStringContainsString('✗ /wx/src/B.php:2 — B (escaped)', $summary);
        self::assertStringContainsString('✗ ?:? — ? (escaped)', $summary);
        self::assertStringContainsString('✗ 42:3 — C (escaped)', $summary, 'A number where a path was expected, and no diff: shown anyway.');

        [, $noRoot] = InfectionReport::summarize($log) ?? [null, ''];
        self::assertStringContainsString('✗ /w/src/A.php:1 — A (escaped)', $noRoot);
    }

    public function testWhatIsNotAnInfectionLogIsNull(): void
    {
        self::assertNull(InfectionReport::summarize('<testsuites/>'));
        self::assertNull(InfectionReport::summarize('{"tests":1}'));
    }
}
