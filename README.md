# Laravel RedisBatchRepository

Replaces default Illuminate\Bus\DatabaseBatchRepository with implementation based on Redis.

## Installation

```
composer require "rnix/laravel-redis-batch-repository"
```

Currently, this package cannot re-define `Illuminate\Bus\BatchRepository` with Service Provider.
You can create your own `class BusServiceProvider extends \Illuminate\Bus\BusServiceProvider` 
with contents from `LaravelRedisBatchRepositoryServiceProvider.php`,
and replace with it service provider in config/app.php, but place after `Illuminate\Redis\RedisServiceProvider::class`.

## Run tests

```
make tests
```
