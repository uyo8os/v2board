<?php

namespace App\Console\Commands;

use App\Services\SubscriptionAuditService;
use Illuminate\Console\Command;

class SubscriptionAuditPrune extends Command
{
    protected $signature = 'subscribe-audit:prune';
    protected $description = '按保留天数和最大条数分批清理订阅明细，关闭采集后仍清理';

    public function handle(SubscriptionAuditService $audit)
    {
        try {
            $result = $audit->prune();
        } catch (\Throwable $error) {
            // 不输出底层 SQL/连接信息；安装与排障仍由管理员检查数据库结构。
            $this->error('订阅明细清理失败，请确认数据库可用且已执行 php artisan v2board:update');
            return 1;
        }
        $this->info('已清理 ' . $result['deleted'] . ' 条订阅明细' . ($result['has_more'] ? '，剩余数据将在后续调度继续清理' : ''));
        return 0;
    }
}
