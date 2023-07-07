<?php

namespace AgentSoftware\LaravelRedisBatchRepository;

use Illuminate\Support\ServiceProvider;
use AgentSoftware\LaravelRedisBatchRepository\Queue\Console\PruneRedisBatchesCommand;

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
