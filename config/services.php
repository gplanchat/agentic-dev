<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Log\Logger;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    // The demo tools: every `AgentTool` is offered to the agent.
    $services->load('App\\Tool\\', '../src/Tool/');

    // The default logger writes to stderr: inside the TUI, it would tear the screen apart.
    $services->set('logger', Logger::class)
        ->args(['info', '%kernel.logs_dir%/%kernel.environment%.log']);
};
