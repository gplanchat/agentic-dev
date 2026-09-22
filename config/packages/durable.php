<?php

declare(strict_types=1);

use App\Lock\SingleWorkerStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Lock\LockFactory;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;

// Le journal sur SQLite : les conversations survivent à la fermeture de la TUI, et se reprennent.
// Les transports Messenger restent en mémoire — la TUI est le seul worker, et reprendre une
// conversation ouvre une exécution neuve depuis son fil. Seul plafond : un tour en cours au moment
// de quitter est perdu.
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('app.durable_connection', Connection::class)
        ->factory([DriverManager::class, 'getConnection'])
        ->args([['driver' => 'pdo_sqlite', 'path' => '%kernel.project_dir%/var/agentic.sqlite']])
        ->set('app.durable_lock_factory', LockFactory::class)
        ->args([inline_service(SingleWorkerStore::class)]);

    $container->extension('durable', [
        'dbal' => ['connection' => 'app.durable_connection', 'lock_factory' => 'app.durable_lock_factory'],
        'event_store' => ['type' => 'dbal'],
        'workflow_metadata' => ['type' => 'dbal'],
        'child_workflow' => ['parent_link_store' => ['type' => 'dbal']],
        'temporal' => ['dsn' => null],
        'activity_transport' => ['type' => 'messenger', 'transport_name' => 'durable_activities'],
        // Sans borne, une activité qui échoue est retentée sans fin, et l'écran reste sur « réfléchit… ».
        'max_activity_retries' => 3,
    ]);
};
