<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Support;

use Override;
use Stripe\Collection;
use Stripe\StripeClient as StripeSdkClient;
use Stripe\StripeObject;
use Stripe\Util\Util;

/**
 * A Stripe SDK client that sends no HTTP requests. It records each request
 * that the SDK's services make, with its exact parameters.
 */
final class RecordingStripeSdkClient extends StripeSdkClient
{
    /** @var list<array{method: string, path: string, params: mixed, opts: mixed}> */
    public array $requests = [];

    /**
     * @param  array<string, mixed>  $response  the decoded JSON body to return for every request
     */
    public function __construct(private readonly array $response)
    {
        parent::__construct('sk_test_recording');
    }

    /**
     * @param  'delete'|'get'|'post'  $method
     * @param  string  $path
     * @param  array<string, mixed>|null  $params
     * @param  mixed  $opts
     */
    #[Override]
    public function request($method, $path, $params, $opts): StripeObject
    {
        $this->record($method, $path, $params, $opts);

        $object = Util::convertToStripeObject($this->response, []);
        if (! $object instanceof StripeObject) {
            throw new \UnexpectedValueException('The recorded response is not a Stripe object.');
        }

        return $object;
    }

    /**
     * @param  'delete'|'get'|'post'  $method
     * @param  string  $path
     * @param  array<string, mixed>|null  $params
     * @param  mixed  $opts
     * @return Collection<StripeObject>
     */
    #[Override]
    public function requestCollection($method, $path, $params, $opts): Collection
    {
        $this->record($method, $path, $params, $opts);

        $collection = Util::convertToStripeObject(['object' => 'list', 'data' => [$this->response]], []);
        if (! $collection instanceof Collection) {
            throw new \UnexpectedValueException('The recorded response is not a Stripe list.');
        }

        return $collection;
    }

    /**
     * @return array{method: string, path: string, params: mixed, opts: mixed}
     */
    public function onlyRequest(): array
    {
        if (count($this->requests) !== 1) {
            throw new \UnexpectedValueException(sprintf('Expected one Stripe request, got %d.', count($this->requests)));
        }

        return $this->requests[0];
    }

    private function record(string $method, string $path, mixed $params, mixed $opts): void
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params, 'opts' => $opts];
    }
}
