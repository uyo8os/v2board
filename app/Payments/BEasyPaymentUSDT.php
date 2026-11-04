<?php

namespace App\Payments;

use \Curl\Curl;

class BEasyPaymentUSDT {
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'bepusdt_url' => [
                'label' => 'API 地址',
                'description' => '您的 BEPUSDT API 接口地址(例如: https://xxx.com)',
                'type' => 'input',
            ],
            'bepusdt_apitoken' => [
                'label' => 'API Token',
                'description' => '您的 BEPUSDT API Token',
                'type' => 'input',
            ],
            'bepusdt_trade_type' => [
                'label' => '交易类型',
                'description' => '您的 BEPUSDT 交易类型',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'amount' => $order['total_amount'] / 100,
            'trade_type' => $this->config['bepusdt_trade_type'],
            'notify_url' => $order['notify_url'],
            'order_id' => $order['trade_no'],
            'redirect_url' => $order['return_url']
        ];
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['bepusdt_apitoken'];
        $params['signature'] = md5($str);

        $curl = new Curl();
        $curl->setUserAgent('BEPUSDT');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->setOpt(CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        $curl->post($this->config['bepusdt_url'] . '/api/v1/order/create-transaction', json_encode($params));
        $result = $curl->response;
        $curl->close();

        if (!isset($result->status_code) || $result->status_code != 200) {
            abort(500, "Failed to create order. Error: {$result->message}");
        }

        $paymentURL = $result->data->payment_url;
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $paymentURL
        ];
    }

    public function notify($params)
    {
        $secret = $this->config['bepusdt_apitoken'] ?? null;
        if (!is_string($secret) || trim($secret) === '' || !is_array($params)) {
            return false;
        }
        foreach ($params as $value) {
            if (!is_string($value) && !is_int($value) && !(is_float($value) && is_finite($value))) {
                return false;
            }
        }
        foreach (['order_id', 'trade_id'] as $field) {
            $value = $params[$field] ?? null;
            if ((!is_string($value) && !is_int($value)) || trim((string) $value) === '') {
                return false;
            }
        }
        if (!is_string($params['signature'] ?? null) || !in_array($params['status'] ?? null, [2, '2'], true)) {
            return false;
        }

        $sign = $params['signature'];
        unset($params['signature']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $secret;
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            return false;
        }
        return [
            'trade_no' => (string) $params['order_id'],
            'callback_no' => (string) $params['trade_id'],
            'custom_result' => 'ok'
        ];
    }
}
