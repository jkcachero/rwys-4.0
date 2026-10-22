<?php

namespace App\Services;

use App\Models\AIConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class AIModelCatalog
{
    public const string CACHE_VERSION = 'provider-reasoning-v2';

    /**
     * @param  array<string, mixed>  $model
     * @return list<string>
     */
    public static function thinkingLevels(array $model): array
    {
        if (! array_key_exists('supported_reasoning_levels', $model)) {
            return [];
        }
        $presets = $model['supported_reasoning_levels'];
        if (! is_array($presets) || ! array_is_list($presets)) {
            throw new RuntimeException('The provider reasoning metadata is invalid.');
        }
        $levels = [];
        foreach ($presets as $preset) {
            $effort = is_array($preset) ? ($preset['effort'] ?? null) : null;
            if (! is_string($effort) || preg_match('/^[a-z][a-z0-9_-]{0,19}$/D', $effort) !== 1) {
                throw new RuntimeException('The provider reasoning metadata is invalid.');
            }
            if (! in_array($effort, $levels, true)) {
                $levels[] = $effort;
            }
        }

        return $levels;
    }

    /** @return list<array{slug: string, displayName: string, thinkingLevels: list<string>}> */
    public function models(AIConnection $connection, bool $refresh = false): array
    {
        $policy = config('workspace.models.openai', []);
        $key = 'ai-models:'.$connection->user_id.':'.$connection->id.':'.hash('sha256', json_encode([
            self::CACHE_VERSION, $connection->provider, $connection->credentials['api_key'], $policy,
        ], JSON_THROW_ON_ERROR));
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 60, function () use ($connection, $policy): array {
            try {
                $http = Http::acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
                if ($connection->provider === 'openai') {
                    $response = $http->withToken($connection->credentials['api_key'])->get('https://api.openai.com/v1/models');
                    $data = $response->json('data');
                    if (! $response->successful() || ! is_array($data) || ! array_is_list($data)) {
                        throw new RuntimeException('Invalid model catalog.');
                    }
                    $models = [];
                    foreach ($data as $model) {
                        if (! is_array($model) || ! is_string($model['id'] ?? null) || $model['id'] === '') {
                            throw new RuntimeException('Invalid model entry.');
                        }
                        $id = $model['id'];
                        if (! isset($policy[$id]) || ! empty($model['shutdown_date'])) {
                            continue;
                        }
                        $models[$id] = ['slug' => $id, 'displayName' => $policy[$id]['displayName'], 'thinkingLevels' => self::thinkingLevels($model)];
                    }

                    return array_values($models);
                }

                $models = [];
                $afterId = null;
                $cursors = [];
                for ($page = 0; $page < 3; $page++) {
                    $response = $http->withHeaders([
                        'x-api-key' => $connection->credentials['api_key'],
                        'anthropic-version' => '2023-06-01',
                    ])->get('https://api.anthropic.com/v1/models', array_filter(['limit' => 100, 'after_id' => $afterId], fn (mixed $value): bool => $value !== null));
                    $data = $response->json('data');
                    if (! $response->successful() || ! is_array($data) || ! array_is_list($data)) {
                        throw new RuntimeException('Invalid model catalog.');
                    }
                    foreach ($data as $model) {
                        if (! is_array($model) || ! is_string($model['id'] ?? null) || $model['id'] === ''
                            || ! is_string($model['display_name'] ?? null) || $model['display_name'] === '') {
                            throw new RuntimeException('Invalid model entry.');
                        }
                        if (array_key_exists('lifecycle', $model) && $model['lifecycle'] !== 'active') {
                            continue;
                        }
                        $effort = $model['capabilities']['effort'] ?? [];
                        $presets = [];
                        if (is_array($effort) && ($effort['supported'] ?? false) === true) {
                            foreach ($effort as $level => $capability) {
                                if ($level !== 'supported' && is_array($capability) && ($capability['supported'] ?? false) === true) {
                                    $presets[] = ['effort' => $level];
                                }
                            }
                        }
                        $models[$model['id']] = ['slug' => $model['id'], 'displayName' => $model['display_name'],
                            'thinkingLevels' => self::thinkingLevels(['supported_reasoning_levels' => $presets])];
                    }
                    if (($response->json('has_more') ?? false) === false) {
                        return array_values($models);
                    }
                    $afterId = $response->json('last_id');
                    if (! is_string($afterId) || $afterId === '' || in_array($afterId, $cursors, true)) {
                        throw new RuntimeException('Invalid model pagination.');
                    }
                    $cursors[] = $afterId;
                }

                throw new RuntimeException('The model catalog pagination limit was reached.');
            } catch (Throwable) {
                throw new RuntimeException('The personal provider model catalog could not be loaded.');
            }
        });
    }
}
