<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InsiderGuardService;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 风控中心：风险用户、活动记录、节点异常与候选账号、人工审核与限制。
 * 节点一律用 node_type + node_id 标识。活动表按小时合并（见 InsiderGuardService），时间字段为 first_at / last_at。
 * 普通订阅记录可下发节点范围，App 配置记录实际输出子集；首次 / 最近时间是估算线索，不是完整下发历史。
 */
class InsiderGuardController extends Controller
{
    const HOURS = [1, 8, 24, 72, 168];
    /** 活动记录对外的字段（联表取 UA 文本） */
    const ACTIVITY_COLUMNS = ['a.id', 'a.user_id', 'a.event_type', 'a.ip_address', 'a.ua_hash', 'a.node_count', 'a.times', 'a.first_at', 'a.last_at', 'ua.ua'];
    const REVIEW_ACTIONS = ['watch', 'clear', 'restrict_recommended'];
    const NODE_TYPES = ['shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria', 'anytls', 'v2node'];
    // 概览「近 24 小时」；节点异常的候选账号 / 时间线窗口：异常前 24 小时、后 6 小时
    const OVERVIEW_WINDOW_SECONDS = 86400;
    const INCIDENT_BEFORE_SECONDS = 86400;
    const INCIDENT_AFTER_SECONDS = 6 * 3600;
    // 风险用户最多返回这么多人；时段 ≥ 24 小时要扫几天的活动，聚合结果缓存一分钟；概览的 24 小时总数缓存两分钟
    const USERS_LIMIT = 500;
    const USERS_CACHE_FROM_HOURS = 24;
    const USERS_CACHE_SECONDS = 60;
    const OVERVIEW_CACHE_SECONDS = 120;
    // 各列表上限
    const INCIDENTS_LIMIT = 20;
    const USER_EVENTS_LIMIT = 100;
    const USER_NODES_LIMIT = 100;
    const USER_REVIEWS_LIMIT = 30;
    const CANDIDATES_LIMIT = 100;
    const TIMELINE_LIMIT = 500;
    // int(11) 列能存的最大 ID
    const MAX_ID = 2147483647;

    public function overview(Request $request)
    {
        $incidents = $this->withNodeNames(
            DB::table('v2_insider_guard_incident')->orderByDesc('id')->limit(self::INCIDENTS_LIMIT)->get()
        );
        // 近 24 小时活动总数要扫整个窗口，缓存两分钟；审核数、限制数、异常列表都是小表，实时
        $recent = Cache::remember('insider_guard_overview_recent', self::OVERVIEW_CACHE_SECONDS, function () {
            return (int)DB::table('v2_insider_guard_activity')
                ->where('last_at', '>=', time() - self::OVERVIEW_WINDOW_SECONDS)->sum('times');
        });
        return response([
            'data' => [
                'recent_events' => (int)$recent,
                'reviews' => DB::table('v2_insider_guard_review')->count(),
                'restricted' => DB::query()->fromSub(
                    DB::table('v2_insider_guard_restrict')->select('user_id')
                        ->union(DB::table('v2_insider_guard_consumption_restrict')->select('user_id')),
                    'restricted_users'
                )->count(),
                'incidents' => $incidents,
            ]
        ]);
    }

    /** 风险用户：按时段统计订阅次数、来源 IP 数并评分 */
    public function users(Request $request)
    {
        $hours = (int)$request->input('hours', 1);
        if (!in_array($hours, self::HOURS, true)) abort(422, '不支持的统计时间');
        $threshold = (int)InsiderGuardService::setting('risk_threshold', InsiderGuardService::DEFAULT_RISK_THRESHOLD);
        $aggregate = function () use ($hours) {
            return $this->aggregateUsers($hours);
        };
        $rows = $hours >= self::USERS_CACHE_FROM_HOURS
            ? Cache::remember('insider_guard_users:' . $hours, self::USERS_CACHE_SECONDS, $aggregate)
            : $aggregate();
        // 限制标记不进缓存，限制 / 解除后列表立刻能反映
        $userIds = array_column($rows, 'user_id');
        $restricted = $userIds
            ? DB::table('v2_insider_guard_restrict')->whereIn('user_id', $userIds)->pluck('user_id')
                ->merge(DB::table('v2_insider_guard_consumption_restrict')->whereIn('user_id', $userIds)->pluck('user_id'))->flip()->all()
            : [];
        // SQL 已按「超阈值维度数 DESC, pulls DESC」排好，等于评分降序，这里不再排
        $items = collect($rows)->map(function ($r) use ($threshold, $restricted) {
            $score = InsiderGuardService::calcScore($r['pulls'], $r['ips']);
            return $r + [
                'score' => $score,
                'high_risk' => $score >= $threshold,
                'restricted' => isset($restricted[$r['user_id']]),
            ];
        })->values();
        return response([
            'data' => $items,
            'hours' => $hours,
            'threshold' => $threshold,
        ]);
    }

