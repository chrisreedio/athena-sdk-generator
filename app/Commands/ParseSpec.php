<?php

namespace App\Commands;

use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Exceptions\ParserNotRegisteredException;
use Crescat\SaloonSdkGenerator\Factory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use LaravelZero\Framework\Commands\Command;

class ParseSpec extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'spec:parse {spec : The spec file to parse.}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Parse a spec file and generate a test file.';

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

        $spec = $this->argument('spec');
        $filename = $spec . '.json';
        $fullSpecPath = $specPath . '/' . $filename;

        $this->info("Parsing spec file: $fullSpecPath");
        $this->info("Generating test file: output/$spec");

        if (!file_exists($fullSpecPath)) {
            $this->error("Spec file not found: $fullSpecPath");
            return self::FAILURE;
        }

        $this->generateSdk($fullSpecPath);

        return self::SUCCESS;
    }

    protected function generateSdk(string $specPath)
    {
        $config = new Config(
            connectorName: 'MySDK',
            namespace: "App\Sdk",
            resourceNamespaceSuffix: 'Resource',
            requestNamespaceSuffix: 'Requests',
            dtoNamespaceSuffix: 'Dto', // Replace with your desired SDK name
            // outputFolder: './Generated', // Replace with your desired output folder
            ignoredQueryParams: ['after', 'order_by', 'per_page'] // Ignore params used for pagination
        );
        $generator = new CodeGenerator($config);
        try {
            $result = $generator->run(Factory::parse('openapi', $specPath));
        } catch (ParserNotRegisteredException $e) {
            $this->error("Parser not registered: {$e->getMessage()}");
            return self::FAILURE;
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
