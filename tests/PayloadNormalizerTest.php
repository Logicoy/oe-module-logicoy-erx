<?php

/**
 * The omit-or-valid rules the LogiCoy backend enforces on outbound fields.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Client\PayloadNormalizer;
use PHPUnit\Framework\TestCase;

class PayloadNormalizerTest extends TestCase
{
    /**
     * The reported failure: a facility with no fax sent "faxNum": "" and the
     * backend rejected the whole registration with
     * "faxNum cannot be empty if present".
     */
    public function testAFacilityWithNoFaxOmitsTheKeyEntirely(): void
    {
        $location = PayloadNormalizer::withoutBlanks([
            'name' => 'OpenEmr Test Fac1',
            'phone' => PayloadNormalizer::phoneNumber('07090409997', 'facility phone number'),
            'email' => 'admin@oemr.com',
            'faxNum' => PayloadNormalizer::phoneNumber('', 'facility fax number'),
        ]);

        $this->assertArrayNotHasKey('faxNum', $location);
        $this->assertSame(
            ['name' => 'OpenEmr Test Fac1', 'phone' => '7090409997', 'email' => 'admin@oemr.com'],
            $location
        );
    }

    public function testBlankAndEmptyValuesAreDroppedButFalsyContentIsKept(): void
    {
        $this->assertSame(
            ['name' => 'Clinic', 'zero' => '0', 'flag' => false],
            PayloadNormalizer::withoutBlanks([
                'name' => 'Clinic',
                'email' => '',
                'faxNum' => null,
                'serviceLevel' => [],
                // '0' is a real value the backend should see, not a blank.
                'zero' => '0',
                'flag' => false,
            ])
        );
    }

    public function testNothingOnFileDropsThePhoneRatherThanSendingItBlank(): void
    {
        $this->assertNull(PayloadNormalizer::phoneNumber('', 'facility fax number'));
        $this->assertNull(PayloadNormalizer::phoneNumber(null, 'facility fax number'));
        $this->assertNull(PayloadNormalizer::phoneNumber('   ', 'facility fax number'));
    }

    /**
     * @dataProvider tenDigitProvider
     */
    public function testNormalizesToTenDigits(string $raw, string $expected): void
    {
        $this->assertSame($expected, PayloadNormalizer::phoneNumber($raw, 'facility phone number'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tenDigitProvider(): array
    {
        return [
            'plain' => ['8185551234', '8185551234'],
            'punctuated' => ['818-555-1234', '8185551234'],
            'spaced with parens' => ['(818) 555 1234', '8185551234'],
            'country code' => ['+1 818 555 1234', '8185551234'],
            // OpenEMR's phone fields are free text; a trunk prefix is common.
            'trunk prefix' => ['07090409997', '7090409997'],
            'all zeroes is still ten digits' => ['000-000-0000', '0000000000'],
        ];
    }

    /**
     * Dropping a number the admin actually typed would resurface as
     * "phone is required", pointing at the wrong problem.
     */
    public function testATooShortNumberIsRaisedRatherThanDropped(): void
    {
        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('facility fax number is not a 10 digit number');

        PayloadNormalizer::phoneNumber('555-1234', 'facility fax number');
    }

    public function testATextOnlyPhoneIsRaisedRatherThanDropped(): void
    {
        $this->expectException(BackendException::class);

        PayloadNormalizer::phoneNumber('ask at front desk', 'facility phone number');
    }
}
