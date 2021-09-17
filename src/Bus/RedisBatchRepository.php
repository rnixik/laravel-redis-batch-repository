<?php

namespace RomanNix\LaravelRedisBatchRepository\Bus;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\PendingBatch;
use Illuminate\Bus\PrunableBatchRepository;
use Illuminate\Bus\UpdatedBatchJobCounts;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Str;

class RedisBatchRepository implements PrunableBatchRepository
{
    protected BatchFactory $factory;

    protected PhpRedisConnection|PredisConnection $redis;

    protected string $redisPrefix;

    public function __construct(BatchFactory $factory, PhpRedisConnection|PredisConnection $redis, string $redisPrefix)
    {
        $this->factory = $factory;
        $this->redis = $redis;
        $this->redisPrefix = $redisPrefix;
    }

    /**
     * @return \Illuminate\Bus\Batch[]
     */
    public function get($limit, $before): array
    {
        $orderedSetKey = $this->getOrderedSetKeyForId();
        $start = '+';
        if ($before) {
            $beforeIdkey = $this->getBatchKey($before);
            $start = "($beforeIdkey";
        }

        $batches = [];
        if ($this->redis instanceof PhpRedisConnection) {
            $batchesKeys = $this->redis->zrevrangebylex($orderedSetKey, $start, '-', 0, $limit);
        } elseif ($this->redis instanceof PredisConnection) {
            $batchesKeys = $this->redis->zrevrangebylex($orderedSetKey, $start, '-', ['LIMIT' => ['OFFSET' => 0, 'COUNT' => $limit]]);
        }

        foreach ($batchesKeys as $batchKey) {
            $batchData = $this->redis->hgetall($batchKey);
            if ($batchData) {
                $batches[] = $this->redisDataToBatch($batchData);
            }
        }

        return $batches;
    }

    public function find(string $batchId): ?Batch
    {
        $batchKey = $this->getBatchKey($batchId);
        $batchData = $this->redis->hgetall($batchKey);
        if ($batchData === null || $batchData === []) {
            return null;
        }

        return $this->redisDataToBatch($batchData);
    }

    public function store(PendingBatch $batch): Batch
    {
        $batchId = (string) Str::orderedUuid();
        $createdAt = Carbon::now()->getTimestamp();

        $batchKey = $this->getBatchKey($batchId);

        $this->redis->zadd($this->getOrderedSetKeyForId(), [$batchKey => 0]);
        $this->redis->zadd($this->getOrderedSetKeyForCreatedAt(), [$batchKey => $createdAt]);
        $this->redis->hset($batchKey, 'id', $batchId);
        $this->redis->hset($batchKey, 'name', $batch->name);
        $this->redis->hset($batchKey, 'total_jobs', 0);
        $this->redis->hset($batchKey, 'pending_jobs', 0);
        $this->redis->hset($batchKey, 'failed_jobs', 0);
        $this->redis->hset($batchKey, 'options', serialize($batch->options));
        $this->redis->hset($batchKey, 'created_at', $createdAt);
        $this->redis->hset($batchKey, 'cancelled_at', null);
        $this->redis->hset($batchKey, 'finished_at', null);

        $this->redis->del($this->getBatchFailedJobIdsKey($batchId));

        return $this->find($batchId);
    }

    public function incrementTotalJobs(string $batchId, int $amount)
    {
        $batchKey = $this->getBatchKey($batchId);
        $this->redis->hincrby($batchKey, 'total_jobs', $amount);
        $this->redis->hincrby($batchKey, 'pending_jobs', $amount);
        $this->redis->hset($batchKey, 'finished_at', null);
        $this->redis->zrem($this->getOrderedSetKeyForFinishedAt(), $batchKey);
    }

    public function decrementPendingJobs(string $batchId, string $jobId)
    {
        $batchKey = $this->getBatchKey($batchId);
        $pendingJobs = $this->redis->hincrby($batchKey, 'pending_jobs', -1);
        $failedJobs = $this->redis->hget($batchKey, 'failed_jobs');
        $failedJobsKey = $this->getBatchFailedJobIdsKey($batchId);
        $this->redis->srem($failedJobsKey, $jobId);

        return new UpdatedBatchJobCounts(
            $pendingJobs,
            $failedJobs
        );
    }

