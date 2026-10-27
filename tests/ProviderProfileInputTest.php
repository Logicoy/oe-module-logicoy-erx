<?php

/**
 * Validation rules for the eRx fields on the OpenEMR user screens. No
 * database, no PHI — the SSN fragments here are made up.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Config\ProviderProfileInput;
use PHPUnit\Framework\TestCase;

class ProviderProfileInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function post(array $fields): array
    {
        $post = ['id' => '7', 'fname' => 'Ada'];
        foreach ($fields as $key => $value) {
            $post[ProviderProfileInput::FIELD_PREFIX . $key] = $value;
        }

        return $post;
    }

    public function testAcceptsACompleteEpcsProfile(): void
    {
        $input = new ProviderProfileInput(self::post([
            'dob' => '1974-03-11',
            'gender' => 'f',
            'prescribing_type' => 'EPCS',
            'dea_expiration_date' => '2026-09-30',
            'dea_state' => 'md',
            'ssn_last4' => '9059',
        ]));

        $this->assertTrue($input->isValid(), implode('; ', $input->getErrors()));
        $this->assertSame([
            'dob' => '1974-03-11',
            'gender' => 'F',
            'prescribing_type' => 'EPCS',
            'dea_expiration_date' => '2026-09-30',
            // Stored uppercase so it matches OpenEMR's state list everywhere else.
            'dea_state' => 'MD',
            'ssn_last4' => '9059',
        ], $input->toArray());
    }

    public function testAnEmptyProfileIsValidAndDefaultsToPlainErx(): void
    {
        $input = new ProviderProfileInput(self::post([
            'dob' => '',
            'prescribing_type' => '',
            'dea_expiration_date' => '',
            'dea_state' => '',
            'ssn_last4' => '',
        ]));

        $this->assertTrue($input->isValid());
        $this->assertSame(ProviderProfileInput::TYPE_ERX, $input->toArray()['prescribing_type']);
        $this->assertNull($input->toArray()['dob']);
        // null, not '': nothing typed must not wipe a stored fragment.
        $this->assertNull($input->toArray()['ssn_last4']);
    }

    public function testABadFieldIsDroppedWithoutTakingTheRestDown(): void
    {
        $input = new ProviderProfileInput(self::post([
            'dob' => '11/03/1974',
            'prescribing_type' => 'EPCS',
            'dea_expiration_date' => '2026-09-30',
            'dea_state' => 'Maryland',
            'ssn_last4' => '123456789',
        ]));

        $this->assertFalse($input->isValid());
        $this->assertCount(3, $input->getErrors());

        $normalized = $input->toArray();
        $this->assertNull($normalized['dob']);
        $this->assertNull($normalized['dea_state']);
        $this->assertNull($normalized['ssn_last4']);
        $this->assertSame('2026-09-30', $normalized['dea_expiration_date']);
        $this->assertSame('EPCS', $normalized['prescribing_type']);
    }

    public function testErrorsNeverCarryTheSubmittedValue(): void
    {
        $input = new ProviderProfileInput(self::post(['ssn_last4' => '123456789']));

        $this->assertFalse($input->isValid());
        foreach ($input->getErrors() as $error) {
            $this->assertStringNotContainsString('123456789', $error);
        }
    }

    public function testRejectsADateOfBirthInTheFuture(): void
    {
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $input = new ProviderProfileInput(self::post(['dob' => $tomorrow]));

        $this->assertFalse($input->isValid());
        $this->assertNull($input->toArray()['dob']);
    }

    public function testAnExpiredDeaDateIsStillStored(): void
    {
        // The backend decides what an expired registration means, not us.
        $input = new ProviderProfileInput(self::post(['dea_expiration_date' => '2019-01-31']));

        $this->assertTrue($input->isValid());
        $this->assertSame('2019-01-31', $input->toArray()['dea_expiration_date']);
    }

    public function testRejectsAnUnknownPrescribingTypeAndFallsBackToErx(): void
    {
        $input = new ProviderProfileInput(self::post(['prescribing_type' => 'CONTROLLED']));

        $this->assertFalse($input->isValid());
        $this->assertSame(ProviderProfileInput::TYPE_ERX, $input->toArray()['prescribing_type']);
    }

    public function testTheClearCheckboxBlanksTheStoredSsn(): void
    {
        $input = new ProviderProfileInput(self::post(['ssn_last4' => '', 'ssn_last4_clear' => '1']));

        $this->assertTrue($input->isValid());
        // '' is the explicit clear; null would mean "leave it alone".
        $this->assertSame('', $input->toArray()['ssn_last4']);
    }

    /**
     * The backend refuses MALE / FEMALE / U / X with "Invalid gender type";
     * only M, F and O are accepted.
     *
     * @dataProvider genderProvider
     */
    public function testOnlyTheThreeGenderCodesTheBackendTakesAreAccepted(
        string $submitted,
        ?string $expected
    ): void {
        $input = new ProviderProfileInput(self::post(['gender' => $submitted]));

        $this->assertSame($expected, $input->toArray()['gender']);
        $this->assertSame($expected === null && $submitted !== '', !$input->isValid());
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function genderProvider(): array
    {
        return [
            'male' => ['M', 'M'],
            'female' => ['F', 'F'],
            'other' => ['O', 'O'],
            'lowercase is normalized' => ['f', 'F'],
            'blank is allowed' => ['', null],
            'spelled out is refused' => ['FEMALE', null],
            'unknown is refused' => ['U', null],
        ];
    }

    public function testAPostWithoutOurFieldsIsRecognisedAsAbsent(): void
    {
        // A user form rendered before this version must not blank a profile.
        $this->assertTrue(ProviderProfileInput::isAbsent(['id' => '7', 'fname' => 'Ada']));
        $this->assertFalse(ProviderProfileInput::isAbsent(self::post(['dob' => ''])));
    }
}
