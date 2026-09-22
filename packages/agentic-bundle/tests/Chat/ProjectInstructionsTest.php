<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Chat;

use Gplanchat\AgenticBundle\Chat\ProjectInstructions;
use PHPUnit\Framework\TestCase;

final class ProjectInstructionsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2).'/var';
        is_dir($dir) || mkdir($dir, 0o777, true);
        $this->file = $dir.'/AGENTS.md';
    }

    protected function tearDown(): void
    {
        is_file($this->file) && unlink($this->file);
    }

    public function testAMissingOrEmptyFileLeavesThePromptAlone(): void
    {
        self::assertSame('Consigne.', (new ProjectInstructions($this->file))->appendTo('Consigne.'));
        self::assertSame('Consigne.', (new ProjectInstructions(null))->appendTo('Consigne.'));

        file_put_contents($this->file, "  \n");
        self::assertSame('Consigne.', (new ProjectInstructions($this->file))->appendTo('Consigne.'));
    }

    /**
     * Le préambule n'est jamais compacté : un fichier démesuré est tronqué, sans couper un
     * caractère en deux.
     */
    public function testAnOversizedFileIsTruncatedOnACharacterBoundary(): void
    {
        file_put_contents($this->file, str_repeat('é', ProjectInstructions::MAX_BYTES));

        $prompt = (new ProjectInstructions($this->file))->appendTo('Consigne.');

        self::assertLessThan(ProjectInstructions::MAX_BYTES + 200, \strlen($prompt));
        self::assertStringContainsString('consignes tronquées', $prompt);
        self::assertTrue(mb_check_encoding($prompt, 'UTF-8'));
    }
}
