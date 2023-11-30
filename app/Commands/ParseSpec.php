<?php

namespace App\Commands;

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

        return self::SUCCESS;
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
