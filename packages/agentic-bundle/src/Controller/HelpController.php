<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Controller;

use Gplanchat\Agentic\Application\Help\ListCommands;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web version of the help screen: the same use case, rendered as HTML.
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

        // ponytail: inline HTML, no Twig — a single screen. Twig when the web version has views.
        return new Response(<<<HTML
            <!doctype html>
            <html lang="en">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Agentic</title></head>
            <body>
            <main>
            <h1>Agentic — available commands</h1>
            <table>{$rows}</table>
            </main>
            </body>
            </html>
            HTML);
    }
}
