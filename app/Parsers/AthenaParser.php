<?php

namespace App\Parsers;

use App\Classes\RequestNameGenerator;
use cebe\openapi\Reader;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter as OpenApiParameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Paths;
use cebe\openapi\spec\Type;
use Crescat\SaloonSdkGenerator\Contracts\Parser;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Method;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Parsers\OpenApiParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use function array_filter;
use function array_key_exists;
use function array_slice;
use function Laravel\Prompts\{alert, intro, outro, info, warning, error, table};
use function collect;
use function json_encode;
use function realpath;
use function str_replace;
use function substr_replace;
use function trim;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

class AthenaParser extends OpenApiParser
{
    public static function build($content): self
    {
        return new self(
            Str::endsWith($content, '.json')
                ? Reader::readFromJsonFile(fileName: realpath($content), resolveReferences: ReferenceContext::RESOLVE_MODE_ALL)
                : Reader::readFromYamlFile(fileName: realpath($content), resolveReferences: ReferenceContext::RESOLVE_MODE_ALL)
        );
    }

    public function parse(): ApiSpecification
    {
        // Generate list of paths
        $paths = $this->parseItems($this->openApi->paths);

        // echo(json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $response = RequestNameGenerator::collection($paths);
        // dd($response);
        // table(['Method', 'Path', 'Summary', 'Request Class Name'], $response);

        // Now we need to scan this list of endpoints and ensure that there is a cached OpenAI ClassName for each one
        $endpoints = $this->parseEndpoints($this->openApi->paths);

        // dd('done');

        return new ApiSpecification(
            name: $this->openApi->info->title,
            description: $this->openApi->info->description,
            baseUrl: Arr::first($this->openApi->servers)->url,
            // endpoints: $this->parseItems($this->openApi->paths)
            endpoints: $endpoints,
        );
    }

    /**
     * @return array|Endpoint[]
     */
    protected function parseItems(Paths $items): array
    {
        // $requests = [];
        // $tableData = [];
        $paths = [];
        // $category = null;
        // Find the category from the first item - strip off /v1/ then the next part of the url is the category

        foreach ($items as $path => $item) {
            // info('Athena Parser - path: ' . $path)
            // if (!$category) {
            //     // Get the category by stripping off the first two parts of the URL and everything after the 3rd segment
            //     $category = Str::of($path)
            //         ->replace('/v1/{practiceid}/', '')
            //         ->explode('/')
            //         ->first();
            // }
            $trimmedPath = str_replace('/v1/{practiceid}', '', $path);
            $paths[] = $this->parseItem($trimmedPath, $item);
            // if ($item instanceof PathItem) {
            //     foreach ($item->getOperations() as $method => $operation) {
            //         $trimmedPath = str_replace('/v1/{practiceid}', '', $path);
            //         $paths[] = ['method' => $method, 'path' => $trimmedPath, 'summary' => $operation->summary];
            //         // $tableData[] = [
            //         //     "<fg=magenta>$method</>",
            //         //     $trimmedPath,
            //         //     // $path,
            //         //     // "<fg=bright-magenta>{$operation->operationId}</>",
            //         //     // "<fg=green>{$this->getClassName($operation)}</>",
            //         //     // "<fg=green>{$this->getClassName($trimmedPath)}</>",
            //         //     "<fg=green>{$operation->summary}</>",
            //         // ];
            //
            //         // $requests[] = $this->parseEndpoint($operation, $this->mapParams($item->parameters, 'path'), $path, $method);
            //         $requests[] = $this->parseEndpoint($operation, $this->mapParams($item->parameters, 'path'), $trimmedPath, $method);
            //         break;
            //     }
            // }
        }

        // table([
        //     'Method',
        //     'Path',
        //     // 'Original Operation ID',
        //     // 'Custom Request Name'
        //     'Summary',
        // ], $tableData);
        // info("Paths: \n" . collect($paths)->join("\n"));
        return Arr::flatten($paths, 1);
    }

