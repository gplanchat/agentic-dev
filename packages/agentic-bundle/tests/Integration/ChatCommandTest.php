<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\AgenticBundle\Console\AgenticApplication;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ChatCommandTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * C'était le défaut rapporté : un identifiant inconnu ouvrait un écran vide qui ne répondait
     * jamais. Il est refusé, en le nommant.
     */
    public function testAnUnknownConversationIsRefused(): void
    {
        $tester = new CommandTester(self::getContainer()->get(AgenticApplication::class)->find('chat'));

        self::assertSame(1, $tester->execute(['conversation' => '00000000-0000-4000-8000-000000000000']));
        self::assertStringContainsString('Conversation inconnue : 00000000-0000-4000-8000-000000000000', $tester->getDisplay());
    }
}
