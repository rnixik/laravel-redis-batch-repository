<?php

namespace RomanNix\LaravelRedisBatchRepository;

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\ServiceProvider;
use RomanNix\LaravelRedisBatchRepository\Bus\RedisBatchRepository;

class LaravelRedisBatchRepositoryServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(BatchRepository::class, RedisBatchRepository::class);

        $this->app->singleton(RedisBatchRepository::class, function ($app) {
            return new RedisBatchRepository(
                $app->make(BatchFactory::class),
                $app->make('redis')->connection(),
                config('queue.batching.table', 'laravel_batches:')
            );
        });
    }
}
