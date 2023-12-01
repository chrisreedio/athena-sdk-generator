<?php

namespace App\Classes;

use App\Enums\GPTModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JetBrains\PhpStorm\Deprecated;
use OpenAI;
use function array_key_exists;
use function array_values;
use function collect;
use function config;
use function dd;
use function dump;
use function is_array;
use function is_numeric;
use function json_decode;
use function json_encode;
use function key;
use function Laravel\Prompts\{alert, outro, spin, table, intro, warning, info, error};
use function serialize;
use function sha1;
use function stripcslashes;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

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

        // Query OpenAI
        $response = static::query($unknownEndpoints->all());
        dump('Response: ', $response);

        // Cache the returned items
        // collect($response)->each(function ($item) {
        //     Cache::put(static::endpointCacheKey($item), $item['class']);
        // });
        $response = [ ];
        try {
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
        } catch (\Exception $e) {
            alert('Error parsing response: ' . $e->getMessage());
            dd('Error parsing response: ', $e->getMessage());
        }

        return [...$cachedEndpoints, ...$response];
    }

    protected static function query(array $content): array
    {
        // Ensure the array is indexed numerically, in order, from zero
        $content = array_values($content);
        $client = OpenAI::factory()
            ->withApiKey(config('openai.api_key'))
            ->withHttpClient(new \GuzzleHttp\Client([
                'timeout' => 25,
            ]))
            ->make();

        $encodedContent = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // $encodedContent = json_encode($content);
        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => $encodedContent],
        ];

        $contentPretty = json_encode($content,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        intro('Sending these messages to OpenAI...');
        info('<fg=yellow>System</>: ' . $messages[0]['content']);
        info('<fg=green>User</>: ' . $contentPretty);

        $model = GPTModel::GPT35Turbo;
        $model = GPTModel::GPT4Turbo;

        $chatOptions = [
            'model' => $model->openAI(),
            // 'model' => 'gpt-4',
            // 'model' => 'gpt-4-1106-preview', // GPT-4 Turbo
            // 'model' => 'gpt-3.5-turbo-1106', // GPT-3.5 Turbo
            // 'response_format' => 'json_object',
            'response_format' => ['type' => 'json_object'],
            // 'request_timeout' => 15,
            'temperature' => 0.2,
            'messages' => $messages,
        ];

        try {
            $result = spin(fn() => $client->chat()->create($chatOptions),
                'Querying <fg=white>OpenAI</>: <fg=' . $model->getColor() . '>' . $model->getLabel() . '</>. Please wait...');
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            dd('Error connecting to OpenAI: ' . $e->getMessage());
            // return [];
        } catch (\OpenAI\Exceptions\TransporterException $e) {
            dd('Error querying OpenAI: ' . $e->getMessage());
            // return [];
        } catch (\Exception $e) {
            // alert('Error querying OpenAI: ' . $e->getMessage());
            dd($e);
            // alert('')
            return [];
        }

        $response = $result->choices[0]->message->content;
        $response = json_decode($response, true);
        dump('Raw Response: ', $response);

        // Extract the data if wrapped
        if (array_key_exists('endpoints', $response)) {
            $response = array_values($response['endpoints']);
        }
        dump('Extracted Response: ', $response);

        // Check if it's not an array or if it's an associative array (single object)
        // Wrap the single object associative array in an array if it isn't a proper array
        if (!is_array($response) || !is_numeric(array_keys($response)[0])) {
            $response = [$response];
        }

        // Ensure the array is indexed numerically, in order, from zero
        return array_values($response);
    }

    protected static function systemPrompt(): string
    {
        $promptParts = [
            // 'You are a expert backend engineer that has designed REST APIs for decades.',
            // 'As a result, you are an expert an categorizing APIs and naming SDK methods.',
            'Convert the given JSON array of endpoint objects (consisting of the endpoint\'s method, path, and a summary of what the API endpoint does into a PHP safe studly case name.',
            'Please make the names descriptive, yet concise.',
            'The only output fields should be the method, path, and class.',
            'It is critical that you include every request in the output. The output should be an array of endpoint objects.',
            // 'Do not assign a root key to the output.',
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
