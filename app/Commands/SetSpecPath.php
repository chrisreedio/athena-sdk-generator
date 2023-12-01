<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use LaravelZero\Framework\Commands\Command;

class SetSpecPath extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'spec:path {path?}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Set the path to the spec files.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $path = $this->argument('path');
        if (!$path) {
            // $path = Cache::put('spec:path', $path)
            $path = Cache::get('spec:path');
            if (!$path) {
                $this->error('No spec path override set.');
                $this->warn('Will fall back to the default: ' . config('app.spec.path'));
                return self::FAILURE;
            }
            $this->info("Spec path override is: $path");
            return self::SUCCESS;
        }

        $this->info("Setting spec path override to: $path");
        Cache::put('spec:path', $path);
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
