<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Ticket;

use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;
use Gplanchat\Agentic\Domain\Ticket\TicketState;
use Gplanchat\AgenticBundle\Ticket\ForgejoTickets;
use Gplanchat\AgenticBundle\Ticket\HeadLabels;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ForgejoTicketsTest extends TestCase
{
    private const HERE = ['repository' => ['full_name' => 'acme/app']];

    public function testAHeadsDependenciesAreItsWorkOrWhatItWaitsOnByTheHeadTheyName(): void
    {
        $dependencies = [
            self::HERE + ['number' => 4, 'title' => 'A PR', 'state' => 'open', 'pull_request' => ['merged' => false]],
            self::HERE + ['number' => 3, 'title' => 'Other change', 'state' => 'open', 'body' => 'Head: #9', 'pull_request' => null],
            self::HERE + ['number' => 2, 'title' => 'Work', 'state' => 'closed', 'body' => "Do it.\n\nHead: #1", 'pull_request' => null],
            self::HERE + ['number' => 5, 'title' => 'Unrelated', 'state' => 'open', 'pull_request' => null],
        ];
        $forge = new RecordingForge(new JsonMockResponse($dependencies), new JsonMockResponse($dependencies));
        $tickets = self::tickets($forge);

        self::assertEquals([new Ticket(2, 'Work', TicketState::Done, "Do it.\n\nHead: #1")], $tickets->children(1));
        self::assertEquals([new Ticket(3, 'Other change', TicketState::Open, 'Head: #9'), new Ticket(5, 'Unrelated', TicketState::Open)], $tickets->blockers(1));
        self::assertSame(['GET https://forge.test/api/v1/repos/acme/app/issues/1/dependencies?limit=100', 'GET https://forge.test/api/v1/repos/acme/app/issues/1/dependencies?limit=100'], $forge->requests);
        self::assertSame(['Accept: application/json', 'Authorization: token s3cret'], $forge->headers[0]);
    }

    public function testALinkToAnotherRepositoryIsRefusedNotReadAsOurs(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse([['repository' => ['full_name' => 'other/app'], 'number' => 5, 'title' => 'T', 'state' => 'closed']]),
            new JsonMockResponse([['number' => 5, 'title' => 'T', 'state' => 'closed']]),
            new JsonMockResponse([['repository' => ['full_name' => 'ACME/App'], 'number' => 5, 'title' => 'T', 'state' => 'open']]),
        );
        $tickets = self::tickets($forge);

        foreach (['"other\\/app"', 'null'] as $named) {
            try {
                $tickets->blockers(1);
                self::fail('A ticket of another repository was read as ours.');
            } catch (\DomainException $e) {
                self::assertSame(\sprintf('#1 is linked to an issue of another repository (%s): a human must handle that link.', $named), $e->getMessage());
            }
        }
        self::assertCount(1, $tickets->blockers(1), 'The same repository, whatever its case.');
    }

    public function testATicketNamingTwoHeadsIsRefused(): void
    {
        $forge = new RecordingForge(new JsonMockResponse([self::HERE + ['number' => 2, 'title' => 'W', 'state' => 'open', 'body' => "Head: #1\nHead: #1\nHead: #7 "]]));

        $this->expectExceptionMessage('#2 names two heads (#1, #7): a work ticket has one — a human must settle it.');

        self::tickets($forge)->children(1);
    }

    public function testAHeadNamedInPassingIsNotAHead(): void
    {
        $forge = new RecordingForge(new JsonMockResponse([self::HERE + ['number' => 2, 'title' => 'W', 'state' => 'open', 'body' => 'See Head: #1 for context.']]));

        self::assertSame([], self::tickets($forge)->children(1));
    }

    public function testAHeadIsOpenedWithItsFamilysLabelId(): void
    {
        $labels = new JsonMockResponse([['id' => 7, 'name' => 'p1'], ['id' => 8, 'name' => ['capacité']], ['id' => 12, 'name' => 'Capacité'], 'noise']);
        $forge = new RecordingForge(
            $labels,
            new JsonMockResponse(['number' => 9, 'title' => 'H', 'state' => 'open', 'body' => 'B', 'labels' => [['id' => 12, 'name' => 'Capacité']]], ['http_code' => 201]),
            clone $labels,
        );
        $tickets = new ForgejoTickets($forge->http, 'https://forge.test', 'acme/app', 't', new HeadLabels(['capability' => 'capacité']));

        self::assertEquals(new Ticket(9, 'H', TicketState::Open, 'B', HeadKind::Capability), $tickets->openHead(HeadKind::Capability, 'H', 'B'));
        self::assertSame([
            'GET https://forge.test/api/v1/repos/acme/app/labels?limit=100',
            'POST https://forge.test/api/v1/repos/acme/app/issues {"title":"H","body":"B","labels":[12]}',
        ], $forge->requests);

        $this->expectExceptionMessage('The repository has no label "debt" for the debt family: create it, or name another in tickets.labels.');
        $tickets->openHead(HeadKind::Debt, 'H', 'B');
    }

    public function testAWorkTicketNamesItsHeadAndTheHeadWaitsOnIt(): void
    {
        $work = ['number' => 4, 'title' => 'W', 'state' => 'open', 'body' => "B\n\nHead: #1"];
        $forge = new RecordingForge(
            new JsonMockResponse($work, ['http_code' => 201]),
            new JsonMockResponse($work),
            new JsonMockResponse([]),
            new JsonMockResponse(['number' => 1, 'title' => 'H', 'state' => 'open'], ['http_code' => 201]),
            new JsonMockResponse($work),
            new JsonMockResponse([self::HERE + $work]),
        );
        $tickets = self::tickets($forge);

        $tickets->openWork(1, 'W', "B\n");
        $tickets->adopt(1, 4);
        $tickets->adopt(1, 4);

        self::assertSame([
            'POST https://forge.test/api/v1/repos/acme/app/issues {"title":"W","body":"B\n\nHead: #1"}',
            'GET https://forge.test/api/v1/repos/acme/app/issues/4',
            'GET https://forge.test/api/v1/repos/acme/app/issues/1/dependencies?limit=100',
            'POST https://forge.test/api/v1/repos/acme/app/issues/1/dependencies {"owner":"acme","repo":"app","index":4}',
            'GET https://forge.test/api/v1/repos/acme/app/issues/4',
            'GET https://forge.test/api/v1/repos/acme/app/issues/1/dependencies?limit=100',
        ], $forge->requests, 'Linked once.');
    }

    public function testATicketUnderAnotherHeadOrUnderNoneIsNotAdopted(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(['number' => 4, 'title' => 'W', 'state' => 'open', 'body' => 'Head: #2']),
            new JsonMockResponse(['number' => 4, 'title' => 'W', 'state' => 'open', 'body' => 'Loose.']),
        );
        $tickets = self::tickets($forge);

        foreach (['#4 hangs under #2 already: a work ticket has one head.', '#4 names no head: add "Head: #1" to its body first.'] as $refusal) {
            try {
                $tickets->adopt(1, 4);
                self::fail('Adopted: '.$refusal);
            } catch (\DomainException $e) {
                self::assertSame($refusal, $e->getMessage());
            }
        }
    }

    public function testAnIssueIsReadByTheApi(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(['pull_request' => null]),
            new JsonMockResponse(['number' => 3, 'title' => 'T', 'state' => 'open', 'body' => 'B', 'labels' => [['id' => 1], 'noise', ['name' => 'defect']]]),
            new JsonMockResponse(['number' => 3, 'title' => 'T', 'state' => 'open', 'labels' => 'defect']),
        );
        $tickets = self::tickets($forge);

        try {
            $tickets->get(3);
            self::fail('An issue without its state was accepted.');
        } catch (\UnexpectedValueException $e) {
            self::assertSame('Unknown Forgejo issue state: null.', $e->getMessage());
        }
        self::assertEquals(new Ticket(3, 'T', TicketState::Open, 'B', HeadKind::Defect), $tickets->get(3));
        self::assertSame('GET https://forge.test/api/v1/repos/acme/app/issues/3', $forge->requests[1]);
        self::assertNull($tickets->get(3)->head, 'Labels that are not a list are none.');
    }

    public function testAnIssueWithoutItsTitleIsRefused(): void
    {
        $this->expectExceptionMessage('An issue without its number or title.');

        self::tickets(new RecordingForge(new JsonMockResponse(['number' => 3, 'state' => 'closed'])))->get(3);
    }

    public function testLinksNameTheBlockerByOwnerRepositoryAndNumber(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse(['number' => 2, 'title' => 'P', 'state' => 'open'], ['http_code' => 201]),
            new JsonMockResponse(['number' => 2, 'title' => 'P', 'state' => 'open']),
        );
        $tickets = self::tickets($forge);

        $tickets->block(1, 2);
        $tickets->unblock(1, 2);

        self::assertSame([
            'POST https://forge.test/api/v1/repos/acme/app/issues/1/dependencies {"owner":"acme","repo":"app","index":2}',
            'DELETE https://forge.test/api/v1/repos/acme/app/issues/1/dependencies {"owner":"acme","repo":"app","index":2}',
        ], $forge->requests);
    }

    public function testRecentAsksForTheLatestIssuesAndCloseForTheClosedState(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse([['number' => 8, 'title' => 'A PR', 'state' => 'open', 'pull_request' => ['merged' => true]], ['number' => 7, 'title' => 'T', 'state' => 'open']]),
            new JsonMockResponse(['number' => 1, 'title' => 'T', 'state' => 'closed'], ['http_code' => 201]),
        );
        $tickets = self::tickets($forge);

        self::assertEquals([new Ticket(7, 'T', TicketState::Open)], $tickets->recent());
        $tickets->close(1);

        self::assertSame([
            'GET https://forge.test/api/v1/repos/acme/app/issues?state=all&type=issues&sort=latest&limit=50',
            'PATCH https://forge.test/api/v1/repos/acme/app/issues/1 {"state":"closed"}',
        ], $forge->requests);
    }

    public function testCommentsAreReadAndPosted(): void
    {
        $forge = new RecordingForge(
            new JsonMockResponse([['body' => 'First'], ['body' => 3], 'noise', ['body' => 'Second']]),
            new JsonMockResponse(['id' => 9, 'body' => 'Third'], ['http_code' => 201]),
        );
        $tickets = self::tickets($forge);

        self::assertSame(['First', 'Second'], $tickets->comments(4));
        $tickets->comment(4, 'Third');

        self::assertSame([
            'GET https://forge.test/api/v1/repos/acme/app/issues/4/comments',
            'POST https://forge.test/api/v1/repos/acme/app/issues/4/comments {"body":"Third"}',
        ], $forge->requests);
    }

    public function testAnEmptyAnswerIsNotAnError(): void
    {
        $forge = new RecordingForge(new MockResponse('', ['http_code' => 204]));

        self::tickets($forge)->unblock(1, 2);

        self::assertCount(1, $forge->requests);
    }

    private static function tickets(RecordingForge $forge): ForgejoTickets
    {
        return new ForgejoTickets($forge->http, 'https://forge.test/', 'acme/app', 's3cret');
    }
}
