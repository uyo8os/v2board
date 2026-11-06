<?php

namespace App\Services;

use App\Models\ServerAnytls;
use App\Models\ServerHysteria;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Models\ServerVmess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 风控中心（InsiderGuard）：登录 / 订阅审计、风险评分、人工限制与清理。
 * 普通订阅记录该请求的可下发节点范围，具体协议可能只输出其中一部分；App 配置记录实际输出的节点。
 * 审计写入失败只记日志，不影响业务；限制状态无法确认时拒绝本次配置输出，避免意外返回真实入口。
 * 节点用 (node_type, node_id) 定位，因为各协议表的 id 互相独立。
 *
 * 数据量控制：
 *   - 活动表按小时合并：同一用户、同一类型、同一小时、同一 IP、同一 UA 只有一行并计数；
 *     同一小时桶数达到上限后合并到溢出桶（并发请求可能短时超限）；UA 文本放字典表，活动行只存哈希
 *   - 节点下发表每人每节点一行（首次 / 最近时间 + 次数），总行数封顶为用户数 × 节点数
 *   - 节点的首次 / 最近下发范围与去重计数不是完整历史，只能作为异常排查线索
 *   - 清理任务每小时按最近活动时间清理活动 / 节点范围，UA 字典多留一天；审核 / 异常登记不自动清理
 *
 * 限制分为假入口与节点排除两种互斥模式：前者将订阅节点入口换成假地址，后者不向该用户下发所选节点；
 * 假地址在风控设置里配置，留空则按用户生成一个固定的、看起来像公网的 IPv4（240～254 开头的 IANA 保留段，全网不可路由，
 * 不会把流量打到别人的服务器上）；解除后恢复。
 */
class InsiderGuardService
{
    const EVENT_LOGIN = 'login';
    const EVENT_SUBSCRIPTION = 'subscription';
    // 同一用户、同一节点集合在这个窗口内只记一次下发；查「异常前 24h 内被下发过」时要把这个窗口加进查询下界，否则边界上会漏
    const DELIVERY_DEDUP_SECONDS = 6 * 3600;
    // 活动按这个粒度合并
    const BUCKET_SECONDS = 3600;
    // 同一用户同一小时普通桶的目标上限（IP × UA 组合），达到后合并到溢出桶
    const BUCKETS_PER_HOUR_LIMIT = 30;
    // UA 字典按主键确认存在，已有 UA 在这个窗口内不重复更新 last_seen
    const UA_SEEN_SECONDS = 86400;
    // 清理时每批删除的行数
    const CLEANUP_BATCH = 5000;
    // 评分：最近 1 小时订阅次数 > 20 记 25 分，来源 IP 数 > 8 记 25 分，最高 50 分
    const SCORE_WINDOW_SECONDS = 3600;
    const SCORE_PULLS_THRESHOLD = 20;
    const SCORE_IPS_THRESHOLD = 8;
    const SCORE_POINTS = 25;
    // 设置默认值（与 ConfigController::fetch、前端 GuardSettings 一致）；高风险阈值 50 与「需人工复核」一致
    const DEFAULT_RISK_THRESHOLD = 50;
    const DEFAULT_RETENTION_DAYS = 3;
    // 自动生成的假入口 IPv4 的首段范围：240～254（IANA 保留，全网不可路由，外观与公网 IP 无异）
    const DECOY_FIRST_OCTET_MIN = 240;
    const DECOY_FIRST_OCTET_MAX = 254;
    // 假入口要替换的字段名（不分大小写）：连接地址，以及 SNI / Host 头 / obfs host 这类能反查真实服务器的字段
    const DECOY_FIELDS = ['host', 'server', 'address', 'server_name', 'servername', 'sni', 'obfs-host', 'obfs_host'];
    // 日志限频：同一类错误 5 分钟一条
    const LOG_THROTTLE_SECONDS = 300;
    // 节点类型 => 模型，用于查名称和校验节点
    const NODE_MODELS = [
        'shadowsocks' => ServerShadowsocks::class,
        'vmess' => ServerVmess::class,
        'trojan' => ServerTrojan::class,
        'tuic' => ServerTuic::class,
        'hysteria' => ServerHysteria::class,
        'vless' => ServerVless::class,
        'anytls' => ServerAnytls::class,
        'v2node' => ServerV2node::class,
    ];

