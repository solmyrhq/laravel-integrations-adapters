<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Integrations\Adapters\Zendesk\Enums\ZendeskStatus;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class ZendeskTicketData extends Data
{
    /**
     * @param  Collection<int, ZendeskCustomFieldData>  $custom_fields
     * @param  array<int, string>|null  $tags
     * @param  array<int, int>|null  $collaborator_ids
     * @param  array<int, int>|null  $follower_ids
     * @param  array<int, int>|null  $email_cc_ids
     * @param  array<int, int>|null  $sharing_agreement_ids
     * @param  array<int, int>|null  $followup_ids
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly ?string $external_id,
        public readonly string $subject,
        public readonly string $raw_subject,
        public readonly string $description,
        public readonly ZendeskStatus $status,
        public readonly ?string $type,
        public readonly ?string $priority,
        public readonly int $custom_status_id,
        public readonly int $requester_id,
        public readonly int $submitter_id,
        public readonly ?int $assignee_id,
        public readonly ?int $organization_id,
        public readonly int $group_id,
        public readonly ?int $brand_id,
        public readonly ?int $forum_topic_id,
        public readonly ?int $problem_id,
        public readonly Carbon $created_at,
        public readonly Carbon $updated_at,
        public readonly ?Carbon $due_at,
        public readonly int $generated_timestamp,
        public readonly ZendeskViaData $via,
        #[DataCollectionOf(ZendeskCustomFieldData::class)]
        public readonly Collection $custom_fields,
        public readonly bool $has_incidents,
        public readonly bool $is_public,
        public readonly bool $allow_channelback,
        public readonly bool $allow_attachments,
        public readonly bool $from_messaging_channel,
        public readonly ?ZendeskSatisfactionRatingData $satisfaction_rating = null,
        public readonly string $encoded_id = '',
        public readonly ?string $recipient = null,
        public readonly ?array $tags = [],
        public readonly ?array $collaborator_ids = [],
        public readonly ?array $follower_ids = [],
        public readonly ?array $email_cc_ids = [],
        public readonly ?array $sharing_agreement_ids = [],
        public readonly ?array $followup_ids = [],
        public readonly ?array $original = null,
    ) {}

    /**
     * Accepts the empty `subject` and `raw_subject` that Zendesk sends for a ticket created from an email with no subject.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'subject' => ['present', 'string'],
            'raw_subject' => ['present', 'string'],
        ];
    }

    /**
     * @param  array<mixed>  $properties
     * @return array<mixed>
     */
    #[\Override]
    public static function prepareForPipeline(array $properties): array
    {
        $properties['original'] ??= $properties;

        return $properties;
    }

    public function getCustomField(int $fieldId): mixed
    {
        return $this->custom_fields
            ->firstWhere('id', $fieldId)
            ?->value;
    }
}
