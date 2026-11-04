<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PaymentCallbackFixtures as Fixtures;

class PaymentCallbackSecurityTest extends TestCase
{
    public static function gateways(): array
    {
        return Fixtures::gateways();
    }

    public static function epayGateways(): array
    {
        return ['EPay' => ['EPay'], 'EPayQrcode' => ['EPayQrcode']];
    }

    #[DataProvider('gateways')]
    public function testRejectsMissingEmptyAndNonStringSecrets(string $gateway): void
    {
        $field = Fixtures::secretField($gateway);
        foreach (['', " \t\r\n", null, false, 0, 123, 1.5, [], new \stdClass()] as $secret) {
            $params = Fixtures::callback($gateway, [], is_scalar($secret) ? (string) $secret : '');
            $adapter = Fixtures::adapter($gateway, Fixtures::config($gateway, $secret));
            $this->assertFalse($adapter->notify($params), 'Rejected secret type: ' . get_debug_type($secret));
        }
        $config = Fixtures::config($gateway);
        unset($config[$field]);
        $this->assertFalse(Fixtures::adapter($gateway, $config)->notify(Fixtures::callback($gateway, [], '')));
    }

    #[DataProvider('gateways')]
    public function testRejectsMalformedPayloadsWithoutCoercingValues(string $gateway): void
    {
        $adapter = Fixtures::adapter($gateway, Fixtures::config($gateway));
        foreach ([null, false, '', 'not-an-array', 2, new \stdClass(), []] as $params) {
            $this->assertFalse($adapter->notify($params));
        }

        $signature = $gateway === 'BEasyPaymentUSDT' ? 'signature' : 'sign';
        $fields = $gateway === 'BEasyPaymentUSDT' ? ['order_id', 'trade_id'] : ['pid', 'out_trade_no', 'trade_no'];
        foreach (array_merge([$signature], $fields) as $field) {
            $params = Fixtures::callback($gateway);
            unset($params[$field]);
            $this->assertFalse($adapter->notify($params), 'Missing field: ' . $field);
            foreach ([null, false, true, [], ['nested'], new \stdClass(), 1.5, '', '   '] as $value) {
                $params = array_replace(Fixtures::callback($gateway), [$field => $value]);
                $this->assertFalse($adapter->notify($params), 'Invalid field: ' . $field);
            }
        }
        foreach ([[], new \stdClass(), true, null, INF, NAN] as $value) {
            $params = array_replace(Fixtures::callback($gateway), ['unexpected' => $value]);
            $this->assertFalse($adapter->notify($params));
        }
    }

    #[DataProvider('gateways')]
    public function testRejectsSignedUnpaidStatusesAndTypeConfusion(string $gateway): void
    {
        $adapter = Fixtures::adapter($gateway, Fixtures::config($gateway));
        $field = $gateway === 'BEasyPaymentUSDT' ? 'status' : 'trade_status';
        $statuses = $gateway === 'BEasyPaymentUSDT'
            ? [null, false, true, 0, 1, 3, 2.0, '02', '2e0', 'failed']
            : [null, false, true, 1, 'WAIT_BUYER_PAY', 'TRADE_CLOSED', 'trade_success'];
        foreach ($statuses as $status) {
            $this->assertFalse($adapter->notify(Fixtures::callback($gateway, [$field => $status])));
        }
    }

    #[DataProvider('gateways')]
    public function testRejectsWrongSignaturesAndTamperedOrders(string $gateway): void
    {
        $adapter = Fixtures::adapter($gateway, Fixtures::config($gateway));
        $this->assertFalse($adapter->notify(Fixtures::callback($gateway, [], 'wrong-key')));
        $params = Fixtures::callback($gateway);
        $params[$gateway === 'BEasyPaymentUSDT' ? 'order_id' : 'out_trade_no'] = 'different-order';
        $this->assertFalse($adapter->notify($params));
    }

    #[DataProvider('epayGateways')]
    public function testMerchantIdAndSignatureTypeMustMatch(string $gateway): void
    {
        $params = Fixtures::callback($gateway);
        foreach ([null, false, true, [], '', ' ', '456'] as $pid) {
            $config = array_replace(Fixtures::config($gateway), ['pid' => $pid]);
            $this->assertFalse(Fixtures::adapter($gateway, $config)->notify($params));
        }
        $adapter = Fixtures::adapter($gateway, Fixtures::config($gateway));
        $this->assertFalse($adapter->notify(Fixtures::callback($gateway, ['pid' => '456'])));
        $params['sign_type'] = 'SHA256';
        $this->assertFalse($adapter->notify($params));
        unset($params['sign_type']);
        $this->assertIsArray($adapter->notify($params), 'The optional MD5 declaration remains optional');
    }

    #[DataProvider('gateways')]
    public function testValidCallbacksKeepTheirWireSignaturesAndReturnContract(string $gateway): void
    {
        // Do not trim a valid secret: surrounding spaces are part of the signed key.
        foreach ([Fixtures::SECRET, ' padded-private-key '] as $secret) {
            $adapter = Fixtures::adapter($gateway, Fixtures::config($gateway, $secret));
            $params = Fixtures::callback($gateway, ['note' => "备注 & + /\\", 'extra' => '', 'amount' => 12.34], $secret);
            $expected = ['trade_no' => 'fixture-order', 'callback_no' => 'fixture-transaction'];
            if ($gateway === 'BEasyPaymentUSDT') $expected['custom_result'] = 'ok';
            $this->assertSame($expected, $adapter->notify($params));

            $ids = $gateway === 'BEasyPaymentUSDT'
                ? ['order_id' => 123456, 'trade_id' => 654321, 'status' => '2']
                : ['pid' => 123, 'out_trade_no' => 123456, 'trade_no' => 654321];
            $params = Fixtures::callback($gateway, $ids, $secret);
            $expected['trade_no'] = '123456';
            $expected['callback_no'] = '654321';
            $this->assertSame($expected, $adapter->notify($params));
        }
    }
}
