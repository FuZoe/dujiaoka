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
}
