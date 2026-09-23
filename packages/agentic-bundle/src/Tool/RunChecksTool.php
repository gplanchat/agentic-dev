<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\AgenticBundle\Check\InfectionReport;
use Gplanchat\AgenticBundle\Check\JUnitReport;
use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Symfony\Component\Console\Input\StringInput;

/**
 * Runs one layer of the project's checks — static, unit, functional, integration, e2e: whatever the
 * configuration names — in the sandbox, and hands back a verdict read from its JUnit report.
 *
 * Why not `run_command`: the model gets `RED — unit` and the failures with their file and line, in
 * a few hundred bytes, instead of kilobytes of output to read; and its arguments are closed — a layer
 * among those configured, a filter —, so it needs no allowlist: `--bootstrap` or `--configuration`
 * cannot slip in through them.
 *
 * Classed `write`: tests run code, and code can write — in the workspace, inside the sandbox. It
 * asks in `standard`, passes in `edition` and `auto`.
 */
final readonly class RunChecksTool implements WorkspaceTool
{
    public const TOOL = 'run_checks';

    /** Where the command writes its JUnit report: the sandbox's own `/tmp`, gone when it exits. */
    public const REPORT = '/tmp/agentic-checks.xml';

    /** What the model reads of the raw output when the report says too little. */
    private const OUTPUT_TAIL_BYTES = 3_000;

    /**
     * @param array<string, array{command: string|list<string>, cwd: string, filter_option: string|null, timeout_seconds: float, description: string, tests?: string, review?: list<string>}> $layers
     *
     * @throws \InvalidArgumentException when a layer's review names a layer that does not exist
     */
    public function __construct(
        private Workspaces $workspaces,
        private array $layers,
    ) {
        foreach ($layers as $name => $layer) {
            // A typo here would send the model after a layer run_checks refuses, at the worst moment.
            if ([] !== $unknown = array_diff($layer['review'] ?? [], array_keys($layers))) {
                throw new \InvalidArgumentException(\sprintf('The review of the check layer "%s" names unknown layers: %s.', $name, implode(', ', $unknown)));
            }
        }
    }

    public function definition(): ToolDefinition
    {
        $layers = array_map(
            static fn (string $name, array $layer): string => '' === $layer['description'] ? $name : \sprintf('%s (%s)', $name, $layer['description']),
            array_keys($this->layers),
            $this->layers,
        );

        return new ToolDefinition(
            self::TOOL,
            'Runs one layer of the project checks in the sandbox and returns the verdict — GREEN or '
            .'RED —, the counts, and each failing test with its file, line and message. Layers: '
            .implode('; ', $layers).'. Prefer it to run_command for tests.',
            ToolEffect::Write,
            [
                'type' => 'object',
                'properties' => [
                    'layer' => ['type' => 'string', 'enum' => array_keys($this->layers), 'description' => 'The layer to run.'],
                    'filter' => ['type' => 'string', 'description' => 'Only the matching tests, for example a test class or method name. Omit to run the whole layer.'],
                ],
                'required' => ['layer'],
            ],
        );
    }

    public function __invoke(array $arguments): string
    {
        return $this->inWorkspace($arguments, null);
    }

    public function inWorkspace(array $arguments, ?string $workspace): string
    {
        $name = (string) ($arguments['layer'] ?? '');
        $layer = $this->layers[$name] ?? null;
        if (null === $layer) {
            return \sprintf('Unknown layer "%s". Layers: %s.', $name, implode(', ', array_keys($this->layers)));
        }

        $filter = trim((string) ($arguments['filter'] ?? ''));
        if ('' !== $filter && null === $layer['filter_option']) {
            return \sprintf('The layer "%s" takes no filter: run it whole.', $name);
        }

        // Thrown if git cannot create the worktree: an infrastructure failure, retried like one.
        [$root, $readOnly] = $this->workspaces->open($workspace);
        $cwd = realpath($root.'/'.ltrim($layer['cwd'], '/'));
        if (false === $cwd || ($cwd !== $root && !str_starts_with($cwd, $root.'/'))) {
            return \sprintf('The layer "%s" is misconfigured: its directory "%s" is not in the workspace.', $name, $layer['cwd']);
        }

        $runs = [];
        foreach ((array) $layer['command'] as $command) {
            $tokens = (new StringInput($command))->getRawTokens();
            $argv = array_map(static fn (string $token): string => str_replace('{report}', self::REPORT, $token), $tokens);
            if ('' !== $filter) {
                // Two arguments, not a line: the filter is a value, whatever it contains.
                array_push($argv, (string) $layer['filter_option'], $filter);
            }
            // Without {report}, the command prints its report — PHPStan's `--error-format=junit` does.
            $runs[] = ['argv' => $argv, 'report' => [] === preg_grep('/\{report\}/', $tokens) ? null : self::REPORT];
        }

        // The interface's own binary when the sandbox can see it — the bare `php` may be older.
        $php = str_starts_with(\PHP_BINARY, '/usr/') ? \PHP_BINARY : 'php';
        [$exitCode, $output, $errors] = $this->workspaces->sandbox()->execute(
            [$php, '-n', '-r', self::RUNNER, '--'],
            $cwd,
            $root,
            $readOnly,
            json_encode(['runs' => $runs], \JSON_THROW_ON_ERROR),
            $layer['timeout_seconds'],
        );

        $heading = \sprintf('%s%s', $name, '' === $filter ? '' : \sprintf(' (filter: %s)', $filter));
        if (null === $exitCode) {
            return \sprintf("ERROR — %s: %s", $heading, $errors);
        }

        /** @var list<array{exit?: int, output?: string, report?: string|null}>|null $results */
        $results = json_decode($output, true);
        if (0 !== $exitCode || !\is_array($results)) {
            return \sprintf('ERROR — %s: the check could not run. %s', $heading, trim($errors));
        }

        $verdicts = [];
        foreach ($runs as $i => $run) {
            $verdicts[] = $this->verdict($results[$i] ?? [], $root);
        }
        $hint = static fn (string $state): string => match ($state) {
            'GREEN' => "\n\n".self::afterGreen($name, $layer['review'] ?? [], '' !== $filter),
            'RED' => "\n\n".self::AFTER_RED,
            default => '',
        };

        if (1 === \count($verdicts)) {
            [$state, $separator, $text, $hinted] = $verdicts[0];

            return \sprintf('%s — %s%s%s%s', $state, $heading, $separator, $text, $hinted ? $hint($state) : '');
        }

        // Several commands, one verdict: the worst of them, then each one's own.
        $states = array_column($verdicts, 0);
        $state = \in_array('ERROR', $states, true) ? 'ERROR' : (\in_array('RED', $states, true) ? 'RED' : 'GREEN');
        $sections = array_map(
            static fn (array $run, array $verdict): string => \sprintf('▸ %s — %s%s%s', self::label($run['argv']), $verdict[0], $verdict[1], $verdict[2]),
            $runs,
            $verdicts,
        );

        return \sprintf("%s — %s\n%s%s", $state, $heading, implode("\n\n", $sections), 'ERROR' === $state ? '' : $hint($state));
    }

    /**
     * One command's verdict, read from its report — or from its output, when the report says too little.
     *
     * @param array{exit?: int, output?: string, report?: string|null} $result
     *
     * @return array{0: string, 1: string, 2: string, 3: bool} state, separator, text, whether the TDD step follows
     */
    private function verdict(array $result, string $root): array
    {
        $status = (int) ($result['exit'] ?? 1);
        $report = (string) ($result['report'] ?? '');

        // Infection's JSON log: a surviving mutant asks for a sharper test, not for other code.
        if (str_starts_with(ltrim($report), '{') && null !== $mutation = InfectionReport::summarize($report, $root)) {
            [$green, $text] = $mutation;

            return [$green ? 'GREEN' : 'RED', "\n", $green ? $text : $text."\n\n".InfectionReport::ADVICE, $green];
        }

        $summary = JUnitReport::summarize($report, $root);
        if (null === $summary) {
            return ['ERROR', ': ', \sprintf("no JUnit report (exit code %d). The end of the output:\n%s", $status, $this->tail((string) ($result['output'] ?? ''), $root)), false];
        }

        [$green, $text] = $summary;
        if ($green && 0 !== $status) {
            // No test failed, yet the run did: a warning turned into a failure, a crash after the report…
            return ['RED', ': ', \sprintf("exit code %d although no test failed.\n%s\nThe end of the output:\n%s", $status, $text, $this->tail((string) ($result['output'] ?? ''), $root)), false];
        }

        return [$green ? 'GREEN' : 'RED', "\n", $text, true];
    }

    /**
     * The tool a command runs, for the model to tell the sections apart: `phpunit`, `phpstan`…
     *
     * @param list<string> $argv
     */
    private static function label(array $argv): string
    {
        foreach ($argv as $token) {
            // Past the interpreter, `env` and its assignments, and the options.
            if (!preg_match('/^(php[0-9.]*|env)$/', basename($token)) && !str_starts_with($token, '-') && !str_contains($token, '=')) {
                return basename($token);
            }
        }

        return $argv[0] ?? '?';
    }

    /**
     * The review, named layer by layer: left to the model, "the layers the change reaches" was the
     * step it skipped.
     *
     * @param list<string> $review
     */
    private static function afterGreen(string $layer, array $review, bool $filtered): string
    {
        $runs = [...($filtered ? [\sprintf('`%s` without a filter', $layer)] : []), ...array_map(static fn (string $name): string => \sprintf('`%s`', $name), $review)];

        return 'TDD: green is not done. Review: '
            .([] === $runs ? '' : 'run '.implode(', then ', $runs).'; ')
            .'refactor with the tests green; never weaken a test to make it pass.';
    }

    /** The next step of the cycle, said where the model reads the verdict. */
    private const AFTER_RED = 'TDD: a test you just wrote must fail on its assertion — on a missing class or method, add the empty shell and run again; on a typo, fix the test —, then write the least code that makes it pass and run the same filter again. Anything else red — a test that passed before, a static finding — is a regression: fix the code, not the check.';

    /**
     * How to work with these layers — appended to the system prompt, so the agent organises what it
     * writes along the pyramid and the TDD cycle from its first turn, not after its first mistake.
     *
     * @param array<string, array{description: string, tests?: string, review?: list<string>}> $layers
     */
    public static function method(array $layers): string
    {
        $lines = array_map(
            static fn (string $name, array $layer): string => \sprintf(
                '- `%s`%s%s%s',
                $name,
                '' === $layer['description'] ? '' : ': '.$layer['description'],
                '' === ($layer['tests'] ?? '') ? '' : '. Its tests: '.$layer['tests'],
                [] === ($layer['review'] ?? []) ? '' : '. Once green, review with: '.implode(', ', $layer['review']),
            ),
            array_keys($layers),
            $layers,
        );

        return implode("\n", [
            '# How to change code here: the test pyramid and TDD',
            '',
            'The project checks are layers of a test pyramid, run with the run_checks tool:',
            ...$lines,
            '',
            'Place each test in the lowest layer that can show the behaviour: static rules first, a unit test for the logic of one class (in memory, no kernel, no process, no disk), a functional test for behaviour through the entry point with every input and output in memory, an integration test only for real outside things — processes, files, network —, end to end for the user\'s path. Put the file where its layer\'s tests live: a test in no layer never runs. If no existing place fits, declare the new one in that layer\'s suite (phpunit.dist.xml, for PHPUnit) in the same change.',
            '',
            'Work in cycles, one behaviour at a time:',
            '1. RED — write the test first. Run its layer with a filter on it: it must fail on its assertion (a missing class or method: add the empty shell, run again).',
            '2. GREEN — write the least code that makes it pass. Run the same filter until it is green.',
            '3. REVIEW — run the whole layer, then the layers its review names (each layer above lists them; the green verdict repeats them). Refactor with the tests green. Never weaken or delete a test to make it pass: fix the code, or say why the test is wrong. A mutation layer, if the review names one, checks the tests themselves: a mutant that survives on the lines you changed asks for a sharper assertion, not for other code.',
            'Do not write production code that no failing test asked for.',
        ]);
    }

    private function tail(string $output, string $root): string
    {
        $output = trim(str_replace($root.'/', '', Bubblewrap::plain($output)));

        return \strlen($output) > self::OUTPUT_TAIL_BYTES ? '[…] '.mb_strcut($output, \strlen($output) - self::OUTPUT_TAIL_BYTES) : $output;
    }

    /**
     * Runs the layer's commands in turn inside the sandbox, then hands back each one's exit code, output
     * and report — as JSON on standard output, since the reports live in the sandbox's `/tmp`.
     * Nothing newer than PHP 7.4: it runs under the sandbox's `php`.
     */
    private const RUNNER = <<<'PHP'
        $in = json_decode(stream_get_contents(STDIN), true);
        $results = [];
        foreach ($in['runs'] as $run) {
            if (null !== $run['report']) { @unlink($run['report']); }
            // Errors apart: when the report is the standard output, nothing else may land in it.
            $errors = '/tmp/agentic-checks.err';
            $process = @proc_open($run['argv'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes);
            if (!is_resource($process)) { $results[] = ['exit' => 127, 'output' => sprintf('Cannot start "%s".', $run['argv'][0]), 'report' => null]; continue; }
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exit = proc_close($process);
            $results[] = [
                'exit' => $exit,
                'output' => $output.@file_get_contents($errors),
                'report' => null === $run['report'] ? $output : (is_file($run['report']) ? file_get_contents($run['report']) : null),
            ];
        }
        echo json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE);
        PHP;
}
