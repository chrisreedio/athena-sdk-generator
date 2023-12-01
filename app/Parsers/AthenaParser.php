<?php

namespace App\Parsers;

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
use Illuminate\Support\Str;
use function collect;
use function realpath;
use function trim;

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
        return new ApiSpecification(
            name: $this->openApi->info->title,
            description: $this->openApi->info->description,
            baseUrl: Arr::first($this->openApi->servers)->url,
            endpoints: $this->parseItems($this->openApi->paths)
        );
    }

    /**
     * @return array|Endpoint[]
     */
    protected function parseItems(Paths $items): array
    {
        $requests = [];

        foreach ($items as $path => $item) {

            if ($item instanceof PathItem) {
                foreach ($item->getOperations() as $method => $operation) {
                    // TODO: variables for the path
                    $requests[] = $this->parseEndpoint($operation, $this->mapParams($item->parameters, 'path'), $path, $method);
                    break;
                }
            }
        }

        return $requests;
    }

    protected function parseEndpoint(Operation $operation, $pathParams, string $path, string $method): ?Endpoint
    {
        // dump('Athena Parser - parseEndpoint');
        dump($method . ' ' . $path . ' ' . $operation->operationId);
        // dd($operation);
        return new Endpoint(
            name: trim($operation->operationId ?: $operation->summary ?: ''),
            method: Method::parse($method),
            pathSegments: Str::of($path)->replace('{', ':')->remove('}')->trim('/')->explode('/')->toArray(),
            collection: $operation->tags[0] ?? null, // In the real-world, people USUALLY only use one tag...
            response: null, // TODO: implement "definition" parsing
            description: $operation->description,
            queryParameters: $this->mapParams($operation->parameters, 'query'),
            // TODO: Check if this differs between spec versions
            pathParameters: $pathParams + $this->mapParams($operation->parameters, 'path'),
            bodyParameters: [], // TODO: implement "definition" parsing
        );
    }

    /**
     * @param  OpenApiParameter[]  $parameters
     * @return Parameter[] array
     */
    protected function mapParams(array $parameters, string $in): array
    {
        return collect($parameters)
            ->filter(fn (OpenApiParameter $parameter) => $parameter->in == $in)
            ->map(fn (OpenApiParameter $parameter) => new Parameter(
                type: $this->mapSchemaTypeToPhpType($parameter->schema?->type),
                nullable: $parameter->required == false,
                name: $parameter->name,
                description: $parameter->description,
            ))
            ->all();
    }

    protected function mapSchemaTypeToPhpType($type): string
    {
        return match ($type) {
            Type::INTEGER => 'int',
            Type::NUMBER => 'float|int', // TODO: is "number" always a float in openapi specs?
            Type::STRING => 'string',
            Type::BOOLEAN => 'bool',
            Type::OBJECT, Type::ARRAY => 'array',
            default => 'mixed',
        };
    }
}
