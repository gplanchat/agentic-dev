<?php

declare(strict_types=1);

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// A single process: journal and transports in memory, the TUI acts as the worker.
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
                // A `DelayStamp` on `sync://` is ignored: the deadlines would never fire.
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
        // Without a bound, a failing activity is retried endlessly, and the screen stays on "thinking…".
        'max_activity_retries' => 3,
    ]);

    $container->extension('agentic', [
        'watch_subjects' => ['order.shipped' => 'an order has left the warehouse'],
        'tool_rules' => [
            ['tool' => 'weather', 'when' => ['city' => 'Lyon'], 'decision' => 'deny', 'reason' => 'Lyon is out of scope.'],
        ],
    ]);
};
