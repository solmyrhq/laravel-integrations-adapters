<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Resources;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Integrations\Adapters\Concerns\ValidatesUrls;
use Integrations\Adapters\Zendesk\Data\ZendeskCommentData;
use Integrations\Adapters\Zendesk\Data\ZendeskCommentPageResponse;
use Integrations\Adapters\Zendesk\ZendeskResource;

class ZendeskAttachments extends ZendeskResource
{
    use ValidatesUrls;

    public function download(string $url): ?string
    {
        return $this->executeWithErrorHandling(function () use ($url): ?string {
            self::assertUrlNotPrivate($url);

            $result = $this->integration
                ->at($url)
                ->get(function () use ($url): string {
                    return Http::timeout(120)->get($url)->throw()->body();
                });

            return is_string($result) ? $result : null;
        });
    }

    /**
     * Get a fresh content_url for an attachment by fetching comments from its ticket.
     */
    public function freshUrl(int $ticketId, int $attachmentId): ?string
    {
        return $this->executeWithErrorHandling(function () use ($ticketId, $attachmentId): ?string {
            /** @var array<string, mixed> $params */
            $params = ['page[size]' => 100];

            do {
                $response = $this->fetchCommentPage($ticketId, $params);

                $url = $this->findAttachmentContentUrl($response->comments, $attachmentId);
                if ($url !== null) {
                    return $url;
                }

                $cursor = $response->meta->has_more ? $response->meta->after_cursor : null;
                $params['page[after]'] = $cursor;
            } while ($cursor !== null);

            return null;
        });
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function fetchCommentPage(int $ticketId, array $params): ZendeskCommentPageResponse
    {
        return $this->integration
            ->at("tickets/{$ticketId}/comments.json")
            ->as(ZendeskCommentPageResponse::class)
            ->withData($params)
            ->get(fn () => $this->sdk()->tickets($ticketId)->comments()->findAll($params));
    }

    /**
     * @param  Collection<int, ZendeskCommentData>  $comments
     */
    private function findAttachmentContentUrl(Collection $comments, int $attachmentId): ?string
    {
        foreach ($comments as $comment) {
            foreach ($comment->attachments as $attachment) {
                if ($attachment->id === $attachmentId) {
                    return $attachment->content_url;
                }
            }
        }

        return null;
    }
}
