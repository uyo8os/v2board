<?php

namespace App\Logging;

use Monolog\LogRecord;
use Throwable;

/** Keep only explicitly approved log fields. Free text can contain secrets anywhere. */
class SafeLogProcessor
{
    private const SAFE_MESSAGES = [
        'Admin POST request',
        'System log database write failed',
        'Subscription audit enqueue failed',
        'InsiderGuard restrict lookup unavailable',
        'InsiderGuard consumption restriction lookup unavailable',
        'InsiderGuard audit unavailable',
        'InsiderGuard ua dictionary unavailable',
        'InsiderGuard delivery audit unavailable',
        'Stripe charge failed',
        'Stripe checkout session failed',
        'Payment notification failed',
        'Commission transfer notification failed',
        'Automatic renewal settings update failed',
        'Speed limit traffic statistics failed',
        'Speed limit notification failed',
        'Traffic update failed',
        'Statistics update failed',
        'Captcha generation/session failed',
        'Captcha cache store failed',
        'Captcha PNG render failed',
        'Traffic statistics completed',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = [];
        foreach (['actor_id', 'target_id'] as $key) {
            $value = $record->context[$key] ?? null;
            if ((is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value))) {
                $context[$key] = (int) $value;
            }
        }

        $action = $record->context['action'] ?? null;
        if (is_string($action) && strlen($action) <= 255 && preg_match('/^App\\\\Http\\\\Controllers\\\\V1\\\\Admin\\\\[A-Za-z0-9_\\\\]+@[A-Za-z0-9_]+$/D', $action)) {
            $context['action'] = $action;
        }

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $context['exception_type'] = get_class($exception);
            $context['exception_file'] = basename($exception->getFile());
            $context['exception_line'] = $exception->getLine();
        }

        return $record->with(
            message: in_array($record->message, self::SAFE_MESSAGES, true) ? $record->message : '[Log details omitted]',
            context: $context,
            extra: []
        );
    }
}
