<?php

namespace Tests\Unit;

use App\Services\ToirMailReadiness;
use PHPUnit\Framework\TestCase;

final class ToirMailReadinessTest extends TestCase
{
    private function validSmtp(): array
    {
        return [
            'host' => 'mail.itpmining.kz',
            'port' => 587,
            'username' => 'notification-user',
            'password' => 'example-placeholder-used-only-in-test',
            'url' => null,
        ];
    }

    public function test_accepts_explicit_smtp_settings_and_supported_smtp_url(): void
    {
        self::assertTrue(ToirMailReadiness::hasUsableSmtpConfiguration(
            'smtp', $this->validSmtp(), 'notifications@itpmining.kz'
        ));
        self::assertTrue(ToirMailReadiness::hasUsableSmtpConfiguration(
            'smtp', ['url' => 'smtps://user:password@mail.itpmining.kz:465'], 'notifications@itpmining.kz'
        ));
    }

    public function test_rejects_log_failover_defaults_bad_sender_and_partial_credentials(): void
    {
        $smtp = $this->validSmtp();
        foreach (['log', 'array', 'failover', 'sendmail'] as $mailer) {
            self::assertFalse(ToirMailReadiness::hasUsableSmtpConfiguration(
                $mailer, $smtp, 'notifications@itpmining.kz'
            ));
        }
        self::assertFalse(ToirMailReadiness::hasUsableSmtpConfiguration(
            'smtp', $smtp, 'hello@example.com'
        ));
        self::assertFalse(ToirMailReadiness::hasUsableSmtpConfiguration(
            'smtp', ['host' => '127.0.0.1', 'port' => 2525], 'notifications@itpmining.kz'
        ));
        self::assertFalse(ToirMailReadiness::hasUsableSmtpConfiguration(
            'smtp', ['host' => 'smtp.example.org', 'port' => 0], 'notifications@itpmining.kz'
        ));
        self::assertFalse(ToirMailReadiness::hasUsableSmtpConfiguration(
            'smtp', ['host' => 'smtp.example.org', 'port' => 587, 'username' => 'user', 'password' => ''],
            'notifications@itpmining.kz'
        ));
    }
}
