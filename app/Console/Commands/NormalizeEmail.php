<?php

namespace App\Console\Commands;

use App\Services\EmailIdentityService;
use Illuminate\Console\Command;

class NormalizeEmail extends Command
{
    protected $signature = 'v2board:normalize-email {--apply : 写入规范化邮箱并使用二进制排序规则，须先暂停站点及后台写入进程}';
    protected $description = '检查历史邮箱规范化冲突（默认只读）';

    public function handle(EmailIdentityService $service): int
    {
        try {
            $report = $service->inspect();
            if ($report['conflicts']) {
                $this->error('邮箱规范化存在冲突，未写入。冲突用户 ID：' . json_encode($report['conflicts']));
                return self::FAILURE;
            }
            $this->info('邮箱检查通过，需要规范化的账号数：' . $report['changed']);
            if (!$this->option('apply')) return self::SUCCESS;
            if (!$this->laravel->isDownForMaintenance()) {
                $this->error('请先进入维护模式，并暂停计划任务、Horizon 和 Webman 等写入进程。');
                return self::FAILURE;
            }
            $changed = $service->apply();
            $this->info('邮箱数据升级完成，已规范化账号数：' . $changed);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('邮箱数据升级失败：' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
