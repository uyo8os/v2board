<?php

namespace App\Services;

use App\Models\SpeedLimitRecord;
use App\Models\SpeedLimitRule;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SpeedLimitService
{
    /**
     * 判断某条规则是否覆盖指定节点
     */
    private function ruleMatchesNode(SpeedLimitRule $rule, string $nodeType, $nodeId): bool
    {
        $nodeIds = is_array($rule->node_ids) ? $rule->node_ids : json_decode($rule->node_ids, true);
        if (!is_array($nodeIds)) return false;
        foreach ($nodeIds as $node) {
            if (($node['type'] ?? null) === $nodeType && (int)($node['id'] ?? 0) === (int)$nodeId) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取覆盖指定节点的所有已启用规则
     */
    public function getRulesForNode(string $nodeType, $nodeId)
    {
        return SpeedLimitRule::where('enable', 1)
            ->get()
            ->filter(function (SpeedLimitRule $rule) use ($nodeType, $nodeId) {
                return $this->ruleMatchesNode($rule, $nodeType, $nodeId);
            });
    }

    /**
     * 处理某节点一次流量上报中多个用户的流量，累加进各覆盖此节点的规则的、该用户当前触发窗口记录中
     * $data: [userId => [upload, download]]，均为节点上报的原始字节数（未乘倍率）。
     * 倍率仅用于计算"扣除流量合计"（用于跟阈值比较），节点流量明细里保留的是未乘倍率的
     * 实际使用流量，倍率单独存一份，展示时再按 (实际上传+实际下载) × 倍率 现场计算合计，
     * 与v2board原生用户流量扣费口径保持一致。
     */
    public function handleNodeTraffic(string $nodeType, $nodeId, string $nodeName, array $data, float $rate = 1.0)
    {
        $rules = $this->getRulesForNode($nodeType, $nodeId);
        if ($rules->isEmpty()) return;
        $now = time();
        foreach ($rules as $rule) {
            foreach ($data as $userId => $traffic) {
                $u = (int)($traffic[0] ?? 0);
                $d = (int)($traffic[1] ?? 0);
                if ($u <= 0 && $d <= 0) continue;
                $this->accumulate($rule, (int)$userId, $now, $nodeType, $nodeId, $nodeName, $u, $d, $rate);
            }
        }
    }

    /**
     * 累加流量到该用户在该规则下当前生效的窗口记录：
     * - "当前生效"指尚未解除的记录（released_at为空），包括未触发的计数窗口和已触发限速中的记录
     * - 若不存在当前生效记录（从未产生过流量，或上一条已解除/已作废），创建新窗口
     * - 若存在但未触发且窗口已到期，视为过期窗口作废，创建新窗口重新计算
     * - 若已触发限速中，不再累加流量、不重复判断，等待解除后下次流量重新开窗口
     * $u/$d 为该节点上报的原始（未乘倍率）实际流量，$rate 为该节点倍率。
     * 顶层 record.u/d 存储的是"扣除流量合计"（(实际上传+实际下载)×倍率累加），用于跟阈值比较；
     * node_traffic 明细里存储的是实际流量与倍率，不预先相乘，供展示时现场还原计算过程。
     */
    private function accumulate(SpeedLimitRule $rule, int $userId, int $now, string $nodeType, $nodeId, string $nodeName, int $u, int $d, float $rate = 1.0)
    {
        $nodeKey = "{$nodeType}-{$nodeId}";
        try {
            DB::beginTransaction();
            $record = SpeedLimitRecord::where('rule_id', $rule->id)
                ->where('user_id', $userId)
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();

            if ($record && $record->triggered) {
                // 已触发限速中，等待解除，不再累加/判断
                DB::commit();
                return;
            }

            if ($record && $record->window_end_at <= $now) {
                // 窗口已到期但未触发，静默作废
                $record->delete();
                $record = null;
            }

            if (!$record) {
                $period = max(60, (int)$rule->trigger_period);
                $record = new SpeedLimitRecord();
                $record->rule_id = $rule->id;
                $record->user_id = $userId;
                $record->window_start_at = $now;
                $record->window_end_at = $now + $period;
                $record->u = 0;
                $record->d = 0;
                $record->node_traffic = json_encode([]);
                $record->triggered = 0;
            }

            $nodeTraffic = json_decode($record->node_traffic, true);
            if (!is_array($nodeTraffic)) $nodeTraffic = [];
            if (!isset($nodeTraffic[$nodeKey])) {
                $nodeTraffic[$nodeKey] = ['type' => $nodeType, 'id' => (int)$nodeId, 'name' => $nodeName, 'rate' => $rate, 'u' => 0, 'd' => 0];
            }
            $nodeTraffic[$nodeKey]['u'] += $u;
            $nodeTraffic[$nodeKey]['d'] += $d;
            $nodeTraffic[$nodeKey]['name'] = $nodeName;
            $nodeTraffic[$nodeKey]['rate'] = $rate;

            $record->u = $record->u + (int)round($u * $rate);
            $record->d = $record->d + (int)round($d * $rate);
            $record->node_traffic = json_encode($nodeTraffic);

            $justTriggered = false;
            if (!$record->triggered && ($record->u + $record->d) >= $rule->threshold) {
                $record->triggered = 1;
                $record->triggered_at = $now;
                $record->release_at = $now + max(60, (int)$rule->limit_duration);
                $justTriggered = true;
            }

            $record->save();
            DB::commit();

            if ($justTriggered) {
                if ($rule->disconnect_existing_connections) {
                    $this->markKickSignal($rule, $userId);
                }
                $this->notifyTriggered($rule, $record);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('动态限速流量统计失败: ' . $e->getMessage());
        }
    }

    /**
     * 规则勾选了"打断已有连接"时，触发瞬间为该用户在本规则覆盖的每个节点写入一条
     * 一次性断连信号缓存。节点下次拉取用户列表时会读取并消费这个信号（附加到返回
     * 的用户数据中，返回后立即清除），从而强制断开该用户在该节点上已建立的连接，
     * 让其重新连接后走新的限速值。信号按节点隔离存储，不会影响未被本规则选中的节点。
     */
    private function markKickSignal(SpeedLimitRule $rule, int $userId)
    {
        $nodeIds = is_array($rule->node_ids) ? $rule->node_ids : json_decode($rule->node_ids, true);
        if (!is_array($nodeIds)) return;
        foreach ($nodeIds as $node) {
            $type = $node['type'] ?? null;
            $id = $node['id'] ?? null;
            if (!$type || !$id) continue;
            $cacheKey = CacheKey::get('SPEED_LIMIT_KICK_SIGNAL', "{$type}-{$id}-{$userId}");
            // 信号保留5分钟：足够节点跨越几次轮询周期（默认60秒/次）取到，
            // 又不会在节点长时间离线时无限期占用缓存
            Cache::put($cacheKey, 1, 300);
        }
    }

    /**
     * 消费（读取并清除）某用户在某节点上的一次性断连信号。
     * 返回 true 表示应当在本次响应中通知节点强制断开该用户的连接。
     */
    public function consumeKickSignal(string $nodeType, $nodeId, int $userId): bool
    {
        $cacheKey = CacheKey::get('SPEED_LIMIT_KICK_SIGNAL', "{$nodeType}-{$nodeId}-{$userId}");
        if (Cache::get($cacheKey)) {
            Cache::forget($cacheKey);
            return true;
        }
        return false;
    }

    private function notifyTriggered(SpeedLimitRule $rule, SpeedLimitRecord $record)
    {
        try {
            $user = User::find($record->user_id);
            $email = $user ? $user->email : "#{$record->user_id}";
            $total = Helper::trafficConvert((int)($record->u + $record->d));
            $message = "动态限速\n———————————————\n邮箱: {$email}\n规则：{$rule->name}\n触发流量：{$total}\n限速时间：" . date('Y-m-d H:i:s', $record->triggered_at) . "\n解除时间：" . date('Y-m-d H:i:s', $record->release_at);
            (new TelegramService())->sendMessageWithAdmin($message);
        } catch (\Exception $e) {
            Log::error('动态限速触发通知发送失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取某节点上当前已触发且未解除的用户限速值覆盖表
     * 返回 [userId => speedLimitMbps]，多条规则同时命中时取更严格（更小）的值
     */
    public function getEffectiveSpeedLimitMapForNode(string $nodeType, $nodeId): array
    {
        $rules = $this->getRulesForNode($nodeType, $nodeId);
        if ($rules->isEmpty()) return [];
        $ruleIds = $rules->pluck('id')->all();
        $ruleMap = $rules->keyBy('id');
        $records = SpeedLimitRecord::whereIn('rule_id', $ruleIds)
            ->where('triggered', 1)
            ->whereNull('released_at')
            ->get(['rule_id', 'user_id']);
        $map = [];
        foreach ($records as $record) {
            $rule = $ruleMap[$record->rule_id] ?? null;
            if (!$rule) continue;
            $uid = (int)$record->user_id;
            $limit = (int)$rule->speed_limit;
            if (!isset($map[$uid]) || $limit < $map[$uid]) {
                $map[$uid] = $limit;
            }
        }
        return $map;
    }

    /**
     * 手动解除某条触发记录的限速：标记为已解除（保留记录进入历史记录页），
     * 该用户本周期结束，下次产生新流量时会重新创建全新窗口记录
     */
    public function releaseManually(int $recordId): bool
    {
        $record = SpeedLimitRecord::where('id', $recordId)
            ->where('triggered', 1)
            ->whereNull('released_at')
            ->first();
        if (!$record) return false;
        $record->released_at = time();
        $record->release_type = 'manual';
        return $record->save();
    }

    /**
     * 扫描所有已触发但未解除、且已到计划解除时间的记录，自动解除（保留记录用于历史查看）
     */
    public function releaseExpired()
    {
        $now = time();
        SpeedLimitRecord::where('triggered', 1)
            ->whereNull('released_at')
            ->where('release_at', '<=', $now)
            ->update([
                'released_at' => $now,
                'release_type' => 'auto',
            ]);
    }

    /**
     * 清理超过指定天数的历史记录（已解除的记录）
     */
    public function cleanupHistory(int $days = 7)
    {
        $threshold = time() - $days * 86400;
        SpeedLimitRecord::whereNotNull('released_at')
            ->where('released_at', '<', $threshold)
            ->delete();
    }
}
