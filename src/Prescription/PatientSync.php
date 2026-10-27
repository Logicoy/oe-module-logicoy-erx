<?php

/**
 * Maps an OpenEMR patient onto the eRx side.
 *
 * The mapping is stored so a repeat sync updates the existing eRx patient
 * rather than creating a second one.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use LogiCoy\ERx\Client\BackendClient;
use OpenEMR\Common\Database\QueryUtils;

class PatientSync
{
    public const TABLE = 'logicoy_erx_patient_map';

    private BackendClient $backend;

    public function __construct(BackendClient $backend)
    {
        $this->backend = $backend;
    }

    /**
     * Ensure the patient exists on the eRx side and return its uuid.
     *
     * @param array<string, mixed> $credential
     */
    public function ensure(int $pid, int $facilityId, array $credential): string
    {
        $existing = $this->findMapping($pid, $facilityId);
        $patient = $this->loadPatient($pid);

        if ($patient === null) {
            throw new PrescriptionWriteException('Patient ' . $pid . ' was not found in OpenEMR');
        }

        if ($existing !== null) {
            $this->backend->post(
                BackendClient::ENDPOINT_PATIENT_UPDATE,
                [
                    'transactionUuid' => PrescribingService::newTransactionId(),
                    'erxPracticePKUuid' => $credential['erx_practice_uuid'] ?? '',
                    'patient' => $this->toErxPatient($patient, $pid) + ['erxPatientPKUuid' => $existing],
                ],
                $credential
            );
            $this->touch($pid, $facilityId);

            return $existing;
        }

        $response = $this->backend->post(
            BackendClient::ENDPOINT_PATIENT_ADD,
            [
                'transactionUuid' => PrescribingService::newTransactionId(),
                'erxPracticePKUuid' => $credential['erx_practice_uuid'] ?? '',
                'patientList' => [$this->toErxPatient($patient, $pid)],
            ],
            $credential
        );

        $uuid = BackendClient::findValue($response, 'erxPatientPKUuid');
        if (empty($uuid)) {
            throw new PrescriptionWriteException('eRx did not return an erxPatientPKUuid for patient ' . $pid);
        }

        $this->saveMapping($pid, $facilityId, $uuid);

        return $uuid;
    }

    public function findMapping(int $pid, int $facilityId): ?string
    {
        $uuid = QueryUtils::fetchSingleValue(
            'SELECT erx_patient_pk_uuid FROM ' . self::TABLE . ' WHERE pid = ? AND facility_id = ?',
            'erx_patient_pk_uuid',
            [$pid, $facilityId]
        );

        return empty($uuid) ? null : (string)$uuid;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPatient(int $pid): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT pid, fname, mname, lname, DOB, sex, phone_cell, phone_home, email, '
            . 'street, city, state, postal_code, country_code FROM patient_data WHERE pid = ? LIMIT 1',
            [$pid]
        );

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function toErxPatient(array $p, int $pid): array
    {
        $payload = [
            'firstName' => (string)($p['fname'] ?? '') ?: 'Unknown',
            'lastName' => (string)($p['lname'] ?? '') ?: 'Unknown',
            'dateOfBirth' => $this->normalizeDate((string)($p['DOB'] ?? '')),
            'genderType' => $this->genderCode((string)($p['sex'] ?? '')),
            // Carries the OpenEMR pid so inbound messages can be matched back.
            'sourcePatientRecordId' => (string)$pid,
        ];

        if (!empty($p['mname'])) {
            $payload['middleName'] = (string)$p['mname'];
        }

        $phone = $this->digitsOnly((string)($p['phone_cell'] ?? ''))
            ?: $this->digitsOnly((string)($p['phone_home'] ?? ''));
        if ($phone !== '') {
            $payload['phone'] = $phone;
        }
        if (!empty($p['email'])) {
            $payload['email'] = (string)$p['email'];
        }

        if (!empty($p['street']) || !empty($p['city'])) {
            $payload['address'] = [
                'addressLine1' => (string)($p['street'] ?? ''),
                'city' => (string)($p['city'] ?? ''),
                'stateProvince' => (string)($p['state'] ?? ''),
                // eRx wants the five digit form, not zip+4.
                'postalCode' => explode('-', (string)($p['postal_code'] ?? ''))[0],
                'country' => (string)($p['country_code'] ?? '') ?: 'US',
            ];
        }

        // A patient under 19 carries their latest height/weight so the eRx side
        // can check weight-based pediatric dosing. The fields (weightInLb,
        // heightInFeet/heightInch and their observation dates) sit flat on the
        // patient object per PatientAddModel. Nothing is added for an adult or
        // when no vitals are on file.
        $payload += PediatricObservation::build(
            (string)($p['DOB'] ?? ''),
            $this->loadLatestVitals($pid)
        );

        return $payload;
    }

    /**
     * The patient's recent, non-deleted vitals (most recent first), narrowed to
     * the columns the observation needs. Joined through forms so a vitals form
     * the user deleted is not resurfaced.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadLatestVitals(int $pid): array
    {
        return QueryUtils::fetchRecords(
            'SELECT fv.date, fv.height, fv.weight '
            . 'FROM form_vitals fv '
            . "JOIN forms f ON f.formdir = 'vitals' AND f.form_id = fv.id AND f.deleted = 0 "
            . 'WHERE fv.pid = ? ORDER BY fv.date DESC LIMIT 25',
            [$pid]
        );
    }

    private function genderCode(string $sex): string
    {
        return match (strtolower(substr($sex, 0, 1))) {
            'm' => 'M',
            'f' => 'F',
            default => 'U',
        };
    }

    private function digitsOnly(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    private function normalizeDate(string $date): string
    {
        if ($date === '' || str_starts_with($date, '0000')) {
            return '';
        }

        return substr($date, 0, 10);
    }

    private function saveMapping(int $pid, int $facilityId, string $uuid): void
    {
        QueryUtils::sqlInsert(
            'INSERT INTO ' . self::TABLE . ' SET pid = ?, facility_id = ?, erx_patient_pk_uuid = ?, '
            . 'last_synced_at = NOW(), created_at = NOW() '
            // A concurrent sync may have won; keep the first mapping.
            . 'ON DUPLICATE KEY UPDATE last_synced_at = NOW()',
            [$pid, $facilityId, $uuid]
        );
    }

    private function touch(int $pid, int $facilityId): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET last_synced_at = NOW() WHERE pid = ? AND facility_id = ?',
            [$pid, $facilityId]
        );
    }
}
