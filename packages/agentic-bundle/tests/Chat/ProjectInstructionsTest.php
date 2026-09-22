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
        self::assertSame('Instruction.', (new ProjectInstructions($this->file))->appendTo('Instruction.'));
        self::assertSame('Instruction.', (new ProjectInstructions(null))->appendTo('Instruction.'));

        file_put_contents($this->file, "  \n");
        self::assertSame('Instruction.', (new ProjectInstructions($this->file))->appendTo('Instruction.'));
    }

    /**
     * The preamble is never compacted: an outsized file is truncated, without cutting a character
     * in two.
     */
    public function testAnOversizedFileIsTruncatedOnACharacterBoundary(): void
    {
        file_put_contents($this->file, str_repeat('é', ProjectInstructions::MAX_BYTES));

        $prompt = (new ProjectInstructions($this->file))->appendTo('Instruction.');

        self::assertLessThan(ProjectInstructions::MAX_BYTES + 200, \strlen($prompt));
        self::assertStringContainsString('instructions truncated', $prompt);
        self::assertTrue(mb_check_encoding($prompt, 'UTF-8'));
    }
}
