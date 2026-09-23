<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Config;

use Gplanchat\AgenticBundle\AgenticBundle;
use Gplanchat\AgenticBundle\Chat\DurableConversations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bundle's configuration read and wired here, with no kernel: the test that asserts a setting is
 * the one that runs the code reading it — what mutation testing needs to hold the two together.
 */
final class AgenticConfigurationTest extends TestCase
{
    public function testATurnMakesFortyToolCallsUnlessToldOtherwise(): void
    {
        self::assertSame(40, $this->conversationOptions([])['maxToolCalls']);
        self::assertSame(7, $this->conversationOptions(['max_tool_calls' => 7])['maxToolCalls']);
    }

    public function testATurnMakesAtLeastOneToolCall(): void
    {
        self::assertSame(1, $this->conversationOptions(['max_tool_calls' => 1])['maxToolCalls']);

        $this->expectException(InvalidConfigurationException::class);
        $this->conversationOptions(['max_tool_calls' => 0]);
    }

    public function testTheModelReachesTheConversations(): void
    {
        self::assertSame('mistral-small-latest', $this->conversationOptions([])['model']);
        self::assertSame('devstral-latest', $this->conversationOptions(['model' => 'devstral-latest'])['model']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed> the start payload options DurableConversations is given
     */
    private function conversationOptions(array $config): array
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new AgenticBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        foreach ($container->getDefinition(DurableConversations::class)->getArguments() as $argument) {
            if (\is_array($argument) && \array_key_exists('maxToolCalls', $argument)) {
                return $argument;
            }
        }

        self::fail('DurableConversations is given no start payload options.');
    }
}
