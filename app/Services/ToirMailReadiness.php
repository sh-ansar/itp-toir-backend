<?php

namespace App\Services;

/**
 * Fail-closed configuration preflight. It cannot guarantee delivery;
 * use toir:mail-check --send-to=... for a real SMTP transport test.
 */
final class ToirMailReadiness
{
    public function configured(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        return self::hasUsableSmtpConfiguration(
            (string) config('mail.default'),
            (array) config('mail.mailers.smtp', []),
            (string) config('mail.from.address', '')
        );
    }

    public static function hasUsableSmtpConfiguration(string $mailer, array $smtp, string $sender): bool
    {
        if ($mailer !== 'smtp' || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Laravel's example sender must never enable production invitations.
        if (preg_match('/@(example\.(com|test|org|invalid)|localhost)$/i', $sender)) {
            return false;
        }

        $url = trim((string) ($smtp['url'] ?? ''));
        if ($url !== '') {
            $parts = parse_url($url);
            return is_array($parts)
                && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['smtp', 'smtps'], true)
                && !empty($parts['host']);
        }

        $host = trim((string) ($smtp['host'] ?? ''));
        $port = (int) ($smtp['port'] ?? 0);
        $username = trim((string) ($smtp['username'] ?? ''));
        $password = (string) ($smtp['password'] ?? '');

        return $host !== ''
            && $port >= 1 && $port <= 65535
            && !($host === '127.0.0.1' && $port === 2525)
            && ($username === '' || $password !== '');
    }
}
