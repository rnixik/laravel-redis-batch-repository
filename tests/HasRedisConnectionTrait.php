<?php

namespace AgentSoftware\LaravelRedisBatchRepository\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Env;

trait HasRedisConnectionTrait
{
    public function getRedisConnection(Application $app, string $driver): PhpRedisConnection|PredisConnection
    {
        $host = Env::get('REDIS_HOST', 'redis');
        $port = Env::get('REDIS_PORT', 6379);

        /** @var PhpRedisConnection|PredisConnection $connection */
        $connection = (new RedisManager($app, $driver, [
            'cluster' => false,
            'options' => [
                'prefix' => 'test_',
            ],
            'default' => [
                'host' => $host,
                'port' => $port,
                'database' => 5,
                'timeout' => 0.5,
                'name' => 'default',
            ],
        ]))->connection();

        return $connection;
    }
}
