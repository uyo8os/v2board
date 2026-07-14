<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Models\Plan;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;

class Traffic extends Telegram {
    public $command = '/traffic';
    public $description = '查询流量信息';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }

        $plan = Plan::find($user->plan_id);
        $planName = $plan ? $plan->name : '无套餐';
        $expireDate = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '永久';

        $transferEnable = Helper::trafficConvert($user->transfer_enable);
        $up = Helper::trafficConvert($user->u);
        $down = Helper::trafficConvert($user->d);
        $remaining = Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d));

        $text = "👤 账户信息\n"
            . "———————————————\n"
            . "📧 邮箱: `{$this->tgSafe($user->email)}`\n"
            . "💰 余额：`" . number_format($user->balance / 100, 2) . "`元\n"
            . "💸 佣金：`" . number_format($user->commission_balance / 100, 2) . "`元\n"
            . "📦 套餐：`{$this->tgSafe($planName)}`\n"
            . "⏳ 到期时间：`{$this->tgSafe($expireDate)}`\n"
            . "———————————————\n"
            . "🚥流量查询\n"
            . "计划流量：`{$transferEnable}`\n"
            . "已用上行：`{$up}`\n"
            . "已用下行：`{$down}`\n"
            . "剩余流量：`{$remaining}`";

        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }

    private function tgSafe($value)
    {
        return str_replace(['`', "\n", "\r"], ['', ' ', ''], (string)$value);
    }
}
