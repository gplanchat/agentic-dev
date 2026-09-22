<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Mcp;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Mcp\Schema\Tool;

/**
 * An MCP server as the application declares it: how to reach it, and what its tools are allowed to
 * do here.
 *
 * **Effects are decided on this side.** A server describes its own tools, annotations included
 * (`readOnlyHint`, `destructiveHint`, `openWorldHint`) — but those are its claims about itself, and
 * the guard is precisely what protects us from a tool that lies. So: the configured `effects` win;
 * annotations are only read when `trust_annotations` says so; and failing both, a tool is `external`
 * — the prudent default, the one that asks in every mode but `auto`.
 */
final readonly class McpServer
{
    /**
     * @param list<string>          $args    arguments of the command, for a server spawned over stdio
     * @param array<string, string> $env     environment of that command
     * @param array<string, string> $headers headers of the HTTP endpoint, for a remote server
     * @param array<string, string> $effects tool name pattern (fnmatch) → read, write or external
     */
    public function __construct(
        public string $name,
        public ?string $command = null,
        public array $args = [],
        public ?string $cwd = null,
        public array $env = [],
        public ?string $url = null,
        public array $headers = [],
        public array $effects = [],
        public bool $trustAnnotations = false,
        public int $timeoutSeconds = 15,
    ) {
        if ((null === $command) === (null === $url)) {
            throw new \InvalidArgumentException(\sprintf('The MCP server "%s" needs either a command (stdio) or a url (http), not both and not neither.', $name));
        }
    }

    /**
     * The name the model sees. Prefixed like Claude Code does, so that two servers offering a
     * `search` tool stay apart, and so that a glance at a tool name says where it comes from.
     */
    public function toolName(string $tool): string
    {
        return \sprintf('mcp__%s__%s', $this->name, $tool);
    }

    public function effectOf(Tool $tool): ToolEffect
    {
        foreach ($this->effects as $pattern => $effect) {
            if (fnmatch((string) $pattern, $tool->name)) {
                return ToolEffect::from($effect);
            }
        }

        if (!$this->trustAnnotations) {
            return ToolEffect::External;
        }

        // Only read from the server's own hints, and only down from `external`: a hint may reassure,
        // it may never grant.
        return match (true) {
            true === $tool->annotations?->openWorldHint => ToolEffect::External,
            true === $tool->annotations?->destructiveHint => ToolEffect::External,
            true === $tool->annotations?->readOnlyHint => ToolEffect::Read,
            false === $tool->annotations?->destructiveHint => ToolEffect::Write,
            default => ToolEffect::External,
        };
    }
}
