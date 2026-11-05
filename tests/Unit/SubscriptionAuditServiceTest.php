<?php

namespace Tests\Unit;

use App\Jobs\WriteSubscriptionAuditJob;
use App\Services\SubscriptionAuditService;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\Support\SubscriptionAuditTestCase;

class SubscriptionAuditServiceTest extends SubscriptionAuditTestCase
{
    public function testRecordQueuesOnlySafeScalarFieldsOnTheDedicatedRedisQueue(): void
    {
        $event = $this->event([
            'user' => (object)['password' => 'private-model'], 'token' => 'private-token',
            'headers' => ['cookie' => 'private-cookie'],
            'request_details' => json_encode([
                'method' => 'GET', 'path' => '/subscribe?token=private-token',
                'query' => ['flag' => 'clash', 'token' => 'private-token', 'target' => 'private-target'],
                'url' => 'https://example.test/?token=private-token', 'headers' => ['cookie' => 'private-cookie'],
            ]),
        ]);
        (new SubscriptionAuditService())->record($event);
        $this->assertCount(1, $this->jobs);
        $job = $this->jobs[0];
        $this->assertInstanceOf(WriteSubscriptionAuditJob::class, $job);
        $this->assertSame('redis', $job->connection);
        $this->assertSame('insider_guard_audit', $job->queue);
        foreach ($job->event as $value) $this->assertTrue(is_scalar($value) || $value === null);
        $encoded = json_encode($job->event);
        foreach (['private-model', 'private-token', 'private-cookie', 'private-target'] as $secret) $this->assertStringNotContainsString($secret, $encoded);
        $this->assertSame(0, DB::table(SubscriptionAuditService::TABLE)->count());
    }

    public function testDuplicateJobDoesNotDuplicateOrOverwriteTheOriginalEvent(): void
    {
        $service = new SubscriptionAuditService();
        $event = $this->event(['requested_at' => time() - 60]);
        (new WriteSubscriptionAuditJob($event))->handle($service);
        (new WriteSubscriptionAuditJob(array_merge($event, ['status' => 500, 'requested_at' => time()])))->handle($service);
        $this->assertSame(1, DB::table(SubscriptionAuditService::TABLE)->count());
        $stored = DB::table(SubscriptionAuditService::TABLE)->first();
        $this->assertSame($event['requested_at'], (int)$stored->requested_at);
        $this->assertSame(200, (int)$stored->status);
    }

    public function testDelayedExpiredJobDoesNotResurrectPrunedData(): void
    {
        $service = new SubscriptionAuditService();
        $event = $this->event(['requested_at' => time() - 4 * 86400]);
        DB::table(SubscriptionAuditService::TABLE)->insert($event);
        $this->assertSame(1, $service->prune()['deleted']);
        (new WriteSubscriptionAuditJob($event))->handle($service);
        $this->assertSame(0, DB::table(SubscriptionAuditService::TABLE)->count());
    }

    public function testPruningContinuesWhenRecordingIsDisabled(): void
    {
        config(['v2board.insider_guard_subscribe_audit_enabled' => 0, 'v2board.insider_guard_subscribe_audit_max_records' => 2]);
        $service = new SubscriptionAuditService();
        $service->record($this->event());
        $this->assertSame([], $this->jobs);
        DB::table(SubscriptionAuditService::TABLE)->insert($this->event(['requested_at' => time() - 4 * 86400]));
        foreach ([5, 1, 3, 2, 4] as $age) $service->append($this->event(['requested_at' => time() - $age * 60]));
        $result = $service->prune();
        $this->assertSame(4, $result['deleted']);
        $this->assertFalse($result['has_more']);
        $this->assertSame(2, DB::table(SubscriptionAuditService::TABLE)->count());
        $this->assertSame(0, $service->prune()['deleted']);
    }

