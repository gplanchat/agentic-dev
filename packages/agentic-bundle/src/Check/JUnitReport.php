<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Check;

/**
 * A JUnit XML report, read down to what the model needs: a verdict, the counts, and each failure
 * with where it happened — a few hundred bytes where the raw output of a test run is kilobytes.
 *
 * Counted from the `<testcase>` elements, not from the suites' attributes: PHPUnit nests suites,
 * other tools do not fill every attribute, and the test cases are what everyone writes.
 */
final class JUnitReport
{
    /** Failures shown in full; the others are counted. */
    public const MAX_FAILURES = 10;

    private const MAX_MESSAGE_LINES = 12;

    private const MAX_MESSAGE_BYTES = 800;

    /**
     * @param string $root stripped from the paths of the messages: the model works in relative paths
     *
     * @return array{0: bool, 1: string}|null whether it is green, and the summary; `null` when the
     *                                        report cannot be read
     */
    public static function summarize(string $xml, string $root = ''): ?array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if ('' === trim($xml) || !$document->loadXML($xml, \LIBXML_NONET)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $cases = $document->getElementsByTagName('testcase');
        $failed = [];
        $skipped = 0;
        $time = 0.0;
        foreach ($cases as $case) {
            $time += (float) $case->getAttribute('time');
            $problem = self::firstChild($case, 'failure') ?? self::firstChild($case, 'error');
            if (null !== $problem) {
                $failed[] = [$case, $problem];
            } elseif (null !== self::firstChild($case, 'skipped')) {
                ++$skipped;
            }
        }

        $total = $cases->length;
        $green = [] === $failed;
        $lines = [\sprintf(
            '%d test%s, %d failed%s, %.2f s.',
            $total,
            1 === $total ? '' : 's',
            \count($failed),
            0 === $skipped ? '' : \sprintf(', %d skipped', $skipped),
            $time,
        )];

        foreach (\array_slice($failed, 0, self::MAX_FAILURES) as [$case, $problem]) {
            $lines[] = '';
            $lines[] = \sprintf(
                '✗ %s::%s%s (%s)',
                $case->getAttribute('class') ?: $case->getAttribute('classname'),
                $case->getAttribute('name'),
                '' === $case->getAttribute('file') ? '' : ' — '.self::relative($case->getAttribute('file'), $root).('' === $case->getAttribute('line') ? '' : ':'.$case->getAttribute('line')),
                $problem->nodeName,
            );
            $lines[] = self::message($problem->textContent ?: $problem->getAttribute('message'), $root);
        }
        if (\count($failed) > self::MAX_FAILURES) {
            $lines[] = '';
            $lines[] = \sprintf('[… %d more failures — narrow the run with a filter]', \count($failed) - self::MAX_FAILURES);
        }

        return [$green, implode("\n", $lines)];
    }

    private static function firstChild(\DOMElement $case, string $name): ?\DOMElement
    {
        foreach ($case->childNodes as $child) {
            if ($child instanceof \DOMElement && $name === $child->nodeName) {
                return $child;
            }
        }

        return null;
    }

    private static function message(string $text, string $root): string
    {
        $text = trim('' === $root ? $text : str_replace($root.'/', '', $text));
        $lines = explode("\n", $text);
        if (\count($lines) > self::MAX_MESSAGE_LINES) {
            $lines = [...\array_slice($lines, 0, self::MAX_MESSAGE_LINES), '[…]'];
        }
        $text = implode("\n", array_map(static fn (string $line): string => '  '.rtrim($line), $lines));

        return \strlen($text) > self::MAX_MESSAGE_BYTES ? mb_strcut($text, 0, self::MAX_MESSAGE_BYTES).' […]' : $text;
    }

    private static function relative(string $path, string $root): string
    {
        return '' !== $root && str_starts_with($path, $root.'/') ? substr($path, \strlen($root) + 1) : $path;
    }
}