    /**
     * 时段内每个用户的订阅次数、IP 数、最近活动、最近 UA、邮箱（纯数组，便于缓存）。
     * 先在活动表上聚合并截断（任一维度超过评分阈值的排前面，再按次数），邮箱、UA 另查，不把用户表 JOIN 进全窗口扫描
     */
    private function aggregateUsers(int $hours): array
    {
        $window = time() - $hours * 3600;
        $pulls = 'SUM(CASE WHEN event_type = ? THEN times ELSE 0 END)';
        $rows = DB::table('v2_insider_guard_activity')
            ->select('user_id')
            ->selectRaw("{$pulls} as pulls", [InsiderGuardService::EVENT_SUBSCRIPTION])
            // 溢出桶（ip_address、ua_hash 为空）不算一个 IP；最新 UA 优先取有 UA 的桶
            ->selectRaw("COUNT(DISTINCT NULLIF(ip_address, '')) as ips")
            ->selectRaw('MAX(last_at) as last_seen')
            ->selectRaw("COALESCE(MAX(CASE WHEN ua_hash <> '' THEN id END), MAX(id)) as latest_id")
            ->where('last_at', '>=', $window)
            ->groupBy('user_id')
            ->orderByRaw("({$pulls} > ?) + (COUNT(DISTINCT NULLIF(ip_address, '')) > ?) DESC, pulls DESC", [
                InsiderGuardService::EVENT_SUBSCRIPTION,
                InsiderGuardService::SCORE_PULLS_THRESHOLD,
                InsiderGuardService::SCORE_IPS_THRESHOLD,
            ])
            ->limit(self::USERS_LIMIT)
            ->get();
        if ($rows->isEmpty()) return [];
        $emails = User::whereIn('id', $rows->pluck('user_id'))->pluck('email', 'id');
        // latest_id 对应的桶的 UA
        $uas = DB::table('v2_insider_guard_activity as a')
            ->leftJoin('v2_insider_guard_ua as ua', 'ua.ua_hash', '=', 'a.ua_hash')
            ->whereIn('a.id', $rows->pluck('latest_id'))
            ->pluck('ua.ua', 'a.user_id');
        return $rows->map(function ($r) use ($emails, $uas) {
            return [
                'user_id' => (int)$r->user_id,
                'email' => $emails[$r->user_id] ?? null,
                'ua' => $uas[$r->user_id] ?? null,
                'pulls' => (int)$r->pulls,
                'ips' => (int)$r->ips,
                'last_seen' => (int)$r->last_seen,
            ];
        })->all();
    }

    /** 活动记录（分页，按小时合并的行，最近活动在前），可按用户 ID、事件类型筛选 */
    public function events(Request $request)
    {
        $current = max(1, (int)$request->input('current', 1));
        $pageSize = max(10, min(500, (int)$request->input('pageSize', 20)));
        // 总数不联 UA 字典，只走活动表的索引
        $count = DB::table('v2_insider_guard_activity');
        $rows = $this->activities();
        if ($request->filled('user_id')) {
            $userId = $this->positiveInt($request->input('user_id'));
            if ($userId === null) abort(422, '用户 ID 无效');
            $count->where('user_id', $userId);
            $rows->where('a.user_id', $userId);
        }
        if ($request->filled('event_type')) {
            $eventType = $this->str($request, 'event_type');
            if (!in_array($eventType, [InsiderGuardService::EVENT_LOGIN, InsiderGuardService::EVENT_SUBSCRIPTION], true)) {
                abort(422, '事件类型无效');
            }
            $count->where('event_type', $eventType);
            $rows->where('a.event_type', $eventType);
        }
        return response([
            'data' => $this->withEmails($rows->forPage($current, $pageSize)->get(self::ACTIVITY_COLUMNS)),
            'total' => $count->count(),
        ]);
    }

