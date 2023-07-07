# Laravel RedisBatchRepository

Replaces default Illuminate\Bus\DatabaseBatchRepository with implementation based on Redis.

## Requirements:

* php 8.1
* laravel 10
* phpredis or predis

## Installation

```
composer require "agentsoftare/laravel-redis-batch-repository"
```

## Pruning

This package provides console command to prune stale batches
from redis store:
```
php artisan queue:prune-redis-batches --hours=24 --unfinished=72
```

## Run tests

```
make tests
```

## License

The MIT Licence
