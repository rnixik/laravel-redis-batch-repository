<?php

namespace RomanNix\LaravelRedisBatchRepository;

use Illuminate\Support\ServiceProvider;
use RomanNix\LaravelRedisBatchRepository\Queue\Console\PruneRedisBatchesCommand;

class ConsoleCommandsServiceProvider extends ServiceProvider
{
    public function register()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneRedisBatchesCommand::class,
            ]);
        }
    }
}
