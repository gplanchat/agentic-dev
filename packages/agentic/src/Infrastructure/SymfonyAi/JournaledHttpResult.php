<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\SymfonyAi;

use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * The provider's reply as the journal returns it.
 *
 * The bridges' converters are not plain `array → result` functions: Mistral's starts by looking at
 * the HTTP code to tell a context overflow from a failure. It therefore needs a response object,
 * not just data.
 *
 * Here there is no HTTP response any more — it happened inside the activity, perhaps three days
 * ago. What is journaled is **always** a success: {@see \Gplanchat\Agentic\Infrastructure\SymfonyAi\ModelInvocationActivityHandler}
 * throws on any code >= 400, so that Durable's retry policy applies to a 429 or a 503 (DUR011)
 * instead of letting the workflow code choke on it. Hence the hard-coded 200: it is the only
 * outcome that ever reaches the replay.
 */
final readonly class JournaledHttpResult implements RawResultInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getDataStream(): iterable
    {
        // An activity returns a value once: a stream of deltas does not replay.
        yield from [];
    }

    public function getObject(): object
    {
        return new JournaledHttpResponse($this->data);
    }
}
