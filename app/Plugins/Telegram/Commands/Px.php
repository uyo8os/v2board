<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\StatUser;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

class Px extends Telegram {
    public $command = '/px';
    public $description = '流量排行';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        if (!$user->is_admin) {
            return;
        }

        $todayStart = strtotime(date('Y-m-d'));
        $yesterdayStart = strtotime(date('Y-m-d', strtotime('-1 day')));
        $yesterdayEnd = $todayStart;

        // 今日排行 (Top 10)
        $todayRank = $this->getRank($todayStart, time(), 10);
        
        // 昨日排行 (Top 5)
        $yesterdayRank = $this->getRank($yesterdayStart, $yesterdayEnd, 5);

        $text = "🚀 流量排行\n"
            . "———————————————\n"
            . "🔥 今日用户流量排行：\n";
        
        if (empty($todayRank)) {
            $text .= "暂无数据\n";
        } else {
            foreach ($todayRank as $index => $item) {
                $rank = $index + 1;
                $email = $this->tgSafe($item['email']);
                $traffic = Helper::trafficConvert($item['total']);
                $text .= "{$rank}. `{$email}` ({$traffic})\n";
            }
        }

        $text .= "\n📅 昨日用户流量排行：\n";
        
        if (empty($yesterdayRank)) {
            $text .= "暂无数据\n";
        } else {
            foreach ($yesterdayRank as $index => $item) {
                $rank = $index + 1;
                $email = $this->tgSafe($item['email']);
                $traffic = Helper::trafficConvert($item['total']);
                $text .= "{$rank}. `{$email}` ({$traffic})\n";
            }
        }

        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }

    private function getRank($startAt, $endAt, $limit) {
        $statistics = StatUser::select([
            'user_id',
            DB::raw('SUM((u + d) * server_rate) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();

        $data = [];
        foreach ($statistics as $item) {
            $user = User::find($item->user_id);
            $data[] = [
                'email' => $user ? $user->email : '未知用户',
                'total' => (int)$item->total
            ];
        }
        return $data;
    }

    private function tgSafe($value)
    {
        return str_replace(['`', "\n", "\r"], ['', ' ', ''], (string)$value);
    }
}
