<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class ParseSpec extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'parse-spec {spec}';

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
        $spec = $this->argument('spec');
        $this->info("Parsing spec file: $spec");
        $this->info("Generating test file: $spec");
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
