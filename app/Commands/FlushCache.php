<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use LaravelZero\Framework\Commands\Command;

class FlushCache extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'cache:flush';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Flush the cache.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Cache::flush();
        $this->info('Cache flushed.');

        return self::SUCCESS;
    }
}
