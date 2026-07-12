<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\LocalCaptchaService;
use Illuminate\Http\Request;

class CaptchaController extends Controller
{
    /**
     * GET /api/v1/guest/captcha
     * 返回 PNG 或 SVG 验证码图片，并将验证码写入 Cache（支持跨域）。
     */
    public function image(Request $request)
    {
        // 生成唯一的验证码 key
        $key = $request->input('key', 'captcha_' . uniqid() . '_' . time());
        
        $code = null;
        try {
            // 优先使用 session（兼容同域部署）
            if ($request->hasSession()) {
                $code = LocalCaptchaService::generateCode($request);
            }
        } catch (\Throwable $e) {
            \Log::error('Captcha generation/session failed: ' . $e->getMessage());
        }
        
        // 生成验证码
        $code = $code ?: LocalCaptchaService::generateCodeString();
        
        // 同时存储到 Cache（支持跨域）
        try {
            \Cache::put('captcha_' . $key, strtolower($code), 300); // 5分钟过期
            \Cache::put('captcha_time_' . $key, time(), 300);
        } catch (\Throwable $e) {
            \Log::error('Captcha cache store failed: ' . $e->getMessage());
        }

        // 渲染图片
        if (function_exists('imagecreatetruecolor')) {
            try {
                $png = LocalCaptchaService::renderImage($code);
                return response($png, 200, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                    'X-Captcha-Key' => $key, // 返回 key 给前端
                ]);
            } catch (\Throwable $e) {
                \Log::error('Captcha PNG render failed: ' . $e->getMessage());
            }
        }

        $svg = LocalCaptchaService::renderSvg($code);
        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Captcha-Key' => $key, // 返回 key 给前端
        ]);
    }
}