    /** 缓存不可用时的进程内日志限频 */
    private static $lastLogAt = [];

    public static function setting(string $key, $default = null)
    {
        return config('v2board.insider_guard_' . $key, $default);
    }

    /** 登录成功：记录来源 IP 与 UA */
    public static function recordLogin(User $user, Request $request): void
    {
        self::record(self::EVENT_LOGIN, (int)$user->id, $request, null);
    }

    /** 记录订阅活动与节点范围（普通订阅为可下发集合，App 为实际输出子集）；假入口不计入范围 */
    public static function recordSubscribe(User $user, Request $request, array $servers, bool $decoy = false): void
    {
        self::record(self::EVENT_SUBSCRIPTION, (int)$user->id, $request, count($servers));
        if (!$decoy) self::deliveries((int)$user->id, $servers);
    }

    /** 按主键直接查数据库，保证限制 / 解除立即生效；无法确认时拒绝返回配置 */
    public static function isRestricted(int $userId): bool
    {
        try {
            return DB::table('v2_insider_guard_restrict')->where('user_id', $userId)->exists();
        } catch (\Throwable $e) {
            self::logWriteError('InsiderGuard restrict lookup unavailable', $e);
            throw new HttpException(503, '暂时无法确认账号的风控状态，请稍后重试');
        }
    }

    /** 限制：记入限制表（已限制则只更新原因，保留首次限制时间） */
    public static function restrict(int $userId, int $adminId, string $reason): void
    {
        DB::table('v2_insider_guard_restrict')->upsert(
            [['user_id' => $userId, 'admin_id' => $adminId, 'reason' => $reason, 'created_at' => time(), 'mode' => 'decoy']],
            ['user_id'],
            ['admin_id', 'reason', 'mode']
        );
        DB::table('v2_insider_guard_node_exclusion')->where('user_id', $userId)->delete();
    }

    public static function release(int $userId): bool
    {
        $deleted = DB::table('v2_insider_guard_restrict')->where('user_id', $userId)->delete();
        if (!$deleted) return false;
        DB::table('v2_insider_guard_node_exclusion')->where('user_id', $userId)->delete();
        return true;
    }

    /** 一条 SQL 读取限制模式和节点快照，查询异常时不下发节点。 */
    public static function filterExcludedNodes(array $servers, int $userId, ?bool &$decoy = null): array
    {
        try {
            $rows = DB::table('v2_insider_guard_restrict as r')
                ->leftJoin('v2_insider_guard_node_exclusion as e', 'e.user_id', '=', 'r.user_id')
                ->where('r.user_id', $userId)->get(['r.mode', 'e.node_type', 'e.node_id']);
            $decoy = false;
            if ($rows->isEmpty()) return $servers;
            $mode = $rows[0]->mode;
            if ($mode === 'decoy') {
                $decoy = true;
                return $servers;
            }
            if ($mode !== 'exclude' || $rows[0]->node_type === null) throw new \RuntimeException('Invalid restriction state');
            $excluded = [];
            foreach ($rows as $row) $excluded[$row->node_type . ':' . $row->node_id] = true;
            return array_values(array_filter($servers, static function ($server) use ($excluded) {
                return !isset($excluded[$server['type'] . ':' . $server['id']]);
            }));
        } catch (\Throwable $e) {
            self::logWriteError('InsiderGuard restriction lookup unavailable', $e);
            throw new HttpException(503, '暂时无法确认账号的风控状态，请稍后重试');
        }
    }

    /**
     * 假入口地址：设置里填了就用（自己的蜜罐域名或 IP）；留空则按用户生成一个固定的假 IPv4，
     * 由用户 ID 和 APP_KEY 派生，同一用户每次拉到的都一样，不同用户不同，外人推不出来
     */
    public static function decoyHost(int $userId): string
    {
        // 配置里的 IPv6 允许带方括号，这里去掉；URI 类输出由 Helper::formatHost 补括号，QuantumultX / Surge / Loon 等行式输出不补，
        // 所以假入口建议填 IPv4 或域名
        $host = trim(trim((string)self::setting('decoy_host', '')), '[]');
        if ($host !== '') return $host;
        $hash = md5('insider_guard_decoy:' . $userId . ':' . config('app.key'));
        $octet = function (int $index, int $min, int $max) use ($hash) {
            return $min + hexdec(substr($hash, $index * 2, 2)) % ($max - $min + 1);
        };
        return $octet(0, self::DECOY_FIRST_OCTET_MIN, self::DECOY_FIRST_OCTET_MAX)
            . '.' . $octet(1, 1, 254) . '.' . $octet(2, 1, 254) . '.' . $octet(3, 1, 254);
    }

