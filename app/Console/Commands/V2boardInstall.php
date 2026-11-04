<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use App\Models\User;
use App\Utils\Helper;
use App\Utils\SqlFile;
use Illuminate\Support\Facades\DB;

class V2boardInstall extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:install {--email= : 管理员邮箱（自动安装时必填）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'v2board 安装';

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
        try {
            $this->info("__     ______  ____                      _  ");
            $this->info("\ \   / /___ \| __ )  ___   __ _ _ __ __| | ");
            $this->info(" \ \ / /  __) |  _ \ / _ \ / _` | '__/ _` | ");
            $this->info("  \ V /  / __/| |_) | (_) | (_| | | | (_| | ");
            $this->info("   \_/  |_____|____/ \___/ \__,_|_|  \__,_| ");
            if (!file_exists(base_path('.env')) && !copy(base_path('.env.example'), base_path('.env'))) {
                abort(500, '复制环境文件失败，请检查目录权限');
            }
            $interactive = $this->input->isInteractive();
            $database = config('database.connections.mysql');
            $data = [
                'DB_HOST' => $interactive ? $this->ask('请输入数据库地址', $database['host']) : $database['host'],
                'DB_PORT' => $interactive ? $this->ask('请输入数据库端口', $database['port']) : $database['port'],
                'DB_DATABASE' => $interactive ? $this->ask('请输入数据库名', $database['database']) : $database['database'],
                'DB_USERNAME' => $interactive ? $this->ask('请输入数据库用户名', $database['username']) : $database['username'],
                'DB_PASSWORD' => $interactive ? $this->secret('请输入数据库密码') : $database['password'],
            ];
            $email = $this->option('email') ?: ($interactive ? $this->ask('请输入管理员邮箱?') : '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) abort(500, '请提供有效的管理员邮箱（--email）');
            foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
                config(['database.connections.mysql.' . $key => $data['DB_' . strtoupper($key)]]);
            }
            DB::purge('mysql');
            $connection = DB::connection('mysql');
            $connection->getPdo();
            if ($connection->select('SHOW TABLES')) abort(500, '数据库非空，拒绝覆盖；已有站点请执行 v2board:update');
            $data['APP_KEY'] = config('app.key') ?: 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC'));
            $this->saveToEnv($data);
            config(['app.key' => $data['APP_KEY']]);
            $file = \File::get(base_path() . '/database/install.sql');
            if (!$file) {
                abort(500, '数据库文件不存在');
            }
            $this->info('正在导入数据库请稍等...');
            foreach (SqlFile::statements($file) as $item) {
                $connection->unprepared($item);
            }
            $this->info('数据库导入完成');
            $password = Helper::guid(false);
            if (!$this->registerAdmin($email, $password)) {
                abort(500, '管理员账号注册失败，请重试');
            }
            if ($this->call('config:cache') !== 0) return self::FAILURE;

            $this->info('一切就绪');
            $this->info("管理员邮箱：{$email}");
            $this->info("管理员密码：{$password}");

            $defaultSecurePath = hash('crc32b', config('app.key'));
            $this->info("访问 http(s)://你的站点/{$defaultSecurePath} 进入管理面板，你可以在用户中心修改你的密码。");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function registerAdmin($email, $password)
    {
        $user = new User();
        $user->email = $email;
        if (strlen($password) < 8) {
            abort(500, '管理员密码长度最小为8位字符');
        }
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 1;
        return $user->save();
    }

    private function saveToEnv($data = [])
    {
        $envPath = app()->environmentFilePath();
        $contents = file_get_contents($envPath);
        foreach($data as $key => $value) {
            $value = str_replace(['\\', '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '\\r', '\\n'], (string)$value);
            $line = $key . '="' . $value . '"';
            $pattern = '/^' . preg_quote($key, '/') . '=[^\r\n]*/m';
            $contents = preg_match($pattern, $contents)
                ? preg_replace_callback($pattern, static fn () => $line, $contents)
                : $contents . "\n" . $line . "\n";
        }
        if (file_put_contents($envPath, $contents, LOCK_EX) === false) abort(500, '保存环境文件失败');
        return true;
    }
}
