<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Skill;

use Symfony\Component\Yaml\Yaml;

/**
 * The skills the application offers, one Markdown file each: a YAML front matter (`description`,
 * `argument`), then the procedure. The file's name is the skill's.
 *
 * @implements \IteratorAggregate<string, Skill>
 */
final readonly class Skills implements \IteratorAggregate
{
    /** @var array<string, Skill> */
    private array $skills;

    public function __construct(Skill ...$skills)
    {
        $byName = [];
        foreach ($skills as $skill) {
            $byName[$skill->name] = $skill;
        }
        ksort($byName);
        $this->skills = $byName;
    }

    /**
     * The skills shipped with the bundle.
     */
    public static function bundled(): self
    {
        return self::fromDirectory(\dirname(__DIR__, 2).'/skills');
    }

    /**
     * @throws \InvalidArgumentException on a file that is not a skill: better refused at start than
     *                                   offered half-read
     */
    public static function fromDirectory(string $directory): self
    {
        $skills = [];
        foreach (glob($directory.'/*.md') ?: [] as $file) {
            if (1 !== preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', (string) file_get_contents($file), $parts)) {
                throw new \InvalidArgumentException(\sprintf('%s: a skill opens with a front matter between two "---" lines.', $file));
            }
            $meta = Yaml::parse($parts[1]);
            if (!\is_array($meta) || !\is_string($meta['description'] ?? null) || !\is_string($meta['argument'] ?? '')) {
                throw new \InvalidArgumentException(\sprintf('%s: its front matter says a `description`, and may say an `argument`.', $file));
            }
            $skills[] = new Skill(basename($file, '.md'), $meta['description'], (string) ($meta['argument'] ?? ''), trim($parts[2]));
        }

        return new self(...$skills);
    }

    public function get(string $name): ?Skill
    {
        return $this->skills[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->skills);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->skills);
    }
}
