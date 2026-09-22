<?php

declare(strict_types=1);

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Un seul processus : journal et transports en mémoire, la TUI fait office de worker.
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'secret' => 'test',
        'test' => true,
        'messenger' => [
            'transports' => [
                'sync' => 'sync://',
                'durable_workflows' => 'in-memory://',
                'durable_activities' => 'in-memory://',
            ],
            'routing' => [
                ResumeWorkflowMessage::class => 'durable_workflows',
                ActivityMessage::class => 'durable_activities',
                // Un `DelayStamp` sur `sync://` est ignoré : les échéances ne tireraient jamais.
                FireWorkflowTimersMessage::class => 'durable_workflows',
                DeliverWorkflowSignalMessage::class => 'sync',
                DeliverWorkflowUpdateMessage::class => 'sync',
            ],
        ],
    ]);

    $container->extension('durable', [
        'event_store' => ['type' => 'in_memory'],
        'workflow_metadata' => ['type' => 'in_memory'],
        'temporal' => ['dsn' => null],
        'activity_transport' => ['type' => 'messenger', 'transport_name' => 'durable_activities'],
        // Sans borne, une activité qui échoue est retentée sans fin, et l'écran reste sur « réfléchit… ».
        'max_activity_retries' => 3,
    ]);

    $container->extension('agentic', [
        'watch_subjects' => ['commande.expediee' => 'une commande a quitté l’entrepôt'],
        'tool_rules' => [
            ['tool' => 'weather', 'when' => ['city' => 'Lyon'], 'decision' => 'deny', 'reason' => 'Lyon est hors périmètre.'],
        ],
    ]);
};
