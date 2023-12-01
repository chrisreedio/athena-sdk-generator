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
use function Laravel\Prompts\{info, warning, error, table};
use function collect;
use function realpath;
use function str_replace;
use function substr_replace;
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

        $tableData = [];
        $paths = [];
        $category = null;
        // Find the category from the first item - strip off /v1/ then the next part of the url is the category
        // dd('BIG FACTS: ', array_keys($items->getPaths()));

        foreach ($items as $path => $item) {
            // info('Athena Parser - path: ' . $path)
            if (!$category) {
                // Get the category by stripping off the first two parts of the URL and everything after the 3rd segment
                $category = Str::of($path)
                    ->replace('/v1/{practiceid}/', '')
                    ->explode('/')
                    ->first();
            }
            if ($item instanceof PathItem) {
                foreach ($item->getOperations() as $method => $operation) {
                    // TODO: variables for the path
                    $trimmedPath = str_replace('/v1/{practiceid}', '', $path);
                    $paths[] = ['method' => $method, 'path' => $trimmedPath, 'summary' => $operation->summary];
                    $tableData[] = [
                        "<fg=magenta>$method</>",
                        $trimmedPath,
                        // $path,
                        "<fg=bright-magenta>{$operation->operationId}</>",
                        // "<fg=green>{$this->getClassName($operation)}</>",
                        // "<fg=green>{$this->getClassName($trimmedPath)}</>",
                        "<fg=green>{$operation->summary}</>",
                    ];

                    // $requests[] = $this->parseEndpoint($operation, $this->mapParams($item->parameters, 'path'), $path, $method);
                    $requests[] = $this->parseEndpoint($operation, $this->mapParams($item->parameters, 'path'), $trimmedPath, $method);
                    break;
                }
            }
        }

        table([
            'Method',
            'Path',
            'Original Operation ID',
            // 'Custom Request Name'
            'Summary',
        ], $tableData);
        // info("Paths: \n" . collect($paths)->join("\n"));
        echo(json_encode($paths, JSON_PRETTY_PRINT) . "\n");

        return $requests;
    }

    protected function parseEndpoint(Operation $operation, $pathParams, string $path, string $method): ?Endpoint
    {
        // dump('Athena Parser - parseEndpoint');
        // $trimmedPath = str_replace('/v1/', '', $path);
        // \Laravel\Prompts\info("<fg=magenta>$method</>\t<fg=blue>$trimmedPath</> <fg=cyan>{$operation->operationId}</>");
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
