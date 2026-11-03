<?php

namespace App\Services;

use Illuminate\Http\Request;

/** 订阅明细只保留少量安全元数据，不读取整组请求头、完整 URL 或合并的用户模型。 */
class SubscriptionAuditRequest
{
    const MAX_DETAILS_BYTES = 4096;
    const FLAGS = [
        'general', 'clash', 'meta', 'clash.meta', 'verge', 'nyanpasu', 'passwall',
        'loon', 'quantumult x', 'quantumult%20x', 'sagernet', 'shadowrocket',
        'shadowsocks', 'sing', 'singbox', 'sing-box', 'ssrplus', 'surfboard',
        'surge', 'stash', 'v2rayn', 'v2rayng', 'v2raytun',
    ];

    private $secrets = [];

    public function __construct(Request $request, $user)
    {
        $values = [$request->input('token')];
        if (is_object($user)) {
            $values[] = $user->token ?? null;
            $values[] = $user->uuid ?? null;
        }
        foreach ($values as $value) {
            if (!is_scalar($value) || (string)$value === '') continue;
            $value = (string)$value;
            $this->secrets[] = $value;
            $this->secrets[] = rawurlencode($value);
            $this->secrets[] = urlencode($value);
        }
        $this->secrets = array_values(array_unique($this->secrets));
        usort($this->secrets, function ($a, $b) { return strlen($b) <=> strlen($a); });
    }

    public function text($value, int $limit): string
    {
        if (!is_scalar($value) && $value !== null) return '';
        $value = (string)$value;
        foreach ($this->secrets as $secret) {
            // 百分号编码不区分十六进制大小写；多脱敏相近文本比遗漏凭据更稳妥。
            $value = str_ireplace($secret, '[redacted]', $value);
        }
        $value = preg_replace('~([?&](?:[^=&\s]*(?:token|secret|password|auth|key|signature|session)[^=&\s]*)=)[^&#\s]*~i', '$1[redacted]', $value);
        return self::boundedText($value, $limit);
    }

    public function capture(Request $request): array
    {
        $details = [
            'method' => $request->getMethod(),
            'path' => $this->text(explode('?', $request->getPathInfo(), 2)[0], 512),
            'query' => [],
        ];
        $flag = $request->query('flag');
        if (is_string($flag)) {
            $flag = strtolower(trim($flag));
            if (in_array($flag, self::FLAGS, true)) {
                $details['query']['flag'] = $flag;
            } elseif (preg_match('/^(sing-box|singbox|sing)\s+(\d{1,3}(?:\.\d{1,3}){0,3})$/', $flag, $match)) {
                $details['query'] = ['flag' => $match[1], 'version' => $match[2]];
            }
        }
        return self::safeDetails($details);
    }

    public function encode(array $details): string
    {
        // 再消毒一次：调用方可以附加布尔限制标记和节点数量，其他未知属性一律丢弃。
        $details['path'] = $this->text($details['path'] ?? '', 512);
        return self::encodeDetails($details);
    }

    public static function boundedText($value, int $limit): string
    {
        if (!is_scalar($value) && $value !== null) return '';
        $value = (string)$value;
        if (function_exists('mb_convert_encoding')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } elseif (!preg_match('//u', $value)) {
            $value = function_exists('iconv') ? (string)@iconv('UTF-8', 'UTF-8//IGNORE', $value) : '';
        }
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = substr((string)$value, 0, max(0, $limit));
        // substr 可能截在多字节字符中间；只删除不完整的尾部字节。
        while ($value !== '' && !preg_match('//u', $value)) $value = substr($value, 0, -1);
        return $value;
    }

    public static function safeDetails(array $details): array
    {
        $method = strtoupper(self::boundedText($details['method'] ?? '', 16));
        $path = self::boundedText($details['path'] ?? '', 512);
        // 路径字段不允许夹带 URL、query 或 fragment，也屏蔽常见凭据路径段。
        $path = explode('#', explode('?', $path, 2)[0], 2)[0];
        if ($path !== '' && substr($path, 0, 1) !== '/') $path = '[redacted]';
        $path = preg_replace('~(/(?:token|secret|password|auth|key|signature|session)/)[^/]+~i', '$1[redacted]', $path);
        $query = isset($details['query']) && is_array($details['query']) ? $details['query'] : [];
        $safeQuery = [];
        if (isset($query['flag']) && is_string($query['flag']) && in_array($query['flag'], self::FLAGS, true)) {
            $safeQuery['flag'] = $query['flag'];
        }
        if (isset($query['version']) && is_string($query['version']) && preg_match('/^\d{1,3}(?:\.\d{1,3}){0,3}$/', $query['version'])) {
            $safeQuery['version'] = $query['version'];
        }
        $safe = [
            'method' => in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true) ? $method : 'OTHER',
            'path' => $path,
            'query' => $safeQuery,
        ];
        if (isset($details['decoy']) && is_bool($details['decoy'])) $safe['decoy'] = $details['decoy'];
        if (isset($details['node_count']) && is_int($details['node_count'])) $safe['node_count'] = max(0, min(1000000, $details['node_count']));
        return $safe;
    }

    public static function encodeDetails(array $details): string
    {
        $json = json_encode(self::safeDetails($details), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) && strlen($json) <= self::MAX_DETAILS_BYTES ? $json : '{}';
    }
}
