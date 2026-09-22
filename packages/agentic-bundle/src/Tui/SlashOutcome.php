<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

/**
 * Ce qu'une commande du chat rend à l'écran.
 */
final readonly class SlashOutcome
{
    public function __construct(
        public string $notice,
        public bool $error = false,
        /** La conversation à afficher désormais, quand la commande en a ouvert une neuve. */
        public ?string $conversation = null,
    ) {
    }
}
