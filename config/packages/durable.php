<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// ponytail: journal en mémoire — la conversation meurt avec le processus. DBAL sur SQLite (plus un
// transport Messenger durable) quand elle devra survivre à la fermeture de la TUI.
return static function (ContainerConfigurator $container): void {
    $container->extension('durable', [
        'event_store' => ['type' => 'in_memory'],
        'workflow_metadata' => ['type' => 'in_memory'],
        'temporal' => ['dsn' => null],
        'activity_transport' => ['type' => 'messenger', 'transport_name' => 'durable_activities'],
        // Sans borne, une activité qui échoue est retentée sans fin, et l'écran reste sur « réfléchit… ».
        'max_activity_retries' => 3,
    ]);
};
