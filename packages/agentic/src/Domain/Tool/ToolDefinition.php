<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Tool;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;

/**
 * What a tool declares: its schema for the model, and its effect for the guard.
 *
 * The JSON schema (`parameters`) stays an array: this is wire, it goes out as is to the provider.
 * The rest is typed — a misspelled `effect` must throw here, not silently become "external" deep
 * inside the guard.
 */
final readonly class ToolDefinition
{
    /**
     * @param array<string, mixed>|null $parameters JSON schema, as it goes out to the provider
     */
    public function __construct(
        public string $name,
        public string $description,
        public ToolEffect $effect = ToolEffect::External,
        public ?array $parameters = null,
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A tool must have a name.');
        }
    }

    /**
     * A boundary factory: the workflow payload arrives from the journal, hence as arrays.
     *
     * A tool that does not declare its effect is treated as external — the cautious default, the
     * one that needs an approval in every mode but `auto`.
     *
     * @param array{description?: string, effect?: string, parameters?: array<string, mixed>|null} $wire
     */
    public static function fromWire(string $name, array $wire): self
    {
        return new self(
            $name,
            (string) ($wire['description'] ?? ''),
            ToolEffect::tryFrom((string) ($wire['effect'] ?? '')) ?? ToolEffect::External,
            $wire['parameters'] ?? null,
        );
    }

    /**
     * Back to the wire: the workflow start payload goes out as JSON, indexed by tool name — it is
     * {@see Toolset::toWire()} that sets the key.
     *
     * @return array{description: string, effect: string, parameters: array<string, mixed>|null}
     */
    public function toWire(): array
    {
        return [
            'description' => $this->description,
            'effect' => $this->effect->value,
            'parameters' => $this->parameters,
        ];
    }
}
