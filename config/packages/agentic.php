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
            // The layers run_checks may run, each in the sandbox and the conversation's workspace.
            // `{report}` is where the JUnit report is expected; the tool reads it rather than
            // returning kilobytes of output. One layer per package and per level of the pyramid.
            'checks' => [
                'component-static' => ['command' => 'php8.2 vendor/bin/phpunit --testsuite static --log-junit {report}', 'cwd' => 'packages/agentic', 'description' => 'agentic: architecture rules, test pyramid', 'tests' => 'packages/agentic/tests/ArchitectureTest.php (dependency rules between layers of the code) and tests/TestPyramidTest.php; add a rule rather than a new file'],
                'component-unit' => ['command' => 'php8.2 vendor/bin/phpunit --testsuite unit --log-junit {report}', 'cwd' => 'packages/agentic', 'filter_option' => '--filter', 'description' => 'agentic: domain and application, in memory', 'tests' => 'packages/agentic/tests/Domain and tests/Application, mirroring src/ (src/Domain/Guard/ToolRule.php → tests/Domain/Guard/ToolRuleTest.php); PHP 8.2, PHPUnit 11', 'review' => ['component-functional', 'bundle-functional', 'component-static']],
                'component-functional' => ['command' => 'php8.2 vendor/bin/phpunit --testsuite functional --log-junit {report}', 'cwd' => 'packages/agentic', 'filter_option' => '--filter', 'description' => 'agentic: the durable workflow on Durable\'s test environment', 'tests' => 'packages/agentic/tests/Infrastructure/<Behaviour>WorkflowTest.php, on Gplanchat\\Durable\\Testing\\WorkflowTestEnvironment with the model scripted', 'review' => ['bundle-functional', 'component-static']],
                'bundle-static' => ['command' => 'php8.4 vendor/bin/phpunit --testsuite static --log-junit {report}', 'cwd' => 'packages/agentic-bundle', 'description' => 'bundle: test pyramid', 'tests' => 'packages/agentic-bundle/tests/TestPyramidTest.php only'],
                'bundle-unit' => ['command' => 'php8.4 vendor/bin/phpunit --testsuite unit --log-junit {report}', 'cwd' => 'packages/agentic-bundle', 'filter_option' => '--filter', 'description' => 'bundle: TUI widgets, JUnit reader, factories', 'tests' => 'packages/agentic-bundle/tests/{Ai,Check,Controller,Tui}, mirroring src/; PHP 8.4, PHPUnit 12', 'review' => ['bundle-functional', 'bundle-static']],
                'bundle-functional' => ['command' => 'php8.4 vendor/bin/phpunit --testsuite functional --log-junit {report}', 'cwd' => 'packages/agentic-bundle', 'filter_option' => '--filter', 'description' => 'bundle: the kernel with in-memory journal and transports', 'tests' => 'packages/agentic-bundle/tests/Integration (historical name), KernelTestCase on TestKernel with in-memory journal and transports', 'review' => ['bundle-integration', 'bundle-static']],
                'bundle-integration' => ['command' => 'php8.4 vendor/bin/phpunit --testsuite integration --log-junit {report}', 'cwd' => 'packages/agentic-bundle', 'filter_option' => '--filter', 'description' => 'bundle: bubblewrap, git worktrees, MCP servers, HTTP', 'tests' => 'packages/agentic-bundle/tests/{Sandbox,Mcp,Chat} and tests/Integration/McpIntegrationTest.php; skip when bwrap is missing', 'review' => ['bundle-static']],
            ],
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
