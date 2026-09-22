<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Log\Logger;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    // Les outils de démo : chaque `AgentTool` est offert à l'agent.
    $services->load('App\\Tool\\', '../src/Tool/');

    // Le logger par défaut écrit sur stderr : dans la TUI, il déchirerait l'écran.
    $services->set('logger', Logger::class)
        ->args(['info', '%kernel.logs_dir%/%kernel.environment%.log']);
};
