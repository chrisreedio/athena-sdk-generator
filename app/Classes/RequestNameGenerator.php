<?php

namespace App\Classes;

use App\Enums\GPTModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JetBrains\PhpStorm\Deprecated;
use OpenAI;
use function array_key_exists;
use function array_keys;
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
use function microtime;
use function round;
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
        $endPointStatuses = collect($endpoints)->map(function ($endpoint) {
            $className = Cache::get(static::endpointCacheKey($endpoint));
            return [
                ...$endpoint,
                'cached' => $className !== null ? "<fg=green>$className</>" : '<fg=red>❌</>',
            ];
        })->toArray();

        // info('Requested Endpoints: ');
        // table(['Method', 'Path', 'Summary', 'Cached Class Name'], $endPointStatuses);

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
            // info('All endpoints are cached. No need to query OpenAI.');
            return $cachedEndpoints;
        }

        $unknownCount = $unknownEndpoints->count();
        warning($unknownCount . ' ' . Str::plural('endpoint', $unknownCount) . ' are not cached. We\'ll need to query OpenAI.');
        table(['Method', 'Path', 'Summary'], $unknownEndpoints->all());

        $response = [];
        // Query OpenAI
        $response = static::query($unknownEndpoints->all());

        // Augment the returned data with the original summary
        try {
            $response = collect($response)->map(function ($item) use ($endpoints) {
                $item['path'] = stripcslashes($item['path']);
                $cacheKey = static::endpointCacheKey($item);
                Cache::put($cacheKey, $item['class']);
                // Retrieve the original endpoint from the $endpoints input array
                $originalEndpoint = collect($endpoints)->firstWhere('path', $item['path']);
                return [
                    'method' => $item['method'],
                    'path' => $item['path'],
                    // Use the original summary
                    'summary' => $originalEndpoint['summary'],
                    'class' => $item['class'],
                ];
            })->toArray();
        } catch (\Exception $e) {
            alert('Error parsing response: ' . $e->getMessage());
            dd('Error parsing response: ', $e->getMessage());
        }
        // info('cached endpoints');
        // table(['Method', 'Path', 'Summary', 'Class'], $cachedEndpoints);

        // info('response endpoints');
        // table(['Method', 'Path', 'Summary', 'Class'], $response);

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
        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => $encodedContent],
        ];

        // $contentPretty = json_encode($content,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        // intro('Sending these messages to OpenAI...');
        // info('<fg=yellow>System</>: ' . $messages[0]['content']);
        // info('<fg=green>User</>: ' . $contentPretty);

        $model = GPTModel::GPT35Turbo;
        $model = GPTModel::GPT4Turbo;

        $chatOptions = [
            'model' => $model->openAI(),
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
            'messages' => $messages,
        ];

        $tokenCount = 0;

        info('Querying <fg=white>OpenAI</>: <fg=' . $model->getColor() . '>' . $model->getLabel() . '</>');
        $stream = $client->chat()->createStreamed($chatOptions);
        $result = static::executeQuery($stream);


        // $response = $result->choices[0]->message->content;
        $response = $result;
        // dump("Raw Response: \n" . $response);
        $response = json_decode($response, true);
        // dump('Parsed Response: ', $response);

        return static::cleanResponse($response);
    }

    private static function executeQuery(OpenAI\Responses\StreamResponse $stream): ?string
    {
        $startTime = microtime(true);
        try {
            // Non-streaming version
            // $result = spin(function () use ($client, $chatOptions) {
            //     return $client->chat()->create($chatOptions);
            // }, 'Querying <fg=white>OpenAI</>: <fg=' . $model->getColor() . '>' . $model->getLabel() . '</>. Please wait...');

            // Streaming Query
            // info('Querying <fg=white>OpenAI</>: <fg=' . $model->getColor() . '>' . $model->getLabel() . '</>');
            // $stream = $client->chat()->createStreamed($chatOptions);
            $result = '';
            // $result = spin(function () use ($stream, &$result) {
                foreach ($stream as $response) {
                    $chunk = $response->choices[0]->delta->content;
                    echo $chunk;
                    $result .= $chunk;
                }
                echo "\n";
                // return $result;
            // }, 'Executing AI Query. Please wait...');

            $totalTime = round(microtime(true) - $startTime, 2);
            info('Query took ' . $totalTime . ' seconds');
            return $result;
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            alert('Error connecting to OpenAI [ConnectionException]: ' . $e->getMessage());
        } catch (\OpenAI\Exceptions\TransporterException $e) {
            alert('Error querying OpenAI [TransportException]: ' . $e->getMessage());
        } catch (\Exception $e) {
            alert('Unknown error querying OpenAI: ' . $e->getMessage());
        }
        return null;
    }

    private static function cleanResponse(array $response): array
    {
        // Extract the data if wrapped
        if (array_key_exists('endpoints', $response)) {
            $response = array_values($response['endpoints']);
        } else if (array_key_exists('response', $response)) {
            $response = array_values($response['response']);
        }
        // dump('Extracted Response: ', $response);

        // Check if it's not an array or if it's an associative array (single object)
        // Wrap the single object associative array in an array if it isn't a proper array
        if (!is_numeric(array_keys($response)[0])) {
            $response = [$response];
        }

        // Ensure the array is indexed numerically, in order, from zero
        return array_values($response);
    }

    public static function systemPrompt(): string
    {
        $promptParts = [
            'For the given JSON array of API endpoints, generate PHP class names in StudlyCaps format according to the following rules:',
            'Use concise names that clearly indicate the endpoint\'s purpose.',
            'Begin class names with "List" if the endpoint retrieves multiple items, replacing "Get".',
            'Begin class names with "Create", "Get", "Update", or "Delete" to reflect the operation performed.',
            'The output should be valid JSON, including only the \'method\', \'path\', and new \'class\' name for each endpoint.',
            'Provide a new class name for every endpoint in the input array, ensuring no endpoint is omitted.',
            'Transformation guidelines:',
            '- "Get" endpoints returning collections should be prefixed with "List".',
            '- "Get" endpoints returning a single item should retain the "Get" prefix.',
            '- POST endpoints should have class names starting with "Create".',
            '- PATCH or PUT endpoints should start with "Update".',
            '- DELETE endpoints should begin with "Delete".',
            'The naming convention should be uniform across all endpoints to ensure consistency.',
            'The root key should be \'endpoints\' that has a value of the aforementioned response objects.',
            'Under no circumstances should you just start printing endless new lines.'
        ];

        return collect($promptParts)->join("\n");
        // return "You are an expert software analysis assistant. You're an expert a classifying APIs and generating SDKs from API concepts. Convert a given JSON input that is the endpoint's method, path, and a summary of what the API call does. Generate JSON output that is a safe PHP classname for each request. Only include the method, path, and class in the output.";
    }

    public static function endpointCacheKey(array $endpoint): string
    {
        // Filter the endpoint array to only include the 'method' and 'path' keys
        return config('app.cache.prefix') . ':' . $endpoint['method'] . ':' . $endpoint['path'];
    }
}
