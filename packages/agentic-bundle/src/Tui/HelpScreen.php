<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use Gplanchat\Agentic\Application\Help\CommandSummary;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Écran d'aide : la liste des commandes, fermée par q, Échap ou Ctrl+C.
 */
final class HelpScreen
{
    /** @param list<CommandSummary> $commands */
    public function build(array $commands, ?TerminalInterface $terminal = null): Tui
    {
        $tui = new Tui(terminal: $terminal);

        $width = max(array_map(static fn (CommandSummary $c): int => mb_strlen($c->name), $commands) ?: [0]);
        $lines = ["\e[1mAgentic — commandes disponibles\e[0m", ''];
        foreach ($commands as $command) {
            $lines[] = \sprintf("  \e[32m%s\e[0m  %s", str_pad($command->name, $width), $command->description);
        }
        $lines[] = '';
        $lines[] = "\e[2mq, Échap ou Ctrl+C pour quitter\e[0m";
        $tui->add(new TextWidget(implode("\n", $lines)));

        $quit = new Keybindings(['quit' => ['q', Key::ESCAPE, 'ctrl+c']]);
        $tui->getEventDispatcher()->addListener(InputEvent::class, static function (InputEvent $event) use ($quit, $tui): void {
            if ($quit->matches($event->getData(), 'quit')) {
                $event->stopPropagation();
                $tui->stop();
            }
        });

        return $tui;
    }
}
