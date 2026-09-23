<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Sandbox;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * tools/infection/mutate-changed on a real repository: the lines changed since HEAD are mutated, and
 * code no test runs is reported — a check that cannot fail would be worse than none.
 */
final class MutateChangedTest extends TestCase
{
    private const PRICE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Shop;

        final class Price
        {
            public static function withTax(int $cents): int
            {
                return intdiv($cents * 120, 100);
            }
        }
        PHP;

    private string $project;

    private string $script;

    protected function setUp(): void
    {
        $package = \dirname(__DIR__, 2);
        $this->script = \dirname($package, 2).'/tools/infection/mutate-changed';
        if (!is_file(\dirname($this->script).'/vendor/bin/infection')) {
            self::markTestSkipped('Infection is not installed: composer install in tools/infection.');
        }

        $this->project = $package.'/var/mutate-changed-test';
        $filesystem = new Filesystem();
        $filesystem->remove($this->project);
        $filesystem->dumpFile($this->project.'/src/Price.php', self::PRICE);
        $filesystem->dumpFile($this->project.'/tests/PriceTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Shop\Tests;

            use PHPUnit\Framework\TestCase;
            use Shop\Price;

            final class PriceTest extends TestCase
            {
                public function testTaxIsAdded(): void
                {
                    self::assertSame(120, Price::withTax(100));
                }
            }
            PHP);
        // The package's own PHPUnit, and the project's classes on top of its autoloader.
        $filesystem->dumpFile($this->project.'/autoload.php', \sprintf(<<<'PHP'
            <?php
            require %s;
            spl_autoload_register(static function (string $class): void {
                $file = __DIR__.'/src/'.substr(strrchr($class, '\\'), 1).'.php';
                is_file($file) && require $file;
            });
            PHP, var_export($package.'/vendor/autoload.php', true)));
        $filesystem->dumpFile($this->project.'/phpunit.xml', '<phpunit bootstrap="autoload.php"><testsuites><testsuite name="all"><directory>tests</directory></testsuite></testsuites><source><include><directory>src</directory></include></source></phpunit>');
        $filesystem->dumpFile($this->project.'/infection.json5', \sprintf('{"source": {"directories": ["src"]}, "phpUnit": {"customPath": %s}, "tmpDir": "/tmp/infection-mutate-changed-test", "logs": {"json": "php://stdout"}}', json_encode($package.'/vendor/bin/phpunit')));
        $this->git('init', '-q', '-b', 'main');
        $this->git('add', '.');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-q', '-m', 'init');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testNothingChangedIsNoMutant(): void
    {
        self::assertSame(0, $this->mutate()['stats']['totalMutantsCount']);
    }

    /**
     * The case that went through unseen: a method added to a class, with no test calling it.
     */
    public function testAnUntestedAdditionIsReportedAsNotCovered(): void
    {
        file_put_contents($this->project.'/src/Price.php', str_replace(
            "    }\n}",
            "    }\n\n    public static function isFree(int \$cents): bool\n    {\n        return 0 === \$cents;\n    }\n}",
            self::PRICE,
        ));

        $log = $this->mutate();

        self::assertGreaterThan(0, $log['stats']['notCoveredCount']);
        self::assertSame(0, $log['stats']['killedCount'], 'Only the changed lines are mutated: withTax() was not touched.');
    }

    /**
     * A new file, untracked: mutated all the same, and a weak test lets a mutant through.
     */
    public function testANewFileIsMutatedAndAWeakTestShows(): void
    {
        file_put_contents($this->project.'/src/Discount.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Shop;\n\nfinal class Discount\n{\n    public static function apply(int \$amount): int\n    {\n        return \$amount > 100 ? \$amount - 10 : \$amount;\n    }\n}\n");
        file_put_contents($this->project.'/tests/DiscountTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Shop\\Tests;\n\nuse PHPUnit\\Framework\\TestCase;\nuse Shop\\Discount;\n\nfinal class DiscountTest extends TestCase\n{\n    public function testABigAmountIsDiscounted(): void\n    {\n        self::assertSame(190, Discount::apply(200));\n    }\n}\n");

        $log = $this->mutate();

        self::assertSame(['GreaterThan'], array_map(static fn (array $mutant): string => $mutant['mutator']['mutatorName'], $log['escaped']));
        // The repository's index is left alone: the new file is still untracked.
        self::assertStringStartsWith('??', trim((new Process(['git', '-C', $this->project, 'status', '--porcelain', '--', 'src/Discount.php']))->mustRun()->getOutput()));
    }

    /**
     * @return array{stats: array<string, int|float>, escaped: list<array<string, mixed>>, uncovered: list<array<string, mixed>>}
     */
    private function mutate(): array
    {
        $run = new Process(['sh', $this->script], $this->project, timeout: 300);
        $run->mustRun();

        return json_decode($run->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function git(string ...$arguments): void
    {
        (new Process(['git', '-C', $this->project, ...$arguments]))->mustRun();
    }
}
