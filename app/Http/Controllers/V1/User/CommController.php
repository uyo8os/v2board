<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Utils\Dict;
use Illuminate\Http\Request;

class CommController extends Controller
{
    public function config()
    {
        return response([
            'data' => [
                'is_telegram' => (int)config('v2board.telegram_bot_enable', 0),
                'telegram_show_bind' => (int)config('v2board.telegram_show_bind', 1),
                'telegram_discuss_link' => config('v2board.telegram_discuss_link'),
                'stripe_pk' => config('v2board.stripe_pk_live'),
                'withdraw_methods' => config('v2board.commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT),
                'withdraw_close' => (int)config('v2board.withdraw_close_enable', 0),
                'currency' => config('v2board.currency', 'CNY'),
                'currency_symbol' => config('v2board.currency_symbol', '¥'),
                'commission_distribution_enable' => (int)config('v2board.commission_distribution_enable', 0),
                'commission_distribution_l1' => config('v2board.commission_distribution_l1'),
                'commission_distribution_l2' => config('v2board.commission_distribution_l2'),
                'commission_distribution_l3' => config('v2board.commission_distribution_l3'),
                'ticket_image_upload_enable' => (int)config('v2board.ticket_image_upload_enable', 0),
                'ticket_image_upload_max_file_size' => (int)config('v2board.ticket_image_upload_max_file_size', 5242880),
                'ticket_image_upload_allowed_types' => $this->getTicketImageUploadAllowedTypes()
            ]
        ]);
    }

    private function getTicketImageUploadAllowedTypes()
    {
        $default = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ];
        $types = config('v2board.ticket_image_upload_allowed_types', $default);
        if (is_array($types)) {
            $types = array_values(array_filter(array_map(function ($type) {
                return strtolower(trim((string)$type));
            }, $types)));
            return $types ?: $default;
        }
        $types = array_values(array_filter(array_map(function ($type) {
            return strtolower(trim((string)$type));
        }, preg_split('/,/', (string)$types))));
        return $types ?: $default;
    }

    public function getStripePublicKey(Request $request)
    {
        $payment = Payment::where('id', $request->input('id'))
            ->where('payment', 'StripeCredit')
            ->first();
        if (!$payment) abort(500, 'payment is not found');
        return response([
            'data' => $payment->config['stripe_pk_live']
        ]);
    }
}
