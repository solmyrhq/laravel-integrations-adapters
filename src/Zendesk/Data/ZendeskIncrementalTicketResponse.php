<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

use function Safe\parse_url;

class ZendeskIncrementalTicketResponse extends Data
{
    /**
     * @param  Collection<int, ZendeskTicketData>  $tickets
     * @param  Collection<int, ZendeskUserData>  $users
     */
    public function __construct(
        #[DataCollectionOf(ZendeskTicketData::class)]
        public readonly Collection $tickets,
        #[DataCollectionOf(ZendeskUserData::class)]
        public readonly Collection $users,
        public readonly ?string $next_page = null,
        public readonly int $count = 0,
    ) {}

    public function nextTimestamp(): ?int
    {
        if ($this->next_page === null) {
            return null;
        }

        parse_str(parse_url($this->next_page)['query'] ?? '', $queryParams);

        return array_key_exists('start_time', $queryParams) && is_numeric($queryParams['start_time'])
            ? (int) $queryParams['start_time']
            : null;
    }
}
