<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ToirCompanyAccessNotification;
use Tests\TestCase;

final class ToirMailLinkTest extends TestCase
{
    public function test_access_notification_uses_the_configured_frontend_path(): void
    {
        config()->set('toir.frontend_url', 'https://platform.itpmining.kz/mro');

        $message = (new ToirCompanyAccessNotification('ITP Mining'))
            ->toMail(new User);

        $this->assertSame('https://platform.itpmining.kz/mro/login', $message->actionUrl);
        $this->assertSame('ITP ТОиР: доступ к компании', $message->subject);
    }

    public function test_frontend_url_with_trailing_slash_is_normalized(): void
    {
        config()->set('toir.frontend_url', 'https://platform.itpmining.kz/mro/');

        $message = (new ToirCompanyAccessNotification('ITP Mining'))
            ->toMail(new User);

        $this->assertSame('https://platform.itpmining.kz/mro/login', $message->actionUrl);
    }
}
