<?php

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function check($condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

function snapshot(): array
{
    $tables = ['v2_user', 'v2_plan', 'v2_order', 'v2_server_shadowsocks', 'v2_speed_limit_rule', 'v2_speed_limit_record', 'v2_insider_guard_restrict'];
    $data = [];
    foreach ($tables as $table) $data[$table] = DB::table($table)->orderByRaw('1')->get()->map(fn ($row) => (array)$row)->all();
    $data['key'] = config('app.key');
    $data['site'] = hash_file('sha256', '/app/config/v2board.php');
    return $data;
}

switch ($argv[1] ?? '') {
    case 'seed':
        check(DB::table('v2_user')->where('is_admin', 1)->count() === 1, 'Fresh installation creates one administrator');
        $time = ['created_at' => time(), 'updated_at' => time()];
        DB::table('v2_server_group')->insert(['id' => 1, 'name' => 'Acceptance'] + $time);
        DB::table('v2_plan')->insert(['id' => 1, 'group_id' => 1, 'transfer_enable' => 100, 'name' => 'Acceptance plan', 'show' => 1, 'month_price' => 1000] + $time);
        foreach ([2, 3] as $id) {
            DB::table('v2_user')->insert([
                'id' => $id, 'email' => "user$id@example.test", 'password' => password_hash('acceptance-user-password', PASSWORD_BCRYPT),
                'uuid' => "00000000-0000-4000-8000-00000000000$id", 'token' => "acceptance-user-$id-token",
                'plan_id' => 1, 'group_id' => 1, 'transfer_enable' => 107374182400, 'expired_at' => time() + 86400,
                'balance' => 12345, 'commission_balance' => 6789,
            ] + $time);
        }
        DB::table('v2_server_shadowsocks')->insert([
            'id' => 1, 'group_id' => '[1]', 'name' => 'Acceptance SS', 'rate' => '1', 'host' => 'node.example.test',
            'port' => '443', 'server_port' => 443, 'cipher' => 'aes-128-gcm', 'show' => 1,
        ] + $time);
        DB::table('v2_order')->insert([
            'user_id' => 2, 'plan_id' => 1, 'type' => 1, 'period' => 'month_price',
            'trade_no' => 'acceptance-existing-order', 'total_amount' => 1000, 'status' => 3,
        ] + $time);
        DB::table('v2_speed_limit_rule')->insert([
            'id' => 1, 'name' => 'Existing rule', 'node_ids' => '[{"type":"shadowsocks","id":1}]',
            'trigger_period' => 3600, 'threshold' => 1024, 'speed_limit' => 10,
        ] + $time);
        DB::table('v2_speed_limit_record')->insert([
            'rule_id' => 1, 'user_id' => 2, 'window_start_at' => time(), 'window_end_at' => time() + 3600,
            'u' => 123, 'd' => 456, 'triggered' => 1, 'triggered_at' => time(), 'release_at' => time() + 3600,
        ] + $time);
        DB::table('v2_insider_guard_restrict')->insert(['user_id' => 3, 'reason' => 'Acceptance restriction', 'created_at' => time()]);
        file_put_contents('/app/config/v2board.php', "<?php\nreturn ['app_name' => 'Acceptance site', 'server_token' => 'acceptance-server-token'];\n");
        file_put_contents('/app/storage/upgrade-before.json', json_encode(snapshot(), JSON_PRETTY_PRINT));
        echo "PASS: Existing users, balances, orders, nodes, restrictions and speed-limit history seeded\n";
        break;
    case 'preserved':
        check(snapshot() === json_decode(file_get_contents('/app/storage/upgrade-before.json'), true), 'Upgrade preserves business records, APP_KEY and site configuration');
        break;
    case 'verify-queue':
        check(DB::table('v2_insider_guard_subscribe_audit')->where('source', 'subscription')->exists(), 'Horizon writes subscription audit jobs to MySQL');
        check(DB::table('v2_insider_guard_subscribe_audit')->where('source', 'app')->exists(), 'Horizon writes App audit jobs to MySQL');
        check(DB::table('v2_mail_log')->where('email', 'queue@example.test')->whereNull('error')->exists(), 'Horizon consumes the original email business queue');
        check(DB::table('failed_jobs')->count() === 0, 'No queue jobs failed');
        break;
    case 'queue-mail':
        App\Jobs\SendEmailJob::dispatch([
            'email' => 'queue@example.test', 'subject' => 'Acceptance queue test', 'template_name' => 'verify',
            'template_value' => ['name' => 'Acceptance', 'code' => '123456', 'url' => 'http://localhost'],
        ]);
        echo "PASS: Email job dispatched through Redis\n";
        break;
    default: throw new RuntimeException('Unknown fixture stage');
}