    public function testCountCapUsesOriginalRequestTimeAndIdForEqualTimestamps(): void
    {
        config(['v2board.insider_guard_subscribe_audit_max_records' => 3]);
        $service = new SubscriptionAuditService();
        $now = time();
        $events = [];
        foreach ([5, 1, 4, 3, 2] as $age) {
            $events[$age] = $this->event(['requested_at' => $now - $age * 60]);
            $service->append($events[$age]);
        }
        $this->assertSame(2, $service->prune()['deleted']);
        $this->assertSame([$events[1]['event_id'], $events[2]['event_id'], $events[3]['event_id']], DB::table(SubscriptionAuditService::TABLE)->orderByDesc('requested_at')->pluck('event_id')->all());
        $sameTime = $this->event(['requested_at' => $now]);
        $service->append($sameTime);
        $latest = $this->event(['requested_at' => $now]);
        $service->append($latest);
        config(['v2board.insider_guard_subscribe_audit_max_records' => 1]);
        $service->prune();
        $this->assertSame([$latest['event_id']], DB::table(SubscriptionAuditService::TABLE)->pluck('event_id')->all());
    }

    public function testLargeReductionDeletesInBatchesWithoutAppendCountsOrSettingsLocks(): void
    {
        $rows = [];
        $now = time();
        for ($i = 0; $i < 2010; $i++) $rows[] = $this->event(['requested_at' => $now - 3000 + $i]);
        foreach (array_chunk($rows, 50) as $chunk) DB::table(SubscriptionAuditService::TABLE)->insert($chunk);
        config(['v2board.insider_guard_subscribe_audit_max_records' => 3]);
        DB::connection()->enableQueryLog();
        $result = (new SubscriptionAuditService())->prune();
        $queries = DB::connection()->getQueryLog();
        $deletes = array_values(array_filter($queries, function ($query) { return stripos($query['query'], 'delete ') === 0; }));
        $this->assertCount(3, $deletes);
        foreach ($deletes as $query) $this->assertLessThanOrEqual(1000, count($query['bindings']));
        foreach ($queries as $query) $this->assertStringNotContainsString('count(', strtolower($query['query']));
        $this->assertSame(2007, $result['deleted']);
        $this->assertFalse($result['has_more']);
        $this->assertSame([$rows[2009]['event_id'], $rows[2008]['event_id'], $rows[2007]['event_id']], DB::table(SubscriptionAuditService::TABLE)->orderByDesc('requested_at')->pluck('event_id')->all());
    }

    public function testQueueFailureCannotInterruptTheCallerOrLogSensitiveMessages(): void
    {
        $dispatcher = Mockery::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('token=private-credential'));
        $this->container->instance(DispatcherContract::class, $dispatcher);
        $service = new SubscriptionAuditService();
        $this->assertNull($service->record($this->event()));
        $this->assertCount(1, $this->logger->warnings);
        $this->assertStringNotContainsString('private-credential', json_encode($this->logger->warnings));
        $this->assertSame(RuntimeException::class, $this->logger->warnings[0][1]['exception_type']);
        $this->assertArrayHasKey('exception_file', $this->logger->warnings[0][1]);
        $this->assertArrayHasKey('exception_line', $this->logger->warnings[0][1]);
    }

    public function testLoggerFailureWhileHandlingAQueueFailureDoesNotEscape(): void
    {
        $dispatcher = Mockery::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis unavailable'));
        $this->container->instance(DispatcherContract::class, $dispatcher);
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->with('daily')->once()->andReturnSelf();
        $logger->shouldReceive('warning')->once()->andThrow(new RuntimeException('Log disk unavailable'));
        Log::swap($logger);
        $this->assertNull((new SubscriptionAuditService())->record($this->event()));
    }

    public function testConfigurationLimitsAreClampedWithoutASettingsTable(): void
    {
        config(['v2board.insider_guard_subscribe_audit_retention_days' => 0, 'v2board.insider_guard_subscribe_audit_max_records' => 0]);
        $service = new SubscriptionAuditService();
        $this->assertSame(1, $service->retentionDays());
        $this->assertSame(1, $service->maxRecords());
        config(['v2board.insider_guard_subscribe_audit_retention_days' => 1000, 'v2board.insider_guard_subscribe_audit_max_records' => 2000000]);
        $this->assertSame(365, $service->retentionDays());
        $this->assertSame(1000000, $service->maxRecords());
    }
}
