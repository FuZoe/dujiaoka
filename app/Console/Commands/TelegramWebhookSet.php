<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use RuntimeException;

class TelegramWebhookSet extends Command
{
    protected $signature = 'telegram:webhook-set {--url= : Public HTTPS webhook URL}';
    protected $description = 'Register the Telegram customer-binding webhook without exposing credentials';

    public function handle(): int
    {
        $token = trim((string) dujiaoka_config_get('telegram_bot_token'));
        $secret = trim((string) config('services.telegram.webhook_secret'));
        $url = trim((string) ($this->option('url') ?: rtrim(config('app.url'), '/').'/api/telegram/webhook'));

        if ($token === '' || $secret === '' || !preg_match('#^https://#i', $url)) {
            $this->error('Bot token, webhook secret, and a public HTTPS webhook URL are required.');
            return 1;
        }

        $options = [
            'connect_timeout' => (float) config('services.telegram.connect_timeout', 5),
            'timeout' => (float) config('services.telegram.timeout', 15),
        ];
        $proxy = trim((string) config('services.telegram.proxy'));
        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }
        $client = new Client($options);

        try {
            $identity = $this->request($client, $token, 'getMe', []);
            $username = ltrim((string) ($identity['result']['username'] ?? ''), '@');
            if ($username === '') {
                throw new RuntimeException('Telegram bot username is missing.');
            }

            $this->request($client, $token, 'setWebhook', [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['message', 'callback_query'],
                'drop_pending_updates' => false,
            ]);
            $this->request($client, $token, 'setMyCommands', [
                'commands' => [
                    ['command' => 'start', 'description' => '打开 NewZoe 商城'],
                    ['command' => 'products', 'description' => '浏览商品'],
                    ['command' => 'orders', 'description' => '查看我的订单'],
                    ['command' => 'cancel', 'description' => '取消当前流程'],
                ],
            ]);
        } catch (\Throwable $exception) {
            $this->error('Telegram webhook registration failed: '.get_class($exception));
            return 1;
        }

        $this->info('Telegram webhook registered for @'.$username.'.');
        $this->line('Webhook URL: '.$url);
        $this->line('Set TELEGRAM_BOT_USERNAME='.$username.' in the production environment.');
        return 0;
    }

    private function request(Client $client, string $token, string $method, array $json): array
    {
        $response = $client->post('https://api.telegram.org/bot'.$token.'/'.$method, ['json' => $json]);
        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload) || empty($payload['ok'])) {
            throw new RuntimeException('Telegram returned an unsuccessful response.');
        }
        return $payload;
    }
}
