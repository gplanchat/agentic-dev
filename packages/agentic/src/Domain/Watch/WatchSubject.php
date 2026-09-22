<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Watch;

/**
 * Un sujet qu'une veille peut guetter : un événement que l'application sait publier.
 *
 * **C'est l'acte de design, comme {@see \Gplanchat\Agentic\Domain\Guard\ToolEffect} l'est pour la
 * garde.** Une veille décrite en texte libre est une veille que rien ne pourra jamais lever : l'agent
 * écrirait « quand la livraison arrive », l'événement métier dirait `commande.expediee`, et personne
 * ne ferait le rapprochement — sans erreur, sans trace, l'agent dormirait jusqu'à son échéance.
 *
 * Le vocabulaire reste fermé, mais c'est l'application qui le ferme ({@see WatchSubjects}) : les
 * faits d'une boutique ne sont pas ceux d'un import de catalogue, et un composant qui les
 * énumérerait obligerait chacun à le forker pour ajouter un cas.
 */
final readonly class WatchSubject
{
    public function __construct(
        public string $value,
        /** Ce que le modèle lit dans le schéma : sans ça il choisirait au hasard dans des chaînes opaques. */
        public string $description,
    ) {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('Un sujet de veille doit porter un nom.');
        }
    }

    public function describe(): string
    {
        return $this->description;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
