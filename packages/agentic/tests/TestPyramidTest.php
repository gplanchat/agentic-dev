<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every test sits in exactly one layer of the pyramid (the suites of phpunit.dist.xml).
 *
 * The suites list directories and files: a test put elsewhere would run with the whole suite but
 * with no layer — run_checks would never run it —, and a test in two suites would run twice.
 */
final class TestPyramidTest extends TestCase
{
    public function testEveryTestSitsInExactlyOneLayer(): void
    {
        $package = \dirname(__DIR__);
        $config = new \SimpleXMLElement((string) file_get_contents($package.'/phpunit.dist.xml'));

        $layers = [];
        foreach ($config->testsuites->testsuite as $suite) {
            $excluded = array_map(static fn (\SimpleXMLElement $path): string => $package.'/'.$path, iterator_to_array($suite->exclude, false));
            $files = array_map(static fn (\SimpleXMLElement $path): string => $package.'/'.$path, iterator_to_array($suite->file, false));
            foreach ($suite->directory as $directory) {
                $files = [...$files, ...self::tests($package.'/'.$directory)];
            }
            foreach (array_diff($files, $excluded) as $file) {
                $layers[substr($file, \strlen($package) + 1)][] = (string) $suite['name'];
            }
        }

        $problems = [];
        foreach (self::tests($package.'/tests') as $file) {
            $relative = substr($file, \strlen($package) + 1);
            $in = $layers[$relative] ?? [];
            if (1 !== \count($in)) {
                $problems[] = \sprintf('%s: %s', $relative, [] === $in ? 'in no layer' : 'in '.implode(', ', $in));
            }
        }

        self::assertSame([], $problems, 'Put each test in exactly one suite of phpunit.dist.xml.');
    }

    /**
     * @return list<string>
     */
    private static function tests(string $directory): array
    {
        $tests = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), 'Test.php')) {
                $tests[] = $file->getPathname();
            }
        }
        sort($tests);

        return $tests;
    }
}