    /** 用户风险详情：评分、最近活动、被下发过的节点、审核记录、限制状态 */
    public function userDetail(Request $request)
    {
        $id = $this->userId($request);
        $user = User::find($id);
        if (!$user) abort(500, '用户不存在');
        $events = $this->activities()->where('a.user_id', $id)->limit(self::USER_EVENTS_LIMIT)->get(self::ACTIVITY_COLUMNS);
        $deliveries = $this->withNodeNames(
            DB::table('v2_insider_guard_node_user')
                ->where('user_id', $id)
                ->orderByDesc('times')
                ->limit(self::USER_NODES_LIMIT)
                ->get(['node_type', 'node_id', 'times', 'first_at', 'last_at'])
        );
        return response([
            'data' => [
                'user_id' => $id,
                'email' => $user->email,
                'banned' => (int)$user->banned,
                'risk' => InsiderGuardService::score($id),
                'restriction' => $this->restriction($id),
                'consumption_restriction' => DB::table('v2_insider_guard_consumption_restrict')->where('user_id', $id)
                    ->first(['admin_id', 'reason', 'created_at']),
                'events' => $events,
                'deliveries' => $deliveries,
                'reviews' => DB::table('v2_insider_guard_review')
                    ->where('user_id', $id)->orderByDesc('id')->limit(self::USER_REVIEWS_LIMIT)->get(),
            ]
        ]);
    }

