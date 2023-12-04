<?php

namespace App\Parsers;

use App\Classes\RequestNameGenerator;
use cebe\openapi\exceptions\IOException;
use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use cebe\openapi\json\InvalidJsonPointerSyntaxException;
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
use Exception;
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
    /**
     * @throws IOException
     * @throws TypeErrorException
     * @throws UnresolvableReferenceException
     * @throws InvalidJsonPointerSyntaxException
     */
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

        // Generate Request Names
        $response = RequestNameGenerator::collection($paths);

        // Now we need to scan this list of endpoints and ensure that there is a cached OpenAI ClassName for each one
        $endpoints = $this->parseEndpoints($this->openApi->paths);

        return new ApiSpecification(
            name: $this->openApi->info->title,
            description: $this->openApi->info->description,
            baseUrl: Arr::first($this->openApi->servers)->url,
            endpoints: $endpoints,
        );
    }

    /**
     * @return array|Endpoint[]
     */
    protected function parseItems(Paths $items): array
    {
        $paths = [];

        foreach ($items as $path => $item) {
            $trimmedPath = str_replace('/v1/{practiceid}', '', $path);
            $paths[] = $this->parseItem($trimmedPath, $item);
        }

        return Arr::flatten($paths, 1);
    }

    protected function parseItem(string $path, PathItem $item): array
    {
        $paths = [];
        foreach ($item->getOperations() as $method => $operation) {
            $paths[] = ['method' => $method, 'path' => $path, 'summary' => $operation->summary];
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
        $trimmedPath = str_replace('/v1/{practiceid}', '', $path);
        $classCacheKey = RequestNameGenerator::endpointCacheKey(['path' => $trimmedPath, 'method' => $method]);
        $className = Cache::get($classCacheKey);

        // info("Endpoint: [$method] $path = " . ($className ?? ''));
        // info('Operation ID: ' . $operation->operationId);
        $bodyParams = [];
        if ($operation->requestBody?->content) {
            // alert('Body Content Detected!');
            if (!array_key_exists('application/x-www-form-urlencoded', $operation->requestBody->content)
                && !array_key_exists('multipart/form-data', $operation->requestBody->content)) {
                error("[$method] $trimmedPath - Body Content type is unknown!");
                dd('Keys: ', array_keys($operation->requestBody->content));
            }
            $bodyContent = $operation->requestBody->content;
            $bodyContentType = array_keys($bodyContent)[0];
            // dump('initial body content type: ' . $bodyContentType);
            $bodyContent = $bodyContent[$bodyContentType];
            // $bodyContent = match (true) {
            //     isset($bodyContent['application/x-www-form-urlencoded']) => $bodyContent['application/x-www-form-urlencoded'],
            //     isset($bodyContent['multipart/form-data']) => $bodyContent['multipart/form-data'],
            //     default => throw new Exception("Unsupported content type")
            // };
            $schema = $bodyContent->schema;
            // extract out the type, required, and properties from the schema
            $schemaType = $schema->type;
            $requiredFields = $schema->required;
            $bodyParams = collect($schema->properties)
                ->map(function ($property, $key) use ($requiredFields) {
                    $subProperties = null;
                    // Type overrides
                    $propertyType = match ($property->type) {
                        Type::OBJECT => Type::ARRAY,
                        Type::INTEGER => 'int',
                        Type::BOOLEAN => 'bool',
                        default => $property->type,
                    };

                    return new Parameter(
                        type: $propertyType,
                        nullable: !in_array($key, $requiredFields ?? []),
                        name: $key,
                        // 'properties' => $subProperties,
                        description: $property->description,
                    );
                })
                ->values()->all();
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

        $endpoint = new Endpoint(
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
        $endpoint->bodyContentType = $bodyContentType ?? null;
        // dump('endpoint\'s body content type: ' . $endpoint->bodyContentType);
        return $endpoint;
    }
}
