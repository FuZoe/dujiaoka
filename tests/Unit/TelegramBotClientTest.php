<?php

namespace Tests\Unit;

use App\Service\TelegramBotClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
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
                        && $options['form_params']['chat_id'] === 'CHANNEL_ID'
                        && $options['form_params']['text'] === 'message';
                })
            )
            ->andReturn(new Response(200, [], json_encode([
                'ok' => true,
                'result' => ['message_id' => 123],
            ])));

        $messageId = (new TelegramBotClient($http))->sendMessage('TOKEN', 'CHANNEL_ID', 'message');

        $this->assertSame(123, $messageId);
    }
}
