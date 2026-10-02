<?php

namespace App\Support\Chat;

/**
 * A signed, short-lived token that lets the browser poll without a session.
 *
 * A session-backed request reads and rewrites the session on every hit — one
 * database read and one write per poll, from every open tab, all day. The poll
 * endpoint instead accepts this token: "user 42, valid until T", HMAC-signed
 * with the app key. Verifying it costs a hash, nothing else. The page hands one
 * out (and a fresh one from /chat/token when it expires).
 */
final class PollToken
{
    public const TTL = 12 * 3600;

    public static function issue(int $userId): string
    {
        $expires = now()->getTimestamp() + self::TTL;
        $payload = "{$userId}.{$expires}";

        return $payload.'.'.self::sign($payload);
    }

    /** The user id, or null when the token is missing, forged or expired. */
    public static function verify(?string $token): ?int
    {
        if (! $token || substr_count($token, '.') !== 2) {
            return null;
        }

        [$userId, $expires, $signature] = explode('.', $token);

        if (! ctype_digit($userId) || ! ctype_digit($expires) || (int) $expires < now()->getTimestamp()) {
            return null;
        }

        return hash_equals(self::sign("{$userId}.{$expires}"), $signature) ? (int) $userId : null;
    }

    private static function sign(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'chat-poll|'.$payload, (string) config('app.key'), true)), '+/', '-_'), '=');
    }
}
