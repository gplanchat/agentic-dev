<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

/**
 * Ce qu'une commande du chat rend à l'écran.
 */
final readonly class SlashOutcome
{
    /**
     * @param list<array{value: string, label: string, description?: string}> $choices de quoi choisir, quand la commande attend qu'on précise
     */
    public function __construct(
        public string $notice,
        public bool $error = false,
        /** La conversation à afficher désormais, quand la commande en a ouvert une autre. */
        public ?string $conversation = null,
        public array $choices = [],
        /** La commande à relancer avec la valeur choisie, par exemple `/rewind`. */
        public ?string $choose = null,
        /** Ce qu'il faut remettre dans la saisie — le message qu'on vient de défaire. */
        public ?string $prefill = null,
    ) {
    }
}
