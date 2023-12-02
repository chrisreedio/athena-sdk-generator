<?php

namespace App\Classes;

use App\Generators\AthenaRequestGenerator;
use App\Parsers\AthenaParser;
use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Exceptions\ParserNotRegisteredException;
use Crescat\SaloonSdkGenerator\Factory;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpFile;
use function collect;
use function dd;
use function dirname;
use function explode;
use function file_exists;
use function file_put_contents;
use function Laravel\Prompts\{info, intro, note, outro, progress, warning, error};
use function ltrim;
use function mkdir;
use function str_replace;
use const DIRECTORY_SEPARATOR;

class SpecFile
{
    protected static string $namespace = 'ChrisReedIO\\AthenaSDK';
    // protected Config $config;
    // Path to the spec file from the root of the spec files
    protected ?string $localPath = null;
    public ?string $category = null;
    public ?string $group = null;

    protected CodeGenerator $generator;
    protected ?ApiSpecification $spec = null;
    protected ?GeneratedCode $code = null;

    const PARSER = 'athena';

    public function __construct(protected string $specPath, protected ?Config $config = null)
    {
        if (!file_exists($this->specPath)) {
            error("Spec file not found: {$this->specPath}");
            return false;
        }

        if (!$this->config) {
            $this->initializeConfig();
        }

        // Ensure that the Athena parser is registered
        Factory::registerParser(self::PARSER, AthenaParser::class);

        // Setup our code generator
        $this->generator = new CodeGenerator($this->config, requestGenerator: new AthenaRequestGenerator($this->config));

        // Calculate the category and group from the spec path
        // TODO - Replace this magic value
        $this->localPath = Str::after($this->specPath, '/athena_openapi_specs/');
        $this->category = Str::of($this->localPath)->before('/')->studly();
        $this->group = Str::of($this->localPath)->after('/')->replace('.json', '')->studly();
        // info('Processing: ' . $this->localPath);
        // info($this->category . ' -> ' . $this->group);
    }

    public static function make(string $specPath): self
    {
        return new static($specPath);
    }

    private function initializeConfig(): void
    {
        $this->config = new Config(
            connectorName: 'AthenaConnector',
            namespace: static::$namespace,
            resourceNamespaceSuffix: 'Resource',
            requestNamespaceSuffix: 'Requests',
            dtoNamespaceSuffix: 'Dto', // Replace with your desired SDK name
            ignoredQueryParams: ['limit', 'offset', 'practiceid'] // Ignore params used for pagination
        );
    }

    public function process(): bool
    {
        // info("Parsing Spec File: <fg=yellow>$this->localPath</>");
        $spec = $this->parseSpec();

        // intro('Done parsing spec file. Generating SDK...');
        $code = $this->generateSdk();

        // info('SDK Generated Successfully! ✨ Now writing files...');
        $this->generateFiles();

        return true;
    }

    public function parseSpec(): ?ApiSpecification
    {
        try {
            $this->spec = Factory::parse('athena', $this->specPath);
        } catch (ParserNotRegisteredException $e) {
            error("Parser not registered: {$e->getMessage()}");
            $this->spec = null;
        }
        return $this->spec;
    }

    protected function generateSdk(): ?GeneratedCode
    {
        if (!$this->spec) {
            error('No spec found. Cannot generate SDK. Run parseSpec() first.');
            return null;
        }

        $this->code = $this->generator->run($this->spec);

        return $this->code;
    }

    public function generateFiles(): void
    {
        // Generated Connector Class
        intro('Generating Connector Class...');
        static::dumpToFile($this->code->connectorClass);

        intro('Generating Base Resource Class...');
        static::dumpToFile($this->code->resourceBaseClass);

        // Generated Resource Classes
        intro('Generating Resource Classes...');
        foreach ($this->code->resourceClasses as $resourceClass) {
            static::dumpToFile($resourceClass);
        }

        intro('Generating Request Classes...');
        foreach ($this->code->requestClasses as $requestClass) {
            static::dumpToFile($requestClass);
        }
        // info('🎉 All files written successfully! 🎉');
    }


    private static function colorPath(PhpFile $file): string
    {
        [$namespace, $class] = explode('@', Utils::formatNamespaceAndClass($file));
        return "<fg=green>$namespace</>@<fg=cyan>$class</>";
    }

    protected static function dumpToFile(PhpFile $file): void
    {
        note("Generating: " . static::colorPath($file));

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
