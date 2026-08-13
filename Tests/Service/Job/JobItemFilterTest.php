<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job;

use MauticPlugin\WittyBundle\Service\Job\JobItemFilter;
use PHPUnit\Framework\TestCase;

/**
 * Regles de filtrage declaratives (cf. sa docblock : une poignee d operateurs
 * fixes plutot que du code arbitraire fourni par l agent). Ce test couvre
 * surtout les cas ou une erreur cote appelant (operateur inconnu, regex
 * invalide) doit echouer FERME (ligne ecartee) plutot que de planter le job
 * ou de tout laisser passer par defaut.
 */
class JobItemFilterTest extends TestCase
{
    public function testResolvePathDescendsNestedArrays(): void
    {
        $this->assertSame('y@example.test', JobItemFilter::resolvePath(['useremail' => ['email' => 'y@example.test']], 'useremail.email'));
    }

    public function testResolvePathReturnsNullOnMissingSegmentWithoutThrowing(): void
    {
        $this->assertNull(JobItemFilter::resolvePath(['a' => 1], 'a.b'));
        $this->assertNull(JobItemFilter::resolvePath(['a' => 1], 'b'));
    }

    public function testNoFiltersKeepsEverything(): void
    {
        $this->assertTrue(JobItemFilter::matchesAll(['x' => 1], []));
    }

    public function testFieldEqualsUsesLooseComparisonForJsonTypes(): void
    {
        $this->assertTrue(JobItemFilter::matchesAll(['has_email' => true], [['op' => 'field_equals', 'path' => 'has_email', 'value' => true]]));
        $this->assertFalse(JobItemFilter::matchesAll(['has_email' => false], [['op' => 'field_equals', 'path' => 'has_email', 'value' => true]]));
    }

    public function testFieldMatchesAppliesARegexPattern(): void
    {
        $filters = [['op' => 'field_matches', 'path' => 'email', 'pattern' => '/^.+@.+\..+$/']];
        $this->assertTrue(JobItemFilter::matchesAll(['email' => 'a@b.com'], $filters));
        $this->assertFalse(JobItemFilter::matchesAll(['email' => 'not-an-email'], $filters));
    }

    public function testInvalidRegexPatternFailsClosedRatherThanThrowing(): void
    {
        $filters = [['op' => 'field_matches', 'path' => 'email', 'pattern' => '(((invalid']];
        $this->assertFalse(JobItemFilter::matchesAll(['email' => 'x'], $filters));
    }

    public function testUnknownOperatorFailsClosed(): void
    {
        $this->assertFalse(JobItemFilter::matchesAll(['x' => 1], [['op' => 'bogus_op', 'path' => 'x']]));
    }

    public function testMultipleFiltersAreCombinedWithAnd(): void
    {
        $data    = ['has_email' => true, 'title' => 'CEO'];
        $filters = [
            ['op' => 'field_equals', 'path' => 'has_email', 'value' => true],
            ['op' => 'field_equals', 'path' => 'title', 'value' => 'CFO'],
        ];

        $this->assertFalse(JobItemFilter::matchesAll($data, $filters), 'Un seul filtre qui echoue doit ecarter la ligne.');
    }
}
