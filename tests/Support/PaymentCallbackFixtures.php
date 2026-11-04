<?php

namespace Tests\Support;

final class PaymentCallbackFixtures
{
    public const SECRET = 'offline-private-payment-key';

    public static function gateways(): array
    {
        return ['EPay' => ['EPay'], 'EPayQrcode' => ['EPayQrcode'], 'BEasyPaymentUSDT' => ['BEasyPaymentUSDT']];
    }

    public static function secretField(string $gateway): string
    {
        return $gateway === 'BEasyPaymentUSDT' ? 'bepusdt_apitoken' : 'key';
    }

    public static function config(string $gateway, $secret = self::SECRET): array
    {
        return ['pid' => '123', self::secretField($gateway) => $secret];
    }

    public static function adapter(string $gateway, array $config)
    {
        $class = 'App\\Payments\\' . $gateway;
        return new $class($config);
    }

    /** Generate the existing gateway wire signatures without using adapter internals. */
    public static function callback(string $gateway, array $overrides = [], string $secret = self::SECRET): array
    {
        $params = $gateway === 'BEasyPaymentUSDT'
            ? ['order_id' => 'fixture-order', 'trade_id' => 'fixture-transaction', 'status' => 2, 'amount' => 12.34]
            : ['pid' => '123', 'out_trade_no' => 'fixture-order', 'trade_no' => 'fixture-transaction', 'trade_status' => 'TRADE_SUCCESS', 'money' => '12.34'];
        $params = array_replace($params, $overrides);
        unset($params['sign'], $params['signature'], $params['sign_type']);
        ksort($params);

        if ($gateway === 'EPayQrcode') {
            $pairs = [];
            foreach ($params as $key => $value) {
                if ($value !== '' && $value !== null) $pairs[] = $key . '=' . $value;
            }
            $message = implode('&', $pairs);
        } else {
            $message = stripslashes(urldecode(http_build_query($params)));
        }
        $params[$gateway === 'BEasyPaymentUSDT' ? 'signature' : 'sign'] = md5($message . $secret);
        if ($gateway !== 'BEasyPaymentUSDT') $params['sign_type'] = 'MD5';
        return $params;
    }
}