    protected function parseItem(string $path, PathItem $item): array
    {
        $paths = [];
        foreach ($item->getOperations() as $method => $operation) {
            $paths[] = ['method' => $method, 'path' => $path, 'summary' => $operation->summary];
            // $tableData[] = [
            //     "<fg=magenta>$method</>",
            //     $trimmedPath,
            //     // $path,
            //     // "<fg=bright-magenta>{$operation->operationId}</>",
            //     // "<fg=green>{$this->getClassName($operation)}</>",
            //     // "<fg=green>{$this->getClassName($trimmedPath)}</>",
            //     "<fg=green>{$operation->summary}</>",
            // ];

            // $requests[] = $this->parseEndpoint($operation, $this->mapParams($item->parameters, 'path'), $path, $method);
            // TODO - Re-enable this and move it to a generateEndpoint function
            // $this->endpoints[] = $this->parseEndpoint($operation, $this->mapParams($item->parameters, 'path'), $path, $method);
            // break; // TODO - Remove this!
        }
        return $paths;
    }

    protected function parseEndpoints(Paths $items): array
    {
        $endpoints = [];
        foreach ($items as $path => $item) {
            if ($item instanceof PathItem) {
                foreach ($item->getOperations() as $method => $operation) {
                    // Grab this endpoint's class name from the cache
                    // dd(RequestNameGenerator::endpointCacheKey($path, $item);
                    $endpoints[] = $this->parseEndpoint($operation, $this->mapParams($item->parameters, 'path'), $path, $method);
                }
            }
        }
        return $endpoints;
    }

