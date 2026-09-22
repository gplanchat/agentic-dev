<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\SymfonyAi;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The HTTP response as the journal gives it back — a 200 and a body, nothing else.
 *
 * The bridges' converters require a real `ResponseInterface`: Mistral's calls
 * `throwOnHttpError($response)` before looking at the data. On replay there is no socket any more,
 * only what the activity reported — and what it reports is always a success, since
 * {@see \Gplanchat\Agentic\Infrastructure\SymfonyAi\ModelInvocationActivityHandler} throws on any code >= 400.
 */
final readonly class JournaledHttpResponse implements ResponseInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {
    }

    public function getStatusCode(): int
    {
        return 200;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(bool $throw = true): array
    {
        return ['content-type' => ['application/json']];
    }

    public function getContent(bool $throw = true): string
    {
        return json_encode($this->data, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $throw = true): array
    {
        return $this->data;
    }

    public function cancel(): void
    {
        // Nothing to cancel: the call happened inside the activity, perhaps three days ago.
    }

    public function getInfo(?string $type = null): mixed
    {
        $info = ['http_code' => 200, 'url' => 'durable://journal'];

        return null === $type ? $info : ($info[$type] ?? null);
    }
}
