<?php

declare(strict_types=1);

use App\Lock\SingleWorkerStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Lock\LockFactory;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;

// The journal on SQLite: conversations survive the TUI being closed, and can be resumed.
// The Messenger transports stay in memory — the TUI is the only worker, and resuming a conversation
// opens a fresh execution from its thread. One ceiling only: a turn in flight when you quit is lost.
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
        // Without a bound, a failing activity is retried forever, and the screen stays on "thinking…".
        'max_activity_retries' => 3,
    ]);
};
