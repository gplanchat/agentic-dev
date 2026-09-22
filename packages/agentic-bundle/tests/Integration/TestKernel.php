<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\AgenticBundle\AgenticBundle;
use Gplanchat\Durable\Bundle\DurableBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
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

    public function getCacheDir(): string
    {
        return \dirname(__DIR__, 2).'/var/cache';
    }

    public function getLogDir(): string
    {
        return \dirname(__DIR__, 2).'/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import(__DIR__.'/config/durable.php');
        // Worktrees on, for the path a conversation carries: no test runs run_command through the
        // kernel — one that did would cut a real worktree of this repository under tests/Integration.
        $container->extension('agentic', ['sandbox' => ['enabled' => true, 'auto_allow' => ['git status', 'git status *']]]);
        $container->services()
            // The default logger writes to stderr: in a TUI, it would tear the screen apart.
            ->set('logger', Logger::class)->args(['warning', 'php://memory'])
            ->set(WeatherTool::class)->autoconfigure()
            ->set(SendEmailTool::class)->autoconfigure()
            ->set(BrokenNoteTool::class)->autoconfigure();
    }
}
