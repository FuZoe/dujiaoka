<?php

namespace App\Service;

use Illuminate\Mail\MailServiceProvider;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a message with the SMTP settings saved by the administrator.
 *
 * Queue workers are long-lived, so the mail manager and its transports must
 * be rebuilt for every send. Keeping this in one service makes all queued mail
 * jobs use the same runtime settings.
 */
class ConfiguredMailSender
{
    public function send(string $to, string $title, string $content): void
    {
        // A queue worker can stay alive for days. Always read the shared
        // settings snapshot again so an admin SMTP change applies to the next
        // message without requiring a worker restart.
        $sysConfig = SystemSettingStore::refresh();
        $defaultFrom = config('mail.from', []);
        $mailConfig = [
            'driver' => $this->valueOrDefault($sysConfig, 'driver', config('mail.driver', 'smtp')),
            'host' => $this->valueOrDefault($sysConfig, 'host', config('mail.host', '')),
            'port' => $this->valueOrDefault($sysConfig, 'port', config('mail.port', 465)),
            'username' => $this->valueOrDefault($sysConfig, 'username', config('mail.username', '')),
            'from' => [
                'address' => $this->valueOrDefault($sysConfig, 'from_address', $defaultFrom['address'] ?? ''),
                'name' => $this->valueOrDefault($sysConfig, 'from_name', $defaultFrom['name'] ?? '独角发卡'),
            ],
            'password' => $this->valueOrDefault($sysConfig, 'password', config('mail.password', '')),
            'encryption' => $this->valueOrDefault($sysConfig, 'encryption', config('mail.encryption', '')),
        ];

        config([
            'mail' => array_merge(config('mail'), $mailConfig),
        ]);

        // Mail services are singletons. Drop the resolved instances so a
        // worker observes settings changed in the admin panel.
        foreach (['mailer', 'swift.mailer', 'swift.transport'] as $service) {
            app()->forgetInstance($service);
        }
        Mail::clearResolvedInstance('mailer');
        (new MailServiceProvider(app()))->register();

        Mail::send(['html' => 'email.mail'], ['body' => $content], function ($message) use ($to, $title) {
            $message->to($to)->subject($title);
        });
    }

    private function valueOrDefault(array $settings, string $key, $default)
    {
        if (array_key_exists($key, $settings) && $settings[$key] !== null && $settings[$key] !== '') {
            return $settings[$key];
        }

        return $default;
    }
}
