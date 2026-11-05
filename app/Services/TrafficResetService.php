<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Fixed calendar state with one compact record per successful manual advance. */
class TrafficResetService
{
    public const ADVANCE_LOG_TABLE = 'v2_traffic_advance_log';
    public const FIELDS = [
        'traffic_reset_anchor_at', 'traffic_reset_offset', 'traffic_reset_at',
        'traffic_reset_plan_id', 'traffic_reset_method',
    ];
    public const SNAPSHOT_FIELDS = ['id', 'plan_id', 'expired_at', 'u', 'd', 'transfer_enable', ...self::FIELDS];

    private TrafficResetCalendar $calendar;
    private DateTimeZone $timezone;

    public function __construct()
    {
        $this->timezone = new DateTimeZone((string) config('app.timezone', 'Asia/Shanghai'));
        $this->calendar = new TrafficResetCalendar($this->timezone->getName());
    }

    public function method(User $user): ?int
    {
        if ($user->plan_id === null) return null;
        $plan = $user->plan;
        if (!$plan instanceof Plan) $plan = Plan::find($user->plan_id);
        if (!$plan) return null;
        $method = (int) ($plan->reset_traffic_method ?? config('v2board.reset_traffic_method', 0));
        return in_array($method, [0, 1, 2, 3, 4], true) ? $method : null;
    }

    public function hasPersonalSchedule(User $user, int $method): bool
    {
        return $user->traffic_reset_anchor_at !== null
            && $user->traffic_reset_plan_id !== null && $user->traffic_reset_method !== null
            && (int) $user->traffic_reset_plan_id === (int) $user->plan_id
            && (int) $user->traffic_reset_method === $method;
    }

    public function nextResetAt(User $user, ?int $now = null): ?int
    {
        $now ??= time();
        $method = $this->method($user);
        if (!$this->eligible($user, $method, $now)) return null;
        if ($this->hasPersonalSchedule($user, $method)) {
            $this->validatePersonal($user);
            $next = (int) $user->traffic_reset_at;
        } else {
            $next = $this->calendar->nextBoundary($method, $this->anchor($user), $now);
        }
        // Preserve the existing final-tail rule; advancing must not grant an extra reset.
        if ($method === 1 && $next >= (int) $user->expired_at - 2160000) return null;
        return $next < (int) $user->expired_at ? $next : null;
    }

    /** Calculate first; commit the primary-key CAS and its record in one short transaction. */
    public function advance(User $user, ?int $now = null): void
    {
        $now ??= time();
        $method = $this->method($user);
        if (!$this->eligible($user, $method, $now)) {
            throw new InvalidArgumentException('You do not allow to renew the subscription');
        }
        $quota = (int) $user->transfer_enable;
        $u = (int) $user->u;
        $d = (int) $user->d;
        if ($quota <= 0 || $u < 0 || $d < 0 || $u > PHP_INT_MAX - $d || $u + $d < $quota) {
            throw new InvalidArgumentException('You have not used up your traffic, you cannot renew your subscription');
        }
        $personal = $this->hasPersonalSchedule($user, $method);
        if ($personal) $this->validatePersonal($user);
        $anchor = $personal ? (int) $user->traffic_reset_anchor_at : $this->anchor($user);
        $offset = $personal ? (int) $user->traffic_reset_offset : 0;
        $start = $personal ? (int) $user->traffic_reset_at : $this->calendar->nextBoundary($method, $anchor, $now);
        if ($start <= $now) throw new RuntimeException('Traffic reset is due; refresh and try again.');
        $end = $this->calendar->nextBoundary($method, $anchor, $start, $offset);
        try {
            $result = $this->calendar->previewAdvance($now, $start, $end, (int) $user->expired_at);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('You do not have enough time to renew your subscription', 0, $exception);
        }
        if ($offset > PHP_INT_MAX - $result['delta']) throw new RuntimeException('Traffic reset offset is invalid.');
        $query = $this->snapshot($user)->where('u', $user->u)->where('d', $user->d)
            ->where('transfer_enable', $user->transfer_enable);
        $values = [
            'expired_at' => $result['expires_at'], 'u' => 0, 'd' => 0,
            'traffic_reset_anchor_at' => $anchor, 'traffic_reset_offset' => $offset + $result['delta'],
            'traffic_reset_at' => $result['end'], 'traffic_reset_plan_id' => $user->plan_id,
            'traffic_reset_method' => $method, 'updated_at' => $now,
        ];
        $plan = $user->plan instanceof Plan ? $user->plan : Plan::find($user->plan_id);
        $record = [
            'user_id' => $user->id, 'email' => (string) $user->email,
            'plan_id' => $user->plan_id, 'plan_name' => (string) ($plan?->name ?? ''),
            'deduct_seconds' => $result['delta'], 'old_expired_at' => (int) $user->expired_at,
            'new_expired_at' => $result['expires_at'], 'created_at' => $now,
        ];
        $db = $user->getConnection();
        $this->write($db, fn () => $db->transaction(function () use ($db, $query, $values, $record) {
            if ($query->update($values) !== 1) throw new RuntimeException('Subscription changed; refresh and try again.');
            $db->table(self::ADVANCE_LOG_TABLE)->insert($record);
            return 1;
        }, 1));
    }

