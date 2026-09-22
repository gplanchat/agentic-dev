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
        // run_command, read_file and edit_file, inside a bubblewrap sandbox: no network, nothing of the disk but what is
        // mounted; .env.local and var/ hidden, .git read-only. The agent writes in its own worktree
        // (`worktrees`), not in the project; `shared` are the paths bound from the project into it —
        // vendor/ among them, since a worktree has none. Outside auto, every command asks; in auto,
        // only those of auto_allow pass (fnmatch patterns).
        'sandbox' => [
            'enabled' => true,
            // The bundle shares vendor/ by default; the packages have their own, and the bundle
            // suite runs with cwd=packages/agentic-bundle.
            'shared' => ['vendor', 'packages/*/vendor'],
            // The machine's default PHP is 8.2; the bundle wants 8.4, so its suite runs through php8.4.
            'auto_allow' => [
                'git status', 'git status *', 'git diff', 'git diff *', 'git log', 'git log *',
                'vendor/bin/phpunit', 'vendor/bin/phpunit *',
                'php8.2 vendor/bin/phpunit', 'php8.2 vendor/bin/phpunit *',
                'php8.4 vendor/bin/phpunit', 'php8.4 vendor/bin/phpunit *',
            ],
        ],
        // The project instructions, appended to the system prompt of every new conversation.
        // 'instructions_file' => '%kernel.project_dir%/AGENTS.md',
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
