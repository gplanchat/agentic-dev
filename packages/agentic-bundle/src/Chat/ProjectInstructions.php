<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Chat;

/**
 * Les consignes du projet, lues dans un fichier (`AGENTS.md`) et ajoutées au prompt système.
 *
 * Lues **au démarrage d'une conversation**, hors du workflow : elles entrent dans la charge, donc
 * au journal, et le rejeu retrouve les mêmes. Modifier le fichier vaut pour les conversations
 * suivantes ; celles qui tournent gardent les consignes avec lesquelles elles ont commencé.
 */
final readonly class ProjectInstructions
{
    /**
     * Le préambule n'est jamais compacté : un fichier démesuré mangerait la fenêtre de chaque tour,
     * pour toute la conversation. D'où un plafond, au-delà duquel le fichier est tronqué.
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
            : $systemPrompt."\n\n# Consignes du projet (".basename((string) $this->path).")\n\n".$instructions;
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
            // mb_strcut ne coupe pas un caractère UTF-8 en deux.
            $content = mb_strcut($content, 0, self::MAX_BYTES)."\n\n[… consignes tronquées à ".self::MAX_BYTES.' octets.]';
        }

        return trim($content);
    }
}