    /** Called by the existing daily task with a snapshot, never just a list of IDs. */
    public function resetLegacy(User $user, int $now): bool
    {
        $method = $this->method($user);
        if (!$this->eligible($user, $method, $now) || $this->hasPersonalSchedule($user, $method)) return false;
        $today = $this->local($now)->setTime(0, 0);
        $lastBoundary = $this->calendar->nextBoundary($method, $this->anchor($user), $today->getTimestamp() - 1);
        if ($lastBoundary !== $today->getTimestamp()
            || ($method === 1 && $now >= (int) $user->expired_at - 2160000)) return false;
        $values = ['u' => 0, 'd' => 0, 'updated_at' => $now];
        if ($user->traffic_reset_anchor_at !== null) $values += $this->emptySchedule();
        $query = $this->snapshot($user);
        return $this->write($user->getConnection(), fn () => $query->update($values)) === 1;
    }

    /** One due user, one write; missed boundaries are skipped without replay loops. */
    public function resetPersonal(User $user, int $now): bool
    {
        if ($user->expired_at === null || (int) $user->expired_at <= $now) return false;
        $method = $this->method($user);
        $query = $this->snapshot($user);
        if ($method === null || $method === 2 || !$this->hasPersonalSchedule($user, $method)) {
            return $this->write($user->getConnection(), fn () => $query->update($this->emptySchedule())) === 1;
        }
        $this->validatePersonal($user);
        $due = (int) $user->traffic_reset_at;
        if ($due > $now || $due >= (int) $user->expired_at
            || ($method === 1 && $due >= (int) $user->expired_at - 2160000)) return false;
        $next = $this->calendar->nextBoundary($method, (int) $user->traffic_reset_anchor_at, $now, (int) $user->traffic_reset_offset);
        return $this->write($user->getConnection(), fn () => $query->update([
            'u' => 0, 'd' => 0, 'traffic_reset_at' => $next, 'updated_at' => $now,
        ])) === 1;
    }

    private function eligible(User $user, ?int $method, int $now): bool
    {
        return $user->expired_at !== null && (int) $user->expired_at > $now
            && $method !== null && $method !== 2;
    }

    private function anchor(User $user): int
    {
        return $this->local((int) $user->expired_at)->setTime(0, 0)->getTimestamp();
    }

    private function local(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone);
    }

    private function validatePersonal(User $user): void
    {
        if ((int) $user->traffic_reset_anchor_at <= 0 || $user->traffic_reset_offset === null
            || (int) $user->traffic_reset_offset < 0 || $user->traffic_reset_at === null
            || (int) $user->traffic_reset_at <= 0) {
            throw new RuntimeException('Traffic reset schedule is invalid.');
        }
        if ($this->calendar->nextBoundary((int) $user->traffic_reset_method,
            (int) $user->traffic_reset_anchor_at, (int) $user->traffic_reset_at - 1,
            (int) $user->traffic_reset_offset) !== (int) $user->traffic_reset_at) {
            throw new RuntimeException('Traffic reset boundary is inconsistent.');
        }
    }

    private function snapshot(User $user)
    {
        $query = $user->getConnection()->table($user->getTable())->where('id', $user->id)
            ->where('plan_id', $user->plan_id)->where('expired_at', $user->expired_at);
        foreach (self::FIELDS as $field) $query->where($field, $user->getRawOriginal($field));
        return $query;
    }

    private function emptySchedule(): array
    {
        return ['traffic_reset_anchor_at' => null, 'traffic_reset_offset' => 0, 'traffic_reset_at' => null,
            'traffic_reset_plan_id' => null, 'traffic_reset_method' => null];
    }

    /** Short writes only: no explicit row lock, retry, sleep or external work. */
    private function write(Connection $db, callable $update): int
    {
        if ($db->transactionLevel() !== 0) throw new RuntimeException('Traffic reset requires an idle connection.');
        $previous = null;
        if ($db->getDriverName() === 'mysql') {
            $previous = (int) $db->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds', [], false)->seconds;
            $db->statement('SET SESSION innodb_lock_wait_timeout = 1');
        }
        try {
            return $update();
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new RuntimeException('Subscription is busy; try again later.', 0, $exception);
            }
            throw $exception;
        } finally {
            if ($previous !== null) {
                try { $db->statement('SET SESSION innodb_lock_wait_timeout = ' . $previous); }
                catch (Throwable) { $db->disconnect(); }
            }
        }
    }
}
