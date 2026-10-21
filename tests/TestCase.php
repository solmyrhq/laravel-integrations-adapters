<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests;

use Illuminate\Foundation\Application;
use Integrations\Testing\IntegrationTestCase;
use Spatie\LaravelData\Support\Creation\ValidationStrategy;

abstract class TestCase extends IntegrationTestCase
{
    /**
     * @param  Application  $app
     */
    #[\Override]
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Matches consumers such as Grace, which set `validation_strategy` to `Always`.
        $app['config']->set('data.validation_strategy', ValidationStrategy::Always->value);
    }
}
