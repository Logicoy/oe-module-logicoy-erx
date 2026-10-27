<?php

/**
 * Finds, creates and links the OpenEMR chart a pharmacy request is about.
 *
 * A pharmacy-initiated request names its patient as the pharmacy knows them,
 * which may be someone this install has never seen. A request for such a
 * patient still needs an answer, and an approval needs a chart to land on.
 * This offers the charts that look like the same person for someone to
 * confirm, creates a chart from the pharmacy's demographics when there is
 * none, and remembers the eRx patient uuid against the chart so the next
 * request for them is matched without asking.
 *
 * Nothing is matched automatically on name alone; a person picks.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\PatientService;
use RuntimeException;

class RequestPatientLinker
{
    private const SELECT = 'SELECT pid, pubpid, fname, mname, lname, DOB, sex FROM patient_data';

    /**
     * The patient_data columns a chart is created with, from the patient as
     * the backend's get-details describes them. Only what the pharmacy sent;
     * anything else stays for the practice to fill in.
     *
     * @param array<string, mixed> $patient rxNewRequestFromPharmacy.patient, raw
     * @return array<string, string>
     */
    public static function toPatientData(array $patient): array
    {
        $address = is_array($patient['address'] ?? null) ? $patient['address'] : [];

        $data = [
            'fname' => self::str($patient['firstName'] ?? ''),
            'lname' => self::str($patient['lastName'] ?? ''),
            'mname' => self::str($patient['middleName'] ?? ''),
            'DOB' => substr(self::str($patient['dateOfBirth'] ?? ''), 0, 10),
            'sex' => self::sex(self::str($patient['genderType'] ?? '')),
        ];

        $phone = preg_replace('/\D/', '', self::str($patient['phone'] ?? '')) ?? '';
        if ($phone !== '') {
            $data['phone_home'] = $phone;
        }
        $email = self::str($patient['email'] ?? '');
        if ($email !== '') {
            $data['email'] = $email;
        }

        $street = trim(self::str($address['addressLine1'] ?? '') . ' ' . self::str($address['addressLine2'] ?? ''));
        if ($street !== '') {
            $data['street'] = $street;
        }
        foreach (['city' => 'city', 'stateProvince' => 'state', 'postalCode' => 'postal_code'] as $from => $to) {
            $value = self::str($address[$from] ?? '');
            if ($value !== '') {
                $data[$to] = $value;
            }
        }
        $country = strtoupper(self::str($address['country'] ?? ''));
        if ($country !== '') {
            // OpenEMR's country list is keyed on three-letter codes.
            $data['country_code'] = $country === 'US' ? 'USA' : $country;
        }

        return $data;
    }

    /**
     * Charts that may be the pharmacy's patient: the same last name with the
     * same birth date or the same first name. Shown for a person to confirm.
     *
     * @param array<string, mixed> $patient raw, as get-details sent it
     * @return array<int, array<string, mixed>>
     */
    public function candidates(array $patient, int $limit = 5): array
    {
        $lname = self::str($patient['lastName'] ?? '');
        $fname = self::str($patient['firstName'] ?? '');
        $dob = substr(self::str($patient['dateOfBirth'] ?? ''), 0, 10);

        if ($lname === '' || ($fname === '' && $dob === '')) {
            return [];
        }

        return QueryUtils::fetchRecords(
            self::SELECT . ' WHERE lname = ? AND (DOB = ? OR fname = ?) '
            . 'ORDER BY (DOB = ?) DESC, (fname = ?) DESC, pid LIMIT ' . max(1, (int)$limit),
            [$lname, $dob, $fname, $dob, $fname]
        );
    }

    /**
     * Charts matching what someone typed: "last, first", "first last", one
     * name, a birth date (YYYY-MM-DD) or a chart number.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 10): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $limit = max(1, (int)$limit);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $term) === 1) {
            return QueryUtils::fetchRecords(
                self::SELECT . ' WHERE DOB = ? ORDER BY lname, fname LIMIT ' . $limit,
                [$term]
            );
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $term) ?: [])));
        if (count($parts) >= 2) {
            $a = $parts[0] . '%';
            $b = $parts[1] . '%';
            return QueryUtils::fetchRecords(
                self::SELECT . ' WHERE (lname LIKE ? AND fname LIKE ?) OR (lname LIKE ? AND fname LIKE ?) '
                . 'ORDER BY lname, fname LIMIT ' . $limit,
                [$a, $b, $b, $a]
            );
        }

        return QueryUtils::fetchRecords(
            self::SELECT . ' WHERE lname LIKE ? OR fname LIKE ? OR pubpid = ? ORDER BY lname, fname LIMIT ' . $limit,
            [$term . '%', $term . '%', $term]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $pid): ?array
    {
        $rows = QueryUtils::fetchRecords(self::SELECT . ' WHERE pid = ? LIMIT 1', [$pid]);

        return $rows[0] ?? null;
    }

    /**
     * Create a chart from the pharmacy's demographics, through OpenEMR's own
     * patient service so its validation, uuid and events apply.
     *
     * @param array<string, mixed> $patient raw, as get-details sent it
     * @return int the new pid
     * @throws RuntimeException with OpenEMR's validation messages
     */
    public function create(array $patient): int
    {
        $result = (new PatientService())->insert(self::toPatientData($patient));

        if (!$result->isValid()) {
            $messages = [];
            foreach ((array)$result->getValidationMessages() as $field => $reasons) {
                foreach ((array)$reasons as $reason) {
                    $messages[] = $field . ': ' . $reason;
                }
            }
            throw new RuntimeException(implode('; ', $messages) ?: 'The patient could not be validated');
        }
        if ($result->hasInternalErrors()) {
            throw new RuntimeException(implode('; ', (array)$result->getInternalErrors()));
        }

        $pid = (int)(($result->getFirstDataResult() ?? [])['pid'] ?? 0);
        if ($pid <= 0) {
            throw new RuntimeException('OpenEMR did not return a pid for the new patient');
        }

        return $pid;
    }

    /**
     * Remember the eRx patient behind a chart, so later notifications for
     * them resolve to it. A chart already mapped to an eRx patient keeps its
     * first mapping: the backend may hold two records for one person, and
     * the one this install syncs to is the one it created.
     */
    public function mapErxPatient(int $pid, int $facilityId, string $erxPatientUuid): void
    {
        if ($erxPatientUuid === '') {
            return;
        }

        QueryUtils::sqlInsert(
            'INSERT INTO ' . PatientSync::TABLE . ' SET pid = ?, facility_id = ?, erx_patient_pk_uuid = ?, '
            . 'last_synced_at = NOW(), created_at = NOW() '
            . 'ON DUPLICATE KEY UPDATE last_synced_at = last_synced_at',
            [$pid, $facilityId, $erxPatientUuid]
        );
    }

    /**
     * What the screen shows for a chart: never more than it needs to tell
     * two people with the same name apart.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function summary(array $row): array
    {
        return [
            'pid' => (int)$row['pid'],
            'pubpid' => (string)($row['pubpid'] ?? ''),
            'name' => trim((string)($row['fname'] ?? '') . ' ' . (string)($row['lname'] ?? '')),
            'dob' => (string)($row['DOB'] ?? ''),
            'sex' => (string)($row['sex'] ?? ''),
        ];
    }

    private static function sex(string $code): string
    {
        // OpenEMR's sex list; its validator also wants at least four characters.
        return match (strtoupper(substr($code, 0, 1))) {
            'M' => 'Male',
            'F' => 'Female',
            default => 'Unknown',
        };
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
