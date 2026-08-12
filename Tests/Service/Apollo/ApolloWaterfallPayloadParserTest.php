<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Apollo;

use MauticPlugin\WittyBundle\Service\Apollo\ApolloWaterfallPayloadParser;
use PHPUnit\Framework\TestCase;

/**
 * Le webhook waterfall d'Apollo peut arriver avec `status` absent d'un envoi
 * malforme, ou avec des emails/telephones "trouves" reduits a un tableau
 * vide (recherche menee, rien trouve) : isSuccess()/extract() doivent
 * distinguer ces cas sans jamais supposer un succes par defaut ni planter
 * sur une charge utile partielle.
 */
class ApolloWaterfallPayloadParserTest extends TestCase
{
    public function testIsSuccessRequiresTheExactStatusValue(): void
    {
        $this->assertTrue(ApolloWaterfallPayloadParser::isSuccess(['status' => 'success']));
        $this->assertFalse(ApolloWaterfallPayloadParser::isSuccess(['status' => 'failed']));
        $this->assertFalse(ApolloWaterfallPayloadParser::isSuccess([]), 'status absent ne doit jamais etre suppose reussi.');
    }

    public function testExtractPrefersSanitizedNumberOverRawNumber(): void
    {
        $result = ApolloWaterfallPayloadParser::extract([
            'people' => [[
                'emails'        => [['email' => 'jane@example.com']],
                'phone_numbers' => [['raw_number' => '+1 555 0123', 'sanitized_number' => '+15550123']],
            ]],
        ]);

        $this->assertSame('jane@example.com', $result['email']);
        $this->assertSame('+15550123', $result['phone']);
    }

    public function testExtractFallsBackToRawNumberWhenSanitizedIsMissing(): void
    {
        $result = ApolloWaterfallPayloadParser::extract([
            'people' => [['phone_numbers' => [['raw_number' => '+1 555 0123']]]],
        ]);

        $this->assertSame('+1 555 0123', $result['phone']);
    }

    public function testNotFoundYieldsNoEmailOrPhoneKeysRatherThanEmptyStrings(): void
    {
        $result = ApolloWaterfallPayloadParser::extract([
            'people' => [['emails' => [], 'phone_numbers' => []]],
        ]);

        $this->assertArrayNotHasKey('email', $result);
        $this->assertArrayNotHasKey('phone', $result);
    }

    public function testMissingPeopleArrayDoesNotThrow(): void
    {
        $result = ApolloWaterfallPayloadParser::extract(['status' => 'success']);

        $this->assertArrayNotHasKey('email', $result);
        $this->assertArrayNotHasKey('phone', $result);
    }

    public function testCountersAreExtractedIncludingZeroValues(): void
    {
        $result = ApolloWaterfallPayloadParser::extract([
            'email_records_enriched'  => 1,
            'mobile_records_enriched' => 0,
            'credits_consumed'        => 9,
            'people'                  => [[]],
        ]);

        $this->assertSame(1, $result['email_records_enriched']);
        $this->assertSame(9, $result['credits_consumed']);
        // Le filtre de extract() retire seulement les valeurs null (email/phone
        // absents), pas les zeros : un compteur a 0 reste un fait exploitable
        // (ex. mobile_records_enriched=0 confirme qu aucun mobile n a ete
        // trouve), contrairement a l absence pure et simple de la cle.
        $this->assertSame(0, $result['mobile_records_enriched']);
    }
}
