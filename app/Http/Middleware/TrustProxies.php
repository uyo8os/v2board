<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array|string
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_AWS_ELB;

    protected function setTrustedProxyIpAddresses(Request $request)
    {
        if (defined('isWEBMAN') && isWEBMAN) {
            return $this->setTrustedProxyIpAddressesToSpecificIps(
                $request,
                config('webman.trusted_proxies', ['127.0.0.1', '::1'])
            );
        }

        return parent::setTrustedProxyIpAddresses($request);
    }

    protected function headers()
    {
        // Nginx passes Host directly; forwarded Host/Port remain untrusted.
        return defined('isWEBMAN') && isWEBMAN
            ? Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO
            : parent::headers();
    }
}
