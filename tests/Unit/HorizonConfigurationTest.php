<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HorizonConfigurationTest extends TestCase
{
    private array $previousEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnvironment = [
            $_ENV['HORIZON_MAX_PROCESSES'] ?? null,
            $_SERVER['HORIZON_MAX_PROCESSES'] ?? null,
            getenv('HORIZON_MAX_PROCESSES'),
        ];
    }

    protected function tearDown(): void
    {
        [$env, $server, $process] = $this->previousEnvironment;
        unset($_ENV['HORIZON_MAX_PROCESSES'], $_SERVER['HORIZON_MAX_PROCESSES']);
        if ($env !== null) $_ENV['HORIZON_MAX_PROCESSES'] = $env;
        if ($server !== null) $_SERVER['HORIZON_MAX_PROCESSES'] = $server;
        putenv($process === false ? 'HORIZON_MAX_PROCESSES' : 'HORIZON_MAX_PROCESSES='.$process);
        parent::tearDown();
    }

    public function testWorkerLimitAndBusinessQueuesRemainEffective(): void
    {
        foreach ([1, 0, -3] as $limit) {
            $_ENV['HORIZON_MAX_PROCESSES'] = $_SERVER['HORIZON_MAX_PROCESSES'] = (string)$limit;
            $config = require dirname(__DIR__, 2).'/config/horizon.php';
            foreach (['local', '*'] as $environment) {
                $workers = $config['environments'][$environment];
                $this->assertSame(1, $workers['V2board']['maxProcesses']);
                $this->assertSame([
                    'order_handle', 'traffic_fetch', 'stat', 'speed_limit',
                    'send_email', 'send_email_mass', 'send_telegram',
                ], $workers['V2board']['queue']);
                $this->assertSame(['insider_guard_audit'], $workers['InsiderGuardAudit']['queue']);
                $this->assertSame(1, $workers['InsiderGuardAudit']['maxProcesses']);
            }
        }
    }

    public function testLinuxDefaultRetainsTheMemoryCalculation(): void
    {
        if (!is_readable('/proc/meminfo')) $this->markTestSkipped('Requires Linux /proc/meminfo');
        unset($_ENV['HORIZON_MAX_PROCESSES'], $_SERVER['HORIZON_MAX_PROCESSES']);
        putenv('HORIZON_MAX_PROCESSES');
        $memory = [];
        foreach (file('/proc/meminfo') as $line) {
            [$key, $value] = explode(':', $line, 2);
            $memory[$key] = (int)trim($value);
        }
        $expected = max(1, min((int)ceil($memory['MemTotal'] * 1024 / 1024 / 1024 / 1024 * 6), 128));
        $config = require dirname(__DIR__, 2).'/config/horizon.php';

        $this->assertSame($expected, $config['environments']['local']['V2board']['maxProcesses']);
        $this->assertSame($expected, $config['environments']['*']['V2board']['maxProcesses']);
    }
}
