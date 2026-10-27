<?php

/**
 * Builds the height/weight fields the eRx side needs for pediatric patients.
 *
 * Surescripts/NCPDP require a weight (and want a height) for a patient under 19
 * so the pharmacy can check weight-based dosing. This module is a thin client:
 * it only forwards the measurements OpenEMR already holds. The backend turns
 * them into the SCRIPT Observation composite.
 *
 * Kept free of database access so the age rule and the measurement selection
 * are unit testable, in the same shape as PayloadNormalizer. PatientSync does
 * the form_vitals read and hands the rows here.
 *
 * The field names, types and units match PatientAddModel / PatientUpdateModel
 * in the LogiCoy OpenAPI document (/v1/patient/add, /v1/patient/update): the
 * fields sit flat on the patient object, weight is an integer in pounds, height
 * is split into a feet part and an inches part, each with its own observation
 * date (ISO yyyy-MM-dd). OpenEMR stores vitals in these same US units, so the
 * only conversion is rounding to the whole numbers the spec types as integers.
 *
 * The backend contract is "omit-or-valid" (see PayloadNormalizer): a field with
 * nothing on file is left out entirely, and when neither height nor weight is
 * on file nothing is returned at all.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use DateTimeImmutable;

class PediatricObservation
{
    /** A patient strictly younger than this (in years) needs the measurements. */
    public const PEDIATRIC_MAX_AGE = 19;

    // PatientAddModel / PatientUpdateModel field names.
    public const FIELD_WEIGHT_LB = 'weightInLb';
    public const FIELD_WEIGHT_DATE = 'weightObservationDate';
    public const FIELD_HEIGHT_FEET = 'heightInFeet';
    public const FIELD_HEIGHT_INCH = 'heightInch';
    public const FIELD_HEIGHT_DATE = 'heightObservationDate';

    /**
     * The height/weight fields to merge into the eRx patient payload, or an
     * empty array when none is owed: the date of birth is unknown or
     * unparseable, the patient is 19 or older, or no usable height or weight is
     * on file.
     *
     * @param string $dob OpenEMR date of birth (Y-m-d, possibly with a time)
     * @param array<int, array<string, mixed>> $vitalsRows form_vitals rows, each
     *     with 'date', 'height' (total inches) and 'weight' (lb); order does not
     *     matter
     * @param DateTimeImmutable|null $asOf the age reference, defaults to today
     * @return array<string, int|string>
     */
    public static function build(string $dob, array $vitalsRows, ?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable('today');

        $age = self::ageInYears($dob, $asOf);
        if ($age === null || $age >= self::PEDIATRIC_MAX_AGE) {
            return [];
        }

        $fields = [];

        $weight = self::latestReading($vitalsRows, 'weight');
        if ($weight !== null) {
            $pounds = (int)round($weight['value']);
            if ($pounds > 0) {
                $fields[self::FIELD_WEIGHT_LB] = $pounds;
                if ($weight['date'] !== '') {
                    $fields[self::FIELD_WEIGHT_DATE] = $weight['date'];
                }
            }
        }

        $height = self::latestReading($vitalsRows, 'height');
        if ($height !== null) {
            $inches = (int)round($height['value']);
            if ($inches > 0) {
                $fields[self::FIELD_HEIGHT_FEET] = intdiv($inches, 12);
                $fields[self::FIELD_HEIGHT_INCH] = $inches % 12;
                if ($height['date'] !== '') {
                    $fields[self::FIELD_HEIGHT_DATE] = $height['date'];
                }
            }
        }

        return $fields;
    }

    /**
     * Whole years between birth and $asOf, or null when the date of birth is
     * missing or cannot be read (a placeholder 0000-.. row included).
     */
    public static function ageInYears(string $dob, DateTimeImmutable $asOf): ?int
    {
        $date = substr(trim($dob), 0, 10);
        if ($date === '' || str_starts_with($date, '0000')) {
            return null;
        }

        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($birth === false || $birth > $asOf) {
            return null;
        }

        return $asOf->diff($birth)->y;
    }

    /**
     * The most recent non-zero reading for one column, as value (float) and date
     * (yyyy-MM-dd, or ''), or null when none of the rows carry one.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{value: float, date: string}|null
     */
    private static function latestReading(array $rows, string $column): ?array
    {
        $best = null;

        foreach ($rows as $row) {
            $raw = $row[$column] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $value = (float)$raw;
            if ($value <= 0) {
                continue;
            }

            $date = substr(trim((string)($row['date'] ?? '')), 0, 10);
            // A reading with no date still beats nothing; a dated one wins ties.
            if ($best === null || strcmp($date, $best['date']) > 0) {
                $best = ['value' => $value, 'date' => $date];
            }
        }

        return $best;
    }
}
