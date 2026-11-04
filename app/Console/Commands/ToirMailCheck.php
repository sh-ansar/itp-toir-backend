<?php

namespace App\Console\Commands;

use App\Services\ToirMailReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Never prints MAIL_PASSWORD, MAIL_URL or SMTP credentials. */
final class ToirMailCheck extends Command
{
    protected $signature = 'toir:mail-check {--send-to= : Send one actual test message to this address}';

    protected $description = 'Verify SMTP configuration, optionally send one non-queued test email';

    public function handle(ToirMailReadiness $readiness): int
    {
        if (! $readiness->configured()) {
            $this->error('TOIR_SMTP_NOT_READY: check MAIL_MAILER, MAIL_HOST, MAIL_PORT and MAIL_FROM_ADDRESS.');

            return self::FAILURE;
        }

        $this->info('TOIR_SMTP_CONFIGURATION_READY');
        $recipient = $this->option('send-to');
        if ($recipient === null || $recipient === '') {
            return self::SUCCESS;
        }
        if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error('TOIR_SMTP_TEST_RECIPIENT_INVALID');

            return self::FAILURE;
        }

        try {
            Mail::raw(
                'Проверка SMTP ITP ТОиР. Если вы получили это письмо, почтовый маршрут работает.',
                static function ($message) use ($recipient): void {
                    $message->to($recipient)->subject('ITP ТОиР — проверка SMTP');
                }
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('TOIR_SMTP_TRANSPORT_FAILED: inspect private Laravel logs.');

            return self::FAILURE;
        }

        // Transport acceptance does not guarantee delivery to the recipient's inbox.
        $this->info('TOIR_SMTP_TRANSPORT_ACCEPTED');

        return self::SUCCESS;
    }
}
