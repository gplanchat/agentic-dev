<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Ai;

use Gplanchat\Agentic\Infrastructure\SymfonyAi\ScriptedChatModelClient;
use Gplanchat\AgenticBundle\Ai\ModelClientFactory;
use PHPUnit\Framework\TestCase;

final class ModelClientFactoryTest extends TestCase
{
    /**
     * `%env(default::MISTRAL_API_KEY)%` is `null` when the variable is missing: that is the no-key case.
     */
    public function testWithoutAKeyTheScriptedClientAnswers(): void
    {
        self::assertInstanceOf(ScriptedChatModelClient::class, ModelClientFactory::create(null));
        self::assertInstanceOf(ScriptedChatModelClient::class, ModelClientFactory::create(' '));
    }
}
