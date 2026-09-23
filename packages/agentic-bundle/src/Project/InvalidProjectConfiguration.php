<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Project;

/**
 * A project's `.agentic/config.*` that cannot be used — and the message says which file, and why.
 */
final class InvalidProjectConfiguration extends \RuntimeException
{
}
