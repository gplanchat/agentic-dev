<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle;

use Gplanchat\Agentic\Application\Help\ListCommands;
use Gplanchat\AgenticBundle\Console\AgenticApplication;
use Gplanchat\AgenticBundle\Console\ConsoleCommandCatalog;
use Gplanchat\AgenticBundle\Console\HelpCommand;
use Gplanchat\AgenticBundle\Controller\HelpController;
use Gplanchat\AgenticBundle\Tui\HelpScreen;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Le bundle fournit deux adaptateurs primaires sur le même cas d'usage : l'application TUI
 * `agentic` (sa propre application Console, distincte de `bin/console`) et sa version web.
 */
final class AgenticBundle extends AbstractBundle
{
    public const COMMAND_TAG = 'gplanchat_agentic.command';

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(HelpScreen::class);

        // Pas de tag `console.command` : ces commandes appartiennent à l'application `agentic`, et
        // un `help` enregistré dans `bin/console` y remplacerait celui de Symfony.
        $services->set(HelpCommand::class)
            ->args([service(HelpScreen::class)])
            ->tag(self::COMMAND_TAG);

        $services->set(AgenticApplication::class)
            ->args([tagged_iterator(self::COMMAND_TAG)])
            ->public();

        $services->set(ConsoleCommandCatalog::class)
            ->args([service(AgenticApplication::class)]);

        $services->set(ListCommands::class)
            ->args([service(ConsoleCommandCatalog::class)]);

        $services->set(HelpController::class)
            ->args([service(ListCommands::class)])
            ->tag('controller.service_arguments');
    }
}
