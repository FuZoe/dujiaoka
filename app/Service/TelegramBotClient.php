<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

class TelegramBotClient
{
    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var array
     */
    private $requestOptions;

    public function __construct(ClientInterface $client = null)
    {
        $this->client = $client ?: new Client();
        $this->requestOptions = [
            'connect_timeout' => (float) config('services.telegram.connect_timeout', 5),
            'timeout' => (float) config('services.telegram.timeout', 15),
        ];

        $proxy = trim((string) config('services.telegram.proxy'));
        if ($proxy !== '') {
            $this->requestOptions['proxy'] = $proxy;
        }
    }

    public function sendMessage(string $token, string $chatId, string $message, array $payload = []): int
    {
        $json = array_merge($payload, [
            'chat_id' => trim($chatId),
            'text' => $message,
            'disable_web_page_preview' => true,
        ]);
        try {
            $response = $this->client->post(
                'https://api.telegram.org/bot'.$token.'/sendMessage',
                array_merge($this->requestOptions, [
                    'json' => $json,
                ])
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram API request failed.');
        }
        $payload = json_decode((string) $response->getBody(), true);

        if (empty($payload['ok']) || empty($payload['result']['message_id'])) {
            throw new RuntimeException('Telegram returned an invalid response.');
        }

        return (int) $payload['result']['message_id'];
    }

    /**
     * Send a PNG image with an optional caption and inline keyboard.
     *
     * Telegram requires multipart form data for uploaded photos. Keeping this
     * here instead of making the shop service call Guzzle directly also keeps
     * proxy, timeout, and error handling identical to text messages.
     */
    public function sendPhoto(
        string $token,
        string $chatId,
        string $photo,
        string $caption = '',
        array $payload = []
    ): int {
        $multipart = [
            [
                'name' => 'chat_id',
                'contents' => trim($chatId),
            ],
            [
                'name' => 'photo',
                'contents' => $photo,
                'filename' => 'binance-payment.png',
                'headers' => ['Content-Type' => 'image/png'],
            ],
        ];

        if ($caption !== '') {
            $multipart[] = [
                'name' => 'caption',
                'contents' => $caption,
            ];
        }

        foreach ($payload as $name => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $multipart[] = [
                'name' => (string) $name,
                'contents' => (string) $value,
            ];
        }

        try {
            $response = $this->client->post(
                'https://api.telegram.org/bot'.$token.'/sendPhoto',
                array_merge($this->requestOptions, [
                    'multipart' => $multipart,
                ])
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram API request failed.');
        }
        $responsePayload = json_decode((string) $response->getBody(), true);

        if (empty($responsePayload['ok']) || empty($responsePayload['result']['message_id'])) {
            throw new RuntimeException('Telegram returned an invalid response.');
        }

        return (int) $responsePayload['result']['message_id'];
    }

    public function answerCallbackQuery(
        string $token,
        string $callbackQueryId,
        string $text = '',
        bool $showAlert = false
    ): void {
        $this->request($token, 'answerCallbackQuery', [
            'callback_query_id' => trim($callbackQueryId),
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
    }

    public function editMessageText(
        string $token,
        string $chatId,
        int $messageId,
        string $message,
        array $payload = []
    ): void {
        $this->request($token, 'editMessageText', array_merge($payload, [
            'chat_id' => trim($chatId),
            'message_id' => $messageId,
            'text' => $message,
            'disable_web_page_preview' => true,
        ]));
    }

    private function request(string $token, string $method, array $json): void
    {
        try {
            $response = $this->client->post(
                'https://api.telegram.org/bot'.$token.'/'.$method,
                array_merge($this->requestOptions, ['json' => $json])
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram API request failed.');
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (empty($payload['ok'])) {
            throw new RuntimeException('Telegram returned an invalid response.');
        }
    }
}
