<?php

namespace RomanNix\LaravelRedisBatchRepository\Queue\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use RomanNix\LaravelRedisBatchRepository\Bus\RedisBatchRepository;

class PruneRedisBatchesCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'queue:prune-redis-batches
                {--hours=24 : The number of hours to retain batch data}
                {--unfinished= : The number of hours to retain unfinished batch data }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune stale entries from the batches redis store';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $repository = $this->laravel[RedisBatchRepository::class];

        $count = $repository->prune(Carbon::now()->subHours($this->option('hours')));
        $this->info("{$count} entries deleted!");

        if ($this->option('unfinished')) {
            $count = $repository->pruneUnfinished(Carbon::now()->subHours($this->option('unfinished')));
            $this->info("{$count} unfinished entries deleted!");
        }
    }
}
