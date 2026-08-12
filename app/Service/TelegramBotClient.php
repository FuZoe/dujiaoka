<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;

class TelegramBotClient
{
    /**
     * @var ClientInterface
     */
    private $client;

    public function __construct(ClientInterface $client = null)
    {
        $this->client = $client ?: new Client([
            'timeout' => 30,
            'proxy' => '',
        ]);
    }

    public function sendMessage(string $token, string $chatId, string $message): int
    {
        $response = $this->client->post(
            'https://api.telegram.org/bot'.$token.'/sendMessage',
            [
                'form_params' => [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'disable_web_page_preview' => true,
                ],
            ]
        );
        $payload = json_decode((string) $response->getBody(), true);

        if (empty($payload['ok']) || empty($payload['result']['message_id'])) {
            throw new RuntimeException('Telegram returned an invalid response.');
        }

        return (int) $payload['result']['message_id'];
    }
}
