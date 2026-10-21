<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Resources;

use Illuminate\Support\Collection;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Adapters\Zendesk\ZendeskResource;
use RuntimeException;
use stdClass;

class ZendeskUsers extends ZendeskResource
{
    /**
     * The user the credentials authenticate as (Zendesk `GET /users/me.json`).
     */
    public function authenticated(): ZendeskUserData
    {
        $user = $this->executeWithErrorHandling(function (): ZendeskUserData {
            return $this->integration
                ->at('users/me.json')
                ->as(ZendeskUserData::class)
                ->get(function (): ?stdClass {
                    $response = $this->sdk()->users()->me();
                    $user = $response->user ?? null;

                    return $user instanceof stdClass ? $user : null;
                });
        });

        // executeWithErrorHandling() returns its null default on a swallowed
        // error; the "me" endpoint always names a user, so a null here is a
        // real failure rather than an absent identity.
        if (! $user instanceof ZendeskUserData) {
            throw new RuntimeException('Zendesk GET /users/me.json returned no user.');
        }

        return $user;
    }

    public function get(int $userId): ?ZendeskUserData
    {
        return $this->executeWithErrorHandling(function () use ($userId): ZendeskUserData {
            return $this->integration
                ->at("users/{$userId}.json")
                ->as(ZendeskUserData::class)
                ->get(function () use ($userId): ?stdClass {
                    $response = $this->sdk()->users()->find($userId);
                    $user = $response->user ?? null;

                    return $user instanceof stdClass ? $user : null;
                });
        });
    }

    /**
     * Iterate through all users and call the callback for each.
     *
     * @param  (callable(ZendeskUserData): void)|null  $callback
     *
     * @param-immediately-invoked-callable $callback
     *
     * @return Collection<int, ZendeskUserData>
     */
    public function list(?callable $callback = null): Collection
    {
        /** @var Collection<int, ZendeskUserData> $users */
        $users = new Collection;

        $this->executeWithErrorHandling(function () use ($callback, &$users): void {
            $this->integration
                ->at('users.json')
                ->get(function () use ($callback, &$users): void {
                    $this->collectUsers($this->sdk()->users()->iterator(), $users, $callback);
                });
        });

        return $users;
    }

    /**
     * @param  iterable<mixed>  $iterator
     * @param  Collection<int, ZendeskUserData>  $users
     * @param  (callable(ZendeskUserData): void)|null  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    private function collectUsers(iterable $iterator, Collection $users, ?callable $callback): void
    {
        foreach ($iterator as $user) {
            if (! $user instanceof stdClass) {
                continue;
            }
            $data = ZendeskUserData::from($user);
            if ($callback !== null) {
                $callback($data);
            }

            $users->push($data);
        }
    }
}
