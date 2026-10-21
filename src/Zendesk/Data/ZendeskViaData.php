<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Spatie\LaravelData\Data;

class ZendeskViaData extends Data
{
    /**
     * @param  array<string, mixed>  $source
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $source = [],
    ) {}

    /**
     * Accepts an empty `source`, which the inferred `required` rule rejects.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'source' => ['array'],
        ];
    }

    /**
     * @param  array<mixed>  $properties
     * @return array<mixed>
     */
    #[\Override]
    public static function prepareForPipeline(array $properties): array
    {
        if (array_key_exists('channel', $properties) && is_int($properties['channel'])) {
            $properties['channel'] = (string) $properties['channel'];
        }

        return $properties;
    }
}
