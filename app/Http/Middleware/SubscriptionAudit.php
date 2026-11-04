<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionAuditRequest;
use App\Services\SubscriptionAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SubscriptionAudit
{
    public function handle(Request $request, Closure $next, $source = 'subscription')
    {
        $user = $request->user();
        if (!$user || !$user->id) return $next($request);
        $started = microtime(true);
        try {
            $audit = app(SubscriptionAuditService::class);
            $enabled = $audit->enabled();
        } catch (\Throwable $error) {
            return $next($request);
        }
        if (!$enabled) return $next($request);
        try {
            $metadata = new SubscriptionAuditRequest($request, $user);
            $details = $metadata->capture($request);
            $packed = @inet_pton((string)$request->ip());
            $event = [
                'event_id' => bin2hex(random_bytes(16)),
                'user_id' => (int)$user->id,
                'email' => $metadata->text((string)$user->email, 256),
                'ip_address' => $packed === false ? '' : inet_ntop($packed),
                'ua' => $metadata->text((string)($request->userAgent() ?? ''), 512),
                'source' => $source === 'app' ? 'app' : 'subscription',
                'format' => '', 'result' => 'error', 'status' => 500,
                'requested_at' => time(),
            ];
        } catch (\Throwable $error) {
            // Optional request details must never prevent configuration delivery.
            return $next($request);
        }
        try {
            $response = $next($request);
            $event['status'] = $response->getStatusCode();
            $result = $request->attributes->get('subscription_audit_result', 'unavailable');
            $event['result'] = in_array($result, ['success', 'unavailable'], true) ? $result : 'unavailable';
            if ($event['status'] >= 400) $event['result'] = 'error';
            return $response;
        } catch (\Throwable $error) {
            $event['status'] = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
            throw $error;
        } finally {
            try {
                $event['format'] = $metadata->text($request->attributes->get('subscription_audit_format', ''), 64);
                $event['duration_ms'] = min(4294967295, max(0, (int)round((microtime(true) - $started) * 1000)));
                $details['decoy'] = (bool)$request->attributes->get('subscription_audit_decoy', false);
                $details['node_count'] = max(0, (int)$request->attributes->get('subscription_audit_node_count', 0));
                $event['request_details'] = $metadata->encode($details);
                $audit->record($event);
            } catch (\Throwable $ignore) {
                // Do not log the Request or exception here: either may contain credentials.
            }
        }
    }
}
