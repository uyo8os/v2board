<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Order;
use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Tj extends Telegram {
    public $command = '/tj';
    public $description = '统计查看';

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

        $today = strtotime(date('Y-m-d'));
        $yesterday = strtotime(date('Y-m-d', strtotime('-1 day')));
        $thisMonth = strtotime(date('Y-m-01'));
        $lastMonth = strtotime(date('Y-m-01', strtotime('-1 month')));

        // 收入统计 (status not in [0, 2])
        $todayIncome = Order::where('created_at', '>=', $today)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');
        
        $yesterdayIncome = Order::where('created_at', '>=', $yesterday)
            ->where('created_at', '<', $today)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        $thisMonthIncome = Order::where('created_at', '>=', $thisMonth)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        $lastMonthIncome = Order::where('created_at', '>=', $lastMonth)
            ->where('created_at', '<', $thisMonth)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // 本月新增收入 (本月注册的新用户产生的消费收入)
        $thisMonthNewUserIncome = Order::where('v2_order.created_at', '>=', $thisMonth)
            ->whereNotIn('v2_order.status', [0, 2])
            ->join('v2_user', 'v2_order.user_id', '=', 'v2_user.id')
            ->where('v2_user.created_at', '>=', $thisMonth)
            ->sum('v2_order.total_amount');

        // 注册统计
        $todayRegister = User::where('created_at', '>=', $today)->count();
        $thisMonthRegister = User::where('created_at', '>=', $thisMonth)->count();

        $text = "📅 统计\n"
            . "———————————————\n"
            . "📅 今日收入: `" . number_format($todayIncome / 100, 2) . "`元\n"
            . "📅 昨日收入: `" . number_format($yesterdayIncome / 100, 2) . "`元\n\n"
            . "📊 本月收入: `" . number_format($thisMonthIncome / 100, 2) . "`元\n"
            . "📊 本月新增收入: `" . number_format($thisMonthNewUserIncome / 100, 2) . "`元\n"
            . "📊 上月收入: `" . number_format($lastMonthIncome / 100, 2) . "`元\n\n"
            . "📅 今日注册: `{$todayRegister}`\n"
            . "📊 本月新增: `{$thisMonthRegister}`";

        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
}
