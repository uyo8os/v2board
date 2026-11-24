<?php

namespace App\Logging;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\RequestException;
use Monolog\LogRecord;
use PDOException;
use Throwable;

class SafeLogProcessor
{
    private const CREDENTIAL_NAME = '(?:password|passwd|passphrase|secret|token|key|credential|auth|cookie|session|signature'
        . '|captcha|otp|verification[_ -]?code|uuid|stripe[_-]?sk|secure[_-]?path|card[_ -]?number|cvv|cvc)';
    private const RAW_FIELD_NAME = '(?:request|response|body|headers?|query|payload|input|config|bindings|sql|trace|args|arguments'
        . '|(?:request|response|raw)[_-]?(?:body|data|headers?|query|params|payload|input|uri|url))';
    private const PRIVATE_FIELD = '~' . self::CREDENTIAL_NAME . '|^(?:pwd|code|' . self::RAW_FIELD_NAME . ')$~i';

    public function __invoke(LogRecord $record): LogRecord
    {
        $remaining = 32;
        $context = $this->diagnosticFields(
            array_diff_key($record->context, array_flip(['actor_id', 'target_id', 'action', 'exception'])),
            $remaining
        );
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

        $message = $record->message;
        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $reason = $this->exceptionMessage($exception);
            $context['exception_type'] = get_class($exception);
            $context['exception_file'] = basename($exception->getFile());
            $context['exception_line'] = $exception->getLine();
            $context['exception_code'] = $this->redactText((string) $exception->getCode(), 16);
            $context['exception_message'] = $reason;
            $context['exception_trace'] = array_map(
                $this->formatFrame(...),
                array_slice($exception->getTrace(), 0, 16)
            );

            $previous = $exception instanceof PDOException ? null : $exception->getPrevious();
            if ($previous !== null) {
                $context['exception_previous'] = [
                    'type' => get_class($previous),
                    'message' => $this->exceptionMessage($previous),
                ];
            }

            // Laravel reports an exception's message as the event title.
            if ($exception->getMessage() !== '') {
                $message = str_replace($exception->getMessage(), $reason, $message);
            }
            if ($message === '') {
                $message = $context['exception_type'];
            }
        }

        return $record->with(message: $this->redactText($message, 2048), context: $context, extra: []);
    }

    private function diagnosticFields(array $fields, int &$remaining, int $depth = 0): array
    {
        $context = [];
        foreach ($fields as $key => $value) {
            if ($remaining-- <= 0) {
                break;
            }
            if (is_string($key) && (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/D', $key) || preg_match(self::PRIVATE_FIELD, $key))) {
                continue;
            }
            if (is_array($value) && $depth < 3) {
                $context[$key] = $this->diagnosticFields($value, $remaining, $depth + 1);
            } elseif (is_string($value)) {
                $context[$key] = $this->redactText($value, 512);
            } elseif ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    private function exceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof PDOException) {
            // A driver or QueryException message can include literal SQL and bound credentials.
            $message = 'Database operation failed';
            $state = $exception->errorInfo[0] ?? (string) $exception->getCode();
            if (is_string($state) && preg_match('/^[A-Z0-9]{5}$/D', $state)) {
                $message .= " (SQLSTATE $state)";
            }
            $driverCode = $exception->errorInfo[1] ?? null;
            if (is_int($driverCode)) {
                $message .= " (driver code $driverCode)";
            }

            return $message;
        }
        if ($exception instanceof RequestException) {
            return 'HTTP request failed (status ' . $exception->response->status() . ')';
        }
        if ($exception instanceof GuzzleRequestException && is_callable([$exception, 'getResponse'])) {
            $response = $exception->getResponse();
            if ($response !== null) {
                return 'HTTP request failed (status ' . $response->getStatusCode() . ')';
            }
        }

        return $this->redactText($exception->getMessage(), 2048);
    }

    private function formatFrame(array $frame): string
    {
        $class = explode("\0", $frame['class'] ?? '', 2)[0];
        $function = $frame['function'] ?? '';
        if (str_starts_with($function, '{closure:')) {
            $function = '{closure}';
        }
        $location = sprintf(
            '%s:%d %s%s%s',
            basename($frame['file'] ?? '[internal]'),
            $frame['line'] ?? 0,
            $class,
            $frame['type'] ?? '',
            $function
        );

        return mb_strcut(mb_scrub($location, 'UTF-8'), 0, 256, 'UTF-8');
    }

    private function redactText(string $text, int $limit): string
    {
        $text = preg_replace(
            [
                '~\b[a-z][a-z0-9+.-]*://[^\s<>"\']+~i',
                '~(?:/[^\s<>"\'?]*)?\?[^?\s<>"\'#=&]+=[^\s<>"\']*~',
                '~\b(?:Bearer|Basic)\h+\S+|\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+|\b[rs]k_(?:live|test)_[A-Za-z0-9]+~i',
                '~-----BEGIN [^\r\n]*PRIVATE KEY-----.*\z~s',
                '~(\bSQLSTATE\[[A-Z0-9]{5}\]).*\z~s',
                '~(\b(?:[A-Za-z0-9_.-]*' . self::CREDENTIAL_NAME . '[A-Za-z0-9_.-]*|pwd|code|'
                    . self::RAW_FIELD_NAME . ')["\']?\h*[:=]\s*).*\z~is',
                '/[\x00-\x1F\x7F]+/',
            ],
            ['[redacted URL]', '[redacted query]', '[redacted]', '[redacted]', '$1', '$1[redacted]', ' '],
            substr($text, 0, 8192)
        ) ?? '[redacted]';

        return mb_strcut(mb_scrub($text, 'UTF-8'), 0, $limit, 'UTF-8');
    }
}