    /** 当前限制记录：无过期时间，与概览统计同口径，包括尚未清理的已删除用户。 */
    public function restrictions(Request $request)
    {
        $current = $this->positiveInt($request->input('current', 1));
        $pageSize = $this->positiveInt($request->input('pageSize', 20));
        if ($current === null || $pageSize === null) abort(422, '分页参数无效');
        $pageSize = max(10, min(100, $pageSize));
        $nodeRestrictions = DB::table('v2_insider_guard_restrict')
            ->select('user_id', 'admin_id', 'reason', 'created_at', 'mode');
        $consumptionRestrictions = DB::table('v2_insider_guard_consumption_restrict')
            ->select('user_id', 'admin_id', 'reason', 'created_at')->selectRaw("'consumption' as mode");
        $query = DB::query()->fromSub($nodeRestrictions->unionAll($consumptionRestrictions), 'restrictions');
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('user_id')->orderBy('mode')
            ->forPage($current, $pageSize)->get(['user_id', 'admin_id', 'reason', 'created_at', 'mode']);
        $exclusions = $this->excludedNodes($rows->pluck('user_id')->all());
        foreach ($rows as $row) $row->excluded_nodes = $row->mode === 'exclude' ? ($exclusions[$row->user_id] ?? []) : [];
        return response([
            'data' => $this->withEmails($rows),
            'total' => $total,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /** 人工审核记录：不改变账号状态 */
    public function review(Request $request)
    {
        $id = $this->userId($request);
        $action = $this->str($request, 'action');
        if (!in_array($action, self::REVIEW_ACTIONS, true)) abort(422, '审核动作无效');
        $reason = $this->reason($request, 8, '审核原因需 8～1000 字');
        if (!User::find($id)) abort(500, '用户不存在');
        $this->logReview($id, (int)$request->user['id'], $action, $reason);
        return response([
            'data' => true
        ]);
    }

    /** 限制账号：不封号，之后拉订阅时节点入口换成假地址；需显式确认与原因，并留下审核记录 */
    public function restrict(Request $request)
    {
        $id = $this->userId($request);
        $reason = $this->reason($request, 12, '限制原因需 12～1000 字');
        if (!in_array($this->str($request, 'confirm'), ['1', 'true', 'yes', 'on'], true)) abort(422, '请确认限制操作');
        $adminId = (int)$request->user['id'];
        if ($id === $adminId) abort(422, '不能限制当前登录的管理员账号');
        $user = User::find($id);
        if (!$user) abort(500, '用户不存在');
        if ($user->is_admin) abort(422, '不能限制管理员账号');
        DB::transaction(function () use ($id, $adminId, $reason) {
            // 先写主键行，串行化对同一账号的变更。
            InsiderGuardService::restrict($id, $adminId, $reason);
            $this->logReview($id, $adminId, 'restrict', $reason);
        });
        return response([
            'data' => true
        ]);
    }

    /** 原子替换为节点排除限制。节点列表上限 100，原因和节点快照一起写入审核记录。 */
    public function nodeExclusions(Request $request)
    {
        $id = $this->userId($request);
        $reason = $this->reason($request, 8, '限制原因需 8～1000 字');
        $raw = $request->input('nodes');
        $nodes = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) < 1 || count($nodes) > 100) abort(422, '请选择 1～100 个节点');
        $normalized = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) abort(422, '节点无效');
            $type = $node['node_type'] ?? null;
            $nodeId = $this->positiveInt($node['node_id'] ?? null);
            if (!is_string($type) || !in_array($type, self::NODE_TYPES, true) || $nodeId === null) abort(422, '节点类型或 ID 无效');
            $normalized[$type . ':' . $nodeId] = ['node_type' => $type, 'node_id' => $nodeId];
        }
        $byType = [];
        foreach ($normalized as $node) $byType[$node['node_type']][] = $node['node_id'];
        foreach ($byType as $type => $ids) {
            $model = InsiderGuardService::NODE_MODELS[$type];
            if ($model::whereIn('id', $ids)->count() !== count($ids)) abort(422, '节点不存在');
        }
        $adminId = (int)$request->user['id'];
        $user = User::find($id);
        if (!$user) abort(500, '用户不存在');
        if ($id === $adminId || $user->is_admin) abort(422, '不能限制管理员账号');
        DB::transaction(function () use ($id, $adminId, $reason, $normalized) {
            DB::table('v2_insider_guard_restrict')->upsert(
                [['user_id' => $id, 'admin_id' => $adminId, 'reason' => $reason, 'created_at' => time(), 'mode' => 'exclude']],
                ['user_id'], ['admin_id', 'reason', 'mode']
            );
            DB::table('v2_insider_guard_node_exclusion')->where('user_id', $id)->delete();
            $rows = array_map(static fn ($node) => ['user_id' => $id] + $node, array_values($normalized));
            DB::table('v2_insider_guard_node_exclusion')->insert($rows);
            $this->logReview($id, $adminId, 'node_exclude', $reason . "\n排除节点: " . implode(', ', array_keys($normalized)));
        });
        return response(['data' => true]);
    }

    /** 解除限制：恢复真实入口，并留下审核记录 */
    public function release(Request $request)
    {
        $id = $this->userId($request);
        $reason = $this->reason($request, 8, '解除原因需 8～1000 字');
        if (!User::find($id)) abort(500, '用户不存在');
        $adminId = (int)$request->user['id'];
        DB::transaction(function () use ($id, $adminId, $reason) {
            if (!InsiderGuardService::release($id)) abort(422, '该用户未被限制');
            $this->logReview($id, $adminId, 'release', $reason);
        });
        return response([
            'data' => true
        ]);
    }

    /** 消费限制与节点限制相互独立，操作及审核记录在同一事务中提交。 */
    public function consumptionRestrict(Request $request)
    {
        $id = $this->userId($request);
        $reason = $this->reason($request, 8, '限制原因需 8～1000 字');
        $adminId = (int)$request->user['id'];
        $user = User::find($id);
        if (!$user) abort(500, '用户不存在');
        if ($id === $adminId || $user->is_admin) abort(422, '不能限制管理员账号');
        DB::transaction(function () use ($id, $adminId, $reason) {
            DB::table('v2_insider_guard_consumption_restrict')->upsert(
                [['user_id' => $id, 'admin_id' => $adminId, 'reason' => $reason, 'created_at' => time()]],
                ['user_id'], ['admin_id', 'reason']
            );
            $this->logReview($id, $adminId, 'consumption_restrict', $reason);
        });
        return response(['data' => true]);
    }

    public function consumptionRelease(Request $request)
    {
        $id = $this->userId($request);
        $reason = $this->reason($request, 8, '解除原因需 8～1000 字');
        if (!User::find($id)) abort(500, '用户不存在');
        $adminId = (int)$request->user['id'];
        DB::transaction(function () use ($id, $adminId, $reason) {
            if (!DB::table('v2_insider_guard_consumption_restrict')->where('user_id', $id)->delete()) abort(422, '该用户未被限制消费');
            $this->logReview($id, $adminId, 'consumption_release', $reason);
        });
        return response(['data' => true]);
    }

    /** 登记节点异常（如节点被封） */
    public function nodeIncident(Request $request)
    {
        [$type, $id] = $this->node($request);
        // 节点异常后可能已经删除，只要节点范围记录里还有它就允许登记。
        $known = (new ServerService())->getServer($id, $type)
            || DB::table('v2_insider_guard_node_user')->where('node_type', $type)->where('node_id', $id)->exists();
        if (!$known) abort(422, '节点不存在，也没有它的节点范围记录');
        $occurredAt = $this->str($request, 'occurred_at');
        $timestamp = is_numeric($occurredAt) ? (int)$occurredAt : strtotime($occurredAt);
        if (!$timestamp || $timestamp < 1 || $timestamp > time() + 60) abort(422, '异常时间无效');
        $reason = trim($this->str($request, 'reason'));
        if ($reason === '' || mb_strlen($reason) > 1000) abort(422, '请填写异常描述（1000 字以内）');
        DB::table('v2_insider_guard_incident')->insert([
            'node_id' => $id,
            'node_type' => $type,
            'occurred_at' => $timestamp,
            'reason' => $reason,
            'admin_id' => (int)$request->user['id'],
            'created_at' => time(),
        ]);
        return response([
            'data' => true
        ]);
    }

    /** 异常前 24 小时与该节点范围可能相关的账号（按去重窗口和首次 / 最近时间估算） */
    public function candidates(Request $request)
    {
        $incident = $this->incident($request);
        $end = (int)$incident->occurred_at;
        $rows = $this->deliveriesAround($incident, $end - self::INCIDENT_BEFORE_SECONDS, $end)
            // 最近一次下发在异常之前的账号更有参考价值，排前面
            ->orderByRaw('CASE WHEN last_at <= ? THEN 0 ELSE 1 END, last_at DESC', [$end])
            ->limit(self::CANDIDATES_LIMIT)
            ->get(['user_id', 'times', 'first_at', 'last_at']);
        return response([
            'data' => [
                'incident' => $incident,
                'candidates' => $this->withEmails($rows),
                'caveat' => '普通订阅记录可下发节点范围，实际客户端可能只支持其中部分节点；这里按首次 / 最近时间及 6 小时去重窗口估算，可能多列账号，不能证明实际收到节点或泄露。',
            ]
        ]);
    }

    /** 异常前 24 小时到后 6 小时的节点范围线索，不是逐次下发历史 */
    public function timeline(Request $request)
    {
        $incident = $this->incident($request);
        $start = (int)$incident->occurred_at - self::INCIDENT_BEFORE_SECONDS;
        $end = (int)$incident->occurred_at + self::INCIDENT_AFTER_SECONDS;
        $deliveries = $this->deliveriesAround($incident, $start, $end);
        $total = (clone $deliveries)->count();
        $rows = $deliveries->orderBy('last_at')->limit(self::TIMELINE_LIMIT)->get(['user_id', 'times', 'first_at', 'last_at']);
        return response([
            'data' => [
                'incident' => $incident,
                'deliveries' => $this->withEmails($rows),
                'deliveries_total' => $total,
                'disclaimer' => '记录是可下发节点范围的首次 / 最近时间及去重计数，不是逐次下发历史；仅为时间关联，不能据此认定实际收到节点或泄露者。',
            ]
        ]);
    }

    /** 正整数且不超过 int(11)，否则 null（数组、负数、超大数都拒绝） */
    private function positiveInt($value): ?int
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,10}$/', (string)$value)) return null;
        $int = (int)$value;
        return $int >= 1 && $int <= self::MAX_ID ? $int : null;
    }

    private function userId(Request $request): int
    {
        $id = $this->positiveInt($request->input('id'));
        if ($id === null) abort(422, '用户 ID 无效');
        return $id;
    }

    /** 审核 / 限制 / 解除的原因：去首尾空白，按字符数限制 $min～1000 */
    private function reason(Request $request, int $min, string $error): string
    {
        $reason = trim($this->str($request, 'reason'));
        if (mb_strlen($reason) < $min || mb_strlen($reason) > 1000) abort(422, $error);
        return $reason;
    }

    /** 取字符串参数；传成数组（a[]=x）时按空串处理，而不是 500 */
    private function str(Request $request, string $key): string
    {
        $value = $request->input($key);
        return is_scalar($value) ? (string)$value : '';
    }

    private function logReview(int $userId, int $adminId, string $action, string $reason): void
    {
        DB::table('v2_insider_guard_review')->insert([
            'user_id' => $userId,
            'admin_id' => $adminId,
            'action' => $action,
            'reason' => $reason,
            'created_at' => time(),
        ]);
    }

    private function node(Request $request): array
    {
        $type = $this->str($request, 'node_type');
        $id = $this->positiveInt($request->input('node_id'));
        if ($id === null || !in_array($type, self::NODE_TYPES, true)) abort(422, '节点类型或 ID 无效');
        return [$type, $id];
    }

    private function incident(Request $request)
    {
        $id = $this->positiveInt($request->input('id'));
        $incident = $id ? DB::table('v2_insider_guard_incident')->find($id) : null;
        if (!$incident) abort(500, '异常记录不存在');
        $this->withNodeNames(collect([$incident]));
        return $incident;
    }

    /**
     * 在 [$from, $to] 窗口内可能相关的账号：每人每节点只有一行（首次 / 最近纳入节点范围的时间），
     * 首次在窗口结束前、最近在窗口开始之后即视为窗口内被下发过；下发记录有去重窗口，下界再往前推一个窗口
     */
    private function deliveriesAround($incident, int $from, int $to)
    {
        return DB::table('v2_insider_guard_node_user')
            ->where('node_type', $incident->node_type)
            ->where('node_id', $incident->node_id)
            ->where('first_at', '<=', $to)
            ->where('last_at', '>=', $from - InsiderGuardService::DELIVERY_DEDUP_SECONDS);
    }

    /** 当前的限制记录（含该用户拿到的假入口地址），未限制为 null */
    private function restriction(int $userId)
    {
        $row = DB::table('v2_insider_guard_restrict')->where('user_id', $userId)->first(['admin_id', 'reason', 'created_at', 'mode']);
        if ($row) {
            $row->decoy_host = $row->mode === 'decoy' ? InsiderGuardService::decoyHost($userId) : null;
            $row->excluded_nodes = $this->excludedNodes([$userId])[$userId] ?? [];
        }
        return $row;
    }

    private function excludedNodes(array $userIds): array
    {
        if (!$userIds) return [];
        $rows = DB::table('v2_insider_guard_node_exclusion')->whereIn('user_id', $userIds)
            ->orderBy('node_type')->orderBy('node_id')->get(['user_id', 'node_type', 'node_id']);
        if ($rows->isEmpty()) return [];
        $names = InsiderGuardService::nodeNames();
        $result = [];
        foreach ($rows as $row) {
            $result[$row->user_id][] = ['node_type' => $row->node_type, 'node_id' => (int)$row->node_id,
                'node_name' => $names[$row->node_type . ':' . $row->node_id] ?? null];
        }
        return $result;
    }

    /** 活动记录联 UA 字典，最近活动在前 */
    private function activities()
    {
        return DB::table('v2_insider_guard_activity as a')
            ->leftJoin('v2_insider_guard_ua as ua', 'ua.ua_hash', '=', 'a.ua_hash')
            ->orderByDesc('a.last_at')
            ->orderByDesc('a.id');
    }

    /** 给带 user_id 的行补 email（用户已删除为 null） */
    private function withEmails(Collection $rows): Collection
    {
        if ($rows->isEmpty()) return $rows;
        $emails = User::whereIn('id', $rows->pluck('user_id')->unique())->pluck('email', 'id');
        foreach ($rows as $row) {
            $row->email = $emails[$row->user_id] ?? null;
        }
        return $rows;
    }

    /** 给带 node_type / node_id 的行补 node_name（节点已删除为 null） */
    private function withNodeNames(Collection $rows): Collection
    {
        if ($rows->isEmpty()) return $rows;
        $names = InsiderGuardService::nodeNames();
        foreach ($rows as $row) {
            $row->node_name = $names[$row->node_type . ':' . $row->node_id] ?? null;
        }
        return $rows;
    }
}
