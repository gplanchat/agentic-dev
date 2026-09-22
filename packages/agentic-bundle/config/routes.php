<?php

declare(strict_types=1);

use Gplanchat\AgenticBundle\Controller\HelpController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('gplanchat_agentic_help', '/')
        ->controller(HelpController::class)
        ->methods(['GET']);
};
