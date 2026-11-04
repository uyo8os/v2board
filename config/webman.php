<?php

return [
    // Only Webman uses these peers; PHP-FPM keeps its existing proxy behavior.
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string)env('WEBMAN_TRUSTED_PROXIES', '127.0.0.1,::1'))
    ))),
];
