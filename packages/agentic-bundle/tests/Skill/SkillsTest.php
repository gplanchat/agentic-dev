<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Skill;

use Gplanchat\AgenticBundle\Project\Project;
use Gplanchat\AgenticBundle\Skill\Skill;
use Gplanchat\AgenticBundle\Skill\Skills;
use Gplanchat\AgenticBundle\Skill\SkillTool;
use Gplanchat\AgenticBundle\Ticket\Forge;
use Gplanchat\AgenticBundle\Ticket\TicketTracker;
use Gplanchat\AgenticBundle\Tui\SlashCommands;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SkillsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/agentic-skills-test-'.getmypid();
        (new Filesystem())->remove($this->directory);
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /**
     * Every bundled skill has its command, and every skill command its skill: a command sending a
     * request for a skill that does not exist would leave the model looking for it.
     */
    public function testEveryBundledSkillHasItsCommand(): void
    {
        $skills = Skills::bundled();

        self::assertSame(['cadrer', 'continuer', 'revue', 'statut'], $skills->names());
        self::assertSame(SlashCommands::SKILLS, array_values(array_filter(array_keys(SlashCommands::COMMANDS), static fn (string $command): bool => null !== $skills->get(substr($command, 1)))));
        foreach ($skills as $skill) {
            self::assertStringStartsWith('# '.$skill->name.' — ', $skill->procedure, 'The procedure is the body, the front matter left out.');
        }
        self::assertSame('<the need, in a sentence or a paragraph>', $skills->get('cadrer')?->argument);
        self::assertSame('', $skills->get('statut')?->argument);
    }

    public function testASkillIsReadFromItsFile(): void
    {
        file_put_contents($this->directory.'/b-skill.md', "---\ndescription: Does B.\n---\n\n# b\nSteps.\n");
        file_put_contents($this->directory.'/a-skill.md', "---\ndescription: Does A.\nargument: '[#n]'\n---\n# a\n");
        file_put_contents($this->directory.'/notes.txt', 'not a skill');

        $skills = Skills::fromDirectory($this->directory);

        self::assertSame(['a-skill', 'b-skill'], $skills->names(), 'Sorted by name; only the Markdown files.');
        self::assertEquals(new Skill('b-skill', 'Does B.', '', "# b\nSteps."), $skills->get('b-skill'));
        self::assertSame('[#n]', $skills->get('a-skill')?->argument);
        self::assertNull($skills->get('c-skill'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function brokenFiles(): iterable
    {
        yield 'no front matter' => ["# a\n", 'a skill opens with a front matter between two "---" lines.'];
        yield 'no description' => ["---\nargument: x\n---\n# a\n", 'its front matter says a `description`, and may say an `argument`.'];
        yield 'an argument that is no text' => ["---\ndescription: D\nargument: [1]\n---\n# a\n", 'its front matter says a `description`, and may say an `argument`.'];
        yield 'a front matter that is no map' => ["---\njust text\n---\n# a\n", 'its front matter says a `description`, and may say an `argument`.'];
        yield 'no procedure' => ["---\ndescription: D\n---\n \n", 'The skill "a" needs a description and a procedure.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenFiles')]
    public function testABrokenFileIsRefusedNotHalfRead(string $contents, string $message): void
    {
        file_put_contents($this->directory.'/a.md', $contents);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Skills::fromDirectory($this->directory);
    }

    public function testASkillIsNamedInLowercaseAndDashes(): void
    {
        foreach (['Cadrer', 'cadrer2', '-cadrer', 'ca drer'] as $name) {
            try {
                new Skill($name, 'D', '', 'P');
                self::fail('Accepted: '.$name);
            } catch (\InvalidArgumentException $e) {
                self::assertSame(\sprintf('A skill is named in lowercase letters and dashes, not "%s".', $name), $e->getMessage());
            }
        }
        self::assertSame('ca-drer', (new Skill('ca-drer', 'D', '', 'P'))->name);
    }

    public function testASkillNeedsADescription(): void
    {
        foreach ([[' ', 'P'], ['D', " \n"]] as [$description, $procedure]) {
            try {
                new Skill('a', $description, '', $procedure);
                self::fail('Accepted an empty description or procedure.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('The skill "a" needs a description and a procedure.', $e->getMessage());
            }
        }
    }

    public function testTheToolIsTheIndexAndHandsTheProcedure(): void
    {
        $skills = new Skills(new Skill('b', 'Does B.', '', 'B steps'), new Skill('a', 'Does A.', '', 'A steps'));
        $tool = new SkillTool($skills, self::project(new TicketTracker(Forge::GitHub, 'acme/app')));
        $definition = $tool->definition();

        self::assertSame('skill', $definition->name);
        self::assertSame(ToolEffect::Read, $definition->effect);
        self::assertSame('Loads the procedure of a skill, before doing the work it covers — then follow it. Skills: `a` — Does A. `b` — Does B.', $definition->description);
        self::assertSame(['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'enum' => ['a', 'b']]], 'required' => ['name']], $definition->parameters);
        self::assertSame('B steps', $tool(['name' => 'b']));
        self::assertSame('No such skill. Skills: a, b.', $tool(['name' => 'c']));
        self::assertSame('No such skill. Skills: a, b.', $tool([]));
        self::assertTrue($tool->isOffered());
        self::assertFalse((new SkillTool($skills, self::project(null)))->isOffered(), 'No tracker, no tickets for the skills to work on.');
    }

    private static function project(?TicketTracker $tickets): Project
    {
        return new Project('/p', [], [], [], [], [], false, null, null, $tickets);
    }
}
