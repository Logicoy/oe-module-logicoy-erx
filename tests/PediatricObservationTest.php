<?php

/**
 * The pediatric height/weight rule: who gets measurements, which reading, and
 * how they are shaped for the eRx patient payload (PatientAddModel fields).
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use DateTimeImmutable;
use LogiCoy\ERx\Prescription\PediatricObservation;
use PHPUnit\Framework\TestCase;

class PediatricObservationTest extends TestCase
{
    private const TODAY = '2026-10-27';

    private function asOf(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }

    public function testUnderNineteenSendsLatestWeightAndHeightAsSpecFields(): void
    {
        $fields = PediatricObservation::build(
            '2015-03-10',
            [
                ['date' => '2026-09-14 10:00:00', 'height' => '52.00000', 'weight' => '72.40000'],
                ['date' => '2025-05-01 09:00:00', 'height' => '45.00000', 'weight' => '47.00000'],
            ],
            $this->asOf()
        );

        $this->assertSame(
            [
                'weightInLb' => 72,
                'weightObservationDate' => '2026-09-14',
                'heightInFeet' => 4,
                'heightInch' => 4,
                'heightObservationDate' => '2026-09-14',
            ],
            $fields
        );
    }

    public function testWeightAndHeightAreIntegers(): void
    {
        $fields = PediatricObservation::build(
            '2015-03-10',
            [['date' => '2026-09-14', 'height' => '52', 'weight' => '72.4']],
            $this->asOf()
        );

        $this->assertIsInt($fields['weightInLb']);
        $this->assertIsInt($fields['heightInFeet']);
        $this->assertIsInt($fields['heightInch']);
    }

    public function testNineteenOrOlderSendsNothing(): void
    {
        $rows = [['date' => '2026-09-14', 'height' => '70', 'weight' => '180']];

        // Birthday already reached this year -> exactly 19.
        $this->assertSame([], PediatricObservation::build('2007-03-10', $rows, $this->asOf()));
        // Comfortably adult.
        $this->assertSame([], PediatricObservation::build('1990-01-01', $rows, $this->asOf()));
    }

    public function testTurnsNineteenTomorrowIsStillPediatricToday(): void
    {
        $fields = PediatricObservation::build(
            '2007-10-28',
            [['date' => '2026-10-01', 'height' => '68', 'weight' => '150']],
            $this->asOf()
        );

        $this->assertSame(150, $fields['weightInLb']);
        $this->assertSame(5, $fields['heightInFeet']);
        $this->assertSame(8, $fields['heightInch']);
    }

    public function testEachMeasurementTakesItsOwnLatestNonZeroReading(): void
    {
        $fields = PediatricObservation::build(
            '2015-03-10',
            [
                // Newest row recorded weight only; height was blank there.
                ['date' => '2026-09-14', 'height' => '0', 'weight' => '53'],
                ['date' => '2026-06-01', 'height' => '49', 'weight' => '50'],
            ],
            $this->asOf()
        );

        $this->assertSame(53, $fields['weightInLb']);
        $this->assertSame('2026-09-14', $fields['weightObservationDate']);
        // Falls back to the most recent row that actually has a height.
        $this->assertSame(4, $fields['heightInFeet']);
        $this->assertSame(1, $fields['heightInch']);
        $this->assertSame('2026-06-01', $fields['heightObservationDate']);
    }

    public function testAMeasurementWithNothingOnFileIsOmittedNotBlank(): void
    {
        $fields = PediatricObservation::build(
            '2015-03-10',
            [['date' => '2026-09-14', 'height' => null, 'weight' => '53']],
            $this->asOf()
        );

        $this->assertArrayHasKey('weightInLb', $fields);
        $this->assertArrayNotHasKey('heightInFeet', $fields);
        $this->assertArrayNotHasKey('heightInch', $fields);
        $this->assertArrayNotHasKey('heightObservationDate', $fields);
    }

    public function testInfantUnderAFootReportsZeroFeet(): void
    {
        $fields = PediatricObservation::build(
            '2026-08-01',
            [['date' => '2026-10-01', 'height' => '11', 'weight' => '14']],
            $this->asOf()
        );

        $this->assertSame(0, $fields['heightInFeet']);
        $this->assertSame(11, $fields['heightInch']);
    }

    public function testNoUsableVitalsSendsNothingAtAll(): void
    {
        $this->assertSame([], PediatricObservation::build('2015-03-10', [], $this->asOf()));
        $this->assertSame([], PediatricObservation::build(
            '2015-03-10',
            [['date' => '2026-09-14', 'height' => '0', 'weight' => '0.00000']],
            $this->asOf()
        ));
    }

    public function testUnknownOrPlaceholderDateOfBirthSendsNothing(): void
    {
        $rows = [['date' => '2026-09-14', 'height' => '48', 'weight' => '52']];

        $this->assertSame([], PediatricObservation::build('', $rows, $this->asOf()));
        $this->assertSame([], PediatricObservation::build('0000-00-00', $rows, $this->asOf()));
    }

    public function testAReadingWithNoDateStillSendsTheValueWithoutADate(): void
    {
        $fields = PediatricObservation::build(
            '2015-03-10',
            [['date' => '', 'height' => '48', 'weight' => '52']],
            $this->asOf()
        );

        $this->assertSame(52, $fields['weightInLb']);
        $this->assertArrayNotHasKey('weightObservationDate', $fields);
        $this->assertSame(4, $fields['heightInFeet']);
        $this->assertArrayNotHasKey('heightObservationDate', $fields);
    }
}
