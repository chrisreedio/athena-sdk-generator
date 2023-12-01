<?php

namespace App\Commands;

use App\Generators\AthenaRequestGenerator;
use App\Parsers\AthenaParser;
use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Exceptions\ParserNotRegisteredException;
use Crescat\SaloonSdkGenerator\Factory;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Nette\PhpGenerator\PhpFile;
use function dump;
use function file_exists;
use function str_replace;
use const DIRECTORY_SEPARATOR;

class ParseSpec extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'spec:parse {spec? : The spec file to parse.} {--force : Overwrite existing files.}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Parse a spec file and generate a test file.';

    protected string $namespace = 'ChrisReedIO\\AthenaSDK';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $specPath = Cache::get('spec:path');
        if (!$specPath) {
            $this->error('No spec path set.');
            return self::FAILURE;
        }

        $spec = null;
        $category = $specScope = $this->argument('spec');
        $this->info('Generating SDK for Spec Scope: ' . $specScope);
        if ($specScope === null) {
            // ALL Categories and Specs
            $this->title('Parsing all categories and specs');
            // Run through each file in the $specPath directory recursively
            $categories = File::directories($specPath);
            foreach ($categories as $category) {
                $category = basename($category);
                dump('category: ' . $category);
                $this->processCategory($category);
            }
        } else if (Str::contains($specScope, '/')) {
            // Single Spec File
            $parts = explode('/', $specScope);
            $category = $parts[0];
            $spec = $parts[1];
            $this->title('Parsing single spec file: ' . $spec . ' in category: ' . $category);
        } else {
            // All Files in a category
            $this->title('Parsing all files in category: ' . $category);
            $this->processCategory($category);
        }

        return self::SUCCESS;
    }

    protected function processCategory(string $category)
    {
        $specPath = Cache::get('spec:path');
        if (!$specPath) {
            $this->error('No spec path set.');
            return self::FAILURE;
        }
        $categoryPath = $specPath . DIRECTORY_SEPARATOR . $category;
        // $fullSpecPath = $specPath . DIRECTORY_SEPARATOR . $category . '.json';
        // $this->processSpecFile($fullSpecPath);
        $files = File::allFiles($categoryPath);
        // collect($files)->each(fn ($file) => dump($file->getRelativePathname()));
        foreach ($files as $file) {
            $curPath = $file->getRelativePathname();
            if (Str::endsWith($curPath, '.json')) {
                // $fullSpecPath = $specPath . DIRECTORY_SEPARATOR . $file;
                // $fullSpecPath = $file->getPath() . DIRECTORY_SEPARATOR . $curPath;
                $fullSpecPath = $file->getPath() . DIRECTORY_SEPARATOR . $curPath;
                $this->info("\tSpec file: $fullSpecPath");
                // $this->processSpecFile($fullSpecPath);
            }
        }
    }

    protected function processSpecFile(string $fullPath)
    {
        // $this->info("Parsing spec file: $fullSpecPath");

        if (!file_exists($fullPath)) {
            $this->error("Spec file not found: $fullPath");
            return self::FAILURE;
        }

        $this->generateSdk($fullPath);
    }

    protected function generateSdk(string $specPath): int
    {
        $config = new Config(
            connectorName: 'AthenaConnector',
            namespace: $this->namespace,
            resourceNamespaceSuffix: 'Resource',
            requestNamespaceSuffix: 'Requests',
            dtoNamespaceSuffix: 'Dto', // Replace with your desired SDK name
            // outputFolder: './Generated', // Replace with your desired output folder
            ignoredQueryParams: ['after', 'order_by', 'per_page'] // Ignore params used for pagination
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
                $this->info("Generated Request Class: " . Utils::formatNamespaceAndClass($requestClass));
                // dump($requestClass->getClasses());
                // $this->dumpToFile($requestClass);
                if ($i++ > 0)
                    break;
            }
        } catch (ParserNotRegisteredException $e) {
            $this->error("Parser not registered: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function dumpToFile(PhpFile $file): void
    {

        $outputRootDir = 'output';
        // dd($file);
        $outputPath = ltrim(str_replace($this->namespace, '', Arr::first($file->getNamespaces())->getName()), '\\');
        $outputPath = str_replace('\\', DIRECTORY_SEPARATOR, $outputPath);
        $outputFilename = Arr::first($file->getClasses())->getName();
        $outputFullPath = collect([$outputRootDir, $outputPath, $outputFilename])->join(DIRECTORY_SEPARATOR) . '.php';
        // dump('outputPath: ' . $outputPath);
        // dump('outputFilename: ' . $outputFilename);
        // dd('outputFullPath: ' . $outputFullPath);

        if (!file_exists(dirname($outputFullPath))) {
            mkdir(dirname($outputFullPath), recursive: true);
        }

        if (file_exists($outputFullPath) && !$this->option('force')) {
            $this->warn("- File already exists: $outputFullPath");

            return;
        }

        if (file_put_contents($outputFullPath, (string)$file) === false) {
            $this->error("- Failed to write: $outputFullPath");
        } else {
            $this->line("- Created: $outputFullPath");
        }
    }

    /**
     * Define the command's schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
