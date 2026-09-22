<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Chat;

/**
 * The project instructions, read from a file (`AGENTS.md`) and appended to the system prompt.
 *
 * Read **when a conversation starts**, outside the workflow: they go into the payload, hence into
 * the journal, and a replay finds the same ones. Editing the file counts for the conversations that
 * follow; those already running keep the instructions they started with.
 */
final readonly class ProjectInstructions
{
    /**
     * The preamble is never compacted: an outsized file would eat the window of every turn, for the
     * whole conversation. Hence a ceiling, beyond which the file is truncated.
     */
    public const MAX_BYTES = 32_768;

    public function __construct(private ?string $path)
    {
    }

    public function appendTo(string $systemPrompt): string
    {
        $instructions = $this->read();

        return null === $instructions
            ? $systemPrompt
            : $systemPrompt."\n\n# Project instructions (".basename((string) $this->path).")\n\n".$instructions;
    }

    private function read(): ?string
    {
        if (null === $this->path || !is_file($this->path) || !is_readable($this->path)) {
            return null;
        }

        $content = file_get_contents($this->path, length: self::MAX_BYTES + 1);
        if (false === $content || '' === trim($content)) {
            return null;
        }

        if (\strlen($content) > self::MAX_BYTES) {
            // mb_strcut never cuts a UTF-8 character in two.
            $content = mb_strcut($content, 0, self::MAX_BYTES)."\n\n[… instructions truncated at ".self::MAX_BYTES.' bytes.]';
        }

        return trim($content);
    }
}
