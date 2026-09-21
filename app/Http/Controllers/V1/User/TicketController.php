<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Models\Plan;
use App\Models\Order;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        $userId = $request->user['id'];
        $ticketId = $request->input('id');

        if ($ticketId) {
            $ticket = Ticket::where('id', $ticketId)
                ->where('user_id', $userId)
                ->firstOrFail();

            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            for ($i = 0; $i < count($ticket['message']); $i++) {
                if ($ticket['message'][$i]['user_id'] !== $ticket->user_id) {
                    $ticket['message'][$i]['is_me'] = false;
                } else {
                    $ticket['message'][$i]['is_me'] = true;
                }
            }

            return response(['data' => $ticket]);

        }
        $ticket = Ticket::where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->get();
        return response([
            'data' => $ticket
        ]);
    }

    public function save(TicketSave $request)
    {
        try {
            DB::beginTransaction();
            if ((int)Ticket::where('status', 0)->where('user_id', $request->user['id'])->lockForUpdate()->count()) {
                throw new \Exception(__('There are other unresolved tickets'));
            }

            // 获取工单状态
            $ticketStatus = config('v2board.ticket_status', 0);

            switch ($ticketStatus) {
                case 0:
                    // 完全开放，不禁止任何工单
                    break;
                case 1:
                    // 仅限有付费订单用户
                    $hasOrder = Order::where('user_id', $request->user['id'])
                        ->whereIn('status', [3, 4])
                        ->exists();

                    if (!$hasOrder) {
                        throw new \Exception(__('请先购买套餐'));
                    }
                    break;
                case 2:
                    // 完全禁止所有工单
                    throw new \Exception(__('当前套餐不允许发起工单'));
                    break;
                default:
                    // 处理未知状态
                    throw new \Exception(__('未知的工单状态'));
            }

            $ticketData = $request->only(['subject', 'level']) + ['user_id' => $request->user['id']];
            $ticket = Ticket::create($ticketData);

            TicketMessage::create([
                'user_id' => $request->user['id'],
                'ticket_id' => $ticket->id,
                'message' => $request->input('message')
            ]);

            DB::commit();
            $this->sendNotify($ticket, $request->input('message'),$request->user['id']);
            return response([
                'data' => true
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function upload(Request $request)
    {
        if (!(int)config('v2board.ticket_image_upload_enable', 0)) {
            abort(403, '工单图片上传未开启');
        }

        // Some PHP-FPM/proxy combinations preserve the uploaded temporary file
        // but make Symfony's is_uploaded_file() check return false. Validate the
        // upload error and readable temporary path instead so those valid files
        // can still be forwarded to the configured image host.
        $file = $request->file('file') ?: $request->file('image');
        if (!$file) {
            abort(422, '未接收到图片文件，请重新选择后上传');
        }

        $uploadError = (int)$file->getError();
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadErrorMessages = [
                UPLOAD_ERR_INI_SIZE => '图片超过服务器允许的上传大小，请调大 upload_max_filesize 和 post_max_size',
                UPLOAD_ERR_FORM_SIZE => '图片超过服务器允许的上传大小',
                UPLOAD_ERR_PARTIAL => '图片上传未完成，请重试',
                UPLOAD_ERR_NO_FILE => '未接收到图片文件，请重新选择后上传',
                UPLOAD_ERR_NO_TMP_DIR => '服务器缺少上传临时目录',
                UPLOAD_ERR_CANT_WRITE => '服务器无法写入上传临时文件',
                UPLOAD_ERR_EXTENSION => '服务器扩展中止了图片上传',
            ];
            abort(422, $uploadErrorMessages[$uploadError] ?? '请选择有效的图片文件');
        }

        $realPath = $file->getRealPath() ?: $file->getPathname();
        if (!$realPath || !is_file($realPath) || !is_readable($realPath)) {
            abort(422, '无法读取图片文件，请重试');
        }

        $maxFileSize = max(1, (int)config('v2board.ticket_image_upload_max_file_size', 5242880));
        if ((int)$file->getSize() > $maxFileSize) {
            abort(422, '图片大小不能超过 ' . $maxFileSize . ' 字节');
        }

        $allowedTypes = $this->getTicketImageUploadAllowedTypes();
        $mimeType = strtolower((string)($file->getMimeType() ?: $file->getClientMimeType()));
        if ($allowedTypes && !in_array($mimeType, $allowedTypes, true)) {
            abort(422, '不支持的图片类型');
        }

        $apiUrl = trim((string)config('v2board.ticket_image_upload_api_url', ''));
        if ($apiUrl === '') {
            abort(500, '图床上传地址未配置');
        }
        $headers = ['Accept' => 'application/json'];
        $token = trim((string)config('v2board.ticket_image_upload_token', ''));
        if ($token !== '') {
            $headers['Authorization'] = preg_match('/^Bearer\\s/i', $token) ? $token : 'Bearer ' . $token;
        }

        $stream = fopen($realPath, 'rb');
        if ($stream === false) {
            abort(422, '无法读取图片文件');
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->attach('file', $stream, $file->getClientOriginalName(), ['Content-Type' => $mimeType])
                ->post($apiUrl);
        } finally {
            fclose($stream);
        }

        if (!$response->successful()) {
            abort(502, '图床上传失败，请稍后重试');
        }

        $payload = $response->json();
        $responseField = trim((string)config('v2board.ticket_image_upload_response_field', 'url')) ?: 'url';
        $url = is_array($payload) ? data_get($payload, $responseField) : null;
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            abort(502, '图床响应中未找到有效图片地址');
        }

        return response([
            'data' => [
                'url' => $url,
                'name' => $file->getClientOriginalName()
            ]
        ]);
    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, __('Invalid parameter'));
        }
        if (empty($request->input('message'))) {
            abort(500, __('Message cannot be empty'));
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, __('Ticket does not exist'));
        }
        if ($ticket->status) {
            abort(500, __('The ticket is closed and cannot be replied'));
        }
        if ($request->user['id'] == $this->getLastMessage($ticket->id)->user_id) {
            abort(500, __('Please wait for the technical enginneer to reply'));
        }
        $ticketService = new TicketService();
        if (
			!$ticketService->reply(
				$ticket,
				$request->input('message'),
				$request->user['id']
			)
		) {
            abort(500, __('Ticket reply failed'));
        }
        $this->sendNotify($ticket, $request->input('message'), $request->user['id']);
        return response([
            'data' => true
        ]);
    }


    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, __('Invalid parameter'));
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, __('Ticket does not exist'));
        }
        $ticket->status = 1;
        if (!$ticket->save()) {
            abort(500, __('Close failed'));
        }
        return response([
            'data' => true
        ]);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
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

    public function withdraw(TicketWithdraw $request)
    {
        if ((int)config('v2board.withdraw_close_enable', 0)) {
            abort(500, 'user.ticket.withdraw.not_support_withdraw');
        }
        if (
			!in_array(
				$request->input('withdraw_method'),
				config(
					'v2board.commission_withdraw_method',
					Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT
				)
			)
		) {
            abort(500, __('Unsupported withdrawal method'));
        }
        $user = User::find($request->user['id']);
        $limit = config('v2board.commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            abort(500, __('The current required minimum withdrawal commission is :limit', ['limit' => $limit]));
        }
        DB::beginTransaction();
        $subject = __('[Commission Withdrawal Request] This ticket is opened by the system');
        $ticket = Ticket::create([
            'subject' => $subject,
            'level' => 2,
            'user_id' => $request->user['id']
        ]);
        if (!$ticket) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        $message = sprintf(
			"%s\r\n%s",
            __('Withdrawal method') . "：" . $request->input('withdraw_method'),
            __('Withdrawal account') . "：" . $request->input('withdraw_account')
        );
        $ticketMessage = TicketMessage::create([
            'user_id' => $request->user['id'],
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if (!$ticketMessage) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        DB::commit();
        $this->sendNotify($ticket, $message);
        return response([
            'data' => true
        ]);
    }

    private function sendNotify(Ticket $ticket, string $message, $userid = null)
	{
		$telegramService = new TelegramService();
		if (!empty($userid)) {
			$user = User::find($userid);

			if ($user) {
				$transfer_enable = $this->getFlowData($user->transfer_enable); // 总流量
				$remaining_traffic = $this->getFlowData($user->transfer_enable - $user->u - $user->d); // 剩余流量
				$u = $this->getFlowData($user->u); // 上传
				$d = $this->getFlowData($user->d); // 下载
				$expired_at = date("Y-m-d H:i:s", $user->expired_at); // 到期时间
				if (isset($_SERVER['HTTP_X_REAL_IP'])) {
				$ip_address = $_SERVER['HTTP_X_REAL_IP'];
				} elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
					$ip_address = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
				} else {
					$ip_address = $_SERVER['REMOTE_ADDR'];
				}

				$api_url = "http://ip-api.com/json/{$ip_address}?fields=520191&lang=zh-CN";
				$response = file_get_contents($api_url);
				$user_location = json_decode($response, true);
				if ($user_location && $user_location['status'] === 'success') {
					$location =  $user_location['city'] . ", " . $user_location['country'];
				} else {
					$location =  "无法确定用户地址";
				}

				$plan = Plan::where('id', $user->plan_id)->first();
				$planName = $plan ? $plan->name : '未找到套餐信息'; // Check if plan data is available

				$money = $user->balance / 100;
				$affmoney = $user->commission_balance / 100;
				$telegramService->sendMessageWithAdmin("📮工单提醒 #{$ticket->id}\n———————————————\n邮箱：\n`{$user->email}`\n用户位置：\n`{$location}`\nIP:\n{$ip_address}\n套餐与流量：\n`{$planName} of {$transfer_enable}/{$remaining_traffic}`\n上传/下载：\n`{$u}/{$d}`\n到期时间：\n`{$expired_at}`\n余额/佣金余额：\n`{$money}/{$affmoney}`\n主题：\n`{$ticket->subject}`\n内容：\n {$message} ", true);
			} else {
				// Handle case where user data is not found
				$telegramService->sendMessageWithAdmin("User data not found for user ID: {$userid}", true);
			}
		} else {
			$telegramService->sendMessageWithAdmin("📮工单提醒 #{$ticket->id}\n———————————————\n主题：\n`{$ticket->subject}`\n内容：\n {$message} ", true);
		}
	}

    private function getFlowData($b)
    {
        $g = $b / (1024 * 1024 * 1024); // 转换流量数据
        $m = $b / (1024 * 1024);
        if ($g >= 1) {
            $text = round($g, 2) . "GB";
        } else {
            $text = round($m, 2) . "MB";
        }
        return $text;
    }
}
