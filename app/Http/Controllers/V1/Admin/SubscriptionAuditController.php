<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionAuditFetch;
use App\Http\Requests\Admin\SubscriptionAuditDetail;
use App\Models\User;
use App\Services\SubscriptionAuditRequest;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SubscriptionAuditController extends Controller
{
    const TABLE = 'v2_insider_guard_subscribe_audit';
    const STATS_CACHE_SECONDS = 60;
    const LIST_COLUMNS = ['id', 'user_id', 'email', 'ip_address', 'ua', 'source', 'format', 'result', 'status', 'duration_ms', 'requested_at'];

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store, no-cache, max-age=0, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
            return $response;
        });
    }

    public function fetch(SubscriptionAuditFetch $request)
    {
        $query = $this->query($this->filters($request));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('requested_at')->orderByDesc('id')
            ->forPage((int)($request->input('current') ?: 1), (int)($request->input('pageSize') ?: 20))
            ->get(self::LIST_COLUMNS);
        return response(['data' => $rows, 'total' => $total]);
    }

    public function stats(SubscriptionAuditFetch $request)
    {
        $filters = $this->filters($request);
        $dimension = $request->input('dimension') ?: 'ip';
        $connection = DB::connection();
        $scope = [config('app.key'), $connection->getName(), $connection->getConfig('host'), $connection->getDatabaseName()];
        $key = 'insider_guard_subscribe_audit_stats:' . hash('sha256', json_encode(
            [$scope, $dimension, $filters], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
        if ((string)$request->input('fresh') !== '1') {
            try {
                $cached = Cache::get($key);
                if (is_array($cached)) return response(['data' => $cached]);
            } catch (\Throwable $ignore) {
            }
        }
        $data = $this->aggregate($filters, $dimension);
        try {
            Cache::put($key, $data, self::STATS_CACHE_SECONDS);
        } catch (\Throwable $ignore) {
        }
        return response(['data' => $data]);
    }

    public function detail(SubscriptionAuditDetail $request)
    {
        $values = $request->validated();
        $row = DB::table(self::TABLE)->where('id', $values['id'])->first();
        if (!$row) abort(404, '订阅审计记录不存在');
        $details = json_decode((string)$row->request_details, true);
        $row->request_details = null;
        if (is_array($details) && isset($details['method'], $details['path'], $details['query'])
            && is_string($details['method']) && is_string($details['path']) && is_array($details['query'])) {
            $safe = SubscriptionAuditRequest::safeDetails($details);
            $safe['query'] = (object)$safe['query'];
            $row->request_details = (object)$safe;
        }
        $url = null;
        $user = User::find($row->user_id, ['id', 'token']);
        if ($user && $user->token) {
            try {
                // The current link follows the existing OTP/TOTP policy; it is never persisted in this audit.
                $url = Helper::getSubscribeUrl($user->token);
            } catch (\Throwable $ignore) {
            }
        }
        unset($row->event_id);
        $row->current_subscribe_url = $url;
        return response(['data' => $row]);
    }

    private function filters(SubscriptionAuditFetch $request): array
    {
        $values = $request->validated();
        $filters = [];
        foreach (['time_from', 'time_to', 'user_id', 'email', 'ip', 'ua', 'format', 'result', 'source', 'status'] as $key) {
            if (!array_key_exists($key, $values) || $values[$key] === null || $values[$key] === '') continue;
            $value = $values[$key];
            if (in_array($key, ['time_from', 'time_to', 'user_id', 'status'], true)) $value = (int)$value;
            if ($key === 'ip') $value = inet_ntop(inet_pton($value));
            $filters[$key] = $value;
        }
        ksort($filters);
        return $filters;
    }

    private function query(array $filters)
    {
        $query = DB::table(self::TABLE);
        foreach ($filters as $key => $value) {
            if ($key === 'time_from' || $key === 'time_to') {
                $query->where('requested_at', $key === 'time_from' ? '>=' : '<=', $value);
            } elseif ($key === 'email' || $key === 'ua') {
                // A literal substring, including '%' and '_'; the UA comparison is case-sensitive.
                if ($key === 'ua' && DB::connection()->getDriverName() === 'sqlite') {
                    $query->whereRaw('instr(ua, ?) > 0', [$value]);
                } else {
                    $literal = strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
                    $query->whereRaw(($key === 'ua' ? 'BINARY ua' : 'email') . " LIKE ? ESCAPE '!'", ['%' . $literal . '%']);
                }
            } else {
                $query->where($key === 'ip' ? 'ip_address' : $key, $value);
            }
        }
        return $query;
    }

    private function uaExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? 'ua COLLATE BINARY' : 'BINARY ua';
    }

    private function aggregate(array $filters, string $dimension): array
    {
        $query = $this->query($filters);
        $ua = $this->uaExpression();
        $totals = (clone $query)->selectRaw(
            "COUNT(*) as total, COUNT(DISTINCT user_id) as users, COUNT(DISTINCT NULLIF(ip_address, '')) as ips, COUNT(DISTINCT NULLIF({$ua}, '')) as uas"
        )->first();
        $ranking = clone $query;
        if ($dimension === 'user') {
            $rows = $ranking->selectRaw('user_id as value, COUNT(*) as count, MAX(requested_at) as last_at')
                ->groupBy('user_id')->orderByDesc('count')->orderBy('user_id')->limit(10)->get();
            $emails = $rows->isEmpty() ? [] : User::whereIn('id', $rows->pluck('value'))->pluck('email', 'id')->all();
        } elseif ($dimension === 'ua') {
            $rows = $ranking->where('ua', '<>', '')->selectRaw("{$ua} as value, COUNT(*) as count, MAX(requested_at) as last_at")
                ->groupBy(DB::raw($ua))->orderByDesc('count')->orderBy('value')->limit(10)->get();
        } else {
            $rows = $ranking->where('ip_address', '<>', '')->selectRaw('ip_address as value, COUNT(*) as count, MAX(requested_at) as last_at')
                ->groupBy('ip_address')->orderByDesc('count')->orderBy('ip_address')->limit(10)->get();
        }
        $items = [];
        foreach ($rows as $row) {
            $value = $dimension === 'user' ? (int)$row->value : (string)$row->value;
            $items[] = [
                'value' => $value,
                'label' => $dimension === 'user' ? ($emails[$value] ?? '#' . $value) : $value,
                'count' => (int)$row->count,
                'last_at' => (int)$row->last_at,
            ];
        }
        return [
            'total' => (int)$totals->total, 'users' => (int)$totals->users,
            'ips' => (int)$totals->ips, 'uas' => (int)$totals->uas,
            'dimension' => $dimension, 'ranking' => $items, 'generated_at' => time(),
        ];
    }
}
