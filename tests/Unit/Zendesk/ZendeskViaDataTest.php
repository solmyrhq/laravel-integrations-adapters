<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Zendesk;

use Integrations\Adapters\Tests\TestCase;
use Integrations\Adapters\Zendesk\Data\ZendeskViaData;

class ZendeskViaDataTest extends TestCase
{
    public function test_accepts_an_empty_source(): void
    {
        $via = ZendeskViaData::from(['channel' => 'api', 'source' => []]);

        $this->assertSame([], $via->source);
    }

    public function test_defaults_a_missing_source_to_empty(): void
    {
        $via = ZendeskViaData::from(['channel' => 'api']);

        $this->assertSame([], $via->source);
    }
}
