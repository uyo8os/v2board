<?php

namespace App\Payments;

class EPay {
    private $config;
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => '支付类型，如: alipay, wxpay, qqpay',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'money' => $order['total_amount'] / 100,
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];
        if (!empty($this->config['type'])) {
            $params['type'] = $this->config['type'];
        }
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $this->config['url'] . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        $secret = $this->config['key'] ?? null;
        if (!is_string($secret) || trim($secret) === '' || !is_array($params)) {
            return false;
        }
        foreach ($params as $value) {
            if (!is_string($value) && !is_int($value) && !(is_float($value) && is_finite($value))) {
                return false;
            }
        }
        foreach (['pid', 'out_trade_no', 'trade_no'] as $field) {
            $value = $params[$field] ?? null;
            if ((!is_string($value) && !is_int($value)) || trim((string) $value) === '') {
                return false;
            }
        }
        $pid = $this->config['pid'] ?? null;
        if ((!is_string($pid) && !is_int($pid)) || trim((string) $pid) === ''
            || (string) $params['pid'] !== (string) $pid
            || !is_string($params['sign'] ?? null)
            || ($params['sign_type'] ?? 'MD5') !== 'MD5'
            || ($params['trade_status'] ?? null) !== 'TRADE_SUCCESS') {
            return false;
        }

        $sign = $params['sign'];
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $secret;
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            return false;
        }

        return [
            'trade_no' => (string) $params['out_trade_no'],
            'callback_no' => (string) $params['trade_no']
        ];
    }
}
