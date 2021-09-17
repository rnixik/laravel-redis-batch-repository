<?php

namespace RomanNix\LaravelRedisBatchRepository\Tests;

class PredisRedisBatchRepositoryTest extends RedisBatchRepositoryTest
{
    use HasRedisConnectionTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->redis = $this->getRedisConnection($this->app, 'predis');
    }
}
