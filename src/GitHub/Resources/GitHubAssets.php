<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Resources;

use Illuminate\Support\Facades\Http;
use Integrations\Adapters\Concerns\ValidatesUrls;
use Integrations\Adapters\GitHub\GitHubResource;
use Integrations\RequestContext;

use function Safe\parse_url;

class GitHubAssets extends GitHubResource
{
    use ValidatesUrls;

    public function download(string $url): ?string
    {
        return $this->executeWithErrorHandling(function () use ($url): ?string {
            self::assertUrlNotPrivate($url);
            $headers = self::isGitHubDomain($url) ? [
                'Accept' => 'application/octet-stream',
                'Authorization' => 'Bearer '.$this->token(),
                'X-GitHub-Api-Version' => '2022-11-28',
            ] : [];

            $result = $this->integration
                ->at($url)
                ->get(function (RequestContext $ctx) use ($url, $headers): string {
                    $response = Http::timeout(120)
                        ->withHeaders($headers)
                        ->throw()
                        ->get($url);

                    $requestId = $response->header('X-GitHub-Request-Id');
                    if ($requestId !== '') {
                        $ctx->reportResponseMetadata(providerRequestId: $requestId);
                    }

                    return $response->body();
                });

            return is_string($result) ? $result : null;
        });
    }

    private static function isGitHubDomain(string $url): bool
    {
        $domain = parse_url($url)['host'] ?? '';

        return $domain === 'github.com'
            || str_ends_with($domain, '.github.com')
            || $domain === 'githubusercontent.com'
            || str_ends_with($domain, '.githubusercontent.com');
    }
}
