<?php

namespace Tests\Unit;

use App\Service\TelegramBotClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TelegramBotClientTest extends TestCase
{
    public function test_it_uses_the_configured_proxy_and_bounded_timeouts(): void
    {
        config([
            'services.telegram.proxy' => 'http://PROXY:8080',
            'services.telegram.connect_timeout' => 4,
            'services.telegram.timeout' => 12,
        ]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('post')
            ->once()
            ->with(
                'https://api.telegram.org/botTOKEN/sendMessage',
                Mockery::on(function (array $options) {
                    return $options['proxy'] === 'http://PROXY:8080'
                        && $options['connect_timeout'] === 4.0
                        && $options['timeout'] === 12.0
                        && !isset($options['form_params'])
                        && $options['json']['chat_id'] === '@channel_username'
                        && $options['json']['text'] === 'message';
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'ok' => true,
                'result' => ['message_id' => 123],
            ])));

        $messageId = (new TelegramBotClient($http))->sendMessage('TOKEN', '@channel_username', 'message');

        $this->assertSame(123, $messageId);
    }

    public function test_it_preserves_a_private_channel_id_as_a_json_string(): void
    {
        config(['services.telegram.proxy' => null]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('post')
            ->once()
            ->withArgs(function (string $url, array $options) {
                return $url === 'https://api.telegram.org/botTOKEN/sendMessage'
                    && $options['json']['chat_id'] === '-1001234567890123';
            })
            ->andReturn(new Response(200, [], json_encode([
                'ok' => true,
                'result' => ['message_id' => 124],
            ])));

        $messageId = (new TelegramBotClient($http))->sendMessage(
            'TOKEN',
            ' -1001234567890123 ',
            'message'
        );

        $this->assertSame(124, $messageId);
    }

    public function test_it_redacts_the_token_from_http_failures(): void
    {
        config(['services.telegram.proxy' => null]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('post')->once()->andThrow(new RuntimeException(
            'Request failed at https://api.telegram.org/botTOKEN/sendMessage'
        ));

        try {
            (new TelegramBotClient($http))->sendMessage('TOKEN', '123456', 'message');
            $this->fail('The request should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Telegram API request failed.', $exception->getMessage());
            $this->assertStringNotContainsString('TOKEN', $exception->getMessage());
        }
    }
}
