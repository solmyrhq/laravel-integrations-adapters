<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Resources;

use Illuminate\Support\Collection;
use Integrations\Adapters\Zendesk\Data\ZendeskCommentData;
use Integrations\Adapters\Zendesk\Data\ZendeskCommentPageResponse;
use Integrations\Adapters\Zendesk\Data\ZendeskSearchResponse;
use Integrations\Adapters\Zendesk\ZendeskResource;
use InvalidArgumentException;
use stdClass;
use Zendesk\API\Http;

use function Safe\json_decode;
use function Safe\json_encode;

class ZendeskComments extends ZendeskResource
{
    /**
     * Iterate through all comments for a ticket.
     *
     * @param  callable(ZendeskCommentData): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function list(int $ticketId, callable $callback): void
    {
        /** @var array<string, mixed> $params */
        $params = ['page[size]' => 100];

        do {
            $response = $this->integration
                ->at("tickets/{$ticketId}/comments.json")
                ->as(ZendeskCommentPageResponse::class)
                ->withData($params)
                ->get(fn () => $this->sdk()->tickets($ticketId)->comments()->findAll($params));

            foreach ($response->comments as $comment) {
                $callback($comment);
            }

            $cursor = $response->meta->has_more ? $response->meta->after_cursor : null;
            if ($cursor !== null) {
                $params['page[after]'] = $cursor;
            }
        } while ($cursor !== null);
    }

    /**
     * Find comments with ID greater than $minCommentId across recently-updated tickets.
     *
     * @param  callable(ZendeskCommentData, int): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function newerThan(int $minCommentId, callable $callback, int $lookbackDays = 7): void
    {
        if ($lookbackDays < 1) {
            throw new InvalidArgumentException("lookbackDays must be at least 1, got {$lookbackDays}.");
        }

        $ticketIds = $this->searchRecentlyUpdatedTicketIds($lookbackDays);

        foreach ($ticketIds as $ticketId) {
            $this->list($ticketId, function (ZendeskCommentData $comment) use ($minCommentId, $callback, $ticketId): void {
                if ($comment->id > $minCommentId) {
                    $callback($comment, $ticketId);
                }
            });
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function searchRecentlyUpdatedTicketIds(int $lookbackDays): Collection
    {
        $cutoff = now()->subDays($lookbackDays)->format('Y-m-d');
        $page = 1;
        $originalBasePath = $this->sdk()->getApiBasePath();

        /** @var Collection<int, int> $ticketIds */
        $ticketIds = new Collection;

        try {
            do {
                $this->sdk()->setApiBasePath('api/v2/');

                $response = $this->integration
                    ->at("search.json?page={$page}")
                    ->as(ZendeskSearchResponse::class)
                    ->withData(['query' => "type:ticket updated>{$cutoff}", 'page' => $page])
                    ->get(fn () => self::decodeSdkResponse(Http::send(
                        $this->sdk(),
                        'search.json',
                        [
                            'queryParams' => [
                                'query' => "type:ticket updated>{$cutoff}",
                                'sort_by' => 'updated_at',
                                'sort_order' => 'desc',
                                'page' => $page,
                            ],
                        ]
                    )));

                if ($response->results->isEmpty()) {
                    break;
                }

                foreach ($response->results as $ticket) {
                    $ticketIds->push($ticket->id);
                }

                $hasNextPage = $response->next_page !== null;
                $page++;
            } while ($hasNextPage);
        } finally {
            $this->sdk()->setApiBasePath($originalBasePath);
        }

        return $ticketIds->unique()->values();
    }

    /**
     * Add a public comment to a ticket. Pass `$idempotencyKey` (e.g.
     * `"comment:order-{$order->id}:zendesk"`) for at-most-once
     * execution. Zendesk doesn't natively dedupe by header, so the local
     * row in `integration_idempotency_keys` is the only protection
     * against double-posts under retry. A second call with the same key
     * throws `Integrations\Exceptions\IdempotencyConflict`.
     */
    public function add(int $ticketId, string $comment, ?string $idempotencyKey = null): ?ZendeskCommentData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId, $comment, $idempotencyKey): ?ZendeskCommentData {
            $result = $this->integration
                ->at("tickets/{$ticketId}.json")
                ->withData(['comment' => $comment])
                ->withIdempotencyKey($idempotencyKey)
                ->put(function () use ($ticketId, $comment): ?ZendeskCommentData {
                    $response = $this->sdk()->tickets()->update($ticketId, [
                        'comment' => [
                            'body' => $comment,
                            'public' => true,
                        ],
                    ]);

                    return $response instanceof stdClass ? $this->commentDataFromResponse($response) : null;
                });

            return $result instanceof ZendeskCommentData ? $result : null;
        });
    }

    /**
     * Add an internal (non-public) note to a ticket. Same idempotency
     * semantics as add().
     */
    public function addInternalNote(int $ticketId, string $note, ?string $idempotencyKey = null): ?ZendeskCommentData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId, $note, $idempotencyKey): ?ZendeskCommentData {
            $result = $this->integration
                ->at("tickets/{$ticketId}.json")
                ->withData(['note' => $note])
                ->withIdempotencyKey($idempotencyKey)
                ->put(function () use ($ticketId, $note): ?ZendeskCommentData {
                    $response = $this->sdk()->tickets()->update($ticketId, [
                        'comment' => [
                            'body' => $note,
                            'public' => false,
                        ],
                    ]);

                    return $response instanceof stdClass ? $this->commentDataFromResponse($response) : null;
                });

            return $result instanceof ZendeskCommentData ? $result : null;
        });
    }

    private function commentDataFromResponse(stdClass $response): ?ZendeskCommentData
    {
        $audit = $response->audit ?? null;
        if (! $audit instanceof stdClass) {
            return null;
        }

        $events = $audit->events ?? [];
        if (! is_array($events)) {
            return null;
        }

        $auditId = $audit->id ?? null;
        if (! is_int($auditId)) {
            return null;
        }

        foreach ($events as $event) {
            $comment = $this->commentDataFromEvent($event, $audit, $auditId);
            if ($comment !== null) {
                return $comment;
            }
        }

        return null;
    }

    private function commentDataFromEvent(mixed $event, stdClass $audit, int $auditId): ?ZendeskCommentData
    {
        if (! $event instanceof stdClass || ($event->type ?? null) !== 'Comment') {
            return null;
        }

        $commentArray = json_decode(json_encode($event), true);
        if (! is_array($commentArray)) {
            return null;
        }

        $commentArray['audit_id'] = $auditId;
        $commentArray['created_at'] ??= $audit->created_at ?? null;
        $commentArray['metadata'] ??= is_object($audit->metadata ?? null)
            ? json_decode(json_encode($audit->metadata), true)
            : [];
        $commentArray['via'] ??= is_object($audit->via ?? null)
            ? json_decode(json_encode($audit->via), true)
            : ['channel' => 'api', 'source' => []];

        return ZendeskCommentData::from($commentArray);
    }
}
