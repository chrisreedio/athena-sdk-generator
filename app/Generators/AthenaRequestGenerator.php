<?php

namespace App\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generators\RequestGenerator;
use Crescat\SaloonSdkGenerator\Helpers\MethodGeneratorHelper;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
// use DateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method as SaloonHttpMethod;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;
use Saloon\Traits\Body\HasJsonBody;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Saloon\Traits\Body\HasMultipartBody;
use function collect;
use function dd;
use function dump;
use function in_array;
use function Laravel\Prompts\alert;
use function Laravel\Prompts\info;
use function sprintf;

class AthenaRequestGenerator extends RequestGenerator
{
   public function generate(ApiSpecification $specification): PhpFile|array
    {
        // dd('Athena Request Generator');
        $classes = [];

        foreach ($specification->endpoints as $endpoint) {
            // dd(array_keys((array)$endpoint));
            // info("\tGenerating SDK Files for <fg=yellow>{$endpoint->category}</> / <fg=cyan>{$endpoint->group}</>");
            info("\t<fg=red>{$endpoint->method->name}</> <fg=magenta>{$endpoint->name}</> - <fg=yellow>{$endpoint->pathAsString()}</>");
            $classes[] = $this->generateRequestClass($endpoint);
            // break; // TODO - Remove this break
        }

        return $classes;
    }

    protected function generateRequestClass(Endpoint $endpoint): PhpFile
    {
        $resourceName = NameHelper::resourceClassName($endpoint->collection ?: $this->config->fallbackResourceName);

        $className = NameHelper::requestClassName($endpoint->name);
        // dd($endpoint);
        // info("Endpoint: {$endpoint->name} - Class Name: $className");
        $classType = new ClassType($className);

        $classFile = new PhpFile;
        $namespaceParts = [
            $this->config->namespace,
            $this->config->requestNamespaceSuffix,
            $endpoint->category, // This or resourceName, not both
            // $resourceName, // This or Category, not both
            $endpoint->group,
        ];
        // dump('Namespace: ' . $this->config->namespace);
        // dump('Namespace Suffix: ' . $this->config->requestNamespaceSuffix);
        // dump('Resource Name: ' . $resourceName);
        // $namespaceString = "{$this->config->namespace}\\{$this->config->requestNamespaceSuffix}\\{$resourceName}";
        $namespaceString = collect(array_filter($namespaceParts))->join('\\');
        // $namespaceString = collect($namespaceParts)->join('\\');
        // dump('Namespace String: ' . $namespaceString);
        $namespace = $classFile->addNamespace($namespaceString);

        $classType->setExtends(Request::class)
            ->setComment($endpoint->name)
            ->addComment('')
            ->addComment(Utils::wrapLongLines($endpoint->description ?? ''));

        if ($endpoint->bodyContentType) {
            // dump('body content type: ' . $endpoint->bodyContentType);
            if ($endpoint->bodyContentType === 'application/x-www-form-urlencoded') {
                $classType
                    ->addImplement(HasBody::class)
                    ->addTrait(HasFormBody::class);

                $namespace
                    ->addUse(HasBody::class)
                    ->addUse(HasFormBody::class);
            } elseif ($endpoint->bodyContentType === 'multipart/form-data') {
                $classType
                    ->addImplement(HasBody::class)
                    ->addTrait(HasMultipartBody::class);

                $namespace
                    ->addUse(HasBody::class)
                    ->addUse(HasMultipartBody::class);
            } else {
                dd('Unknown body content type: ' . $endpoint->bodyContentType);
            }
        }

        // TODO: We assume JSON body if post/patch, make these assumptions configurable in the future.
        // if ($endpoint->method->isPost() || $endpoint->method->isPatch()) {
        //     $classType
        //         ->addImplement(HasBody::class)
        //         ->addTrait(HasJsonBody::class);
        //
        //     $namespace
        //         ->addUse(HasBody::class)
        //         ->addUse(HasJsonBody::class);
        // }

        $classType->addProperty('method')
            ->setProtected()
            ->setType(SaloonHttpMethod::class)
            ->setValue(
                new Literal(
                    sprintf('Method::%s', $endpoint->method->value)
                )
            );

        $classType->addMethod('resolveEndpoint')
            ->setPublic()
            ->setReturnType('string')
            ->addBody(
                collect($endpoint->pathSegments)
                    ->map(function ($segment) {
                        return Str::startsWith($segment, ':')
                            ? new Literal(sprintf('{$this->%s}', NameHelper::safeVariableName($segment)))
                            : $segment;
                    })
                    ->pipe(function (Collection $segments) {
                        return new Literal(sprintf('return "/%s";', $segments->implode('/')));
                    })

            );

        $classConstructor = $classType->addMethod('__construct');

        // Priority 1. - Path Parameters
        foreach ($endpoint->pathParameters as $pathParam) {
            MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $pathParam);
        }

        // Priority 2. - Body Parameters
        if (! empty($endpoint->bodyParameters)) {

            // TODO - Refactor this to allow for 'nested' parameters.
            // alert('Body Parameters');
            // dd($endpoint->bodyParameters);
            $bodyParams = collect($endpoint->bodyParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredBodyParams))
                ->values()
                ->toArray();

            foreach ($bodyParams as $bodyParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $bodyParam);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultBody', $bodyParams, withArrayFilterWrapper: true);
        }

        // Priority 3. - Query Parameters
        if (! empty($endpoint->queryParameters)) {
            $queryParams = collect($endpoint->queryParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredQueryParams))
                ->values()
                ->toArray();

            foreach ($queryParams as $queryParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $queryParam);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultQuery', $queryParams, withArrayFilterWrapper: true);
        }

        $namespace
            ->addUse(SaloonHttpMethod::class)
            // ->addUse(DateTime::class)
            ->addUse(Request::class)
            ->add($classType);

        return $classFile;
    }
}
