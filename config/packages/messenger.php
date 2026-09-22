<?php

declare(strict_types=1);

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Un seul processus : la TUI fait office de worker sur des transports en mémoire.
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
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
};
