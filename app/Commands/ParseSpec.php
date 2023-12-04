<?php

namespace App\Commands;

use App\Classes\Category;
use App\Classes\SpecFile;
use App\Generators\AthenaRequestGenerator;
use App\Parsers\AthenaParser;
use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
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
use function basename;
use function explode;
use function Laravel\Prompts\{info, intro, warning, error};
use function dd;
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
    protected string $specPath;

    protected GeneratedCode $code;

    public function __construct()
    {
        parent::__construct();
        $this->initializeSpecPath();
    }

    protected function initializeSpecPath(): void
    {
        $this->specPath = Cache::get('spec:path') ?? config('app.spec.path');
        if (!$this->specPath) {
            $this->error('No spec path set.');
            $this->specPath = null;
        }
    }

    public function handle(): int
    {
        if (!$this->specPath) {
            return self::FAILURE;
        }

        $specScope = $this->argument('spec');

        // Check for the lack of a provided spec scope
        if (is_null($specScope)) {
            $this->processAllCategories();
        } elseif (Str::contains($specScope, '/')) { // Check for a category and group
            $this->processSingleSpecFile($specScope);
        } else { // We have only a single 'name', which is the category
            $this->processCategory($specScope);
        }

        return self::SUCCESS;
    }

    protected function processAllCategories()
    {
        $this->title('Parsing all categories and specs');
        collect(File::directories($this->specPath))->each(function ($path) {
            $this->processCategory(basename($path));
        });
    }

    protected function processCategory($category)
    {
        $this->title('Parsing all files in category: ' . $category);

        $categoryPath = $this->specPath . DIRECTORY_SEPARATOR . $category;
        $files = File::allFiles($categoryPath);

        foreach ($files as $file) {
            $curPath = $file->getRelativePathname();
            if (Str::endsWith($curPath, '.json')) {
                intro(explode('athena_openapi_specs/', $file->getRealPath())[1]);
                $fullSpecPath = $file->getPath() . DIRECTORY_SEPARATOR . $curPath;
                $specFile = SpecFile::make($fullSpecPath)->process();
                // $spec = SpecFile::make($fullSpecPath)->parseSpec();
                // dd($spec);
            }
        }
        return true;
    }

    protected function processSingleSpecFile($specScope)
    {
        [$category, $spec] = explode('/', $specScope);
        $fullPath = $this->specPath . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . $spec . '.json';

        $this->title('Parsing: ' . $spec . ' in category: ' . $category);
        $specFile = SpecFile::make($fullPath)->process();
        // $specFile = SpecFile::make($fullPath);
        // $spec = $specFile->parseSpec();
        // // dd($spec);
        // $code = $specFile->generateSdk();
        // dd($code);
    }


}
