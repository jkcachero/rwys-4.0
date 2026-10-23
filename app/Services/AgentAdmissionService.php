<?php

namespace App\Services;

use App\Models\Client;
use App\Models\PersonalAccessToken;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class AgentAdmissionService
{
    public function rateLimit(Client $client, PersonalAccessToken $token, bool $write): ?int
    {
        $maximum = $write ? 6 : 60;
        $prefix = $write ? 'write' : 'read';
        $keys = [
            "agent:{$prefix}:client:{$client->getKey()}",
            "agent:{$prefix}:token:{$token->getKey()}",
        ];

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, $maximum)) {
                return RateLimiter::availableIn($key);
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, 60);
        }

        return null;
    }

    public function acquire(Client $client): ?Lock
    {
        foreach ([1, 2] as $slot) {
            $lock = Cache::lock("agent:in-flight:{$client->getKey()}:{$slot}", 120);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
