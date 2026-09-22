<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

/**
 * Ce que dit la banane pendant que l'agent travaille — l'équivalent des « Pondering… » de Claude
 * Code. Purement décoratif : rien ici n'entre au journal, le hasard y est donc permis.
 */
final class BananaWords
{
    public const PHRASES = [
        'Épluche la question',
        'Mûrit au soleil',
        'Consulte le bananier',
        'Compte les régimes',
        'Glisse sur une peau de banane',
        'Prépare un banana split',
        'Mixe un smoothie',
        'Danse le Peanut Butter Jelly',
        'Négocie avec les singes',
        'Cherche la courbure idéale',
        'Jongle avec trois bananes',
        'Vérifie le taux de potassium',
        'Remonte le régime',
        'Pèle les hypothèses',
        'Tartine le beurre de cacahuète',
        'Fait flamber la banane',
        'Brunit un peu sur les bords',
        'Grimpe au cocotier (erreur de rayon)',
    ];

    /** Braille : une cellule de large partout, contrairement aux emojis. */
    private const SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /** Une phrase tient ce nombre de temps avant de laisser la place à la suivante. */
    private const BEATS_PER_PHRASE = 8;

    private int $first;

    public function __construct(?int $seed = null)
    {
        $this->first = $seed ?? random_int(0, \count(self::PHRASES) - 1);
    }

    /**
     * Une nouvelle série commence : la banane ne répète pas sa dernière blague d'entrée de jeu.
     */
    public function shuffle(): void
    {
        $this->first = ($this->first + random_int(1, \count(self::PHRASES) - 1)) % \count(self::PHRASES);
    }

    public function line(int $beat, float $elapsedSeconds): string
    {
        $phrase = self::PHRASES[($this->first + intdiv($beat, self::BEATS_PER_PHRASE)) % \count(self::PHRASES)];

        return \sprintf(
            "\e[38;2;255;214;64m%s %s…\e[0m \e[2m(%d s)\e[0m",
            self::SPINNER[$beat % \count(self::SPINNER)],
            $phrase,
            (int) $elapsedSeconds,
        );
    }
}
