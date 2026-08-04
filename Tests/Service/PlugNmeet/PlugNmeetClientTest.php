<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\PlugNmeet;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * L'API plugNmeet s'authentifie par HMAC du corps JSON (jamais par cle seule) :
 * une signature mal calculee echoue silencieusement cote serveur plugNmeet (401
 * generique), donc c'est le point le plus important a verifier ici, avec la
 * fusion profonde des reglages de verrouillage par defaut.
 */
class PlugNmeetClientTest extends TestCase
{
    private const SERVER_URL = 'https://meet.example.test';
    private const API_KEY    = 'key-123';
    private const API_SECRET = 'super-secret';

    public function testCreateRoomSignsRequestWithHmacOfBody(): void
    {
        $capturedBody    = null;
        $capturedHeaders = null;
        $capturedUrl     = null;

        $client = $this->client(function (string $method, string $url, array $options) use (&$capturedBody, &$capturedHeaders, &$capturedUrl): ResponseInterface {
            $capturedUrl     = $url;
            $capturedBody    = $options['body'];
            $capturedHeaders = $options['headers'];

            return new MockResponse(json_encode(['status' => true], JSON_THROW_ON_ERROR));
        });

        $client->createRoom('room01', ['title' => 'Team meeting']);

        $this->assertSame(self::SERVER_URL.'/auth/room/create', $capturedUrl);
        $this->assertSame(self::API_KEY, $this->headerValue($capturedHeaders, 'API-KEY'));

        $expectedSignature = hash_hmac('sha256', (string) $capturedBody, self::API_SECRET);
        $this->assertSame($expectedSignature, $this->headerValue($capturedHeaders, 'HASH-SIGNATURE'), 'La signature doit etre le HMAC-SHA256 exact du corps envoye.');

        $body = json_decode((string) $capturedBody, true);
        $this->assertSame('room01', $body['room_id']);
        $this->assertSame('Team meeting', $body['metadata']['room_title']);
    }

    public function testCreateRoomWithListenersLockedOnlyLocksMicAndWebcam(): void
    {
        $capturedBody = null;
        $client = $this->client(function (string $method, string $url, array $options) use (&$capturedBody): ResponseInterface {
            $capturedBody = $options['body'];

            return new MockResponse(json_encode(['status' => true], JSON_THROW_ON_ERROR));
        });

        $client->createRoom('room01', ['listeners_locked' => true]);

        $lock = json_decode((string) $capturedBody, true)['metadata']['default_lock_settings'];

        $this->assertTrue($lock['lock_microphone']);
        $this->assertTrue($lock['lock_webcam']);
        // Les autres verrouillages par defaut ne doivent pas etre touches.
        $this->assertTrue($lock['lock_screen_sharing']);
        $this->assertFalse($lock['lock_chat']);
    }

    public function testBuildJoinUrlAppendsAccessToken(): void
    {
        $client = $this->client(function () {
            throw new \RuntimeException('No HTTP call expected.');
        });

        $this->assertSame(self::SERVER_URL.'?access_token=abc123', $client->buildJoinUrl('abc123'));
    }

    public function testGetRecordingDownloadUrlUsesToken(): void
    {
        $client = $this->client(function (string $method, string $url) {
            $this->assertSame(self::SERVER_URL.'/auth/recording/getDownloadToken', $url);

            return new MockResponse(json_encode(['status' => true, 'token' => 'dl-token-xyz'], JSON_THROW_ON_ERROR));
        });

        $this->assertSame(
            self::SERVER_URL.'/download/recording/dl-token-xyz',
            $client->getRecordingDownloadUrl('RM_abc'),
        );
    }

    public function testApiErrorResponseThrowsPlugNmeetException(): void
    {
        $client = $this->client(function () {
            return new MockResponse(json_encode(['status' => false, 'msg' => 'Invalid API key'], JSON_THROW_ON_ERROR));
        });

        $this->expectException(PlugNmeetException::class);
        $this->expectExceptionMessage('Invalid API key');

        $client->isRoomActive('room01');
    }

    /**
     * MockHttpClient normalise les en-tetes en liste de chaines "Nom: valeur",
     * pas en tableau associatif.
     *
     * @param array<int, string> $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, $name.':')) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    private function client(callable $responseFactory): PlugNmeetClient
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('getPlugNmeetServerUrl')->willReturn(self::SERVER_URL);
        $config->method('getPlugNmeetApiKey')->willReturn(self::API_KEY);
        $config->method('getPlugNmeetApiSecret')->willReturn(self::API_SECRET);

        return new PlugNmeetClient($config, new MockHttpClient($responseFactory));
    }
}
