<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ConfigSave;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class SubscriptionAuditConfigurationTest extends TestCase
{
    /** @dataProvider settingCases */
    public function testAuditSettingsAreValidatedBeforeSaving(array $values, bool $expected): void
    {
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
        $this->assertSame($expected, $validator->make($values, ConfigSave::RULES)->passes());
    }

    public static function settingCases(): array
    {
        return [
            'default-sized settings' => [[
                'insider_guard_subscribe_audit_enabled' => 1,
                'insider_guard_subscribe_audit_retention_days' => 3,
                'insider_guard_subscribe_audit_max_records' => 100000,
            ], true],
            'disabled and lower bounds' => [[
                'insider_guard_subscribe_audit_enabled' => 0,
                'insider_guard_subscribe_audit_retention_days' => 1,
                'insider_guard_subscribe_audit_max_records' => 1,
            ], true],
            'upper bounds' => [[
                'insider_guard_subscribe_audit_retention_days' => 365,
                'insider_guard_subscribe_audit_max_records' => 1000000,
            ], true],
            'invalid toggle' => [['insider_guard_subscribe_audit_enabled' => 2], false],
            'zero retention' => [['insider_guard_subscribe_audit_retention_days' => 0], false],
            'excess retention' => [['insider_guard_subscribe_audit_retention_days' => 366], false],
            'fractional retention' => [['insider_guard_subscribe_audit_retention_days' => 1.5], false],
            'zero rows' => [['insider_guard_subscribe_audit_max_records' => 0], false],
            'excess rows' => [['insider_guard_subscribe_audit_max_records' => 1000001], false],
            'fractional rows' => [['insider_guard_subscribe_audit_max_records' => '10.5'], false],
            'unrelated settings can still be saved' => [['insider_guard_retention_days' => 7], true],
        ];
    }
}
