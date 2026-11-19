<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** PIN 6 digit untuk memverifikasi email saat daftar dengan email & password. */
class RegistrationPin extends Notification
{
    public function __construct(public string $pin, public int $validMinutes) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kode Verifikasi Pabalu: '.$this->pin)
            ->greeting('Halo!')
            ->line('Gunakan kode berikut untuk memverifikasi email dan menyelesaikan pendaftaran akun Pabalu:')
            ->line('# '.$this->pin)
            ->line("Kode berlaku selama {$this->validMinutes} menit. Jangan berikan kode ini kepada siapa pun, termasuk yang mengaku dari Pabalu.")
            ->line('Bila kamu tidak merasa mendaftar, abaikan email ini.');
    }
}
