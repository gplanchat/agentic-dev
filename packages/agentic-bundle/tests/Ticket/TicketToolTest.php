<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Ticket;

use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\AgenticBundle\Project\Project;
use Gplanchat\AgenticBundle\Ticket\Forge;
use Gplanchat\AgenticBundle\Ticket\HeadLabels;
use Gplanchat\AgenticBundle\Ticket\TicketOperation;
use Gplanchat\AgenticBundle\Ticket\TicketTool;
use Gplanchat\AgenticBundle\Ticket\TicketTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class TicketToolTest extends TestCase
{
    private const HERE = ['repository_url' => 'https://api.github.com/repos/acme/app'];

    private const HEAD = ['id' => 100, 'number' => 1, 'title' => 'Tickets', 'state' => 'open', 'body' => "\nWhy.\n", 'labels' => [['name' => 'capability']]];

    public function testWithoutATrackerTheToolsAreNotOffered(): void
    {
        $tool = new TicketTool(TicketOperation::Read, self::project(null), 't');

        self::assertFalse($tool->isOffered());
        self::assertTrue(self::tool(TicketOperation::Read, new RecordingForge())->isOffered());
        self::assertStringContainsString('names no ticket tracker', $tool(['ticket' => 1]));
    }

    public function testOnlyReadingIsHarmless(): void
    {
        foreach (TicketOperation::cases() as $operation) {
            self::assertSame(TicketOperation::Read === $operation ? ToolEffect::Read : ToolEffect::External, $operation->definition()->effect, $operation->value);
            self::assertSame($operation->value, $operation->definition()->name);
        }
    }

    public function testTheSchemasTheModelFills(): void
    {
        $number = static fn (string $what): array => ['type' => 'integer', 'minimum' => 1, 'description' => $what];
        $text = static fn (string $what): array => ['type' => 'string', 'description' => $what];

        self::assertSame(['type' => 'object', 'properties' => ['ticket' => $number('The ticket number.')], 'required' => ['ticket']], TicketOperation::Read->definition()->parameters);
        self::assertSame(['type' => 'object', 'properties' => [
            'family' => ['type' => 'string', 'enum' => ['defect', 'debt', 'groundwork', 'capability', 'investigation']],
            'title' => ['type' => 'string'],
            'body' => $text('What is asked, for whom, and why.'),
            'time_box' => $text('An investigation only, and required there: how long it may take.'),
            'decision' => $text('An investigation only, and required there: the decision it must enable.'),
        ], 'required' => ['family', 'title']], TicketOperation::OpenHead->definition()->parameters);
        self::assertSame(['type' => 'object', 'properties' => [
            'head' => $number('The head ticket it belongs to.'),
            'title' => $text('Starts with the task id, e.g. "3.2 Forgejo adapter".'),
            'body' => $text('What must be true once it is done, and how to prove it.'),
        ], 'required' => ['head', 'title']], TicketOperation::OpenWork->definition()->parameters);
        self::assertSame(['type' => 'object', 'properties' => ['ticket' => $number('The ticket that waits.'), 'blocker' => $number('The ticket to finish first.')], 'required' => ['ticket', 'blocker']], TicketOperation::Block->definition()->parameters);
        self::assertSame(['type' => 'object', 'properties' => ['ticket' => $number('The ticket that waits.'), 'blocker' => $number('The ticket it no longer waits on.')], 'required' => ['ticket', 'blocker']], TicketOperation::Unblock->definition()->parameters);
        self::assertSame(['type' => 'object', 'properties' => ['head' => $number('The head ticket done.')], 'required' => ['head']], TicketOperation::Close->definition()->parameters);
    }

    public function testAHeadIsReadWithItsWorkAndWhatItWaitsOn(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(self::HEAD),
            new JsonMockResponse([self::HERE + ['number' => 2, 'title' => '3.2 Adapter', 'state' => 'closed']]),
            new JsonMockResponse(self::HEAD),
            new JsonMockResponse([self::HERE + ['number' => 3, 'title' => 'Other change', 'state' => 'open']]),
            new JsonMockResponse([]),
        );

        self::assertSame(implode("\n", [
            '#1 Tickets [open] — head, capability',
            'Why.',
            'Work:',
            '  #2 3.2 Adapter [done]',
            'Waits on:',
            '  #3 Other change [open, READY]',
        ]), self::tool(TicketOperation::Read, $forge)(['ticket' => '1']));
    }

    public function testAWorkTicketIsReadAlone(): void
    {
        $work = ['number' => 2, 'title' => 'W', 'state' => 'open', 'body' => ' '];
        $headless = new RecordingForge(new JsonMockResponse($work), new JsonMockResponse($work), new JsonMockResponse([]));
        $emptyHead = new RecordingForge(new JsonMockResponse(self::HEAD), new JsonMockResponse([]), new JsonMockResponse(self::HEAD), new JsonMockResponse([]));

        self::assertSame('#2 W [open]', self::tool(TicketOperation::Read, $headless)(['ticket' => 2]));
        self::assertSame("#1 Tickets [open] — head, capability\nWhy.\nWork: none yet.", self::tool(TicketOperation::Read, $emptyHead)(['ticket' => 1]));
    }

    public function testAHeadIsOpenedOnceWithItsFamily(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse([]), // recent: not opened yet
            new JsonMockResponse(['name' => 'investigation']),
            new JsonMockResponse(['number' => 9, 'title' => 'Sub-issues?', 'state' => 'open', 'labels' => ['investigation']], ['http_code' => 201]),
        );

        $answer = self::tool(TicketOperation::OpenHead, $forge)->inContext(['family' => 'investigation', 'title' => ' Sub-issues? ', 'body' => 'Why.', 'time_box' => 2, 'decision' => 3], new ToolContext('call-9'));

        self::assertSame('Opened the investigation head #9.', $answer);
        self::assertSame('POST https://api.github.com/repos/acme/app/issues {"title":"Sub-issues?","body":"Why.\n\nTime box: 2\nDecision it must enable: 3\n\n\\u003C!-- agentic:call-9 --\\u003E","labels":["investigation"]}', $forge->requests[2]);
    }

    public function testAHeadNeedsAKnownFamilyAndAnInvestigationItsBound(): void
    {
        $tool = self::tool(TicketOperation::OpenHead, new RecordingForge());

        self::assertSame('"family" is one of: defect, debt, groundwork, capability, investigation.', $tool(['family' => 'feature', 'title' => 'T']));
        self::assertSame('"family" is one of: defect, debt, groundwork, capability, investigation.', $tool(['title' => 'T']));
        self::assertStringStartsWith('An investigation says how long', $tool(['family' => 'investigation', 'title' => 'T', 'time_box' => '2 days']));
        self::assertSame('A ticket needs a title.', $tool(['family' => 'debt', 'title' => ' ']));
    }

    public function testAWorkTicketIsOpenedUnderItsHead(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(self::HEAD),
            new JsonMockResponse(['id' => 500, 'number' => 5, 'title' => '3.2 Adapter', 'state' => 'open'], ['http_code' => 201]),
            new JsonMockResponse(['message' => 'Not Found'], ['http_code' => 404]),
            new JsonMockResponse(['id' => 500, 'number' => 5, 'title' => '3.2 Adapter', 'state' => 'open']),
            new JsonMockResponse(self::HEAD, ['http_code' => 201]),
        );

        $answer = self::tool(TicketOperation::OpenWork, $forge)(['head' => 1, 'title' => '3.2 Adapter', 'body' => 'Proof: the adapter test.']);

        self::assertSame('Opened #5 under the head #1. It closes with its code: commit_worktree with closes 5.', $answer);
        self::assertSame('POST https://api.github.com/repos/acme/app/issues {"title":"3.2 Adapter","body":"Proof: the adapter test."}', $forge->requests[1]);
        self::assertSame('POST https://api.github.com/repos/acme/app/issues/1/sub_issues {"sub_issue_id":500}', $forge->requests[4]);
    }

    public function testWaitsAreLinkedUnlessTheyCloseACycle(): void
    {
        $two = ['id' => 200, 'number' => 2, 'title' => 'B', 'state' => 'open'];
        $forge = new RecordingForge(
            new JsonMockResponse($two), new JsonMockResponse([]), // what #2 waits on: nothing
            new JsonMockResponse([]), // #1 does not wait on #2 yet
            new JsonMockResponse($two), new JsonMockResponse($two, ['http_code' => 201]),
            new JsonMockResponse($two), new JsonMockResponse($two),
            new JsonMockResponse($two), new JsonMockResponse([self::HERE + ['number' => 1, 'title' => 'A', 'state' => 'open']]), new JsonMockResponse([]),
        );

        self::assertSame('#1 waits on #2.', self::tool(TicketOperation::Block, $forge)(['ticket' => 1, 'blocker' => 2]));
        self::assertSame('#1 no longer waits on #2.', self::tool(TicketOperation::Unblock, $forge)(['ticket' => 1, 'blocker' => 2]));
        self::assertSame('POST https://api.github.com/repos/acme/app/issues/1/dependencies/blocked_by {"issue_id":200}', $forge->requests[4]);
        self::assertSame('DELETE https://api.github.com/repos/acme/app/issues/1/dependencies/blocked_by/200', $forge->requests[6]);
        self::assertStringStartsWith('#1 cannot wait on #2', self::tool(TicketOperation::Block, $forge)(['ticket' => 1, 'blocker' => 2]));
    }

    public function testOnlyAHeadIsClosedHere(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(self::HEAD), new JsonMockResponse([]), new JsonMockResponse(self::HEAD),
            new JsonMockResponse(['number' => 2, 'title' => 'W', 'state' => 'open']),
        );

        self::assertSame('#1 closed as done.', self::tool(TicketOperation::Close, $forge)(['head' => 1]));
        self::assertStringStartsWith('PATCH https://api.github.com/repos/acme/app/issues/1 ', $forge->requests[2]);
        self::assertStringStartsWith('#2 is a work ticket: it closes with its code', self::tool(TicketOperation::Close, $forge)(['head' => 2]));
    }

    public function testAMalformedNumberIsHandedBackToTheModel(): void
    {
        $tool = self::tool(TicketOperation::Close, new RecordingForge());

        self::assertSame('"head" must be a ticket number.', $tool(['head' => 'twelve']));
        self::assertSame('"head" must be a ticket number.', $tool(['head' => 0]));
    }

    public function testTheTrackerSpeaksToItsForgeWithItsLabels(): void
    {
        $answer = new JsonMockResponse(['number' => 1, 'title' => 'T', 'state' => 'open', 'labels' => [['name' => 'dette']]]);
        $forge = new RecordingForge($answer, clone $answer, clone $answer);
        $labels = new HeadLabels(['debt' => 'dette']);

        $github = (new TicketTracker(Forge::GitHub, 'acme/app', null, $labels))->tickets($forge->http, 't')->get(1);
        (new TicketTracker(Forge::GitHub, 'acme/app', 'https://github.example.test/api/v3'))->tickets($forge->http, 't')->get(1);
        $forgejo = (new TicketTracker(Forge::Forgejo, 'acme/app', 'https://codeberg.org', $labels))->tickets($forge->http, 't')->get(1);

        self::assertSame([
            'GET https://api.github.com/repos/acme/app/issues/1',
            'GET https://github.example.test/api/v3/repos/acme/app/issues/1',
            'GET https://codeberg.org/api/v1/repos/acme/app/issues/1',
        ], $forge->requests);
        self::assertSame('debt', $github->head?->value);
        self::assertSame('debt', $forgejo->head?->value);
    }

    public function testAForgejoTrackerNeedsItsUrl(): void
    {
        foreach ([null, ''] as $url) {
            try {
                new TicketTracker(Forge::Forgejo, 'acme/app', $url);
                self::fail('A Forgejo tracker without its url.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('A Forgejo ticket tracker needs the url of the forge.', $e->getMessage());
            }
        }
    }

    public function testTheRepositoryIsOwnerSlashName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TicketTracker(Forge::GitHub, 'acme/app/../other');
    }

    private static function tool(TicketOperation $operation, RecordingForge $forge): TicketTool
    {
        return new TicketTool($operation, self::project(new TicketTracker(Forge::GitHub, 'acme/app')), 't', $forge->http);
    }

    private static function project(?TicketTracker $tickets): Project
    {
        return new Project('/p', [], [], [], [], [], false, null, null, $tickets);
    }
}
