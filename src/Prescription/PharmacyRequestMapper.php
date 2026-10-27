<?php

/**
 * Pharmacy-initiated renewal and change requests: flattens the backend's
 * get-details answer for the screen, and builds the response payloads.
 *
 * No clinical logic and no SCRIPT construction. The medication structures in
 * these payloads are the backend's own — a change response echoes back the
 * requested medication exactly as get-details returned it. Which decisions a
 * request accepts is the backend's answer too; this class only refuses what
 * the backend would refuse anyway, so the prescriber hears it before a
 * transmission is queued rather than after.
 *
 * APPROVED_WITH_CHANGES and REPLACE are the same answer with a different
 * code: both start from the pharmacy's own medication (the one it requested
 * on a change, the one it dispensed on a renewal, or the one prescribed
 * when a renewal names none dispensed) and let the prescriber
 * change what the prescribing screen also sends for a new prescription: the
 * drug (picked from the backend's own drug search, so its NDC is the
 * backend's), quantity, days supply, directions and refills. The pharmacy's
 * quantity codes (unit of measure, qualifier) stay with the new value. The
 * backend does the NCPDP coding, as it does for RxNew.
 *
 * UNCONFIRMED SHAPE: the LogiCoy backend team has not yet said that
 * rxChangeResponseMedication.medicationResponse is what it expects for this
 * code, nor that drugDescription is taken when the drug differs. On a
 * renewal the medication goes under rxRenewalResponseMedication.medicationResponse,
 * mirroring the change response, which is a guess at a shape the backend
 * has not documented at all: the renewal API only describes APPROVED and
 * DENIED, which carry no medication. Until it is confirmed, expect the
 * backend to refuse the response and the queue row to show its reason.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use InvalidArgumentException;

class PharmacyRequestMapper
{
    public const TYPE_RENEWAL = 'renewal';
    public const TYPE_CHANGE = 'change';
    /** A pharmacy asking for a new prescription on a patient's behalf. */
    public const TYPE_NEW_RX = 'new-rx';

    public const APPROVED = 'APPROVED';
    public const APPROVED_WITH_CHANGES = 'APPROVED_WITH_CHANGES';
    public const REPLACE = 'REPLACE';
    public const DENIED = 'DENIED';
    public const VALIDATED = 'VALIDATED';

    /** Decisions this module can send, per request type. */
    public const SUPPORTED_DECISIONS = [
        self::TYPE_RENEWAL => [self::APPROVED, self::APPROVED_WITH_CHANGES, self::REPLACE, self::DENIED],
        self::TYPE_CHANGE => [
            self::APPROVED, self::APPROVED_WITH_CHANGES, self::REPLACE, self::VALIDATED, self::DENIED,
        ],
        // The backend sends the pharmacy's medication as the prescription on
        // an approval; there is no changed form of it.
        self::TYPE_NEW_RX => [self::APPROVED, self::DENIED],
    ];

    /** The decisions that carry the prescriber's changes to the medication. */
    public const CHANGING_DECISIONS = [self::APPROVED_WITH_CHANGES, self::REPLACE];

    /**
     * RxRenewalDeniedCodeTypeRef, as the backend defines it. A renewal denial
     * without one of these is refused by the backend (RX_RENEWAL_ERC_6506).
     */
    public const RENEWAL_DENIAL_REASONS = [
        'AA' => 'Patient unknown to the provider',
        'AB' => 'Patient never under provider care',
        'AC' => 'Patient no longer under provider care',
        'AD' => 'Patient has requested refill too soon',
        'AE' => 'Medication never prescribed for the patient',
        'AF' => 'Patient should contact provider first',
        'AG' => 'Fill/Refill not appropriate',
        'AM' => 'Patient needs appointment',
        'AN' => 'Prescriber not associated with this practice or location',
        'AP' => 'Request already responded to by other means (e.g. phone or fax)',
        'BE' => 'Medication denied at patient request',
        'CZ' => 'Patient had allergy to requested medication',
        'DA' => 'Medication has been discontinued',
    ];

    /**
     * Denial reasons for a pharmacy-initiated new Rx request: the same NCPDP
     * codes as a renewal, as the backend's deniedResponseCode lists them.
     */
    public const NEW_RX_DENIAL_REASONS = self::RENEWAL_DENIAL_REASONS;

    /** notesToPharmacist on a new Rx response, as the backend bounds it. */
    public const NEW_RX_NOTES_MAX = 210;

    /**
     * What a prescriber may change when approving with changes. The drug is
     * given as drugName + ndcNumber from the backend's drug search; the
     * pharmacy's drug stands when ndcNumber is empty.
     */
    public const EDITABLE_FIELDS = ['drugName', 'ndcNumber', 'quantity', 'daysSupply', 'sig', 'refills'];

    /**
     * The MedicationResponse fields a change response carries. Anything else
     * in a requested medication is the pharmacy's context, not part of the
     * answer.
     */
    private const CHANGE_RESPONSE_FIELDS = [
        'quantity', 'daysSupply', 'writtenDate', 'lastFillDate', 'substitutions', 'sig',
        'pharmacyRequestedRefills', 'numberOfRefills', 'otherMedicationDate', 'rxDrugType',
        'rxCompoundIngredientModel', 'priorAuthorization', 'note', 'drugCoverageStatusCode',
        'priorAuthorizationStatus', 'timeZone', 'rxFillIndicator',
    ];

    /**
     * Which request type a notification's rxTypeName is, if either.
     */
    public static function typeForNotification(string $rxTypeName): ?string
    {
        $name = strtoupper(trim($rxTypeName));

        if (str_starts_with($name, 'RX_RENEWAL')) {
            return self::TYPE_RENEWAL;
        }
        if (str_starts_with($name, 'RX_CHANGE')) {
            return self::TYPE_CHANGE;
        }
        // NEW_RX_REQUEST, not RX_NEW: the latter is the verification status
        // of a prescription sent from here.
        if (str_starts_with($name, 'NEW_RX')) {
            return self::TYPE_NEW_RX;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response /v1/rxrenewal/get-details
     * @return array<string, mixed>
     */
    public static function renewalDetails(array $response): array
    {
        $request = self::arr($response['rxRenewalRequestFromPharmacy']
            ?? $response['data']['rxRenewalRequestFromPharmacy'] ?? []);
        $accepted = self::codes($request['acceptedResponseCodes'] ?? []);

        return [
            'type' => self::TYPE_RENEWAL,
            'patient' => self::patient(self::arr($request['patient'] ?? [])),
            'pharmacy' => self::pharmacy(self::arr($request['pharmacy'] ?? [])),
            'prescriber' => self::prescriber(self::arr($request['prescriber'] ?? [])),
            'prescribed' => self::medication(self::arr($request['medicationPrescribed'] ?? [])),
            'dispensed' => self::medication(self::arr($request['medicationDispensed'] ?? [])),
            'followUp' => self::str($request['followUpRequest'] ?? ''),
            'acceptedCodes' => $accepted,
            'decisions' => self::decisions(self::TYPE_RENEWAL, $accepted),
            'denialReasons' => self::RENEWAL_DENIAL_REASONS,
        ];
    }

    /**
     * @param array<string, mixed> $response /v1/rxchange/get-details
     * @return array<string, mixed>
     */
    public static function changeDetails(array $response): array
    {
        $request = self::arr($response['rxChangeRequestFromPharmacy']
            ?? $response['data']['rxChangeRequestFromPharmacy'] ?? []);
        $accepted = self::codes($request['acceptedResponseCodes'] ?? []);
        $requestCode = self::arr($request['rxChangeMsgReqCodeTypeRef'] ?? []);

        $requested = [];
        foreach (self::requestedMedications($response) as $raw) {
            $requested[] = ['requestedMedicationId' => self::requestedMedicationId($raw)]
                + (self::medication($raw) ?? []);
        }

        $subCodes = [];
        foreach ((array)($request['rxChangeMsgReqSubCodeTypeRef'] ?? []) as $sub) {
            if (is_array($sub)) {
                $subCodes[] = [
                    'code' => self::str($sub['name'] ?? ''),
                    'description' => self::str($sub['description'] ?? ''),
                ];
            }
        }

        return [
            'type' => self::TYPE_CHANGE,
            'patient' => self::patient(self::arr($request['patient'] ?? [])),
            'pharmacy' => self::pharmacy(self::arr($request['pharmacy'] ?? [])),
            'prescriber' => self::prescriber(self::arr($request['prescriber'] ?? [])),
            'requestCode' => self::str($requestCode['name'] ?? $request['messageRequestCode'] ?? ''),
            'requestDescription' => self::str(
                $requestCode['description'] ?? $request['messageRequestCodeValue'] ?? ''
            ),
            'subCodes' => $subCodes,
            'prescribed' => self::medication(self::arr($request['medicationPrescribed'] ?? [])),
            'requested' => $requested,
            'followUp' => self::str($request['followUpRequest'] ?? ''),
            'message' => self::str($response['erxMessage'] ?? ''),
            'acceptedCodes' => $accepted,
            'decisions' => self::decisions(self::TYPE_CHANGE, $accepted),
        ];
    }

    /**
     * @param array<string, mixed> $response /v1/pharmacy-initiated/new-rx/get-details
     * @return array<string, mixed>
     */
    public static function newRxDetails(array $response): array
    {
        $request = self::arr($response['rxNewRequestFromPharmacy']
            ?? $response['data']['rxNewRequestFromPharmacy'] ?? []);

        return [
            'type' => self::TYPE_NEW_RX,
            'patient' => self::patient(self::arr($request['patient'] ?? [])),
            'pharmacy' => self::pharmacy(self::arr($request['pharmacy'] ?? [])),
            'prescriber' => self::prescriber(self::arr($request['prescriber'] ?? [])),
            'requested' => self::medication(self::arr($request['medicationRequested'] ?? [])),
            'message' => self::str($response['erxMessage'] ?? ''),
            // The backend creates the patient on its side while answering
            // get-details when the pharmacy's patient is new to it.
            'isNewPatient' => ($response['isNewPatient'] ?? false) === true,
            'erxPatientUuid' => self::str($response['erxPatientPKUuid'] ?? ''),
            'sourcePatientRecordId' => self::str($response['sourcePatientRecordId'] ?? ''),
            'acceptedCodes' => [],
            'decisions' => self::SUPPORTED_DECISIONS[self::TYPE_NEW_RX],
            'denialReasons' => self::NEW_RX_DENIAL_REASONS,
        ];
    }

    /**
     * The pharmacy's requested medications exactly as the backend sent them,
     * for building a change response later.
     *
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    public static function requestedMedications(array $response): array
    {
        $request = self::arr($response['rxChangeRequestFromPharmacy']
            ?? $response['data']['rxChangeRequestFromPharmacy'] ?? []);

        return array_values(array_filter(
            (array)($request['medicationRequested'] ?? []),
            static fn($m) => is_array($m) && $m !== []
        ));
    }

    /**
     * What a response is checked against, cached when the prescriber opens a
     * request so the response is built from what they reviewed.
     *
     * The medications a renewal is about are kept raw as well: what was
     * prescribed, so an approval can be written into the chart once the
     * pharmacy has been answered, and what was dispensed, which an approval
     * with changes starts from.
     *
     * @param array<string, mixed> $response
     * @return array{
     *     type: string,
     *     acceptedCodes: array<int, string>,
     *     requested: array<int, array<string, mixed>>,
     *     prescribed: array<string, mixed>,
     *     dispensed: array<string, mixed>
     * }
     */
    public static function detailCache(string $type, array $response): array
    {
        if ($type === self::TYPE_NEW_RX) {
            $request = self::arr($response['rxNewRequestFromPharmacy']
                ?? $response['data']['rxNewRequestFromPharmacy'] ?? []);

            return [
                'type' => $type,
                'acceptedCodes' => [],
                // One medication, kept under requested like a change request's
                // so an approval is written to the chart the same way.
                'requested' => array_values(array_filter([self::arr($request['medicationRequested'] ?? [])])),
                'prescribed' => [],
                'dispensed' => [],
                // The patient as the pharmacy described them, for creating or
                // matching a chart here. Chart data; never logged.
                'patient' => self::arr($request['patient'] ?? []),
                'erxPatientUuid' => self::str($response['erxPatientPKUuid'] ?? ''),
                'sourcePatientRecordId' => self::str($response['sourcePatientRecordId'] ?? ''),
            ];
        }

        $key = $type === self::TYPE_RENEWAL ? 'rxRenewalRequestFromPharmacy' : 'rxChangeRequestFromPharmacy';
        $request = self::arr($response[$key] ?? $response['data'][$key] ?? []);

        return [
            'type' => $type,
            'acceptedCodes' => self::codes($request['acceptedResponseCodes'] ?? []),
            'requested' => $type === self::TYPE_CHANGE ? self::requestedMedications($response) : [],
            'prescribed' => $type === self::TYPE_RENEWAL ? self::arr($request['medicationPrescribed'] ?? []) : [],
            'dispensed' => $type === self::TYPE_RENEWAL ? self::arr($request['medicationDispensed'] ?? []) : [],
        ];
    }

    /**
     * The medication a renewal answer is about: what the pharmacy dispensed,
     * or what was prescribed when it named nothing dispensed.
     *
     * @param array<string, mixed> $cache from detailCache()
     * @return array<string, mixed>
     */
    private static function renewalMedication(array $cache): array
    {
        $dispensed = self::arr($cache['dispensed'] ?? []);

        return $dispensed !== [] ? $dispensed : self::arr($cache['prescribed'] ?? []);
    }

    /**
     * The script a transmitted response puts on the patient's chart, in the
     * shape PrescriptionWriter takes, or null when the answer produces none.
     *
     * An APPROVED renewal re-issues the medication the pharmacy asked to
     * renew, with the refills it asked for. A renewal approved with changes
     * or replaced issues the medication as sent: the dispensed one with the
     * prescriber's values over it. An approved, changed or replaced change
     * issues the requested medication the prescriber picked, as sent.
     * DENIED issues nothing, and
     * VALIDATED says the original stands, which is already on the chart.
     * Everything comes from what the prescriber reviewed (the cache) and what
     * was sent (the payload); nothing is composed here.
     *
     * @param array<string, mixed> $payload the queued response, as sent
     * @param array<string, mixed> $cache from detailCache()
     * @return array<string, string>|null drugName, ndcNumber, sig, quantity, refills
     */
    public static function approvedMedication(string $type, array $payload, array $cache): ?array
    {
        if (($cache['type'] ?? '') !== $type) {
            return null;
        }

        if ($type === self::TYPE_NEW_RX) {
            $sent = self::arr($payload['rxPharmacyIntiNewRxInfo'] ?? []);
            $decision = strtoupper(self::str($sent['responseCode'] ?? ''));
            if ($decision !== self::APPROVED) {
                return null;
            }
            // An approval sends the pharmacy's requested medication as is.
            $raw = self::arr(((array)($cache['requested'] ?? []))[0] ?? []);
        } elseif ($type === self::TYPE_RENEWAL) {
            $sent = self::arr($payload['rxRenewalResponseMedication'] ?? []);
            $decision = strtoupper(self::str($sent['rxRenewalResponseCodeTypeRef'] ?? ''));
            if ($decision === self::APPROVED) {
                $raw = self::arr($cache['prescribed'] ?? []);
                // The pharmacy's requested count rides on the dispensed
                // medication when it names one; the prescribed copy may
                // not carry it.
                $requestedRefills = self::str(self::arr($cache['dispensed'] ?? [])['pharmacyRequestedRefills'] ?? '');
                if ($requestedRefills !== '' && self::str($raw['pharmacyRequestedRefills'] ?? '') === '') {
                    $raw['pharmacyRequestedRefills'] = $requestedRefills;
                }
            } elseif (in_array($decision, self::CHANGING_DECISIONS, true)) {
                $raw = self::withSentValues(self::renewalMedication($cache), $sent);
            } else {
                return null;
            }
        } else {
            $sent = self::arr($payload['rxChangeResponseMedication'] ?? []);
            $decision = strtoupper(self::str($sent['rxChangeResponseCodeTypeRef'] ?? ''));
            if ($decision !== self::APPROVED && !in_array($decision, self::CHANGING_DECISIONS, true)) {
                return null;
            }
            // The response echoes the medication without its description;
            // the reviewed request has it.
            $id = self::str(self::dig(self::arr($sent['medicationResponse'] ?? []), 'requestedMedicaitonId'));
            $raw = self::findRequested((array)($cache['requested'] ?? []), $id);
            if ($raw === null) {
                return null;
            }
            $raw = self::withSentValues($raw, $sent);
        }

        $medication = self::medication($raw);
        if ($medication === null || $medication['description'] === '') {
            return null;
        }

        // A plain renewal approval grants the refills the pharmacy asked
        // for; anything else carries the count that was sent.
        $grantsRequested = $type === self::TYPE_RENEWAL && $decision === self::APPROVED
            && $medication['pharmacyRequestedRefills'] !== '';

        return [
            'drugName' => $medication['description'],
            'ndcNumber' => $medication['ndc'],
            'sig' => $medication['sig'],
            'quantity' => $medication['quantity'],
            'refills' => $grantsRequested ? $medication['pharmacyRequestedRefills'] : $medication['refills'],
        ];
    }

    /**
     * What was sent is what goes on the chart: an approval with changes
     * carries the prescriber's values over the pharmacy's. The sent drug is
     * under ndc; the pharmacy's drugCoded is dropped so medication() reads
     * the sent one.
     *
     * @param array<string, mixed> $raw the pharmacy's medication, as cached
     * @param array<string, mixed> $sent the response body, as queued
     * @return array<string, mixed>
     */
    private static function withSentValues(array $raw, array $sent): array
    {
        $raw = array_merge($raw, self::arr($sent['medicationResponse'] ?? []));
        if (isset($raw['ndc'])) {
            unset($raw['drugCoded']);
        }

        return $raw;
    }

    /**
     * Structural checks on a prescriber's decision.
     *
     * @param array<string, mixed> $input decision, deniedReason, notes, requestedMedicationId
     * @param array<string, mixed> $cache from detailCache()
     * @return array<int, string>
     */
    public static function validate(string $type, array $input, array $cache): array
    {
        if (($cache['type'] ?? '') !== $type) {
            return [xl('Review the request before responding to it')];
        }

        $errors = [];
        $decision = strtoupper(trim((string)($input['decision'] ?? '')));
        $accepted = (array)($cache['acceptedCodes'] ?? []);

        if (!in_array($decision, self::SUPPORTED_DECISIONS[$type] ?? [], true)) {
            $errors[] = xl('Choose a response');
        } elseif ($accepted !== [] && !in_array($decision, $accepted, true)) {
            $errors[] = xl('The pharmacy does not accept this response for this request');
        }

        $notes = trim((string)($input['notes'] ?? ''));

        if ($type === self::TYPE_NEW_RX) {
            if ($decision === self::DENIED) {
                $reason = strtoupper(trim((string)($input['deniedReason'] ?? '')));
                if (!array_key_exists($reason, self::NEW_RX_DENIAL_REASONS)) {
                    $errors[] = xl('Choose a reason for the denial');
                }
            }
            if ($decision === self::APPROVED && self::arr(((array)($cache['requested'] ?? []))[0] ?? []) === []) {
                $errors[] = xl('The request names no medication to approve');
            }
            if (mb_strlen($notes) > self::NEW_RX_NOTES_MAX) {
                $errors[] = xl('Notes to the pharmacist must be at most') . ' ' . self::NEW_RX_NOTES_MAX . ' '
                    . xl('characters');
            }
        }

        if ($type === self::TYPE_RENEWAL && $decision === self::DENIED) {
            $reason = strtoupper(trim((string)($input['deniedReason'] ?? '')));
            if (!array_key_exists($reason, self::RENEWAL_DENIAL_REASONS)) {
                $errors[] = xl('Choose a reason for the denial');
            }
        }

        if ($type === self::TYPE_RENEWAL && in_array($decision, self::CHANGING_DECISIONS, true)) {
            if (self::renewalMedication($cache) === []) {
                $errors[] = xl('The request names no medication to change');
            }
            $errors = array_merge($errors, self::validateChanges($input));
        }

        if ($type === self::TYPE_CHANGE) {
            if ($decision === self::DENIED && $notes === '') {
                $errors[] = xl('Explain the denial in the notes');
            }
            $id = (string)($input['requestedMedicationId'] ?? '');
            if (self::findRequested((array)($cache['requested'] ?? []), $id) === null) {
                $errors[] = xl('Choose which requested medication this response is for');
            }
            if (in_array($decision, self::CHANGING_DECISIONS, true)) {
                $errors = array_merge($errors, self::validateChanges($input));
            }
        }

        return $errors;
    }

    /**
     * The same rules the prescribing screen applies to a new script.
     *
     * @param array<string, mixed> $input
     * @return array<int, string>
     */
    private static function validateChanges(array $input): array
    {
        $errors = [];

        // An NDC means a different drug; it comes from the backend's search,
        // 9 to 11 digits, and needs its name for the pharmacy.
        $ndc = preg_replace('/\D/', '', (string)($input['ndcNumber'] ?? '')) ?? '';
        if ($ndc !== '') {
            if (strlen($ndc) < 9 || strlen($ndc) > 11) {
                $errors[] = xl('NDC number must be 9 to 11 digits');
            }
            if (trim((string)($input['drugName'] ?? '')) === '') {
                $errors[] = xl('Select a drug');
            }
        }

        $quantity = trim((string)($input['quantity'] ?? ''));
        if ($quantity === '' || !is_numeric($quantity) || (float)$quantity <= 0) {
            $errors[] = xl('Enter the quantity');
        }

        $days = trim((string)($input['daysSupply'] ?? ''));
        if ($days !== '' && (!ctype_digit($days) || (int)$days <= 0)) {
            $errors[] = xl('Days supply must be a whole number of days');
        }

        if (trim((string)($input['sig'] ?? '')) === '') {
            $errors[] = xl('Directions (sig) are required');
        }

        $refills = trim((string)($input['refills'] ?? ''));
        if ($refills === '' || !ctype_digit($refills) || (int)$refills > 99) {
            $errors[] = xl('Refills must be a number from 0 to 99');
        }

        return $errors;
    }

    /**
     * /v1/rxrenewal/send-response. APPROVED and DENIED carry no medication.
     * APPROVED_WITH_CHANGES and REPLACE carry the dispensed medication with
     * the prescriber's values over it, under medicationResponse like a change
     * response does. UNCONFIRMED: the backend documents no medication on a
     * renewal response at all; this mirrors the change response until the
     * LogiCoy backend team says what the codes expect.
     *
     * @param array<string, string> $context from PrescribingService::requestContext()
     * @param array<string, mixed> $input validated by validate()
     * @param array<string, mixed> $cache from detailCache(); needed for a changed or replaced medication
     * @return array<string, mixed>
     */
    public static function renewalResponse(
        array $context,
        string $notificationUuid,
        array $input,
        array $cache = []
    ): array {
        $decision = strtoupper(trim((string)$input['decision']));

        $medication = ['rxRenewalResponseCodeTypeRef' => $decision];
        if ($decision === self::DENIED) {
            $medication['rxRenewalDeniedCodeTypeRef'] = strtoupper(trim((string)$input['deniedReason']));
        }
        if (in_array($decision, self::CHANGING_DECISIONS, true)) {
            $start = self::renewalMedication($cache);
            if ($start === []) {
                throw new InvalidArgumentException('Reviewed renewal names no medication to change');
            }
            $medication['medicationResponse'] = self::applyChanges(self::echoedMedication($start), $input);
        }
        $notes = trim((string)($input['notes'] ?? ''));
        if ($notes !== '') {
            $medication['notes'] = $notes;
        }

        return $context + [
            'erxNotificationPkUuid' => $notificationUuid,
            'rxRenewalResponseMedication' => $medication,
        ];
    }

    /**
     * /v1/pharmacy-initiated/new-rx/send-response. APPROVED has the backend
     * send the requested medication to the pharmacy as a new prescription;
     * DENIED carries an NCPDP reason. Nothing about the medication is sent
     * from here.
     *
     * @param array<string, string> $context from PrescribingService::requestContext()
     * @param array<string, mixed> $input validated by validate()
     * @param string $timeZone the server's, as the backend requires one
     * @return array<string, mixed>
     */
    public static function newRxResponse(
        array $context,
        string $notificationUuid,
        array $input,
        string $timeZone
    ): array {
        $decision = strtoupper(trim((string)$input['decision']));

        $info = ['responseCode' => $decision];
        if ($decision === self::DENIED) {
            $info['deniedResponseCode'] = strtoupper(trim((string)$input['deniedReason']));
        }
        $notes = trim((string)($input['notes'] ?? ''));
        if ($notes !== '') {
            $info['notesToPharmacist'] = mb_substr($notes, 0, self::NEW_RX_NOTES_MAX);
        }

        return $context + [
            'erxNotificationPkUuid' => $notificationUuid,
            'timeZone' => $timeZone,
            'rxPharmacyIntiNewRxInfo' => $info,
        ];
    }

    /**
     * /v1/rxchange/send-response. Every decision carries the medication it is
     * about, echoed from the pharmacy's request with its requestedMedicaitonId
     * (the backend's spelling).
     *
     * @param array<string, string> $context
     * @param array<string, mixed> $input validated by validate()
     * @param array<string, mixed> $cache from detailCache()
     * @return array<string, mixed>
     */
    public static function changeResponse(array $context, string $notificationUuid, array $input, array $cache): array
    {
        $requested = self::findRequested((array)($cache['requested'] ?? []), (string)$input['requestedMedicationId']);
        if ($requested === null) {
            throw new InvalidArgumentException('Requested medication not found in the reviewed request');
        }

        $medication = self::echoedMedication($requested);
        $medication['requestedMedicaitonId'] = (int)self::requestedMedicationId($requested);

        $decision = strtoupper(trim((string)$input['decision']));
        if (in_array($decision, self::CHANGING_DECISIONS, true)) {
            $medication = self::applyChanges($medication, $input);
        }

        $body = [
            'rxChangeResponseCodeTypeRef' => $decision,
            'medicationResponse' => $medication,
        ];
        $notes = trim((string)($input['notes'] ?? ''));
        if ($notes !== '') {
            $body['notes'] = $notes;
        }

        return $context + [
            'erxNotificationPkUuid' => $notificationUuid,
            'rxChangeResponseMedication' => $body,
        ];
    }

    /**
     * The pharmacy's medication in MedicationResponse shape: the response
     * fields it carries, and its coded drug. MedicationRequested and
     * MedicationDispensed call the coded drug drugCoded; MedicationResponse
     * calls the same structure ndc.
     *
     * @param array<string, mixed> $raw a medication as the backend sent it
     * @return array<string, mixed>
     */
    private static function echoedMedication(array $raw): array
    {
        $medication = [];
        if (isset($raw['drugCoded'])) {
            $medication['ndc'] = $raw['drugCoded'];
        }
        foreach (self::CHANGE_RESPONSE_FIELDS as $field) {
            if (array_key_exists($field, $raw) && $raw[$field] !== null) {
                $medication[$field] = $raw[$field];
            }
        }

        return $medication;
    }

    /**
     * The prescriber's values over the pharmacy's. The pharmacy's codes —
     * quantity qualifier and unit of measure — stay with the new value.
     *
     * @param array<string, mixed> $medication the echoed MedicationResponse
     * @param array<string, mixed> $input validated by validate()
     * @return array<string, mixed>
     */
    private static function applyChanges(array $medication, array $input): array
    {
        $ndc = preg_replace('/\D/', '', (string)($input['ndcNumber'] ?? '')) ?? '';
        if ($ndc !== '') {
            // The pharmacy's strength and DB codes belong to its drug; the
            // backend fills in the new drug's from the NDC.
            $medication['ndc'] = ['productCode' => ['code' => $ndc, 'qualifier' => 'ND']];
            $medication['drugDescription'] = trim((string)$input['drugName']);
        }

        $quantity = self::arr($medication['quantity'] ?? []);
        $quantity['value'] = trim((string)$input['quantity']);
        $medication['quantity'] = $quantity;

        $days = trim((string)($input['daysSupply'] ?? ''));
        if ($days !== '') {
            $medication['daysSupply'] = (int)$days;
        } else {
            unset($medication['daysSupply']);
        }

        $sig = self::arr($medication['sig'] ?? []);
        $sig['sigText'] = trim((string)$input['sig']);
        $medication['sig'] = $sig;

        $medication['numberOfRefills'] = trim((string)$input['refills']);

        return $medication;
    }

    /**
     * @param array<int, array<string, mixed>> $requested
     * @return array<string, mixed>|null
     */
    private static function findRequested(array $requested, string $id): ?array
    {
        if ($id === '' || !is_numeric($id)) {
            return null;
        }
        foreach ($requested as $medication) {
            if (is_array($medication) && self::requestedMedicationId($medication) === (int)$id) {
                return $medication;
            }
        }

        return null;
    }

    /**
     * The backend documents this as requestedMedicaitonId, misspelt; the
     * corrected spelling is accepted too in case it is ever fixed.
     *
     * @param array<string, mixed> $medication
     */
    private static function requestedMedicationId(array $medication): ?int
    {
        foreach (['requestedMedicaitonId', 'requestedMedicationId'] as $key) {
            if (isset($medication[$key]) && is_numeric($medication[$key])) {
                return (int)$medication[$key];
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $accepted
     * @return array<int, string>
     */
    private static function decisions(string $type, array $accepted): array
    {
        $supported = self::SUPPORTED_DECISIONS[$type];

        // No list means the backend did not say; offer everything and let it
        // validate. A list means only what it accepts.
        return $accepted === [] ? $supported : array_values(array_intersect($supported, $accepted));
    }

    /**
     * @return array<int, string>
     */
    private static function codes(mixed $codes): array
    {
        if (is_string($codes)) {
            $codes = explode(',', $codes);
        }

        $out = [];
        foreach ((array)$codes as $code) {
            $code = strtoupper(trim((string)(is_array($code) ? ($code['name'] ?? '') : $code)));
            if ($code !== '') {
                $out[] = $code;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, string>|null
     */
    private static function medication(array $m): ?array
    {
        if ($m === []) {
            return null;
        }

        $coded = self::arr($m['drugCoded'] ?? $m['ndc'] ?? []);

        return [
            'description' => self::str($m['drugDescription'] ?? ''),
            'ndc' => self::str(self::dig($coded, 'productCode', 'code')),
            'strength' => self::str(self::dig($coded, 'strength', 'strengthValue')),
            'quantity' => self::str(self::dig($m, 'quantity', 'value')),
            'daysSupply' => self::str($m['daysSupply'] ?? ''),
            'sig' => self::str(self::dig($m, 'sig', 'sigText')),
            'refills' => self::str($m['numberOfRefills'] ?? ''),
            'pharmacyRequestedRefills' => self::str($m['pharmacyRequestedRefills'] ?? ''),
            'writtenDate' => self::date(self::arr($m['writtenDate'] ?? [])),
            'lastFillDate' => self::date(self::arr($m['lastFillDate'] ?? [])),
            'substitutions' => self::str($m['substitutions'] ?? ''),
            'note' => self::str($m['note'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, string>
     */
    private static function patient(array $p): array
    {
        $address = self::arr($p['address'] ?? []);

        return [
            'name' => trim(self::str($p['firstName'] ?? '') . ' ' . self::str($p['lastName'] ?? '')),
            'firstName' => self::str($p['firstName'] ?? ''),
            'middleName' => self::str($p['middleName'] ?? ''),
            'lastName' => self::str($p['lastName'] ?? ''),
            'dob' => self::str($p['dateOfBirth'] ?? ''),
            'gender' => self::str($p['genderType'] ?? ''),
            'phone' => self::str($p['phone'] ?? ''),
            'email' => self::str($p['email'] ?? ''),
            'address' => implode(', ', array_filter([
                self::str($address['addressLine1'] ?? ''),
                self::str($address['addressLine2'] ?? ''),
                self::str($address['city'] ?? ''),
                self::str($address['stateProvince'] ?? ''),
                self::str($address['postalCode'] ?? $address['zipCode'] ?? ''),
            ])),
        ];
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, string>
     */
    private static function pharmacy(array $p): array
    {
        $address = self::arr($p['address'] ?? []);

        return [
            'name' => self::str($p['businessName'] ?? ''),
            'ncpdpId' => self::str(self::dig($p, 'identification', 'ncpdpid')),
            'npi' => self::str(self::dig($p, 'identification', 'npi')),
            'phone' => self::str(self::dig($p, 'communicationNumbers', 'primaryTelephone', 'number')),
            'address' => implode(', ', array_filter([
                self::str($address['addressLine1'] ?? ''),
                self::str($address['city'] ?? ''),
                self::str($address['stateProvince'] ?? ''),
                self::str($address['postalCode'] ?? $address['zipCode'] ?? ''),
            ])),
        ];
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, string>
     */
    private static function prescriber(array $p): array
    {
        $name = self::arr($p['name'] ?? []);

        return [
            'name' => trim(self::str($name['firstName'] ?? '') . ' ' . self::str($name['lastName'] ?? '')),
            'npi' => self::str($p['npi'] ?? self::dig($p, 'identification', 'npi')),
        ];
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function date(array $d): string
    {
        return self::str($d['date'] ?? $d['dateTime'] ?? '');
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function dig(array $node, string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return '';
            }
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
