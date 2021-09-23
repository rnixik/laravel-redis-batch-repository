# Laravel RedisBatchRepository

Replaces default Illuminate\Bus\DatabaseBatchRepository with implementation based on Redis.

## Requirements:

* php 8
* laravel 8
* phpredis or predis

## Installation

```
composer require "rnix/laravel-redis-batch-repository"
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
