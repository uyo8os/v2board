<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthService
{
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    private static function realIp(Request $request)
    {
        $candidates = [];
    
        // 优先 Cloudflare
        $cf = $request->headers->get('CF-Connecting-IP');
        if ($cf) $candidates[] = $cf;
    
        // 然后 X-Forwarded-For（取链路中的第一个）
        $xff = $request->headers->get('X-Forwarded-For');
        if ($xff) {
            foreach (explode(',', $xff) as $part) {
                $part = trim($part);
                if ($part !== '') $candidates[] = $part;
            }
        }
    
        // 再尝试 X-Real-IP
        $xri = $request->headers->get('X-Real-IP');
        if ($xri) $candidates[] = $xri;
    
        // 兜底
        $remote = $request->server->get('REMOTE_ADDR');
        if ($remote) $candidates[] = $remote;
    
        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return $request->ip();
    }

    public function generateAuthData(Request $request)
    {
        $guid = Helper::guid();
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
        ], config('app.key'), 'HS256');
        if (!self::addSession($this->user->id, $guid, [
            'ip' => $this->realIp($request),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $authData,
            'auth_version' => self::credentialVersion($this->user),
        ], self::credentialVersion($this->user))) {
            abort(503, '会话保存失败，请重试');
        }
        return [
            'token' => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        try {
            if (!is_string($jwt) || $jwt === '') {
                return false;
            }
            // A cached value must never replace signature, session or role checks.
            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            $id = $data['id'] ?? null;
            $userId = (is_int($id) || (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id)))
                ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            $session = $data['session'] ?? null;
            if ($userId === false || !is_string($session) || $session === '') return false;
            $sessions = self::getSessionMap($userId);
            $meta = $sessions[$session] ?? null;
            if (!is_array($meta)) return false;

            // Read the primary so a replica or the old one-hour cache cannot retain revoked roles.
            $fields = ['id', 'email', 'is_admin', 'is_staff'];
            $user = User::select(array_merge($fields, ['banned', 'auth_version']))->useWritePdo()->find($userId);
            if (!$user || (int)$user->banned !== 0) return false;
            // Unversioned sessions predate A02 and are valid only until the first revocation.
            if (($meta['auth_version'] ?? '') !== self::credentialVersion($user)) return false;
            return $user->only($fields);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function credentialVersion(User $user): string
    {
        return (string)($user->auth_version ?? '');
    }

    private static function currentUser($userId, string $version): User
    {
        $user = User::useWritePdo()->find($userId);
        if (!$user || (int)$user->banned !== 0 || self::credentialVersion($user) !== $version) {
            abort(403, '未登录或登陆已过期');
        }
        return $user;
    }

    private static function addSession($userId, $guid, $meta, string $version)
    {
        return self::withSessionLock($userId, static function () use ($userId, $guid, $meta, $version) {
            // Never upgrade a password/code verified before a reset to the new credential version.
            self::currentUser($userId, $version);
            $sessions = self::getSessionMap($userId);
            $sessions[$guid] = $meta;
            return Cache::forever(CacheKey::get('USER_SESSIONS', $userId), $sessions);
        });
    }

    public function generateQuickLoginCode(?string $authorization = null): string
    {
        $code = Helper::guid();
        $saved = self::withSessionLock($this->user->id, function () use ($code, $authorization) {
            $version = self::credentialVersion($this->user);
            self::currentUser($this->user->id, $version);
            if ($authorization !== null) {
                $authenticated = self::decryptAuthData($authorization);
                if (!$authenticated || (int)$authenticated['id'] !== (int)$this->user->id) {
                    abort(403, '未登录或登陆已过期');
                }
            }
            return Cache::put(CacheKey::get('TEMP_TOKEN', $code), [
                'user_id' => (int)$this->user->id,
                'auth_version' => $version,
            ], 60);
        });
        if (!$saved) abort(503, '快捷登录码保存失败，请重试');
        return $code;
    }

    public static function loginWithQuickLoginCode($code, Request $request): array
    {
        if (!is_string($code) || !preg_match('/^[a-f0-9]{32}$/D', $code)) {
            abort(500, __('Token error'));
        }
        $key = CacheKey::get('TEMP_TOKEN', $code);
        try {
            // Cache::pull is get+forget on several drivers, so serialize the entire exchange.
            return Cache::lock($key . ':lock', 10)->block(5, static function () use ($key, $request) {
                $data = Cache::get($key);
                if (!is_array($data) || !is_int($data['user_id'] ?? null) || $data['user_id'] <= 0
                    || !is_string($data['auth_version'] ?? null)) {
                    abort(500, __('Token error'));
                }
                $user = User::useWritePdo()->find($data['user_id']);
                if (!$user || (int)$user->banned !== 0 || self::credentialVersion($user) !== $data['auth_version']) {
                    abort(500, __('Token error'));
                }
                if (!Cache::forget($key)) abort(503, '快捷登录码失效失败，请重试');
                $user->last_login_at = time();
                if (!$user->save()) abort(503, '登录失败，请重试');
                return (new self($user))->generateAuthData($request);
            });
        } catch (LockTimeoutException $e) {
            abort(503, '快捷登录繁忙，请重试');
        }
    }

    private static function getSessionMap($userId): array
    {
        $sessions = Cache::get(CacheKey::get('USER_SESSIONS', $userId), []);
        return is_array($sessions) ? $sessions : [];
    }

    private static function withSessionLock($userId, callable $callback): bool
    {
        try {
            // Serialize all map mutations so a concurrent login cannot restore a revoked session.
            return Cache::lock(CacheKey::get('USER_SESSIONS', $userId) . ':lock', 10)->block(5, $callback);
        } catch (LockTimeoutException $e) {
            return false;
        }
    }

    private static function forgetLegacyAuthorization(array $sessions): void
    {
        foreach ($sessions as $meta) {
            if (is_array($meta) && is_string($meta['auth_data'] ?? null) && $meta['auth_data'] !== '') {
                Cache::forget($meta['auth_data']);
            }
        }
    }

    public function getSessions()
    {
        return self::getSessionMap($this->user->id);
    }

    public function removeSession($sessionId)
    {
        if (!is_string($sessionId) || $sessionId === '') return false;
        $removedSessions = [];
        $saved = self::withSessionLock($this->user->id, function () use ($sessionId, &$removedSessions) {
            $sessions = $this->getSessions();
            $removedSessions = array_intersect_key($sessions, [$sessionId => true]);
            unset($sessions[$sessionId]);
            return Cache::forever(CacheKey::get('USER_SESSIONS', $this->user->id), $sessions);
        });
        if (!$saved) return false;
        self::forgetLegacyAuthorization($removedSessions);
        return true;
    }

    public function removeAllSession()
    {
        $removedSessions = [];
        $removed = self::withSessionLock($this->user->id, function () use (&$removedSessions) {
            $removedSessions = $this->getSessions();
            return Cache::forget(CacheKey::get('USER_SESSIONS', $this->user->id));
        });
        if (!$removed) return false;
        self::forgetLegacyAuthorization($removedSessions);
        return true;
    }
}
