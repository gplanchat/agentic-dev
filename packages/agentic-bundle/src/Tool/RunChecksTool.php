<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
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
     * @param array<string, array{command: string, cwd: string, filter_option: string|null, timeout_seconds: float, description: string, tests?: string}> $layers
     */
    public function __construct(
        private Workspaces $workspaces,
        private array $layers,
    ) {
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

        $argv = array_map(
            static fn (string $token): string => str_replace('{report}', self::REPORT, $token),
            (new StringInput($layer['command']))->getRawTokens(),
        );
        if ('' !== $filter) {
            // Two arguments, not a line: the filter is a value, whatever it contains.
            array_push($argv, (string) $layer['filter_option'], $filter);
        }

        // The interface's own binary when the sandbox can see it — the bare `php` may be older.
        $php = str_starts_with(\PHP_BINARY, '/usr/') ? \PHP_BINARY : 'php';
        [$exitCode, $output, $errors] = $this->workspaces->sandbox()->execute(
            [$php, '-n', '-r', self::RUNNER, '--'],
            $cwd,
            $root,
            $readOnly,
            json_encode(['argv' => $argv, 'report' => self::REPORT], \JSON_THROW_ON_ERROR),
            $layer['timeout_seconds'],
        );

        $heading = \sprintf('%s%s', $name, '' === $filter ? '' : \sprintf(' (filter: %s)', $filter));
        if (null === $exitCode) {
            return \sprintf("ERROR — %s: %s", $heading, $errors);
        }

        /** @var array{exit?: int, output?: string, report?: string|null}|null $run */
        $run = json_decode($output, true);
        if (0 !== $exitCode || !\is_array($run)) {
            return \sprintf('ERROR — %s: the check could not run. %s', $heading, trim($errors));
        }

        $status = (int) ($run['exit'] ?? 1);
        $summary = JUnitReport::summarize((string) ($run['report'] ?? ''), $root);
        if (null === $summary) {
            return \sprintf("ERROR — %s: no JUnit report (exit code %d). The end of the output:\n%s", $heading, $status, $this->tail((string) ($run['output'] ?? ''), $root));
        }

        [$green, $text] = $summary;
        if ($green && 0 !== $status) {
            // No test failed, yet the run did: a warning turned into a failure, a crash after the report…
            return \sprintf("RED — %s: exit code %d although no test failed.\n%s\nThe end of the output:\n%s", $heading, $status, $text, $this->tail((string) ($run['output'] ?? ''), $root));
        }

        return \sprintf("%s — %s\n%s\n\n%s", $green ? 'GREEN' : 'RED', $heading, $text, $green ? self::AFTER_GREEN : self::AFTER_RED);
    }

    /** The next step of the cycle, said where the model reads the verdict. */
    private const AFTER_RED = 'TDD: a test you just wrote must fail on its assertion. If it fails on a missing class or method, add the empty shell and run again; if on a typo, fix the test. Then write the least code that makes it pass, and run the same filter again.';

    private const AFTER_GREEN = 'TDD: green is not done. Review: run the whole layer, the layers above it that the change reaches, and the static ones; refactor with the tests green; never weaken a test to make it pass.';

    /**
     * How to work with these layers — appended to the system prompt, so the agent organises what it
     * writes along the pyramid and the TDD cycle from its first turn, not after its first mistake.
     *
     * @param array<string, array{description: string, tests?: string}> $layers
     */
    public static function method(array $layers): string
    {
        $lines = array_map(
            static fn (string $name, array $layer): string => \sprintf(
                '- `%s`%s%s',
                $name,
                '' === $layer['description'] ? '' : ': '.$layer['description'],
                '' === ($layer['tests'] ?? '') ? '' : '. Its tests: '.$layer['tests'],
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
            '3. REVIEW — run the whole layer, the layers above it that the change reaches, and the static ones. Refactor with the tests green. Never weaken or delete a test to make it pass: fix the code, or say why the test is wrong.',
            'Do not write production code that no failing test asked for.',
        ]);
    }

    private function tail(string $output, string $root): string
    {
        $output = trim(str_replace($root.'/', '', Bubblewrap::plain($output)));

        return \strlen($output) > self::OUTPUT_TAIL_BYTES ? '[…] '.mb_strcut($output, \strlen($output) - self::OUTPUT_TAIL_BYTES) : $output;
    }

    /**
     * Runs the layer's command inside the sandbox, then hands back its exit code, its output and the
     * report it wrote — as JSON on standard output, since the report lives in the sandbox's `/tmp`.
     * Nothing newer than PHP 7.4: it runs under the sandbox's `php`.
     */
    private const RUNNER = <<<'PHP'
        $in = json_decode(stream_get_contents(STDIN), true);
        @unlink($in['report']);
        $process = @proc_open($in['argv'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) { fwrite(STDERR, sprintf('Cannot start "%s".', $in['argv'][0])); exit(1); }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        echo json_encode([
            'exit' => $exit,
            'output' => $output,
            'report' => is_file($in['report']) ? file_get_contents($in['report']) : null,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        PHP;
}
