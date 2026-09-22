<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Composer ne sait pas dire qu'une couche n'importe pas une dépendance du paquet : ce test le dit.
 */
final class ArchitectureTest extends TestCase
{
    public function testTheDomainDependsOnNoFrameworkNorRuntime(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__).'/src/Domain'));
        foreach ($files as $file) {
            if ('php' === $file->getExtension()
                && preg_match('/^use (Symfony|Gplanchat\\\\Durable)\\\\/m', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders);
    }
}
