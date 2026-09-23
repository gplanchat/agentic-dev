<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\CurrentPrincipal;
use Gplanchat\AgenticBundle\AgenticBundle;
use Gplanchat\Durable\Bundle\DurableBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Log\Logger;

/**
 * Not final: {@see McpTestKernel} adds an MCP server to it, and only that test needs the process
 * such a server costs.
 */
class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DurableBundle(), new AgenticBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    /**
     * One container per process: under mutation testing each mutant runs in a process of its own, and
     * a container compiled once and kept would never run the mutated configuration code — every
     * mutant of AgenticBundle::configure() or loadExtension() would survive, tested or not.
     */
    public function getCacheDir(): string
    {
        $dir = \dirname(__DIR__, 2).'/var/cache/'.getmypid();
        if (!self::$cleanupRegistered) {
            self::$cleanupRegistered = true;
            register_shutdown_function(static fn () => (new Filesystem())->remove($dir));
        }

        return $dir;
    }

    private static bool $cleanupRegistered = false;

    public function getLogDir(): string
    {
        return \dirname(__DIR__, 2).'/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import(__DIR__.'/config/durable.php');
        // Worktrees on, for the path a conversation carries: no test runs run_command through the
        // kernel — one that did would cut a real worktree of this repository under tests/Integration.
        $container->extension('agentic', ['sandbox' => [
            'enabled' => true,
            'auto_allow' => ['git status', 'git status *'],
            // Never run by these tests: it is here for its name, which must reach the model as written.
            'checks' => ['kernel-unit' => ['command' => 'true', 'tests' => 'tests/Unit']],
        ]]);
        $container->services()
            // The default logger writes to stderr: in a TUI, it would tear the screen apart.
            ->set('logger', Logger::class)->args(['warning', 'php://memory'])
            // The suite does not depend on the account running it, and a test can become someone else.
            ->set(SwitchablePrincipal::class)->public()
            ->alias(CurrentPrincipal::class, SwitchablePrincipal::class)
            ->set(WeatherTool::class)->autoconfigure()
            ->set(SendEmailTool::class)->autoconfigure()
            ->set(BrokenNoteTool::class)->autoconfigure();
    }
}