    /**
     * 假入口：节点列表照常，端口、凭据都不动，只换地址类字段（节点名只在与地址相同时替换）。
     * 连接地址（host / server / address）与 SNI、Host 头、obfs host 一律换成假地址：这些字段指向真实服务器时解析即得真实 IP，
     * 换掉没有副作用（假地址本来就连不上）；其它字符串只在与原地址相同时替换
     */
    public static function applyDecoy(array &$servers, int $userId): void
    {
        $decoy = self::decoyHost($userId);
        $renamed = 0;
        foreach ($servers as &$server) {
            $originals = [];
            foreach (['host', 'server', 'address'] as $field) {
                $value = strtolower(trim((string)($server[$field] ?? '')));
                if ($value !== '') $originals[$value] = true;
            }
            // 节点名恰好就是地址时也要换掉；多个节点都这样时加序号，避免客户端因 proxy 重名拒绝整份配置
            $name = (string)($server['name'] ?? '');
            if ($name !== '' && isset($originals[strtolower(trim($name))])) {
                $renamed++;
                $server['name'] = $decoy . ($renamed > 1 ? " {$renamed}" : '');
            }
            self::replaceHostDeep($server, $originals, $decoy, true);
            // 自签证书的 pin / 证书不下发给被限制用户：证书全网唯一，拿去扫描平台可能反查到真实服务器；
            // 自定义 ECH 的 ech_config 里明文带着 public_name，可能就是真实域名，一并去掉（各输出类没有 ech_config 时不输出 ECH）
            foreach (['tls_settings', 'tlsSettings'] as $field) {
                if (isset($server[$field]) && is_array($server[$field])) {
                    unset($server[$field]['pinned_peer_cert_sha256'], $server[$field]['tls_cert'], $server[$field]['ech_config']);
                }
            }
        }
        unset($server);
    }

    /** $force：上层键名已命中 DECOY_FIELDS（例如 tcp + http 伪装把 Host 存成列表），里面的字符串一律替换 */
    private static function replaceHostDeep(array &$data, array $originals, string $decoy, bool $top = false, bool $force = false): void
    {
        foreach ($data as $key => &$value) {
            $field = is_string($key) ? strtolower($key) : '';
            $hit = $force || in_array($field, self::DECOY_FIELDS, true);
            if (is_array($value)) {
                self::replaceHostDeep($value, $originals, $decoy, false, $hit);
                continue;
            }
            // 空字符串不动（没配 SNI 就别凭空加一个）；顶层 name 是节点名，在 applyDecoy 里单独处理
            if (!is_string($value) || $value === '' || ($top && $key === 'name')) continue;
            if ($hit || isset($originals[strtolower(trim($value))])) {
                $value = $decoy;
            }
        }
        unset($value);
    }

