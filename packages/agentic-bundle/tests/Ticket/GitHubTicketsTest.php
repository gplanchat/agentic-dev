<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Ticket;

use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;
use Gplanchat\Agentic\Domain\Ticket\TicketMark;
use Gplanchat\Agentic\Domain\Ticket\TicketState;
use Gplanchat\AgenticBundle\Ticket\GitHubTickets;
use Gplanchat\AgenticBundle\Ticket\HeadLabels;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubTicketsTest extends TestCase
{
    public function testAnIssueClosedAsNotPlannedIsAbandonedNotDone(): void
    {
        $forge = new RecordingForge(new JsonMockResponse([
            ['repository_url' => 'https://api.github.com/repos/acme/app', 'number' => 2, 'title' => 'Done', 'state' => 'closed', 'state_reason' => 'completed'],
            ['repository_url' => 'https://api.github.com/repos/acme/app', 'number' => 3, 'title' => 'Given up', 'state' => 'closed', 'state_reason' => 'not_planned'],
            ['repository_url' => 'https://api.github.com/repos/acme/app', 'number' => 4, 'title' => 'Duplicate', 'state' => 'closed', 'state_reason' => 'duplicate'],
            ['repository_url' => 'https://api.github.com/repos/acme/app', 'number' => 5, 'title' => 'Open', 'state' => 'open', 'state_reason' => null],
        ]));

        $blockers = self::tickets($forge)->blockers(1);

        self::assertSame(
            [TicketState::Done, TicketState::Abandoned, TicketState::Abandoned, TicketState::Open],
            array_map(static fn (Ticket $ticket): TicketState => $ticket->state, $blockers),
        );
        self::assertSame(['GET https://api.github.com/repos/acme/app/issues/1/dependencies/blocked_by?per_page=100'], $forge->requests);
    }

    public function testABlockerOfAnotherRepositoryIsRefusedNotReadAsOurs(): void
    {
        $this->expectExceptionMessage('#1 is blocked by an issue of another repository ("https:\\/\\/api.github.com\\/repos\\/other\\/app")');

        self::tickets(new RecordingForge(new JsonMockResponse([
            ['repository_url' => 'https://api.github.com/repos/other/app', 'number' => 5, 'title' => 'T', 'state' => 'closed'],
        ])))->blockers(1);
    }

    public function testABlockerWhoseRepositoryIsUnsaidIsRefused(): void
    {
        $this->expectException(\DomainException::class);

        self::tickets(new RecordingForge(new JsonMockResponse([['number' => 5, 'title' => 'T', 'state' => 'closed']])))->blockers(1);
    }

    public function testTheRepositoryIsComparedWholeAndCaseInsensitively(): void
    {
        $blockers = self::tickets(new RecordingForge(new JsonMockResponse([
            ['repository_url' => 'https://api.github.com/repos/ACME/App', 'number' => 5, 'title' => 'T', 'state' => 'open'],
        ])))->blockers(1);

        self::assertCount(1, $blockers);
        $configuredInCapitals = new GitHubTickets(new RecordingForge(new JsonMockResponse([
            ['repository_url' => 'https://api.github.com/repos/acme/app', 'number' => 5, 'title' => 'T', 'state' => 'open'],
        ]))->http, 'Acme/App', 't');
        self::assertCount(1, $configuredInCapitals->blockers(1));
        $this->expectException(\DomainException::class);
        self::tickets(new RecordingForge(new JsonMockResponse([
            ['repository_url' => 'https://api.github.com/repos/xacme/app', 'number' => 5, 'title' => 'T', 'state' => 'open'],
        ])))->blockers(1);
    }

    public function testAHeadIsReadByItsFamilysLabelInTheProjectsVocabulary(): void
    {
        $issue = ['number' => 3, 'title' => 'T', 'state' => 'open', 'labels' => [['name' => 'p1'], ['name' => 'Dette technique']]];
        $forge = new RecordingForge(new JsonMockResponse($issue), new JsonMockResponse(['labels' => ['capability']] + $issue), new JsonMockResponse(['labels' => [['name' => 'p1']]] + $issue), new JsonMockResponse(['labels' => 'debt'] + $issue));

        $tickets = new GitHubTickets($forge->http, 'acme/app', 't', new HeadLabels(['debt' => 'dette technique']));

        self::assertSame(HeadKind::Debt, $tickets->get(3)->head);
        self::assertSame(HeadKind::Capability, $tickets->get(3)->head, 'A family left out keeps its own name; a label may come as a bare name.');
        self::assertNull($tickets->get(3)->head);
        self::assertNull($tickets->get(3)->head, 'Labels that are not a list are none.');
    }

    public function testAHeadsWorkIsItsSubIssues(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse([['repository_url' => 'https://api.github.com/repos/acme/app', 'number' => 4, 'title' => 'W', 'state' => 'open']]),
            new JsonMockResponse([['repository_url' => 'https://api.github.com/repos/acme/lib', 'number' => 5, 'title' => 'W', 'state' => 'open']]),
        );
        $tickets = self::tickets($forge);

        self::assertEquals([new Ticket(4, 'W', TicketState::Open)], $tickets->children(1));
        self::assertSame('GET https://api.github.com/repos/acme/app/issues/1/sub_issues?per_page=100', $forge->requests[0]);
        try {
            $tickets->children(1);
            self::fail('A sub-issue of another repository was read as ours.');
        } catch (\DomainException $e) {
            self::assertSame('#1 is the head of an issue of another repository ("https:\\/\\/api.github.com\\/repos\\/acme\\/lib"): a human must handle that link.', $e->getMessage());
        }
    }

    public function testAHeadIsOpenedWithItsFamilysLabelOnlyIfTheForgeHasIt(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(['name' => 'dette technique']),
            new JsonMockResponse(['number' => 9, 'title' => 'H', 'state' => 'open', 'labels' => [['name' => 'dette technique']]], ['http_code' => 201]),
            new JsonMockResponse(['message' => 'Not Found'], ['http_code' => 404]),
        );
        $tickets = new GitHubTickets($forge->http, 'acme/app', 't', new HeadLabels(['debt' => 'dette technique']));

        self::assertSame(HeadKind::Debt, $tickets->openHead(HeadKind::Debt, 'H', 'B')->head);
        self::assertSame([
            'GET https://api.github.com/repos/acme/app/labels/dette%20technique',
            'POST https://api.github.com/repos/acme/app/issues {"title":"H","body":"B","labels":["dette technique"]}',
        ], $forge->requests);

        $this->expectExceptionMessage('The forge has no label "defect" for the defect family: create it, or name another in tickets.labels.');
        $tickets->openHead(HeadKind::Defect, 'H', 'B');
    }

    public function testAWorkTicketIsOpenedPlainThenAdoptedAsASubIssue(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(['id' => 400, 'number' => 4, 'title' => 'W', 'state' => 'open'], ['http_code' => 201]),
            new JsonMockResponse(['message' => 'Not Found'], ['http_code' => 404]),
            new JsonMockResponse(['id' => 400, 'number' => 4, 'title' => 'W', 'state' => 'open']),
            new JsonMockResponse(['id' => 100, 'number' => 1, 'title' => 'H', 'state' => 'open'], ['http_code' => 201]),
            new JsonMockResponse(['id' => 100, 'number' => 1, 'title' => 'H', 'state' => 'open']),
            new JsonMockResponse(['id' => 200, 'number' => 2, 'title' => 'H2', 'state' => 'open']),
        );
        $tickets = self::tickets($forge);

        $tickets->openWork(1, 'W', 'B');
        $tickets->adopt(1, 4);
        $tickets->adopt(1, 4);

        self::assertSame([
            'POST https://api.github.com/repos/acme/app/issues {"title":"W","body":"B"}',
            'GET https://api.github.com/repos/acme/app/issues/4/parent',
            'GET https://api.github.com/repos/acme/app/issues/4',
            'POST https://api.github.com/repos/acme/app/issues/1/sub_issues {"sub_issue_id":400}',
            'GET https://api.github.com/repos/acme/app/issues/4/parent',
        ], $forge->requests, 'Adopted once, never with replace_parent.');

        $this->expectExceptionMessage('#4 hangs under #2 already: a work ticket has one head.');
        $tickets->adopt(1, 4);
    }

    public function testTheOpenTicketsCarryTheirMarks(): void
    {
        $forge = new RecordingForge(new JsonMockResponse([
            ['number' => 8, 'title' => 'A PR', 'state' => 'open', 'pull_request' => ['url' => 'x']],
            ['number' => 7, 'title' => 'T', 'state' => 'open', 'labels' => [['name' => 'p1'], ['name' => 'taken'], ['name' => 'waits:author']]],
        ]));

        self::assertEquals([new Ticket(7, 'T', TicketState::Open, '', null, [TicketMark::Taken, TicketMark::WaitsForAuthor])], self::tickets($forge)->listOpen());
        self::assertSame(['GET https://api.github.com/repos/acme/app/issues?state=open&sort=created&direction=desc&per_page=100'], $forge->requests);
    }

    public function testAMarkIsALabelThatMustExist(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(['name' => 'taken']),
            new JsonMockResponse([['name' => 'taken']]),
            new JsonMockResponse([]),
            new JsonMockResponse(['message' => 'Not Found'], ['http_code' => 404]),
        );
        $tickets = self::tickets($forge);

        $tickets->mark(4, TicketMark::Taken);
        $tickets->unmark(4, TicketMark::WaitsForAuthor);

        self::assertSame([
            'GET https://api.github.com/repos/acme/app/labels/taken',
            'POST https://api.github.com/repos/acme/app/issues/4/labels {"labels":["taken"]}',
            'DELETE https://api.github.com/repos/acme/app/issues/4/labels/waits%3Aauthor',
        ], $forge->requests);
        $this->expectExceptionMessage('The forge has no label "waits:measure" to mark tickets with: create it.');
        $tickets->mark(4, TicketMark::WaitsForMeasure);
    }

    public function testCommentsAreReadAndPosted(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse([['body' => 'First'], ['body' => null], 'noise', ['body' => 'Second']]),
            new JsonMockResponse(['id' => 9, 'body' => 'Third'], ['http_code' => 201]),
        );
        $tickets = self::tickets($forge);

        self::assertSame(['First', 'Second'], $tickets->comments(4));
        $tickets->comment(4, 'Third');

        self::assertSame([
            'GET https://api.github.com/repos/acme/app/issues/4/comments?per_page=100',
            'POST https://api.github.com/repos/acme/app/issues/4/comments {"body":"Third"}',
        ], $forge->requests);
    }

    public function testPullRequestsAreNotTickets(): void
    {
        $forge = new RecordingForge(new JsonMockResponse([
            ['number' => 8, 'title' => 'A PR', 'state' => 'open', 'pull_request' => ['url' => 'x']],
            ['number' => 7, 'title' => 'An issue', 'state' => 'open', 'body' => 'b'],
        ]));

        $recent = self::tickets($forge)->recent();

        self::assertEquals([new Ticket(7, 'An issue', TicketState::Open, 'b')], $recent);
        self::assertSame(['GET https://api.github.com/repos/acme/app/issues?state=all&sort=created&direction=desc&per_page=50'], $forge->requests);
    }

    public function testAnIssueIsReadByItsNumber(): void
    {
        $forge = new RecordingForge(new JsonMockResponse(['number' => 3, 'title' => 'T', 'state' => 'open', 'body' => null]));

        self::assertEquals(new Ticket(3, 'T', TicketState::Open, ''), self::tickets($forge)->get(3));
        self::assertSame(['GET https://api.github.com/repos/acme/app/issues/3'], $forge->requests);
        self::assertSame(['Accept: application/vnd.github+json', 'Authorization: Bearer s3cret', 'X-GitHub-Api-Version: 2022-11-28'], $forge->headers[0]);
    }

    public function testAnIssueWithoutItsNumberIsRefused(): void
    {
        $this->expectExceptionMessage('An issue without its number or title.');

        self::tickets(new RecordingForge(new JsonMockResponse(['title' => 'T', 'state' => 'open'])))->get(3);
    }

    public function testAnIssueWithoutItsIdCannotBeLinked(): void
    {
        $this->expectExceptionMessage('GitHub gave no id for the issue #2.');

        self::tickets(new RecordingForge(new JsonMockResponse(['number' => 2, 'title' => 'P', 'state' => 'open'])))->block(1, 2);
    }

    public function testAnEmptyAnswerIsNotAnError(): void
    {
        $forge = new RecordingForge(new MockResponse('', ['http_code' => 204]));

        self::tickets($forge)->close(1);

        self::assertCount(1, $forge->requests);
    }

    public function testBlockingSendsTheBlockersInternalIdNotItsNumber(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(['id' => 987654, 'number' => 2, 'title' => 'P', 'state' => 'open']),
            new JsonMockResponse(['id' => 111, 'number' => 1, 'title' => 'G', 'state' => 'open'], ['http_code' => 201]),
        );

        self::tickets($forge)->block(1, 2);

        self::assertSame([
            'GET https://api.github.com/repos/acme/app/issues/2',
            'POST https://api.github.com/repos/acme/app/issues/1/dependencies/blocked_by {"issue_id":987654}',
        ], $forge->requests);
    }

    public function testUnblockingNamesTheBlockersInternalId(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(['id' => 987654, 'number' => 2, 'title' => 'P', 'state' => 'open']),
            new JsonMockResponse(['id' => 111, 'number' => 1, 'title' => 'G', 'state' => 'open']),
        );

        self::tickets($forge)->unblock(1, 2);

        self::assertSame('DELETE https://api.github.com/repos/acme/app/issues/1/dependencies/blocked_by/987654', $forge->requests[1]);
    }

    public function testClosingSaysCompletedAndAuthenticates(): void
    {
        $forge = new RecordingForge(new JsonMockResponse(['number' => 1, 'title' => 'T', 'state' => 'closed']));

        self::tickets($forge)->close(1);

        self::assertSame(['PATCH https://api.github.com/repos/acme/app/issues/1 {"state":"closed","state_reason":"completed"}'], $forge->requests);
        self::assertContains('Authorization: Bearer s3cret', $forge->headers[0]);
    }

    public function testAnUnknownStateIsRefused(): void
    {
        $this->expectExceptionMessage('Unknown GitHub issue state: "merged".');

        self::tickets(new RecordingForge(new JsonMockResponse(['number' => 1, 'title' => 'T', 'state' => 'merged'])))->get(1);
    }

    public function testAForgeRefusalThrows(): void
    {
        $this->expectException(\Throwable::class);

        self::tickets(new RecordingForge(new JsonMockResponse(['message' => 'Not Found'], ['http_code' => 404])))->get(1);
    }

    private static function tickets(RecordingForge $forge): GitHubTickets
    {
        return new GitHubTickets($forge->http, 'acme/app', 's3cret');
    }
}
