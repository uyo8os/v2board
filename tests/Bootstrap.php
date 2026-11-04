<?php

require dirname(__DIR__) . '/vendor/autoload.php';

// Keep test caches separate from the site's production config and package files.
register_shutdown_function(static function () {
    foreach (glob(dirname(__DIR__) . '/bootstrap/cache/*.phpunit.php') as $file) {
        unlink($file);
    }
});