    /** 按小时合并写入活动表：同一桶已存在时次数 +1、更新 last_at 与节点数 */
    private static function record(string $type, int $userId, Request $request, ?int $nodeCount): void
    {
        try {
            $now = time();
            $hourStart = $now - $now % self::BUCKET_SECONDS;
            // 去掉非法 UTF-8，否则 utf8mb4 列会拒绝写入
            $ua = mb_substr(mb_convert_encoding((string)($request->userAgent() ?? ''), 'UTF-8', 'UTF-8'), 0, 512);
            $ip = substr((string)$request->ip(), 0, 45);
            $uaHash = $ua === '' ? '' : md5($ua);
            // 同一小时内桶数超限（随机 UA / 伪造 IP 刷表）：后续记录并入该小时的溢出桶，只计数不再建新行、不写字典
            $buckets = DB::table('v2_insider_guard_activity')
                ->where('user_id', $userId)->where('last_at', '>=', $hourStart)->count();
            if ($buckets >= self::BUCKETS_PER_HOUR_LIMIT) {
                $ip = '';
                $uaHash = '';
            } elseif ($uaHash !== '') {
                self::rememberUa($uaHash, $ua, $now);
            }
            DB::table('v2_insider_guard_activity')->upsert([[
                'user_id' => $userId,
                'event_type' => $type,
                'hour_start' => $hourStart,
                'ip_address' => $ip,
                'ua_hash' => $uaHash,
                'times' => 1,
                'node_count' => $nodeCount,
                'first_at' => $now,
                'last_at' => $now,
            ]], ['user_id', 'event_type', 'hour_start', 'ip_address', 'ua_hash'], [
                'times' => DB::raw('times + 1'),
                'last_at',
                'node_count',
            ]);
        } catch (\Throwable $e) {
            self::logWriteError('InsiderGuard audit unavailable', $e);
        }
    }

    /**
     * UA 字典：缺失立即补写，已有 UA 一天更新一次 last_seen。
     * 以本库记录为准，避免共享 / 残留 Redis 标记或缓存故障导致活动有哈希却没有字典。
     * 数据库写入失败只记日志（活动行照常写，带哈希），同 UA 下次请求继续尝试补齐。
     */
    private static function rememberUa(string $hash, string $ua, int $now): void
    {
        try {
            $lastSeen = DB::table('v2_insider_guard_ua')->where('ua_hash', $hash)->value('last_seen');
            if ($lastSeen !== null && (int)$lastSeen > $now - self::UA_SEEN_SECONDS) return;
            DB::table('v2_insider_guard_ua')->upsert(
                [['ua_hash' => $hash, 'ua' => $ua, 'first_seen' => $now, 'last_seen' => $now]],
                ['ua_hash'],
                ['last_seen']
            );
        } catch (\Throwable $e) {
            self::logWriteError('InsiderGuard ua dictionary unavailable', $e);
        }
    }

    /** 清缓存键；缓存不可用时也不能再抛（调用方都在 catch 里） */
    private static function forgetQuietly(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (\Throwable $ignore) {
        }
    }

    /** 每人每节点一行：保留首次 / 最近范围和去重计数，不能还原完整的节点下发历史 */
    private static function deliveries(int $userId, array $servers): void
    {
        $dedupKey = null;
        try {
            $rows = [];
            $keys = [];
            $now = time();
            foreach ($servers as $server) {
                $id = $server['id'] ?? null;
                $type = (string)($server['type'] ?? '');
                if (!is_numeric($id) || (int)$id < 1 || $type === '') continue;
                $keys[] = $type . ':' . (int)$id;
                $rows[] = [
                    'user_id' => $userId,
                    'node_type' => substr($type, 0, 40),
                    'node_id' => (int)$id,
                    'times' => 1,
                    'first_at' => $now,
                    'last_at' => $now,
                ];
            }
            if (!$rows) return;
            // 客户端每小时自动更新订阅，节点集合没变时不重复写库；集合一变（加节点、换套餐）立刻记一次
            sort($keys);
            $dedupKey = 'insider_guard_delivery:' . $userId . ':' . md5(implode(',', $keys));
            if (!Cache::add($dedupKey, 1, self::DELIVERY_DEDUP_SECONDS)) return;
            foreach (array_chunk($rows, 200) as $batch) {
                DB::table('v2_insider_guard_node_user')->upsert($batch, ['user_id', 'node_type', 'node_id'], [
                    'times' => DB::raw('times + 1'),
                    'last_at',
                ]);
            }
        } catch (\Throwable $e) {
            // 写失败要撤掉去重键，否则 6 小时内不会再记，候选账号 / 时间线会漏掉这个人
            if ($dedupKey) self::forgetQuietly($dedupKey);
            self::logWriteError('InsiderGuard delivery audit unavailable', $e);
        }
    }

