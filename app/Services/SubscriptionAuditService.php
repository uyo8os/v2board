<?php

namespace App\Services;

use App\Jobs\WriteSubscriptionAuditJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** 逐次订阅明细；与原有小时活动、评分和限制独立，队列不记录终身计数。 */
class SubscriptionAuditService
{
    const TABLE = 'v2_insider_guard_subscribe_audit';
    const QUEUE = 'insider_guard_audit';
    const CHUNK = 1000;
    const PRUNE_SECONDS = 20;
    private static $lastLogAt = [];

    public function enabled(): bool
    {
        return (int)config('v2board.insider_guard_subscribe_audit_enabled', 1) === 1;
    }

    public function retentionDays(): int
    {
        return max(1, min(365, (int)config('v2board.insider_guard_subscribe_audit_retention_days', 3)));
    }

    public function maxRecords(): int
    {
        return max(1, min(1000000, (int)config('v2board.insider_guard_subscribe_audit_max_records', 100000)));
    }

    /** 返回响应前只整理安全标量并入队；Redis / 日志故障均不能影响订阅。 */
    public function record(array $event): void
    {
        if (!$this->enabled()) return;
        try {
            // 必须在投递前过滤；不能让未知属性或请求模型进入 Redis / failed jobs。
            WriteSubscriptionAuditJob::dispatch($this->payload($event));
        } catch (\Throwable $error) {
            $this->logFailure('Subscription audit enqueue failed', $error);
        }
    }

    /** 队列 worker 内执行；异常交给队列重试，相同事件不重复写入。 */
    public function append(array $event): void
    {
        $event = $this->payload($event);
        if ($event['requested_at'] < time() - $this->retentionDays() * 86400) return;
        DB::table(self::TABLE)->insertOrIgnore($event);
    }

    private function payload(array $event): array
    {
        $id = $event['event_id'] ?? '';
        $userId = $event['user_id'] ?? 0;
        $source = $event['source'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/i', $id)
            || !is_scalar($userId) || !preg_match('/^\d{1,10}$/', (string)$userId)
            || (int)$userId < 1 || (int)$userId > 2147483647
            || !in_array($source, ['subscription', 'app'], true)) {
            throw new \InvalidArgumentException('Invalid subscription audit identity');
        }
        $ip = $event['ip_address'] ?? '';
        $ip = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? inet_ntop(inet_pton($ip)) : '';
        $format = $event['format'] ?? '';
        $format = is_string($format) && preg_match('/^[A-Za-z0-9_.-]{0,64}$/', $format) ? $format : '';
        $result = in_array($event['result'] ?? '', ['success', 'unavailable', 'error'], true) ? $event['result'] : 'error';
        $email = SubscriptionAuditRequest::boundedText($event['email'] ?? '', 256);
        $email = preg_replace('/^(.{0,64}).*$/us', '$1', $email);
        $details = $event['request_details'] ?? null;
        if (is_string($details)) {
            $details = strlen($details) <= SubscriptionAuditRequest::MAX_DETAILS_BYTES ? json_decode($details, true, 8) : null;
        }
        return [
            'event_id' => strtolower($id), 'user_id' => (int)$userId,
            'email' => $email, 'ip_address' => $ip,
            'ua' => SubscriptionAuditRequest::boundedText($event['ua'] ?? '', 512),
            'source' => $source, 'format' => $format, 'result' => $result,
            'status' => $this->integer($event['status'] ?? 500, 100, 599, 500),
            'duration_ms' => $this->integer($event['duration_ms'] ?? 0, 0, 4294967295, 0),
            'requested_at' => $this->integer($event['requested_at'] ?? time(), 1, 4294967295, time()),
            'request_details' => is_array($details) ? SubscriptionAuditRequest::encodeDetails($details) : null,
        ];
    }

    private function integer($value, int $minimum, int $maximum, int $default): int
    {
        return is_scalar($value) && is_numeric($value) ? max($minimum, min($maximum, (int)$value)) : $default;
    }

    /**
     * 即使关闭采集也清理。先按时间，再按保留条数边界淘汰最旧记录。
     * 每批最多 1000 行，20 秒预算在批次间检查；不保证单条 SQL 的执行时限。
     * 并发入队可能使数量短时超限，由下一轮继续收敛，无每次写入的全表 COUNT 或全局锁。
     */
    public function prune(): array
    {
        $deadline = microtime(true) + self::PRUNE_SECONDS;
        $deleted = 0;
        $cutoff = time() - $this->retentionDays() * 86400;
        do {
            $ids = DB::table(self::TABLE)->where('requested_at', '<', $cutoff)
                ->orderBy('requested_at')->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
            if (!$ids) break;
            $deleted += DB::table(self::TABLE)->whereIn('id', $ids)->delete();
        } while (microtime(true) < $deadline);
        if (microtime(true) >= $deadline) return ['deleted' => $deleted, 'has_more' => true];

        // 只在清理轮次按时间索引寻找第 N+1 条；较晚到达的旧事件也按原请求时间淘汰。
        $boundary = DB::table(self::TABLE)->orderByDesc('requested_at')->orderByDesc('id')
            ->offset($this->maxRecords())->first(['requested_at', 'id']);
        if (!$boundary) return ['deleted' => $deleted, 'has_more' => false];
        if (microtime(true) >= $deadline) return ['deleted' => $deleted, 'has_more' => true];
        do {
            $ids = $this->atOrBefore($boundary)->orderBy('requested_at')->orderBy('id')
                ->limit(self::CHUNK)->pluck('id')->all();
            if (!$ids) break;
            $deleted += DB::table(self::TABLE)->whereIn('id', $ids)->delete();
        } while (microtime(true) < $deadline);
        return ['deleted' => $deleted, 'has_more' => $this->atOrBefore($boundary)->exists()];
    }

    private function atOrBefore($boundary)
    {
        return DB::table(self::TABLE)->where(function ($query) use ($boundary) {
            $query->where('requested_at', '<', $boundary->requested_at)
                ->orWhere(function ($sameTime) use ($boundary) {
                    $sameTime->where('requested_at', $boundary->requested_at)->where('id', '<=', $boundary->id);
                });
        });
    }

    private function logFailure(string $message, \Throwable $error): void
    {
        try {
            if (!Cache::add('insider_guard_audit_log:' . md5(__FILE__ . $message), 1, 300)) return;
        } catch (\Throwable $ignored) {
            if ((self::$lastLogAt[$message] ?? 0) > time() - 300) return;
            self::$lastLogAt[$message] = time();
        }
        try {
            // 不写异常消息/堆栈/请求：SQL 或队列异常文本可能包含元数据。
            Log::channel('daily')->warning($message, ['exception' => $error]);
        } catch (\Throwable $ignored) {
        }
    }
}
