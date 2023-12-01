<?php

namespace App\Classes;

use Illuminate\Support\Facades\Cache;
use OpenAI;
use function collect;
use function config;
use function dd;
use function json_encode;
use function Laravel\Prompts\{table, intro, warning, info, error};

class RequestNameGenerator
{
    public static function single(string $method, string $path, ?string $summary = null): string
    {
        $content = ['method' => $method, 'path' => $path, 'summary' => $summary];
        $content = json_encode($content);
        return static::query($content);
    }

    public static function collection(array $endpoints): array
    {
        // Check each item in the endpoints array to make sure that row isn't already 'cached'
        // If it is, then don't send it to OpenAI
        // If it isn't, then send it to OpenAI and cache the result
        // Then return the entire array of results
        info('Requested Endpoints: ');
        table(['Method', 'Path', 'Summary'], $endpoints);
        // dump($endpoints);

        info('Filtering out endpoints that are cached...');
        [$cachedEndpoints, $unknownEndpoints] = collect($endpoints)->partition(function ($endpoint) {
            return Cache::has(static::endpointCacheKey($endpoint));
        });

        if (!$cachedEndpoints->isEmpty()) {
            // Update the cached endpoints array with the cached values
            $cachedEndpoints = collect($cachedEndpoints)->map(function ($endpoint) {
                return [
                    ...$endpoint,
                    'class' => Cache::get(static::endpointCacheKey($endpoint))
                ];
            })->toArray();
        }

        if ($unknownEndpoints->isEmpty()) {
            info('All endpoints are cached. No need to query OpenAI.');
            return $cachedEndpoints;
        }

        warning('These endpoints are not cached, so we will query OpenAI for them:');
        table(['Method', 'Path', 'Summary'], $unknownEndpoints->all());
        // dd('stopping');

        $response = json_decode(static::query(json_encode($endpoints)), true);

        // Cache the returned items
        collect($response)->each(function ($item) {
            Cache::put(static::endpointCacheKey($item), $item['class']);
        });

        return [...$cachedEndpoints, ...$response];
    }

    protected static function endpointCacheKey(array $endpoint): string
    {
        // Filter the endpoint array to only include the 'method' and 'path' keys
        $filteredEndpoint = collect($endpoint)->only(['method', 'path'])->toArray();
        return config('app.cache.prefix') . ':' . sha1(serialize($filteredEndpoint));
    }

    protected static function query(string $content): string
    {
        $client = OpenAI::client(config('openai.api_key'));

        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => $content],
        ];

        intro('Sending these messages to OpenAI...');
        info('<fg=yellow>System</>: ' . $messages[0]['content']);
        info('<fg=green>User</>: ' . $messages[1]['content']);

        $result = $client->chat()->create([
            'model' => 'gpt-4',
            'messages' => $messages,
        ]);

        return $result->choices[0]->message->content;
    }

    protected static function systemPrompt(): string
    {
        return "You are a expert software analysis assistant. You're are an expert an classifying APIs and generating SDKs from API concepts. Convert a given JSON input that is the endpoint's method, path, and a summary of what the API call does. Generate JSON output that is a safe PHP classname for each request. Only include the method, path, and class in the output.";
    }
}
