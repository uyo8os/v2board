<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Utils\SqlFile;
use App\Services\EmailIdentityService;

class V2boardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'v2board 更新';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!is_file(base_path('.env'))) {
            $this->error('.env 不存在，请先安装项目');
            return self::FAILURE;
        }
        DB::connection()->getPdo();
        // Preflight identities before any cumulative SQL or data update.
        $emailService = new EmailIdentityService();
        $emailReport = $emailService->inspect();
        if ($emailReport['conflicts']) {
            $this->error('邮箱规范化存在冲突，请先处理用户 ID：' . json_encode($emailReport['conflicts']));
            return self::FAILURE;
        }
        if (!app()->isDownForMaintenance()) {
            $this->error('请先进入维护模式，并暂停计划任务、Horizon 和 Webman 等写入进程。');
            return self::FAILURE;
        }
        $file = \File::get(base_path() . '/database/update.sql');
        if (!$file) {
            abort(500, '数据库文件不存在');
        }
        $this->info('正在导入数据库请稍等...');
        foreach (SqlFile::statements($file) as $index => $item) {
            try {
                DB::unprepared($item);
            } catch (QueryException $e) {
                // This cumulative script also contains already-applied legacy DDL.
                // Do not hide permissions, invalid SQL, invalid data or connectivity failures.
                $code = (int)($e->errorInfo[1] ?? 0);
                $alreadyApplied = ($code === 1050 && preg_match('/^CREATE TABLE\b/i', $item))
                    || (in_array($code, [1060, 1061, 1091], true) && preg_match('/^ALTER TABLE\b/i', $item))
                    || ($code === 1054 && preg_match('/^ALTER TABLE\b[\s\S]*\b(?:CHANGE|DROP)\b/i', $item))
                    || ($code === 1072 && preg_match('/^ALTER TABLE `v2_user`\s+ADD UNIQUE `email_deleted_at`/i', $item))
                    || ($code === 1072 && preg_match('/^ALTER TABLE `v2_stat_user`\s+ADD (?:UNIQUE|INDEX) `server_id/i', $item))
                    || (in_array($code, [1051, 1146], true)
                        && preg_match('/^(?:ALTER TABLE|UPDATE|DROP TABLE)\s+`(?:v2_server|v2_server_log|v2_tutorial|v2_server_stat|v2_server_v2ray|v2_stat_order)`/i', $item));
                if (!$alreadyApplied) {
                    $this->error('SQL #' . ($index + 1) . ' 失败：' . $e->getMessage());
                    return self::FAILURE;
                }
            }
        }
        if ($this->call('v2board:normalize-email', ['--apply' => true]) !== 0) return self::FAILURE;
        if ($this->call('config:cache') !== 0 || $this->call('horizon:terminate') !== 0) return self::FAILURE;
        $this->info('更新完毕；Horizon 已收到退出信号，请确保进程守护会自动拉起队列。');
        return self::SUCCESS;
    }
}
