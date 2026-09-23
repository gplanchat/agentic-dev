<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Check;

/**
 * Infection's JSON log, read down to what the model needs: the counts, and each mutant no test
 * noticed — where, which mutator, and the diff that should have failed a test.
 *
 * Green means no escaped mutant and no uncovered one: in the review of a TDD cycle, run on the lines
 * just changed, a surviving mutant is a test that still passes on wrong code.
 */
final class InfectionReport
{
    /** Mutants shown in full; the others are counted. */
    public const MAX_MUTANTS = 10;

    private const MAX_DIFF_LINES = 8;

    /** What a surviving mutant asks for — not a change to the code. */
    public const ADVICE = 'Mutation: each mutant above is a change to the code that no test noticed. Add or sharpen an assertion that fails on it — a boundary, a branch, a returned value —, then run this layer again. Do not change the code to kill a mutant.';

    /**
     * @param string $root stripped from the paths: the model works in relative paths
     *
     * @return array{0: bool, 1: string}|null whether it is green, and the summary; `null` when this is
     *                                        not an Infection log
     */
    public static function summarize(string $json, string $root = ''): ?array
    {
        $log = json_decode($json, true);
        if (!\is_array($log) || !\is_array($log['stats'] ?? null)) {
            return null;
        }

        $stats = $log['stats'];
        $survivors = [
            ...array_map(static fn (mixed $mutant): array => [$mutant, 'escaped'], \is_array($log['escaped'] ?? null) ? $log['escaped'] : []),
            ...array_map(static fn (mixed $mutant): array => [$mutant, 'not covered'], \is_array($log['uncovered'] ?? null) ? $log['uncovered'] : []),
        ];

        if (0 === (int) ($stats['totalMutantsCount'] ?? 0)) {
            return [true, 'No mutant: nothing changed in the mutated sources since HEAD.'];
        }

        $lines = [\sprintf(
            '%d mutants, %d killed, %d escaped, %d not covered%s, MSI %s %%.',
            (int) $stats['totalMutantsCount'],
            (int) ($stats['killedCount'] ?? 0) + (int) ($stats['killedByStaticAnalysisCount'] ?? 0),
            (int) ($stats['escapedCount'] ?? 0),
            (int) ($stats['notCoveredCount'] ?? 0),
            0 === (int) ($stats['timeOutCount'] ?? 0) ? '' : \sprintf(', %d timed out', (int) $stats['timeOutCount']),
            (string) ($stats['msi'] ?? '?'),
        )];

        foreach (\array_slice($survivors, 0, self::MAX_MUTANTS) as [$mutant, $kind]) {
            $mutator = \is_array($mutant['mutator'] ?? null) ? $mutant['mutator'] : [];
            $path = (string) ($mutator['originalFilePath'] ?? '?');
            $lines[] = '';
            $lines[] = \sprintf(
                '✗ %s:%s — %s (%s)',
                '' !== $root && str_starts_with($path, $root.'/') ? substr($path, \strlen($root) + 1) : $path,
                (string) ($mutator['originalStartLine'] ?? '?'),
                (string) ($mutator['mutatorName'] ?? '?'),
                $kind,
            );
            $lines[] = self::diff(\is_string($mutant['diff'] ?? null) ? $mutant['diff'] : '');
        }
        if (\count($survivors) > self::MAX_MUTANTS) {
            $lines[] = '';
            $lines[] = \sprintf('[… %d more mutants]', \count($survivors) - self::MAX_MUTANTS);
        }

        return [[] === $survivors, implode("\n", $lines)];
    }

    /**
     * The changed lines only — the context is the file the model just wrote.
     */
    private static function diff(string $diff): string
    {
        $changed = array_filter(
            explode("\n", $diff),
            static fn (string $line): bool => ('-' === ($line[0] ?? '') || '+' === ($line[0] ?? '')) && !str_starts_with($line, '---') && !str_starts_with($line, '+++'),
        );
        if (\count($changed) > self::MAX_DIFF_LINES) {
            $changed = [...\array_slice($changed, 0, self::MAX_DIFF_LINES), '[…]'];
        }

        return implode("\n", array_map(static fn (string $line): string => '  '.rtrim($line), $changed));
    }
}
