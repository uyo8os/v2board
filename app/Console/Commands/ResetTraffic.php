<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ResetTraffic extends Command
{
    public const PERSONAL_CURSOR_CACHE_KEY = 'traffic_reset_personal_scan';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:traffic {--personal-only : 仅处理提前开启后的个人排期}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量清空';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(TrafficResetService $resets)
    {
        $now = time();
        if ($this->option('personal-only')) {
            $this->resetPersonal($resets, $now);
            return;
        }

        Redis::setex('traffic_reset_lock', 300, 1);
        try {
            $resetMethods = Plan::select(
                DB::raw('GROUP_CONCAT(`id`) as plan_ids'),
                'reset_traffic_method'
            )->groupBy('reset_traffic_method')->get();

            foreach ($resetMethods as $resetMethod) {
                $method = (int) ($resetMethod->reset_traffic_method
                    ?? config('v2board.reset_traffic_method', 0));
                if ($method === 2 || $method < 0 || $method > 4) {
                    continue;
                }
                if (($method === 0 && date('d', $now) !== '01')
                    || ($method === 3 && date('md', $now) !== '0101')) {
                    continue;
                }

                $builder = User::select(TrafficResetService::SNAPSHOT_FIELDS)->whereNotNull('expired_at')
                    ->where('expired_at', '>', $now)
                    ->whereIn('plan_id', explode(',', $resetMethod->plan_ids))
                    ->where(function ($query) use ($method) {
                        // A valid personal schedule is reset only through its cursor.
                        $query->whereNull('traffic_reset_anchor_at')
                            ->orWhereNull('traffic_reset_plan_id')
                            ->orWhereColumn('traffic_reset_plan_id', '!=', 'plan_id')
                            ->orWhereNull('traffic_reset_method')
                            ->orWhere('traffic_reset_method', '!=', $method);
                    });

                $builder->chunkById(100, function ($users) use ($resets, $method, $now) {
                    foreach ($users as $user) {
                        if (!$this->legacyResetIsDue($user, $method, $now)) {
                            continue;
                        }
                        try {
                            $resets->resetLegacy($user, $now);
                        } catch (\Throwable $exception) {
                            Log::error('Traffic reset failed', [
                                'user_id' => $user->id,
                                'exception' => $exception,
                            ]);
                        }
                    }
                });
            }
        } finally {
            Redis::del('traffic_reset_lock');
        }
    }

    private function resetPersonal(TrafficResetService $resets, int $now): void
    {
        $deadline = hrtime(true) + 2000000000;
        $cursor = Cache::get(self::PERSONAL_CURSOR_CACHE_KEY);
        if (!is_array($cursor)
            || !array_key_exists('at', $cursor)
            || ($cursor['at'] !== null && !is_int($cursor['at']))
            || !isset($cursor['id'], $cursor['until'])
            || !is_int($cursor['id']) || $cursor['id'] < 0
            || !is_int($cursor['until']) || $cursor['until'] > $now) {
            $cursor = ['at' => null, 'id' => 0, 'until' => $now];
        }

        // The only range is on the due index. Expiry/context checks run on each
        // bounded candidate instead of making MySQL scan past unlimited old rows.
        $query = User::select(TrafficResetService::SNAPSHOT_FIELDS)->where('traffic_reset_at', '<=', $cursor['until']);
        if ($cursor['at'] !== null) {
            $query->where('traffic_reset_at', '>=', $cursor['at'])
                ->where(function ($query) use ($cursor) {
                    $query->where('traffic_reset_at', '>', $cursor['at'])
                        ->orWhere(function ($query) use ($cursor) {
                            $query->where('traffic_reset_at', $cursor['at'])
                                ->where('id', '>', $cursor['id']);
                        });
                });
        }
        $users = $query
            ->orderBy('traffic_reset_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($users->isEmpty()) {
            Cache::forever(self::PERSONAL_CURSOR_CACHE_KEY, ['at' => null, 'id' => 0, 'until' => $now]);
            return;
        }

        $failed = 0;
        $examples = [];
        foreach ($users as $user) {
            if (hrtime(true) >= $deadline) break;
            // Failed/busy records are revisited next sweep, never at the cost of
            // starving all later IDs. Keep the original key before the write.
            $cursor['at'] = (int) $user->traffic_reset_at;
            $cursor['id'] = (int) $user->id;
            try {
                $resets->resetPersonal($user, $now);
            } catch (\Throwable $exception) {
                ++$failed;
                if (count($examples) < 3) $examples[] = ['user_id' => $user->id, 'exception_type' => get_class($exception)];
            }
        }
        Cache::forever(self::PERSONAL_CURSOR_CACHE_KEY, $cursor);
        if ($failed > 0) Log::error('Personal traffic reset page had failures', ['count' => $failed, 'examples' => $examples]);
    }

    private function legacyResetIsDue(User $user, int $method, int $now): bool
    {
        switch ($method) {
            case 0:
                return date('d', $now) === '01';
            case 1:
                $today = (int) date('d', $now);
                $lastDay = (int) date('t', $now);
                $expireDay = (int) date('d', $user->expired_at);
                return ($today === $expireDay || ($today === $lastDay && $expireDay >= $lastDay))
                    && $now < (int) $user->expired_at - 2160000;
            case 3:
                return date('md', $now) === '0101';
            case 4:
                $anniversary = date('m-d', $user->expired_at);
                if ($anniversary === '02-29' && date('L', $now) === '0') {
                    $anniversary = '02-28';
                }
                return date('m-d', $now) === $anniversary;
            default:
                return false;
        }
    }
}
