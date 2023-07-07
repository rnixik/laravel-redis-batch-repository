<?php

namespace AgentSoftware\LaravelRedisBatchRepository\Tests;

use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Container\Container;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Collection;
use Orchestra\Testbench\TestCase;
use AgentSoftware\LaravelRedisBatchRepository\Bus\RedisBatchRepository;

class RedisBatchRepositoryTest extends TestCase
{
    use HasRedisConnectionTrait;

    protected PhpRedisConnection|PredisConnection $redis;

    public function setUp(): void
    {
        parent::setUp();
        $this->redis = $this->getRedisConnection($this->app, 'phpredis');
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->redis->flushdb();
        $this->redis->disconnect();
    }

    public function getRepository(): RedisBatchRepository
    {
        return new RedisBatchRepository(
            $this->app->get(BatchFactory::class),
            $this->redis,
            'redis_batches_test'
        );
    }

    public function testStore()
    {
        $pendingBatch = new PendingBatch(
            $this->app->get(Container::class),
            new Collection()
        );
        $pendingBatch->name('Test batch');

        $batch = $this->getRepository()->store($pendingBatch);
        $this->assertNotNull($batch->id);
        $this->assertNotNull($batch->createdAt);
        $this->assertEquals(0, $batch->totalJobs);
        $this->assertEquals(0, $batch->pendingJobs);
        $this->assertEquals(0, $batch->failedJobs);
        $this->assertCount(0, $batch->failedJobIds);
        $this->assertEquals('Test batch', $batch->name);
        $this->assertNull($batch->cancelledAt);
        $this->assertNull($batch->finishedAt);
    }

    public function testGet()
    {
        $this->fillRedisWithBatches(10);

        $batchesPage1 = $this->getRepository()->get(3, null);
        $this->assertCount(3, $batchesPage1);
        $this->assertEquals("Test batch 10", $batchesPage1[0]->name);
        $this->assertEquals("Test batch 9", $batchesPage1[1]->name);
        $this->assertEquals("Test batch 8", $batchesPage1[2]->name);

        $batchesPage2 = $this->getRepository()->get(3, $batchesPage1[2]->id);
        $this->assertCount(3, $batchesPage2);
        $this->assertEquals("Test batch 7", $batchesPage2[0]->name);
        $this->assertEquals("Test batch 6", $batchesPage2[1]->name);
        $this->assertEquals("Test batch 5", $batchesPage2[2]->name);

        $batchesPage3 = $this->getRepository()->get(3, $batchesPage2[2]->id);
        $this->assertCount(3, $batchesPage3);
        $batchesPage4 = $this->getRepository()->get(3, $batchesPage3[2]->id);
        $this->assertCount(1, $batchesPage4);
        $this->assertEquals("Test batch 1", $batchesPage4[0]->name);
    }

    public function testIncrementTotalJobs()
    {
        $batch = $this->fillRedisWithBatches(1)[0];
        $this->getRepository()->incrementTotalJobs($batch->id, 1);
        $this->getRepository()->incrementTotalJobs($batch->id, 3);

        $batch = $this->getRepository()->find($batch->id);
        $this->assertEquals(4, $batch->totalJobs);
        $this->assertEquals(4, $batch->pendingJobs);
        $this->assertNull($batch->finishedAt);
    }

    public function testDecrementPendingJobs()
    {
        $batch = $this->fillRedisWithBatches(2)[0];
        $this->getRepository()->incrementTotalJobs($batch->id, 10);
        $counts1 = $this->getRepository()->decrementPendingJobs($batch->id, 'some-job-id-1');

        $batch = $this->getRepository()->find($batch->id);
        $this->assertEquals(9, $batch->pendingJobs);
        $this->assertEquals(9, $counts1->pendingJobs);
        $this->assertEquals(0, $counts1->failedJobs);

        $counts = $this->getRepository()->incrementFailedJobs($batch->id, 'some-job-id-2');
        $batch = $this->getRepository()->find($batch->id);
        $this->assertEquals(9, $batch->pendingJobs);
        $this->assertEquals(9, $counts->pendingJobs);
        $this->assertEquals(1, $counts->failedJobs);

        $counts = $this->getRepository()->incrementFailedJobs($batch->id, 'some-job-id-3');
        $batch = $this->getRepository()->find($batch->id);
        $this->assertEquals(9, $counts->pendingJobs);
        $this->assertEquals(2, $counts->failedJobs);
        $this->assertCount(2, $batch->failedJobIds);

        $counts = $this->getRepository()->decrementPendingJobs($batch->id, 'some-job-id-2');
        $batch = $this->getRepository()->find($batch->id);
        $this->assertEquals(8, $batch->pendingJobs);
        $this->assertEquals(8, $counts->pendingJobs);
        $this->assertEquals(2, $counts->failedJobs);

        $this->assertCount(1, $batch->failedJobIds);
        $this->assertContains('some-job-id-3', $batch->failedJobIds);
    }

    public function testMarkAsFinished()
    {
        $batch = $this->fillRedisWithBatches(2)[0];
        $this->getRepository()->markAsFinished($batch->id);
        $batch = $this->getRepository()->find($batch->id);
        $this->assertTrue($batch->finished());
    }

    public function testCancel()
    {
        $batch = $this->fillRedisWithBatches(2)[0];
        $this->getRepository()->cancel($batch->id);
        $batch = $this->getRepository()->find($batch->id);
        $this->assertTrue($batch->finished());
        $this->assertTrue($batch->canceled());
    }

    public function testDelete()
    {
        $batch = $this->fillRedisWithBatches(2)[0];
        $this->getRepository()->delete($batch->id);
        $batch = $this->getRepository()->find($batch->id);
        $this->assertNull($batch);
    }

    public function testPrune()
    {
        $repository = $this->getRepository();
        Carbon::setTestNow(Carbon::now()->subDays(7));
        $batches = $this->fillRedisWithBatches(10);

        Carbon::setTestNow(Carbon::now()->subDays(5));
        for ($i = 1; $i <= 4; $i++) {
            $repository->markAsFinished($batches[$i]->id);
        }
        Carbon::setTestNow();

        $batches = $repository->get(10, null);
        $this->assertCount(10, $batches);

        $count = $repository->prune(Carbon::now()->subDays(3));
        $this->assertEquals(4, $count);
        $batches = $repository->get(10, null);
        $this->assertCount(6, $batches);
    }

    public function pruneUnfinished()
    {
        $repository = $this->getRepository();
        $this->fillRedisWithBatches(4);
        Carbon::setTestNow(Carbon::now()->subDays(7));
        $this->fillRedisWithBatches(6);
        Carbon::setTestNow();

        $batches = $repository->get(10, null);
        $this->assertCount(10, $batches);

        $count = $repository->pruneUnfinished(Carbon::now()->subDays(3));
        $this->assertEquals(6, $count);
        $batches = $repository->get(10, null);
        $this->assertCount(4, $batches);
    }

    /**
     * @return Batch[]
     */
    protected function fillRedisWithBatches(int $num)
    {
        $batches = [];
        $repository = $this->getRepository();
        for ($i = 1; $i <= $num; $i++) {
            $pendingBatch = new PendingBatch(
                $this->app->get(Container::class),
                new Collection()
            );
            $pendingBatch->name("Test batch $i");
            $batches[] = $repository->store($pendingBatch);
        }

        return $batches;
    }
}
