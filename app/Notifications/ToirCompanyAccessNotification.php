<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ToirCompanyAccessNotification extends Notification
{
    public function __construct(public readonly string $companyName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('ITP ТОиР: доступ к компании')
            ->greeting('Доступ предоставлен')
            ->line('Вам предоставлен доступ к компании '.$this->companyName.' в ITP ТОиР.')
            ->line('Войдите со своей существующей учётной записью. Пароль не меняется.')
            ->action('Открыть ITP ТОиР', rtrim((string) config('toir.frontend_url'), '/').'/login');
    }
}
