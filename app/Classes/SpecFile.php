<?php

namespace App\Classes;

use App\Generators\AthenaRequestGenerator;
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
use function explode;
use function file_exists;
use function file_put_contents;
use function Laravel\Prompts\{info, intro, note, progress, warning, error};
use function ltrim;
use function mkdir;
use function split;
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
            ignoredQueryParams: ['limit', 'offset', 'practiceid'] // Ignore params used for pagination
        );
        // Register our custom parser
        Factory::registerParser('athena', AthenaParser::class);
        $generator = new CodeGenerator($config, requestGenerator: new AthenaRequestGenerator($config));
        // $generator = new CodeGenerator($config);
        try {
            $spec = Factory::parse('athena', $specPath);
            // dd('done');
            // dump('Spec:');
            intro('Done parsing spec file. Generating SDK...');
            // dd($spec);
            $result = $generator->run($spec);

            // Generated Connector Class
            intro('Generating Connector Class...');
            note("Generating: " . static::colorPath($result->connectorClass));
            static::dumpToFile($result->connectorClass);
            // dd($result->connectorClass);

            // Generated Base Resource Class
            intro('Generating Base Resource Class...');
            note("Generating: " . static::colorPath($result->resourceBaseClass));
            static::dumpToFile($result->resourceBaseClass);

            // Generated Resource Classes
            intro('Generating Resource Classes...');
            foreach ($result->resourceClasses as $resourceClass) {
                note("Generating: " . static::colorPath($resourceClass));
                static::dumpToFile($resourceClass);
            }

            // Generated Request Classes
            intro('Generating Request Classes...');
            foreach ($result->requestClasses as $requestClass) {
                note("Generating: " . static::colorPath($requestClass));
                static::dumpToFile($requestClass);
            }

        } catch (ParserNotRegisteredException $e) {
            error("Parser not registered: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    private static function colorPath(PhpFile $file): string
    {
        [$namespace, $class] = explode('@', Utils::formatNamespaceAndClass($file));
        return "<fg=green>$namespace</>@<fg=cyan>$class</>";
    }

    protected static function dumpToFile(PhpFile $file): void
    {
        $outputRootDir = config('app.output.path');
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

        // if (file_exists($outputFullPath)) {// && !$this->option('force')) {
        // warning("File already exists: $outputFullPath");
        // return;
        // }

        if (file_put_contents($outputFullPath, (string)$file) === false) {
            error("Failed to write: $outputFullPath");
        } // else {
        // $this->line("- Created: $outputFullPath");
        // }
    }
}