    protected function parseEndpoint(Operation $operation, $pathParams, string $path, string $method): ?Endpoint
    {
        // dump('Athena Parser - parseEndpoint');
        // $trimmedPath = str_replace('/v1/', '', $path);
        // \Laravel\Prompts\info("<fg=magenta>$method</>\t<fg=blue>$trimmedPath</> <fg=cyan>{$operation->operationId}</>");
        // dd($operation);
        $trimmedPath = str_replace('/v1/{practiceid}', '', $path);
        $classCacheKey = RequestNameGenerator::endpointCacheKey(['path' => $trimmedPath, 'method' => $method]);
        $className = Cache::get($classCacheKey);

        // dump('Path: ' . $trimmedPath);
        // dump('Path: ' . $path);
        // dump('Cache Key: ' . $classCacheKey);
        // dump('Class Name: ' . $className);
        // info("Endpoint: [$method] $path = " . ($className ?? ''));
        // info('Operation ID: ' . $operation->operationId);
        $bodyParams = [];
        if ($operation->requestBody?->content) {
            // alert('Body Content Detected!');
            if (!array_key_exists('application/x-www-form-urlencoded', $operation->requestBody->content)
                && !array_key_exists('multipart/form-data', $operation->requestBody->content)) {
                // dump($operation->requestBody->content);
                error("[$method] $trimmedPath - Body Content type is unknown!");
                dd('Keys: ', array_keys($operation->requestBody->content));
            }
            $bodyContent = $operation->requestBody->content;
            if (array_key_exists('application/x-www-form-urlencoded', $bodyContent)) {
                $bodyContent = $bodyContent['application/x-www-form-urlencoded'];
            } else {
                $bodyContent = $bodyContent['multipart/form-data']; // TODO - May need more types?
            }
            $schema = $bodyContent->schema;
            // extract out the type, required, and properties from the schema
            $schemaType = $schema->type;
            $requiredFields = $schema->required;
            $bodyParams = collect($schema->properties)
                ->map(function ($property, $key) use ($requiredFields) {
                    $subProperties = null;
                    // Type overrides
                    $propertyType = match($property->type) {
                        Type::OBJECT => Type::ARRAY,
                        Type::INTEGER => 'int',
                        Type::BOOLEAN => 'bool',
                        default => $property->type,
                    };

                    // Handle nested properties / objects
                    // if ($property->type === Type::OBJECT) {
                    //     $subProperties = collect($property->properties)
                    //         ->map(function ($subProperty, $subKey) use ($requiredFields) {
                    //             if ($subProperty->type === Type::OBJECT) {
                    //                 alert('FOUND A NESTED OBJECT!');
                    //                 dd($subKey);
                    //             }
                    //             return [
                    //                 'name' => $subKey,
                    //                 'type' => $subProperty->type,
                    //                 'nullable' => !in_array($subKey, $requiredFields ?? []),
                    //                 'description' => $subProperty->description,
                    //             ];
                    //         })
                    //         ->values()->all();
                    // }
                    return new Parameter(
                        type: $propertyType,
                        nullable: !in_array($key, $requiredFields ?? []),
                        name: $key,
                        // 'properties' => $subProperties,
                        description: $property->description,
                    );
                    // return array_filter([
                    //     'name' => $key,
                    //     'type' => $property->type,
                    //     'nullable' => !in_array($key, $requiredFields ?? []),
                    //     // 'properties' => $subProperties,
                    //     'description' => $property->description,
                    // ]);
                })
                ->values()->all();
            // dump($bodyParams);
        }

        $augmentedPathParams = $pathParams + $this->mapParams($operation->parameters, 'path');
        // dump('Augmented Path Params: ', $augmentedPathParams);
        $filteredPathParams = collect($augmentedPathParams)
            ->filter(function ($param) {
                return $param->name !== 'practiceid';
            })
            ->toArray();

        // Build the path segments
        $pathSegments = Str::of($path)
            ->remove('/v1/{practiceid}')
            ->replace('{', ':')
            ->remove('}')
            ->trim('/')
            ->explode('/')
            ->toArray();

        return new Endpoint(
            // name: trim($operation->operationId ?: $operation->summary ?: ''),
            name: trim($className ?? ''),
            method: Method::parse($method),
            pathSegments: $pathSegments,
            collection: $operation->tags[0] ?? null, // In the real-world, people USUALLY only use one tag...
            response: null, // TODO: implement "definition" parsing
            description: $operation->description,
            queryParameters: $this->mapParams($operation->parameters, 'query'),
            // TODO: Check if this differs between spec versions
            pathParameters: $filteredPathParams,
            bodyParameters: $bodyParams,//[], // TODO: implement "definition" parsing
        );
    }

    protected function getClassName(string $path, string $method = 'get'): string
    {
        // $operationId = $operation->operationId;

        // $removableIds = [
        //     'Practiceid',
        //     'Appointmentid',
        // ];

        // Remove the leading slash if it exists
        $path = ltrim($path, '/');

        // Split the path into segments
        $segments = explode('/', $path);

        // Remove the first segment as it's the directory name
        array_shift($segments);

        // Determine if the path is a 'list' or a specific 'get' operation
        $isList = !preg_match('/{.+}/', $path);
        $prefix = $isList ? 'List' : 'Get';

        // Replace any '{param}' with 'ByParam'
        $segments = array_map(function ($segment) {
            return preg_replace_callback('/{(\w+)}/', function ($matches) {
                return '';
                // return 'By' . str_replace(' ', '', ucwords(str_replace('_', ' ', $matches[1])));
            }, $segment);
        }, $segments);

        // Create the StudlyCase string
        $className = str_replace(' ', '', ucwords(implode(' ', $segments)));

        // Prefix the class name with 'List' or 'Get' depending on the presence of parameters
        if (strtoupper($method) === 'GET') {
            $className = $prefix . $className;
        } else {
            $className = ucfirst(strtolower($method)) . $className;
        }

        return $className;

        // Step 1: Remove common redundant parts
        // $operationId = str_ireplace($removableIds, '', $operationId);

        // return Str::studly($operationId);
    }


}
