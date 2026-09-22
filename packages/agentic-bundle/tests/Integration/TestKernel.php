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

final class TestKernel extends Kernel
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

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->import(__DIR__.'/config/durable.php');
        $container->services()
            // Le logger par défaut écrit sur stderr : dans une TUI, il déchirerait l'écran.
            ->set('logger', Logger::class)->args(['warning', 'php://memory'])
            ->set(WeatherTool::class)->autoconfigure()
            ->set(SendEmailTool::class)->autoconfigure()
            ->set(BrokenNoteTool::class)->autoconfigure();
    }
}
