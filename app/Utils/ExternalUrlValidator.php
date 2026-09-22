<?php

namespace App\Utils;

/**
 * Validation helpers for server-side requests to administrator-configured URLs.
 *
 * This is intentionally fail-closed: image upload endpoints must be HTTPS
 * public DNS names and must not resolve to private or reserved address ranges.
 */
class ExternalUrlValidator
{
    /**
     * Validate an image-host URL and, when requested, resolve it to public IPs.
     *
     * @param mixed $url
     * @param bool $resolveDns
     * @return array
     */
    public static function inspectImageUploadUrl($url, $resolveDns = true)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return self::invalid('图床上传地址未配置');
        }

        if (strlen($url) > 2048) {
            return self::invalid('图床上传地址过长');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return self::invalid('图床上传地址格式不正确');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return self::invalid('图床上传地址必须使用 HTTPS');
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return self::invalid('图床上传地址不能包含用户名或密码');
        }

        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            return self::invalid('图床上传地址只能使用 HTTPS 默认端口');
        }

        $host = strtolower((string)$parts['host']);
        if (substr($host, -1) === '.') {
            return self::invalid('图床上传地址的域名格式不正确');
        }

        // Only public DNS names are accepted. Literal IPs and single-label
        // names such as localhost are deliberately rejected.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::invalid('图床上传地址必须使用公网域名，不能直接填写 IP');
        }
        if (strpos($host, '.') === false || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return self::invalid('图床上传地址必须使用有效的公网域名');
        }

        $result = [
            'valid' => true,
            'url' => $url,
            'host' => $host,
            'port' => 443,
            'addresses' => [],
            'curl_resolve' => [],
        ];

        if (!$resolveDns) {
            return $result;
        }

        if (!function_exists('dns_get_record') || !defined('DNS_A') || !defined('DNS_AAAA')) {
            return self::invalid('服务器缺少 DNS 解析能力，无法验证图床地址');
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || !$records) {
            return self::invalid('图床域名无法解析');
        }

        foreach ($records as $record) {
            $ip = isset($record['ip']) ? $record['ip'] : (isset($record['ipv6']) ? $record['ipv6'] : null);
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if (!self::isPublicIp($ip)) {
                return self::invalid('图床域名解析到了内网或保留地址');
            }

            $result['addresses'][] = $ip;
        }

        $result['addresses'] = array_values(array_unique($result['addresses']));
        if (!$result['addresses']) {
            return self::invalid('图床域名没有可用的公网地址');
        }

        foreach ($result['addresses'] as $ip) {
            // CURLOPT_RESOLVE keeps the request on the address that was
            // checked above, reducing DNS-rebinding TOCTOU exposure.
            $resolveIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
            $result['curl_resolve'][] = $host . ':443:' . $resolveIp;
        }

        return $result;
    }

    private static function isPublicIp($ip)
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private static function invalid($message)
    {
        return [
            'valid' => false,
            'message' => $message,
            'addresses' => [],
            'curl_resolve' => [],
        ];
    }
}