    public function incrementFailedJobs(string $batchId, string $jobId)
    {
        $batchKey = $this->getBatchKey($batchId);
        $failedJobs = $this->redis->hincrby($batchKey, 'failed_jobs', 1);
        $pendingJobs = $this->redis->hget($batchKey, 'pending_jobs');
        $failedJobsKey = $this->getBatchFailedJobIdsKey($batchId);

        if ($this->redis instanceof PhpRedisConnection) {
            $this->redis->sadd($failedJobsKey, $jobId);
        } elseif ($this->redis instanceof PredisConnection) {
            $this->redis->sadd($failedJobsKey, [$jobId]);
        }

        return new UpdatedBatchJobCounts(
            $pendingJobs,
            $failedJobs
        );
    }

    public function markAsFinished(string $batchId)
    {
        $batchKey = $this->getBatchKey($batchId);
        $finishedAt = Carbon::now()->getTimestamp();
        $this->redis->hset($batchKey, 'finished_at', $finishedAt);
        $this->redis->zadd($this->getOrderedSetKeyForFinishedAt(), [$batchKey => $finishedAt]);
    }

    public function cancel(string $batchId)
    {
        $batchKey = $this->getBatchKey($batchId);
        $now = Carbon::now()->getTimestamp();
        $this->redis->hset($batchKey, 'cancelled_at', $now);
        $this->redis->hset($batchKey, 'finished_at', $now);
        $this->redis->zadd($this->getOrderedSetKeyForFinishedAt(), [$batchKey => $now]);
    }

    public function delete(string $batchId)
    {
        $this->redis->del([
            $this->getBatchKey($batchId),
            $this->getBatchFailedJobIdsKey($batchId),
        ]);
        $this->redis->zrem($this->getOrderedSetKeyForId(), $this->getBatchKey($batchId));
        $this->redis->zrem($this->getOrderedSetKeyForCreatedAt(), $this->getBatchKey($batchId));
        $this->redis->zrem($this->getOrderedSetKeyForFinishedAt(), $this->getBatchKey($batchId));
    }

    public function transaction(Closure $callback)
    {
        $callback();
    }

    public function prune(DateTimeInterface $before)
    {
        $totalDeleted = 0;
        $batchesKeys = $this->redis->zrangebyscore($this->getOrderedSetKeyForFinishedAt(), '0', '(' . $before->getTimestamp());
        foreach ($batchesKeys as $batchKey) {
            // They all are finished
            $batchId = $this->redis->hget($batchKey, 'id');
            if ($batchId) {
                $this->delete($batchId);
                $totalDeleted++;
            }
        }

        return $totalDeleted;
    }

    public function pruneUnfinished(DateTimeInterface $before)
    {
        $totalDeleted = 0;
        $batchesKeys = $this->redis->zrangebyscore($this->getOrderedSetKeyForCreatedAt(), '0', '(' . $before->getTimestamp());
        foreach ($batchesKeys as $batchKey) {
            $batchId = $this->redis->hget($batchKey, 'id');
            $finishedAt = $this->redis->hget($batchKey, 'finished_at');
            if ($batchId && $finishedAt === null) {
                $this->delete($batchId);
                $totalDeleted++;
            }
        }

        return $totalDeleted;
    }

    protected function redisDataToBatch(array $batch): Batch
    {
        $failedJobIds = $this->redis->smembers($this->getBatchFailedJobIdsKey($batch['id']));
        return $this->factory->make(
            $this,
            $batch['id'],
            $batch['name'],
            (int) $batch['total_jobs'],
            (int) $batch['pending_jobs'],
            (int) $batch['failed_jobs'],
            $failedJobIds,
            unserialize($batch['options']),
            CarbonImmutable::createFromTimestamp($batch['created_at']),
            $batch['cancelled_at'] ? CarbonImmutable::createFromTimestamp($batch['cancelled_at']) : null,
            $batch['finished_at'] ? CarbonImmutable::createFromTimestamp($batch['finished_at']) : null,
        );
    }

    protected function getOrderedSetKeyForId(): string
    {
        return $this->redisPrefix . 'job_batches_id';
    }

    protected function getOrderedSetKeyForCreatedAt(): string
    {
        return $this->redisPrefix . 'job_batches_created_at';
    }

    protected function getOrderedSetKeyForFinishedAt(): string
    {
        return $this->redisPrefix . 'job_batches_finished_at';
    }

    protected function getBatchKey(string $batchId): string
    {
        return $this->redisPrefix . 'batch_' . $batchId;
    }

    protected function getBatchFailedJobIdsKey(string $batchId): string
    {
        return $this->redisPrefix . 'failed_job_ids_' . $batchId;
    }
}