    /**
     * 写入失败通过安全日志处理器记录异常类型和位置，同一类错误 5 分钟内只记一次；
     * 缓存不可用时退回进程内限频，避免每个请求都写日志
     */
    private static function logWriteError(string $message, \Throwable $e): void
    {
        try {
            if (!Cache::add('insider_guard_write_error:' . md5($message), 1, self::LOG_THROTTLE_SECONDS)) return;
        } catch (\Throwable $ignore) {
            if ((self::$lastLogAt[$message] ?? 0) > time() - self::LOG_THROTTLE_SECONDS) return;
            self::$lastLogAt[$message] = time();
        }
        try {
            Log::channel('daily')->warning($message, ['exception' => $e]);
        } catch (\Throwable $ignore) {
            // 日志目录不可写或磁盘已满时，仍保留审计容错及限制查询的 503 行为。
        }
    }

    /**
     * 风险评分（最近 1 小时）：达到高风险阈值即需人工复核。
     * 活动按小时合并，这里取最近 1 小时内有活动的桶，桶内更早的记录也会算进来（最多多算一个小时）
     */
    public static function score(int $userId): array
    {
        $since = time() - self::SCORE_WINDOW_SECONDS;
        $rows = DB::table('v2_insider_guard_activity')->where('user_id', $userId)->where('last_at', '>=', $since);
        $pulls = (int)(clone $rows)->where('event_type', self::EVENT_SUBSCRIPTION)->sum('times');
        // 溢出桶的 ip_address 为空，不算一个 IP
        $ips = (clone $rows)->where('ip_address', '<>', '')->distinct()->count('ip_address');
        $score = self::calcScore($pulls, $ips);
        $threshold = (int)self::setting('risk_threshold', self::DEFAULT_RISK_THRESHOLD);
        return [
            'score' => $score,
            'subscription_requests_1h' => $pulls,
            'distinct_ips_1h' => $ips,
            'manual_review_required' => $score >= $threshold,
        ];
    }

    public static function calcScore(int $pulls, int $ips): int
    {
        return min(100, ($pulls > self::SCORE_PULLS_THRESHOLD ? self::SCORE_POINTS : 0)
            + ($ips > self::SCORE_IPS_THRESHOLD ? self::SCORE_POINTS : 0));
    }

    /** 全部节点的 "type:id" => 名称 映射；只查 id、name，不走 ServerService 的流量 / 状态合并 */
    public static function nodeNames(): array
    {
        $names = [];
        foreach (self::NODE_MODELS as $type => $class) {
            foreach ($class::query()->get(['id', 'name']) as $row) {
                $names[$type . ':' . $row->id] = (string)$row->name;
            }
        }
        return $names;
    }

    /**
     * 每小时清理超过保留期（1～365 天）的活动 / 节点范围及已删除用户的限制记录。
     * UA 字典多留一天；持续活跃节点行的首次时间和累计次数保留，审核 / 异常登记不自动清理。
     */
    public static function cleanup(): array
    {
        $days = max(1, min(365, (int)self::setting('retention_days', self::DEFAULT_RETENTION_DAYS)));
        $cutoff = time() - $days * 86400;
        $targets = [
            'v2_insider_guard_activity' => ['last_at', $cutoff, 'id'],
            'v2_insider_guard_node_user' => ['last_at', $cutoff, 'id'],
            // UA 的 last_seen 最多比引用它的活动晚一天更新，多留一天，避免还在保留期内的活动查不到 UA
            'v2_insider_guard_ua' => ['last_seen', $cutoff - self::UA_SEEN_SECONDS, 'ua_hash'],
        ];
        $counts = [];
        // 用户删除时限制表没有联动，顺带清掉已不存在用户的限制记录
        $counts['v2_insider_guard_restrict'] = DB::table('v2_insider_guard_restrict')
            ->whereNotIn('user_id', User::query()->select('id'))->delete();
        $counts['v2_insider_guard_node_exclusion'] = DB::table('v2_insider_guard_node_exclusion')
            ->whereNotIn('user_id', User::query()->select('id'))->delete();
        foreach ($targets as $table => [$column, $before, $orderBy]) {
            // 分批删，避免一条大 DELETE 长时间持锁；带 ORDER BY 让语句级 binlog 不报 unsafe
            $counts[$table] = 0;
            do {
                $deleted = DB::table($table)->where($column, '<', $before)->orderBy($orderBy)->limit(self::CLEANUP_BATCH)->delete();
                $counts[$table] += $deleted;
            } while ($deleted > 0);
        }
        return $counts;
    }
}
