<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('agentic', [
        // Empty: a scripted client answers, with no network and no key.
        'mistral_api_key' => '%env(default::MISTRAL_API_KEY)%',
        // The decision hooks, before the mode: deny > ask > allow, fnmatch patterns on the tool and
        // on its arguments. For instance:
        //   ['tool' => 'send_email', 'when' => ['to' => '*@example.test'], 'decision' => 'allow'],
        //   ['tool' => 'send_email', 'when' => ['to' => '*@competitor.test'], 'decision' => 'deny', 'reason' => 'Never to competitors.'],
        'tool_rules' => [],
        // run_command, read_file, edit_file and run_checks, inside a bubblewrap sandbox: no network,
        // nothing of the disk but what is mounted; .env.local and var/ hidden, .git, .claude and
        // .agentic read-only. The agent writes in its own worktree of the project it is launched in.
        // What a project says about itself — its check layers, more shared paths, more auto-mode
        // commands, its rules — is in its own .agentic/config.* (this repository's included), used
        // once approved. What stays here holds for every project: the defaults.
        'sandbox' => [
            'enabled' => true,
        ],
        // Instructions of the installation, for every project. A project's own are its AGENTS.md
        // (or what its .agentic/config.* names): this repository's carries ADR-001.
        // 'instructions_file' => '%kernel.project_dir%/INSTALLATION.md',
        // The sub-agents `delegate` may hand a mission to. A profile narrows what its sub-agent may
        // do — model, instructions, tools, ceiling — and never grants more than the caller has: the
        // delegate takes the strictest of its ceiling and of the parent's effective mode.
        'agents' => [
            'weatherman' => [
                'description' => 'Looks up the weather of cities',
                'prompt' => 'You answer about the weather, in one sentence per city.',
                'tools' => ['weather'],
            ],
            'scribe' => [
                'description' => 'Reads the project and writes notes',
                'prompt' => 'You read before you write, and you keep notes short.',
                'ceiling' => 'edition',
                'tools' => ['read_file', 'save_note'],
                'max_turns' => 3,
            ],
        ],
        // MCP servers whose tools are offered to the agent, discovered when a conversation starts
        // and frozen in its payload. An MCP tool is `external` unless a rule says otherwise: what a
        // server says about its own tools is a claim, and the guard is what protects from it.
        //   'mcp' => ['servers' => [
        //       'filesystem' => ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-filesystem', __DIR__],
        //           'effects' => ['read_*' => 'read', 'list_*' => 'read']],
        //       'docs' => ['url' => 'https://example.test/mcp', 'headers' => ['Authorization' => 'Bearer …']],
        //   ]],
        'watch_subjects' => [
            'order.shipped' => 'an order has left the warehouse',
            'payment.received' => 'a payment has been collected',
            'supplier.replied' => 'a supplier answered a request',
        ],
    ]);
};
