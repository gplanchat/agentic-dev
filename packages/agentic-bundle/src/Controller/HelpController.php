<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Controller;

use Gplanchat\Agentic\Application\Help\ListCommands;
use Symfony\Component\HttpFoundation\Response;

/**
 * Version web de l'écran d'aide : le même cas d'usage, rendu en HTML.
 */
final readonly class HelpController
{
    public function __construct(private ListCommands $listCommands)
    {
    }

    public function __invoke(): Response
    {
        $rows = '';
        foreach (($this->listCommands)() as $command) {
            $rows .= \sprintf(
                '<tr><th scope="row"><code>%s</code></th><td>%s</td></tr>',
                htmlspecialchars($command->name),
                htmlspecialchars($command->description),
            );
        }

        // ponytail: HTML en ligne, sans Twig — un seul écran. Twig quand la version web aura des vues.
        return new Response(<<<HTML
            <!doctype html>
            <html lang="fr">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Agentic</title></head>
            <body>
            <main>
            <h1>Agentic — commandes disponibles</h1>
            <table>{$rows}</table>
            </main>
            </body>
            </html>
            HTML);
    }
}
