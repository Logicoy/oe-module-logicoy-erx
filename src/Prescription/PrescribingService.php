<?php

/**
 * Backs the prescribing screen.
 *
 * Every clinical answer — which drug exists, its NDC, its DEA schedule, which
 * pharmacies can receive it — comes from the LogiCoy backend. This class only
 * assembles request payloads and flattens responses for the view.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Config\CredentialStore;

class PrescribingService
{
    private BackendClient $backend;

    public function __construct(BackendClient $backend)
    {
        $this->backend = $backend;
    }

    /**
     * Identifiers every eRx call carries. The search and send endpoints name the
     * clinician erxClinicianUserPKUuid; the draft endpoints call the same value
     * erxClinicianUserUuid, which is why this goes through the store's resolver
     * rather than reading one column.
     *
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    private function context(array $credential): array
    {
        return self::requestContext($credential);
    }

    /**
     * The same identifiers for callers outside this class. A background job
     * acting on a notification passes the clinician the notification names,
     * since the practice credential it holds may carry none.
     *
     * @param array<string, mixed> $credential
     * @return array<string, string>
     */
    public static function requestContext(array $credential, string $clinicianPkUuid = ''): array
    {
        return [
            'transactionUuid' => self::newTransactionId(),
            'erxPracticePKUuid' => (string)($credential['erx_practice_uuid'] ?? ''),
            'erxPracticeServiceLocPKUuid' => (string)($credential['erx_location_uuid'] ?? ''),
            'erxClinicianUserPKUuid' => $clinicianPkUuid !== ''
                ? $clinicianPkUuid
                : CredentialStore::clinicianPkUuid($credential),
        ];
    }

    /**
     * @param array<string, mixed> $credential
     * @return array<int, array<string, mixed>>
     */
    public function searchDrugsByName(array $credential, string $productName): array
    {
        $response = $this->backend->post(
            BackendClient::ENDPOINT_DRUG_SEARCH_BY_NAME,
            $this->context($credential) + ['productName' => $productName],
            $credential
        );

        return $this->flattenDrugResults($response);
    }

    /**
     * @param array<string, mixed> $credential
     * @return array<int, array<string, mixed>>
     */
    public function searchDrugsByNdc(array $credential, string $ndcNumber): array
    {
        $response = $this->backend->post(
            BackendClient::ENDPOINT_DRUG_SEARCH_BY_NDC,
            $this->context($credential) + ['ndcNumber' => $ndcNumber],
            $credential
        );

        return $this->flattenDrugResults($response);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function flattenDrugResults(array $response): array
    {
        $results = $response['medicationSearchResults']
            ?? $response['data']['medicationSearchResults']
            ?? [];

        if (!is_array($results)) {
            return [];
        }

        $drugs = [];
        foreach ($results as $r) {
            if (!is_array($r)) {
                continue;
            }

            $schedule = trim((string)($r['scheduleClassType'] ?? ''));
            $isControlled = self::isControlled($r);

            $drugs[] = [
                'ndcNumber' => (string)($r['ndcNumber'] ?? ''),
                // searchDrugDescToSend is what the backend wants echoed back on
                // the prescription; fall back to the display description.
                'drugName' => (string)($r['searchDrugDescToSend'] ?? $r['searchDrugDesc'] ?? $r['productName'] ?? ''),
                'displayName' => (string)($r['searchDrugDesc'] ?? $r['productName'] ?? ''),
                'genericName' => (string)($r['genericDrugName'] ?? ''),
                'strength' => (string)($r['drugStrength'] ?? ''),
                'form' => (string)($r['drugForm'] ?? ''),
                'manufacturer' => (string)($r['mfgName'] ?? ''),
                'packageSize' => (string)($r['packageSize'] ?? ''),
                'unitOfMeasure' => (string)($r['uiMeasureCodeValue'] ?? $r['measureCodeValue'] ?? ''),
                'route' => (string)($r['roaCodeVal'] ?? ''),
                // A scheduled drug needs EPCS, which this module does not do.
                'scheduleClass' => $schedule,
                'isControlled' => $isControlled,
                'controlledWarning' => (string)($r['scheduleClassTypeWarningMessage'] ?? ''),
            ];
        }

        return $drugs;
    }

    /**
     * Schedule codes the backend uses for a drug that is not controlled.
     *
     * "R" is a prescription legend drug and "O" is over the counter — neither
     * is scheduled. The backend returns "R" on ordinary drugs rather than a
     * blank or "0", so treating every non-blank code as a schedule marked the
     * whole formulary controlled and disabled the prescribing form for it.
     */
    private const NON_CONTROLLED_SCHEDULES = ['', '0', 'N', 'O', 'R'];

    /**
     * Whether a search result is a controlled substance.
     *
     * The backend answers this itself: scheduleClassTypeWarning is true for
     * every DEA schedule and false otherwise, so it is taken whenever present.
     * The schedule code only decides when that flag is absent, and an
     * unrecognised code counts as controlled — over-warning costs a prescriber
     * one manual check, while under-warning would offer to transmit a scheduled
     * drug without EPCS.
     *
     * @param array<string, mixed> $result one medicationSearchResults entry
     */
    public static function isControlled(array $result): bool
    {
        $flag = $result['scheduleClassTypeWarning'] ?? null;
        if (is_bool($flag)) {
            return $flag;
        }

        $schedule = strtoupper(trim((string)($result['scheduleClassType'] ?? '')));

        return !in_array($schedule, self::NON_CONTROLLED_SCHEDULES, true);
    }

    /**
     * Whether a drug, named by NDC, is a controlled substance.
     *
     * Drafts come back from the backend without their schedule, so the send
     * gate asks the drug database — the backend's, through the same search
     * the prescribing screen uses. Null when the backend returns nothing for
     * the NDC; the caller decides what an unknown drug means.
     *
     * @param array<string, mixed> $credential
     */
    public function isControlledNdc(array $credential, string $ndcNumber): ?bool
    {
        $wanted = preg_replace('/\D/', '', $ndcNumber) ?? '';
        if ($wanted === '') {
            return null;
        }

        $response = $this->backend->post(
            BackendClient::ENDPOINT_DRUG_SEARCH_BY_NDC,
            $this->context($credential) + ['ndcNumber' => $wanted],
            $credential
        );
        $results = $response['medicationSearchResults']
            ?? $response['data']['medicationSearchResults']
            ?? [];

        // Only the result for this very NDC counts. A list that names other
        // products, or none, leaves the drug unclassified rather than letting
        // a neighbour's schedule stand in for it.
        foreach (is_array($results) ? $results : [] as $r) {
            if (!is_array($r) || (preg_replace('/\D/', '', (string)($r['ndcNumber'] ?? '')) ?? '') !== $wanted) {
                continue;
            }
            // A result that carries neither the flag nor a schedule code says
            // nothing about the drug, and nothing must not read as "not
            // controlled".
            $hasFlag = is_bool($r['scheduleClassTypeWarning'] ?? null);
            if (!$hasFlag && trim((string)($r['scheduleClassType'] ?? '')) === '') {
                return null;
            }

            return self::isControlled($r);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $credential
     * @param array<string, mixed> $filters organizationName, city, postalCode
     * @return array<int, array<string, mixed>>
     */
    public function searchPharmacies(array $credential, array $filters): array
    {
        $response = $this->backend->post(
            BackendClient::ENDPOINT_PHARMACY_SEARCH,
            $this->context($credential) + [
                'pharmacySearchFilters' => array_filter([
                    'organizationName' => $filters['organizationName'] ?? null,
                    'city' => $filters['city'] ?? null,
                    'postalCode' => $filters['postalCode'] ?? null,
                ], static fn($v) => $v !== null && $v !== ''),
            ],
            $credential
        );

        $results = $response['pharmacySearchResults']
            ?? $response['data']['pharmacySearchResults']
            ?? [];

        // The backend splits retail and mail order; the prescriber picks from
        // one list, so merge them and keep the distinction as a flag.
        $pharmacies = [];
        foreach ([['pharmacyList', false], ['mailOrderList', true]] as [$key, $isMailOrder]) {
            $list = $results[$key] ?? $results[0][$key] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $p) {
                if (!is_array($p)) {
                    continue;
                }
                // PharmacySearchDetails: the id is pharmacyOrgId (int64) and the
                // address fields carry a "standardized" prefix.
                $orgId = $p['pharmacyOrgId'] ?? $p['organizationId'] ?? $p['orgId'] ?? '';
                if ($orgId === '' || $orgId === null) {
                    // Nothing to route to; selecting it would only fail on save.
                    continue;
                }
                $pharmacies[] = [
                    'organizationId' => (string)$orgId,
                    'name' => (string)($p['organizationName'] ?? $p['name'] ?? ''),
                    'address' => (string)($p['standardizedAddressLine1'] ?? $p['addressLine1'] ?? ''),
                    'city' => (string)($p['standardizedCity'] ?? $p['city'] ?? ''),
                    'state' => (string)($p['standardizedStateProvince'] ?? $p['stateProvince'] ?? $p['state'] ?? ''),
                    'postalCode' => (string)($p['postalCode'] ?? ''),
                    'phone' => (string)($p['primaryTelephone'] ?? $p['phone'] ?? $p['phoneNumber'] ?? ''),
                    'isMailOrder' => $isMailOrder,
                ];
            }
        }

        return $pharmacies;
    }

    public static function newTransactionId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
