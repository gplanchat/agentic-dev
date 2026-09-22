<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Tool;

/**
 * Un appel d'outil demandé par le modèle, dit dans le vocabulaire du domaine.
 *
 * Le domaine ne parle pas le type du fournisseur (`Symfony\AI\Platform\Result\ToolCall`, 0.x et
 * sans promesse de compatibilité) : l'adaptateur traduit à la frontière, et la garde ne voit que
 * ceci.
 */
final readonly class ToolInvocation
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $callId,
        public string $name,
        public array $arguments = [],
    ) {
        if ('' === trim($callId)) {
            throw new \InvalidArgumentException('Un appel d\'outil doit porter un identifiant.');
        }
    }
}
