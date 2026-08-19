<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job;

use MauticPlugin\WittyBundle\Service\Job\SpreadsheetRowMapper;
use PHPUnit\Framework\TestCase;

class SpreadsheetRowMapperTest extends TestCase
{
    public function testMapsColumnsAccordingToTheHeaderIndex(): void
    {
        $headerIndex = ['Email' => 0, 'First Name' => 1, 'Title' => 2];
        $mapping     = ['Email' => 'email', 'First Name' => 'firstname', 'Title' => 'position'];
        $row         = ['jane@acme.com', 'Jane', 'Sales Director'];

        $fields = SpreadsheetRowMapper::mapRow($row, $headerIndex, $mapping);

        $this->assertSame(['email' => 'jane@acme.com', 'firstname' => 'Jane', 'position' => 'Sales Director'], $fields);
    }

    public function testMissingEmailReturnsNull(): void
    {
        $headerIndex = ['Email' => 0, 'First Name' => 1];
        $mapping     = ['Email' => 'email', 'First Name' => 'firstname'];

        $this->assertNull(SpreadsheetRowMapper::mapRow(['', 'Jane'], $headerIndex, $mapping));
    }

    public function testEmailWithoutAtSignReturnsNull(): void
    {
        $headerIndex = ['Email' => 0];
        $mapping     = ['Email' => 'email'];

        $this->assertNull(SpreadsheetRowMapper::mapRow(['not-an-email'], $headerIndex, $mapping));
    }

    public function testEmailIsTrimmed(): void
    {
        $headerIndex = ['Email' => 0];
        $mapping     = ['Email' => 'email'];

        $fields = SpreadsheetRowMapper::mapRow(['  jane@acme.com  '], $headerIndex, $mapping);

        $this->assertSame('jane@acme.com', $fields['email']);
    }

    public function testMappedColumnAbsentFromTheHeaderBecomesAnEmptyString(): void
    {
        $headerIndex = ['Email' => 0];
        $mapping     = ['Email' => 'email', 'Ghost Column' => 'phone'];

        $fields = SpreadsheetRowMapper::mapRow(['jane@acme.com'], $headerIndex, $mapping);

        $this->assertSame('', $fields['phone']);
    }
}
