<?php

namespace App\Classes;

use App\Parsers\AthenaParser;
use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Exceptions\ParserNotRegisteredException;
use Crescat\SaloonSdkGenerator\Factory;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpFile;
use function collect;
use function dirname;
use function file_exists;
use function file_put_contents;
use function Laravel\Prompts\{info, warning, error};
use function ltrim;
use function mkdir;
use function str_replace;
use const DIRECTORY_SEPARATOR;

class SpecFile
{
    protected static string $namespace = 'ChrisReedIO\\AthenaSDK';

    public static function process(string $fullPath): bool
    {
        // TODO - Replace this magic value
        $specDir = '/athena_openapi_specs/';
        $localPath = Str::after($fullPath, $specDir);
        // info("Parsing Spec File: $localPath");

        if (!file_exists($fullPath)) {
            error("Spec file not found: $fullPath");
            return false;
        }

        return static::generateSdk($fullPath);
    }

    protected static function generateSdk(string $specPath): bool
    {
        $config = new Config(
            connectorName: 'AthenaConnector',
            namespace: static::$namespace,
            resourceNamespaceSuffix: 'Resource',
            requestNamespaceSuffix: 'Requests',
            dtoNamespaceSuffix: 'Dto', // Replace with your desired SDK name
            ignoredQueryParams: ['limit', 'offset'] // Ignore params used for pagination
        );
        // Register our custom parser
        Factory::registerParser('athena', AthenaParser::class);
        // $generator = new CodeGenerator($config, requestGenerator: new AthenaRequestGenerator($config));
        $generator = new CodeGenerator($config);
        try {
            $spec = Factory::parse('athena', $specPath);
            // dd($spec);
            $result = $generator->run($spec);

            // Generated Connector Class
            // echo "Generated Connector Class: " . Utils::formatNamespaceAndClass($result->connectorClass) . "\n";
            // $this->dumpToFile($result->connectorClass);
            // dd($result->connectorClass);

            // Generated Base Resource Class
            // echo "Generated Base Resource Class: " . Utils::formatNamespaceAndClass($result->resourceBaseClass) . "\n";
            // $this->dumpToFile($result->resourceBaseClass);

            // Generated Resource Classes
            // foreach ($result->resourceClasses as $resourceClass) {
            //     echo "Generated Resource Class: " . Utils::formatNamespaceAndClass($resourceClass) . "\n";
            //     $this->dumpToFile($resourceClass);
            // }

            // Generated Request Classes
            $i = 0;
            foreach ($result->requestClasses as $requestClass) {
                // info("Generated Request Class: " . Utils::formatNamespaceAndClass($requestClass));
                // dump($requestClass->getClasses());
                static::dumpToFile($requestClass);
                // if ($i++ > 0)
                //     break;
            }
        } catch (ParserNotRegisteredException $e) {
            error("Parser not registered: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    protected static function dumpToFile(PhpFile $file): void
    {
        $outputRootDir = 'output';
        // dd($file);
        $outputPath = ltrim(str_replace(static::$namespace, '', Arr::first($file->getNamespaces())->getName()), '\\');
        $outputPath = str_replace('\\', DIRECTORY_SEPARATOR, $outputPath);
        $outputFilename = Arr::first($file->getClasses())->getName();
        $outputFullPath = collect([$outputRootDir, $outputPath, $outputFilename])->join(DIRECTORY_SEPARATOR) . '.php';
        // dump('outputPath: ' . $outputPath);
        // dump('outputFilename: ' . $outputFilename);
        // dd('outputFullPath: ' . $outputFullPath);

        if (!file_exists(dirname($outputFullPath))) {
            mkdir(dirname($outputFullPath), recursive: true);
        }

        if (file_exists($outputFullPath)) {// && !$this->option('force')) {
            // warning("File already exists: $outputFullPath");

            return;
        }

        if (file_put_contents($outputFullPath, (string)$file) === false) {
            error("Failed to write: $outputFullPath");
        } // else {
            // $this->line("- Created: $outputFullPath");
        // }
    }
}
