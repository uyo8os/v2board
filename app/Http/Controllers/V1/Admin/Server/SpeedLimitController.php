<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\SpeedLimitRecord;
use App\Models\SpeedLimitRule;
use App\Models\User;
use App\Services\SpeedLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpeedLimitController extends Controller
{
    public function fetch(Request $request)
    {
        $rules = SpeedLimitRule::orderBy('id', 'desc')->get();
        foreach ($rules as $k => $rule) {
            $nodeIds = json_decode($rule->node_ids, true);
            if (is_array($nodeIds)) $rules[$k]['node_ids'] = $nodeIds;
        }
        return [
            'data' => $rules
        ];
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'name' => 'required',
            'node_ids' => 'required|array|min:1',
            'plan_limit_enabled' => 'nullable|boolean',
            'plan_ids' => 'required_if:plan_limit_enabled,1|nullable|array',
            'plan_ids.*' => 'integer|min:1|exists:v2_plan,id',
            'trigger_period' => 'required|integer|min:60',
            'threshold' => 'required|integer|min:1',
            'speed_limit' => 'required|integer|min:1',
            'limit_duration' => 'required|integer|min:60',
            'disconnect_existing_connections' => 'nullable|boolean',
        ], [
            'name.required' => '规则名称不能为空',
            'node_ids.required' => '请选择要限速的节点',
            'node_ids.array' => '节点参数格式有误',
            'node_ids.min' => '请至少选择一个节点',
            'plan_limit_enabled.boolean' => '订阅选择开关参数有误',
            'plan_ids.required_if' => '请至少选择一个订阅',
            'plan_ids.array' => '订阅参数格式有误',
            'plan_ids.*.integer' => '订阅参数格式有误',
            'plan_ids.*.min' => '订阅参数格式有误',
            'plan_ids.*.exists' => '所选订阅不存在',
            'trigger_period.required' => '触发时间不能为空',
            'trigger_period.integer' => '触发时间必须为整数',
            'trigger_period.min' => '触发时间不能少于60秒',
            'threshold.required' => '流量超出阈值不能为空',
            'threshold.integer' => '流量超出阈值必须为整数',
            'speed_limit.required' => '限速值不能为空',
            'speed_limit.integer' => '限速值必须为整数',
            'limit_duration.required' => '限速时长不能为空',
            'limit_duration.integer' => '限速时长必须为整数',
            'limit_duration.min' => '限速时长不能少于60秒',
        ]);

        $params['disconnect_existing_connections'] = !empty($params['disconnect_existing_connections']) ? 1 : 0;
        $params['plan_limit_enabled'] = !empty($params['plan_limit_enabled']) ? 1 : 0;
        $params['plan_ids'] = $params['plan_limit_enabled']
            ? array_values(array_unique(array_map('intval', $params['plan_ids'] ?? [])))
            : [];
        if ($params['plan_limit_enabled'] && empty($params['plan_ids'])) abort(422, '请至少选择一个订阅');

        $normalizedNodeIds = [];
        foreach ((array)$params['node_ids'] as $node) {
            if (!isset($node['type']) || !isset($node['id'])) continue;
            $normalizedNodeIds[] = [
                'type' => (string)$node['type'],
                'id' => (int)$node['id'],
            ];
        }
        if (empty($normalizedNodeIds)) abort(500, '请至少选择一个有效的节点');
        $params['node_ids'] = json_encode($normalizedNodeIds);

        if ($request->input('id')) {
            $rule = SpeedLimitRule::find($request->input('id'));
            if (!$rule) abort(500, '规则不存在');
            $oldPlanIds = array_map('intval', $rule->plan_ids ?? []);
            $newPlanIds = $params['plan_ids'];
            sort($oldPlanIds);
            sort($newPlanIds);
            $scopeChanged = (bool)$rule->plan_limit_enabled !== (bool)$params['plan_limit_enabled']
                || ($params['plan_limit_enabled'] && $oldPlanIds !== $newPlanIds);
            try {
                DB::transaction(function () use ($rule, $params, $scopeChanged) {
                    $rule->update($params);
                    // 新的适用范围从新窗口计数，已触发记录保留并在节点下发时重新检查范围。
                    if ($scopeChanged) {
                        SpeedLimitRecord::where('rule_id', $rule->id)->where('triggered', 0)->delete();
                    }
                });
                return ['data' => true];
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
        }

        $params['enable'] = 1;
        if (!SpeedLimitRule::create($params)) abort(500, '创建失败');
        return ['data' => true];
    }

    public function drop(Request $request)
    {
        $ruleId = $request->input('id');
        $rule = SpeedLimitRule::find($ruleId);
        if (!$rule) abort(500, '规则不存在');
        if (!$rule->delete()) abort(500, '删除失败');
        // 规则删除后该规则不再匹配任何节点，所有关联记录（当前生效中+历史）一并清除，
        // 正在生效的限速会随着记录消失自动解除（下次节点拉取即恢复用户原限速）
        SpeedLimitRecord::where('rule_id', $ruleId)->delete();
        return ['data' => true];
    }

    public function toggle(Request $request)
    {
        $rule = SpeedLimitRule::find($request->input('id'));
        if (!$rule) abort(500, '规则不存在');
        $rule->enable = $rule->enable ? 0 : 1;
        if (!$rule->save()) abort(500, '操作失败');
        return ['data' => true];
    }

    /**
     * 管理页：显示当前正在限速中的用户（已触发且未解除），支持邮箱模糊搜索与分页
     */
    public function records(Request $request)
    {
        $ruleId = $request->input('rule_id');
        if (!$ruleId) abort(500, '缺少规则id');
        $rule = SpeedLimitRule::find($ruleId);
        if (!$rule) abort(500, '规则不存在');

        $current = $request->input('current') ? (int)$request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? (int)$request->input('page_size') : 10;
        $email = $request->input('email');

        $builder = SpeedLimitRecord::where('rule_id', $ruleId)
            ->where('triggered', 1)
            ->whereNull('released_at');

        if ($email) {
            $userIds = User::where('email', 'like', "%{$email}%")->pluck('id');
            $builder->whereIn('user_id', $userIds);
        }

        $total = $builder->count();
        $records = $builder->orderBy('triggered_at', 'desc')
            ->forPage($current, $pageSize)
            ->get();

        $data = $this->formatRecords($records, $rule);

        return [
            'data' => $data,
            'total' => $total
        ];
    }

    /**
     * 历史记录页：显示已解除的记录（手动+自动都有），支持邮箱模糊搜索与分页
     */
    public function history(Request $request)
    {
        $ruleId = $request->input('rule_id');
        if (!$ruleId) abort(500, '缺少规则id');
        $rule = SpeedLimitRule::find($ruleId);
        if (!$rule) abort(500, '规则不存在');

        $current = $request->input('current') ? (int)$request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? (int)$request->input('page_size') : 10;
        $email = $request->input('email');

        $builder = SpeedLimitRecord::where('rule_id', $ruleId)
            ->where('triggered', 1)
            ->whereNotNull('released_at');

        if ($email) {
            $userIds = User::where('email', 'like', "%{$email}%")->pluck('id');
            $builder->whereIn('user_id', $userIds);
        }

        $total = $builder->count();
        $records = $builder->orderBy('released_at', 'desc')
            ->forPage($current, $pageSize)
            ->get();

        $data = $this->formatRecords($records, $rule);

        return [
            'data' => $data,
            'total' => $total
        ];
    }

    public function clearHistory(Request $request)
    {
        $ruleId = $request->input('rule_id');
        if (!$ruleId) abort(500, '缺少规则id');
        SpeedLimitRecord::where('rule_id', $ruleId)
            ->where('triggered', 1)
            ->whereNotNull('released_at')
            ->delete();
        return ['data' => true];
    }

    public function release(Request $request)
    {
        $recordId = $request->input('id');
        if (!$recordId) abort(500, '缺少记录id');
        $speedLimitService = new SpeedLimitService();
        if (!$speedLimitService->releaseManually((int)$recordId)) abort(500, '解除失败');
        return ['data' => true];
    }

    private function formatRecords($records, SpeedLimitRule $rule)
    {
        $userIds = $records->pluck('user_id')->unique()->toArray();
        $userMap = User::whereIn('id', $userIds)->pluck('email', 'id');

        return $records->map(function ($record) use ($userMap, $rule) {
            $nodeTraffic = json_decode($record->node_traffic, true);
            $nodeTraffic = is_array($nodeTraffic) ? array_values($nodeTraffic) : [];
            // node_traffic 里存的是节点上报的原始（未乘倍率）实际流量，这里按
            // (上传+下载)×倍率 现场计算该节点的扣除流量合计，与v2board原生流量
            // 扣费口径一致，供"节点/上传/下载/倍率/合计"明细弹窗展示
            foreach ($nodeTraffic as $k => $nt) {
                $rate = (float)($nt['rate'] ?? 1);
                $nodeTraffic[$k]['rate'] = $rate;
                $nodeTraffic[$k]['total'] = (int)round((($nt['u'] ?? 0) + ($nt['d'] ?? 0)) * $rate);
            }
            return [
                'id' => $record->id,
                'user_id' => $record->user_id,
                'email' => $userMap[$record->user_id] ?? '-',
                'triggered_at' => $record->triggered_at,
                'release_at' => $record->release_at,
                'released_at' => $record->released_at,
                'release_type' => $record->release_type,
                'u' => $record->u,
                'd' => $record->d,
                'total' => $record->u + $record->d,
                'threshold' => $rule->threshold,
                'speed_limit' => $rule->speed_limit,
                'node_traffic' => $nodeTraffic,
            ];
        })->values();
    }
}
