<?php

namespace App\Services;

use App\Jobs\SpeedLimitTrafficJob;
use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;

class UserService
{
    public function getResetAt(User $user, ?int $now = null): ?int
    {
        return (new TrafficResetService())->nextResetAt($user, $now);
    }

    public function getResetDay(User $user, ?int $now = null): ?int
    {
        return $this->getResetInfo($user, $now)['reset_day'];
    }

    public function getResetInfo(User $user, ?int $now = null): array
    {
        $now ??= time();
        $resetAt = $this->getResetAt($user, $now);
        return [
            'reset_at' => $resetAt,
            'reset_day' => $resetAt === null ? null : max(0, (int) ceil(($resetAt - $now) / 86400)),
        ];
    }

    public function getResetPeriod(User $user): ?int
    {
        if ($user->expired_at === null || (int) $user->expired_at <= time()) return null;
        return match ((new TrafficResetService())->method($user)) {
            0 => 1, 1 => 30, 3 => 12, 4 => 365, default => null,
        };
    }

    public function isAvailable(User $user)
    {
        if (!$user->banned && $user->transfer_enable && ($user->expired_at > time() || $user->expired_at === NULL)) {
            return true;
        }
        return false;
    }

    public function getAvailableUsers()
    {
        return User::whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                ->orWhereNull('expired_at');
            })
            ->where('banned', 0)
            ->get();
    }

    public function getDeviceLimitedUsers()
    {
        return User::whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                ->orWhereNull('expired_at');
            })
            ->where('banned', 0)
            ->where('device_limit','>', 0)
            ->select('id')
            ->get();
    }

    public function getUnAvailbaleUsers()
    {
        return User::where(function ($query) {
            $query->where('expired_at', '<', time())
                ->orWhere('expired_at', 0);
        })
            ->where(function ($query) {
            $query->where('plan_id', NULL)
                ->orWhere('transfer_enable', 0);
        })
            ->get();
    }

    public function getUsersByIds($ids)
    {
        return User::whereIn('id', $ids)->get();
    }

    public function getAllUsers()
    {
        return User::all();
    }

    public function addBalance(int $userId, int $balance):bool
    {
        $user = User::lockForUpdate()->find($userId);
        if (!$user) {
            return false;
        }
        $user->balance = $user->balance + $balance;
        if ($user->balance < 0) {
            return false;
        }
        if (!$user->save()) {
            return false;
        }
        return true;
    }

    public function isNotCompleteOrderByUserId(int $userId): bool
    {
        return Order::where('user_id', $userId)
            ->whereIn('status', [0, 1])
            ->exists();
    }

    public function trafficFetch(array $server, string $protocol, array $data)
    {
        TrafficFetchJob::dispatch($data, $server, $protocol);
        StatUserJob::dispatch($data, $server, $protocol, 'd');
        StatServerJob::dispatch($data, $server, $protocol, 'd');
        SpeedLimitTrafficJob::dispatch($data, $server, $protocol);
    }

    public static function getMaxId()
    {
        return User::max('id');
    }
}
