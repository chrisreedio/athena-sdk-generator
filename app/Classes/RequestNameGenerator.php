<?php

namespace App\Classes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JetBrains\PhpStorm\Deprecated;
use OpenAI;
use function collect;
use function config;
use function dd;
use function dump;
use function is_array;
use function json_decode;
use function json_encode;
use function key;
use function Laravel\Prompts\{outro, spin, table, intro, warning, info, error};
use function serialize;
use function sha1;
use function stripcslashes;

class RequestNameGenerator
{
    #[Deprecated]
    public static function single(string $method, string $path, ?string $summary = null): array
    {
        $content = ['method' => $method, 'path' => $path, 'summary' => $summary];
        return static::query($content);
    }

    public static function collection(array $endpoints): array
    {
        // Check each item in the endpoints array to make sure that row isn't already 'cached'
        // If it is, then don't send it to OpenAI
        // If it isn't, then send it to OpenAI and cache the result
        // Then return the entire array of results
        info('Requested Endpoints: ');
        // table(['Method', 'Path', 'Summary'], $endpoints);

        $endPointStatuses = collect($endpoints)->map(function ($endpoint) {
            $className = Cache::get(static::endpointCacheKey($endpoint));
            return [
                ...$endpoint,
                'cached' => $className !== null ? "<fg=green>$className</>" : '<fg=red>❌</>',
            ];
        })->toArray();
        table(['Method', 'Path', 'Summary', 'Cached Class Name'], $endPointStatuses);
        // dump($endpoints);

        // info('Filtering out endpoints that are cached...');
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

        $unknownCount = $unknownEndpoints->count();
        warning($unknownCount . ' ' . Str::plural('endpoint', $unknownCount) . ' are not cached. We\'ll need to query OpenAI.');
        // table(['Method', 'Path', 'Summary'], $unknownEndpoints->all());

        $response = static::query($unknownEndpoints->all());
        // dump('Response: ', $response);

        // Cache the returned items
        // collect($response)->each(function ($item) {
        //     Cache::put(static::endpointCacheKey($item), $item['class']);
        // });
        $response = collect($response)->map(function ($item) use ($endpoints) {
            // dump('Caching: ', $item);
            $item['path'] = stripcslashes($item['path']);
            Cache::put(static::endpointCacheKey($item), $item['class']);
            // dump('Looking for path: ' . $item['path']);
            $originalEndpoint = collect($endpoints)->firstWhere('path', $item['path']);
            // dd('Original Endpoint: ', $originalEndpoint);
            // dd('Path: ' . $item['path'] . ': ' . $summary);
            return [
                'method' => $item['method'],
                'path' => $item['path'],
                // Look up the summary in the $endpoints input array
                'summary' => $originalEndpoint['summary'],
                'class' => $item['class'],
            ];
        })->toArray();

        return [...$cachedEndpoints, ...$response];
    }

    protected static function query(array $content): array
    {
        // $client = OpenAI::client(config('openai.api_key'));
        $client = OpenAI::factory()
            ->withApiKey(config('openai.api_key'))
            ->withHttpClient(new \GuzzleHttp\Client([
                'timeout' => 15,
            ]))
            ->make();

        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => json_encode($content)],
        ];

        intro('Sending these messages to OpenAI...');
        info('<fg=yellow>System</>: ' . $messages[0]['content']);
        info('<fg=green>User</>: ' . json_encode($content, JSON_PRETTY_PRINT));

        $chatOptions = [
            // 'model' => 'gpt-4',
            'model' => 'gpt-4-1106-preview', // GPT-4 Turbo
            // 'response_format' => 'json_object',
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
            'messages' => $messages,
        ];

        $result = spin(fn() => $client->chat()->create($chatOptions), 'Querying OpenAI. Please wait...');

        $response = $result->choices[0]->message->content;
        $response = json_decode($response, true);

        // Check if it's not an array or if it's an associative array (single object)
        // Wrap the single object associative array in an array if it isn't a proper array
        if (!is_array($response) || (array_keys($response)[0] !== 1)) {
            $response = [$response];
        }

        return $response;
    }

    protected static function systemPrompt(): string
    {
        $promptParts = [
            'You are a expert backend engineer that has designed REST APIs for decades.',
            'As a result, you are an expert an categorizing APIs and naming SDK methods.',
            'Convert the given JSON input that is the endpoint\'s method, path, and a summary of what the API call does.',
            'The only output fields should be the method, path, and class in the output.',
            'Generate JSON output that is a safe PHP classname for each request.',
        ];
        return collect($promptParts)->join("\n");
        // return "You are a expert software analysis assistant. You're are an expert an classifying APIs and generating SDKs from API concepts. Convert a given JSON input that is the endpoint's method, path, and a summary of what the API call does. Generate JSON output that is a safe PHP classname for each request. Only include the method, path, and class in the output.";
    }

    protected static function endpointCacheKey(array $endpoint): string
    {
        // Filter the endpoint array to only include the 'method' and 'path' keys
        $filteredEndpoint = collect($endpoint)->only(['method', 'path'])->toArray();
        return config('app.cache.prefix') . ':' . sha1(serialize($filteredEndpoint));
    }
}
