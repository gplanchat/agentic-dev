<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Project;

use Internal\Toml\Toml;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Util\XmlUtils;
use Symfony\Component\Yaml\Yaml;

/**
 * A project's `.agentic/config.*`, read and validated — yaml, toml, json or xml, one of them.
 *
 * Not PHP: a file found in the directory the agent is launched from would run on the host before
 * anyone approved it. The declarative formats say what they say, and the approval covers the rest.
 */
final readonly class ProjectFile
{
    private const FORMATS = ['yaml', 'yml', 'toml', 'json', 'xml'];

    /**
     * @param array<string, mixed> $settings validated by {@see ProjectConfiguration}
     */
    public function __construct(
        public string $path,
        public string $contents,
        public array $settings,
    ) {
    }

    /**
     * What the approval covers: the bytes of the file, whatever they say.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->contents);
    }

    /**
     * @throws InvalidProjectConfiguration a PHP file, two files, or one that does not read or validate
     */
    public static function find(string $root): ?self
    {
        $directory = $root.'/.agentic';
        if (is_file($directory.'/config.php')) {
            throw new InvalidProjectConfiguration(\sprintf('%s/config.php is not read: PHP would run on this machine as soon as the agent starts. Write it as yaml, toml, json or xml.', $directory));
        }

        $found = array_values(array_filter(
            array_map(static fn (string $format): string => $directory.'/config.'.$format, self::FORMATS),
            is_file(...),
        ));
        if ([] === $found) {
            return null;
        }
        if (\count($found) > 1) {
            $names = array_map(basename(...), $found);
            sort($names);

            throw new InvalidProjectConfiguration(\sprintf('%s holds %d configurations (%s): keep one.', $directory, \count($found), implode(', ', $names)));
        }

        $path = $found[0];
        $contents = (string) file_get_contents($path);
        try {
            $settings = (new Processor())->processConfiguration(new ProjectConfiguration(), [self::parse($path, $contents)]);
        } catch (\Throwable $failure) {
            throw new InvalidProjectConfiguration(\sprintf('%s: %s', $path, $failure->getMessage()), previous: $failure);
        }

        return new self($path, $contents, $settings);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parse(string $path, string $contents): array
    {
        $parsed = match (pathinfo($path, \PATHINFO_EXTENSION)) {
            'yaml', 'yml' => Yaml::parse($contents),
            'toml' => Toml::parseToArray($contents),
            'json' => json_decode($contents, true, flags: \JSON_THROW_ON_ERROR),
            default => XmlUtils::convertDomElementToArray(XmlUtils::loadFile($path)->documentElement ?? throw new \UnexpectedValueException('No root element.')),
        };

        // An empty file says nothing: the defaults hold.
        return \is_array($parsed) ? $parsed : [];
    }
}
