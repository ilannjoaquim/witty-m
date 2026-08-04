<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\PlugNmeet;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\WittyBundle\Service\PlugNmeet\InvitationLinkSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Le token d'invitation est le seul rempart entre "n'importe qui devine une
 * URL" et "rejoint une reunion sous l'identite d'un autre contact" : sa
 * resistance a la falsification et le respect de l'expiration sont donc les
 * deux proprietes qui comptent vraiment ici, pas juste le cas nominal.
 */
class InvitationLinkSignerTest extends TestCase
{
    public function testSignThenVerifyRoundTrips(): void
    {
        $signer = $this->signer();

        $result = $signer->sign(42, 'team-standup');
        $claims = $signer->verify($result['token']);

        $this->assertSame(['lead_id' => 42, 'room_id' => 'team-standup'], $claims);
        $this->assertStringContainsString($result['token'], $result['url']);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $signer = $this->signer();
        $token  = $signer->sign(42, 'team-standup')['token'];

        [$body, $signature] = explode('.', $token, 2);
        $decoded              = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $decoded['lead_id']   = 999;
        $tamperedBody         = rtrim(strtr(base64_encode(json_encode($decoded)), '+/', '-_'), '=');

        $this->assertNull($signer->verify($tamperedBody.'.'.$signature));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $signer = $this->signer();
        $token  = $signer->sign(42, 'team-standup')['token'];

        [$body] = explode('.', $token, 2);

        $this->assertNull($signer->verify($body.'.0000000000000000000000000000000000000000000000000000000000000000'));
    }

    public function testMalformedTokenIsRejected(): void
    {
        $signer = $this->signer();

        $this->assertNull($signer->verify('not-a-valid-token'));
        $this->assertNull($signer->verify(''));
    }

    public function testExpiredTokenIsRejected(): void
    {
        // sign() impose un TTL minimum d'un jour (max(1, $ttlDays)) : on ne
        // peut pas produire un token deja expire via l'API publique, donc on
        // en fabrique un a la main avec la meme secle.
        $secret = 'test-secret-key';
        $signer = $this->signer($secret);

        $payload   = ['lead_id' => 42, 'room_id' => 'team-standup', 'exp' => time() - 10];
        $body      = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $body, $secret);

        $this->assertNull($signer->verify($body.'.'.$signature));
    }

    public function testDifferentSecretsProduceUnverifiableTokens(): void
    {
        $signerA = $this->signer('secret-a');
        $signerB = $this->signer('secret-b');

        $token = $signerA->sign(42, 'team-standup')['token'];

        $this->assertNull($signerB->verify($token));
    }

    private function signer(string $secret = 'test-secret-key'): InvitationLinkSigner
    {
        $coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $coreParametersHelper->method('get')->with('secret_key')->willReturn($secret);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name, array $params, int $referenceType): string => 'https://example.test/meet/join/'.$params['token'],
        );

        return new InvitationLinkSigner($coreParametersHelper, $router);
    }
}
