<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\TrafficResetService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrafficAdvanceController extends Controller
{
    public function fetch(Request $request)
    {
        $values = $request->validate([
            'current' => 'sometimes|integer|min:1|max:1000000',
            'pageSize' => 'sometimes|integer|min:1|max:100',
            'email' => 'sometimes|nullable|string|max:64',
        ]);
        $current = (int) ($values['current'] ?? 1);
        $pageSize = (int) ($values['pageSize'] ?? 10);
        $query = $this->records($values['email'] ?? null);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($current, $pageSize)->get([
            'id', 'user_id', 'created_at', 'email', 'plan_id', 'plan_name',
            'deduct_seconds', 'old_expired_at', 'new_expired_at',
        ]);
        return response(['data' => $rows, 'total' => $total])
            ->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function clear(Request $request)
    {
        $values = $request->validate(['email' => 'sometimes|nullable|string|max:64']);
        // Snapshot a bounded set: no range delete or loop that can chase new records.
        $ids = $this->records($values['email'] ?? null)->orderBy('id')->limit(1000)->pluck('id');
        $deleted = $ids->isEmpty() ? 0 : DB::table(TrafficResetService::ADVANCE_LOG_TABLE)
            ->whereIn('id', $ids)->delete();
        return response(['data' => ['deleted' => $deleted]]);
    }

    private function records(?string $email): Builder
    {
        $query = DB::table(TrafficResetService::ADVANCE_LOG_TABLE);
        $email = trim($email ?? '');
        if ($email !== '') $query->where('email', $email);
        return $query;
    }
}
