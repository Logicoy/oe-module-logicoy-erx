<?php

/**
 * Registers this practice, its service location and its prescribers with the
 * LogiCoy backend, and stores the identifiers the backend returns.
 *
 * These uuids are minted by the backend during onboarding — there is nothing to
 * type in. Each step depends on the one before it.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Client\PayloadNormalizer;
use LogiCoy\ERx\Prescription\PrescribingService;
use OpenEMR\Common\Database\QueryUtils;

class ProvisioningService
{
    private BackendClient $backend;
    private CredentialStore $store;
    private ProviderProfileStore $profiles;

    public function __construct(
        BackendClient $backend,
        CredentialStore $store,
        ?ProviderProfileStore $profiles = null
    ) {
        $this->backend = $backend;
        $this->store = $store;
        $this->profiles = $profiles ?? new ProviderProfileStore();
    }

    /**
     * A provisioned identifier, or null when the step has not run yet.
     *
     * @param array<string, mixed> $credential
     */
    private function provisioned(array $credential, string $field): ?string
    {
        return CredentialInput::normalizeProvisionedUuid($credential[$field] ?? null);
    }

    /**
     * Step 1 — register the practice, from the OpenEMR facility record.
     *
     * @param array<string, mixed> $credential
     */
    public function registerPractice(int $facilityId, array $credential, string $clientRegistrationUuid): string
    {
        // A placeholder must not read as "already registered". Doing so
        // returned the placeholder as if it were a real identifier and skipped
        // the call to the backend altogether.
        $existing = $this->provisioned($credential, 'erx_practice_uuid');
        if ($existing !== null) {
            return $existing;
        }
        if ($clientRegistrationUuid === '') {
            throw new BackendException('Set the eRx client registration UUID under Globals first.');
        }

        $facility = $this->facility($facilityId);

        $response = $this->backend->post(BackendClient::ENDPOINT_PRACTICE_ADD, [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'clientRegistrationPKUuid' => $clientRegistrationUuid,
            'practice' => PayloadNormalizer::withoutBlanks([
                'name' => (string)($facility['name'] ?? ''),
                'phone' => PayloadNormalizer::phoneNumber($facility['phone'] ?? null, 'facility phone number'),
                'email' => (string)($facility['email'] ?? ''),
                'practiceType' => 'Clinic',
                'practiceAddress' => $this->address($facility),
            ]),
        ], $credential);

        $uuid = BackendClient::findValue($response, 'erxPracticePKUuid');
        if (empty($uuid)) {
            throw new BackendException('The backend did not return an erxPracticePKUuid');
        }

        $this->persist($credential, ['erx_practice_uuid' => $uuid]);

        return $uuid;
    }

    /**
     * Step 2 — register the service location under that practice.
     *
     * @param array<string, mixed> $credential
     */
    public function registerLocation(int $facilityId, array $credential): string
    {
        $existing = $this->provisioned($credential, 'erx_location_uuid');
        if ($existing !== null) {
            return $existing;
        }

        $practiceUuid = $this->provisioned($credential, 'erx_practice_uuid');
        if ($practiceUuid === null) {
            throw new BackendException('Register the practice before the service location.');
        }

        $facility = $this->facility($facilityId);

        $response = $this->backend->post(BackendClient::ENDPOINT_LOCATION_ADD, [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxPracticePKUuid' => $practiceUuid,
            'serviceLocation' => PayloadNormalizer::withoutBlanks([
                'name' => (string)($facility['name'] ?? ''),
                'phone' => PayloadNormalizer::phoneNumber($facility['phone'] ?? null, 'facility phone number'),
                'email' => (string)($facility['email'] ?? ''),
                // A facility with no fax on file must not send the key at all.
                'faxNum' => PayloadNormalizer::phoneNumber($facility['fax'] ?? null, 'facility fax number'),
                'practiceServiceLocationAddress' => $this->address($facility),
            ]),
        ], $credential);

        $uuid = BackendClient::findValue($response, 'erxPracticeServiceLocPKUuid');
        if (empty($uuid)) {
            throw new BackendException('The backend did not return an erxPracticeServiceLocPKUuid');
        }

        $this->persist($credential, ['erx_location_uuid' => $uuid]);

        return $uuid;
    }

    /**
     * Step 3 — register a prescriber.
     *
     * A prescriber has one identifier under two names: /v1/clinician/add returns
     * it as erxClinicianUserPKUuid, which is what the send and search endpoints
     * want, while the draft endpoints ask for the same value as
     * erxClinicianUserUuid. Storing only what came back left the draft columns
     * empty and every draft call was rejected with "Clinician User UUID is
     * mandatory", so whichever name the response uses is stored under both.
     *
     * @param array<string, mixed> $credential
     * @return array{erx_clinician_user_uuid: string, erx_clinician_user_pk_uuid: string}
     */
    public function registerClinician(int $providerUserId, array $credential): array
    {
        $practiceUuid = $this->provisioned($credential, 'erx_practice_uuid');
        $locationUuid = $this->provisioned($credential, 'erx_location_uuid');
        if ($practiceUuid === null || $locationUuid === null) {
            throw new BackendException('Register the practice and service location first.');
        }

        // Registering a prescriber who is already registered is not a harmless
        // repeat: /v1/clinician/add is not idempotent, and a second add for an
        // NPI the practice already holds leaves the record half written. The
        // symptoms point nowhere near the cause — /v1/clinician/get still
        // returns the prescriber, while /v1/clinician/update answers
        // "Clinician Record do not exist", a further add fails CLIN_ERC_3059,
        // and every medication search for that practice fails MED_ERC_5067
        // while pharmacy search and the draft endpoints carry on working.
        //
        // The practice and service location steps have always returned early;
        // this one did not, so re-pressing Register prescriber corrupted the
        // registration rather than doing nothing.
        $existing = $this->existingClinician(
            (int)($credential['facility_id'] ?? 0),
            $providerUserId
        );
        if ($existing !== null) {
            return $existing;
        }

        $user = $this->provider($providerUserId);
        if ($user === null) {
            throw new BackendException('OpenEMR user ' . $providerUserId . ' was not found.');
        }
        if (empty($user['npi'])) {
            throw new BackendException('That user has no NPI on file. Add one under Administration, Users.');
        }

        // Collected on the OpenEMR user screens; see ProviderProfileForm.
        $profile = $this->profiles->get($providerUserId);
        $epcs = ($profile['prescribing_type'] ?? '') === ProviderProfileInput::TYPE_EPCS;

        // Everything /v1/clinician/add insists on, checked in one pass so the
        // admin gets a single list rather than a 400 per missing field.
        $this->requireClinicianFields($user, $profile, $epcs);

        $details = [
            'firstName' => (string)($user['fname'] ?? ''),
            'middleName' => (string)($user['mname'] ?? ''),
            'lastName' => (string)($user['lname'] ?? ''),
            'npi' => (string)$user['npi'],
            'email' => trim((string)($user['email'] ?? '')),
            'clinicianRoleTypeRef' => 'PRESCRIBER',
            // EPCS means the backend may route controlled substances for this
            // prescriber. Signing still happens there, never here.
            'clinicianPrescribingTypeRef' => $epcs ? 'EPCS' : 'NON_EPCS',
        ] + $this->editableDetails($user, $profile, $epcs);

        $details += $this->identityDetails($providerUserId, $user, $profile, $epcs);
        $details = PayloadNormalizer::withoutBlanks($details);

        $response = $this->backend->post(BackendClient::ENDPOINT_CLINICIAN_ADD, [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxPracticePKUuid' => $practiceUuid,
            'erxPracticeServiceLocPKUuid' => $locationUuid,
            'clinicianUserDetails' => $details,
        ], $credential);

        $userUuid = BackendClient::findValue($response, 'erxClinicianUserUuid');
        $pkUuid = BackendClient::findValue($response, 'erxClinicianUserPKUuid');

        $clinicianUuid = (string)($pkUuid ?? $userUuid ?? '');
        if ($clinicianUuid === '') {
            throw new BackendException('The backend did not return a clinician identifier');
        }

        return [
            'erx_clinician_user_uuid' => (string)($userUuid ?? $clinicianUuid),
            'erx_clinician_user_pk_uuid' => $clinicianUuid,
        ];
    }

    /**
     * Step 3, later — push a registered prescriber's changed details to the
     * backend.
     *
     * /v1/clinician/update takes the DEA details, service levels, contact
     * details and address of a clinician it already holds. It does not take
     * the name or the prescribing type: a prescriber registered as NON_EPCS
     * cannot be turned into EPCS from here, only by LogiCoy. What this does
     * for a prescriber whose local type is EPCS is send the DEA details and
     * the CONTROLLED_SUBSTANCE service level, which the backend applies only
     * to a clinician it holds as EPCS.
     *
     * Takes the prescriber's own credential row, which carries the clinician
     * identifier. Nothing is persisted locally, so a repeat is harmless.
     *
     * @param array<string, mixed> $credential the provider scoped row
     * @return array{clinician_uuid: string, epcs: bool, service_levels: array<int, string>}
     */
    public function updateClinician(int $providerUserId, array $credential): array
    {
        $practiceUuid = $this->provisioned($credential, 'erx_practice_uuid');
        $locationUuid = $this->provisioned($credential, 'erx_location_uuid');
        if ($practiceUuid === null || $locationUuid === null) {
            throw new BackendException('Register the practice and service location first.');
        }

        $clinician = self::clinicianIdentifiers($credential);
        if ($clinician === null) {
            throw new BackendException('Register the prescriber with LogiCoy before updating them.');
        }

        $user = $this->provider($providerUserId);
        if ($user === null) {
            throw new BackendException('OpenEMR user ' . $providerUserId . ' was not found.');
        }

        $profile = $this->profiles->get($providerUserId);
        $epcs = ($profile['prescribing_type'] ?? '') === ProviderProfileInput::TYPE_EPCS;

        // The same completeness rules as registration, so the backend is not
        // asked to apply an incomplete EPCS profile.
        $this->requireClinicianFields($user, $profile, $epcs);
        $identity = $this->identityDetails($providerUserId, $user, $profile, $epcs);
        // Not in the update model; the backend keeps what it holds.
        unset($identity['ssnLast4Digits']);

        $details = PayloadNormalizer::withoutBlanks(
            ['erxClinicianUserPKUuid' => $clinician['erx_clinician_user_pk_uuid']]
            + $this->editableDetails($user, $profile, $epcs)
            + $identity
        );

        $response = $this->backend->post(BackendClient::ENDPOINT_CLINICIAN_UPDATE, [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxPracticePKUuid' => $practiceUuid,
            'erxPracticeServiceLocPKUuid' => $locationUuid,
            'clinicianUserDetails' => $details,
        ], $credential);

        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            throw new BackendException('LogiCoy did not accept the update: ' . $failure);
        }

        return [
            'clinician_uuid' => $clinician['erx_clinician_user_pk_uuid'],
            'epcs' => $epcs,
            'service_levels' => $details['serviceLevel'],
        ];
    }

    /**
     * Step 3, later — turn a prescriber LogiCoy holds as NON_EPCS into an
     * EPCS prescriber.
     *
     * The backend validates the DEA, assigns EPCS roles, registers the
     * prescriber with Surescripts at controlled substance service levels, and
     * starts ID.me proofing in the same call: the link it returns is opened
     * in a browser by the prescriber, and ID.me sends them back to
     * $returnUrl. Irreversible once completed; the backend refuses a
     * prescriber it already holds as EPCS.
     *
     * The local profile must already be EPCS with the identity fields
     * complete, so what LogiCoy is told matches what this install holds.
     *
     * @param array<string, mixed> $credential the provider scoped row
     * @return array{link: string, description: string}
     */
    public function upgradeToEpcs(int $providerUserId, array $credential, string $idmeEmail, string $returnUrl): array
    {
        $practiceUuid = $this->provisioned($credential, 'erx_practice_uuid');
        $locationUuid = $this->provisioned($credential, 'erx_location_uuid');
        if ($practiceUuid === null || $locationUuid === null) {
            throw new BackendException('Register the practice and service location first.');
        }
        $clinician = self::clinicianIdentifiers($credential);
        if ($clinician === null) {
            throw new BackendException('Register the prescriber with LogiCoy before upgrading them to EPCS.');
        }

        $idmeEmail = trim($idmeEmail);
        if (filter_var($idmeEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new BackendException('Enter the email address of the prescriber\'s ID.me account.');
        }

        $user = $this->provider($providerUserId);
        if ($user === null) {
            throw new BackendException('OpenEMR user ' . $providerUserId . ' was not found.');
        }
        $profile = $this->profiles->get($providerUserId);
        if (($profile['prescribing_type'] ?? '') !== ProviderProfileInput::TYPE_EPCS) {
            throw new BackendException(
                'Set the prescriber\'s eRx prescribing type to EPCS under Administration, Users first.'
            );
        }
        // Refuses with the full list of what is missing before any call.
        $identity = $this->identityDetails($providerUserId, $user, $profile, true);

        $response = $this->backend->post(BackendClient::ENDPOINT_CLINICIAN_UPGRADE_EPCS, [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxClinicianUserPKUuid' => $clinician['erx_clinician_user_pk_uuid'],
            'erxPracticePKUuid' => $practiceUuid,
            'erxPracticeServiceLocPKUuid' => $locationUuid,
            'dea' => $identity['dea'],
            'deaState' => $identity['deaState'],
            'deaExpirationDate' => $identity['deaExpirationDate'],
            'ssnLast4Digits' => $identity['ssnLast4Digits'],
            'idmePrimaryEmailId' => $idmeEmail,
            'clientRedirectionUrl' => $returnUrl,
        ], $credential);

        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            throw new BackendException('LogiCoy did not accept the EPCS upgrade: ' . $failure);
        }

        // Only an https link is ever handed to a browser; anything else in
        // that field is a backend fault, not a place to send a user.
        $link = trim((string)($response['idmeVerificationLink'] ?? ''));
        $parts = parse_url($link);
        $safe = is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && (string)($parts['host'] ?? '') !== '';
        if ($link !== '' && !$safe) {
            throw new BackendException('The LogiCoy backend returned an unusable ID.me link.');
        }

        return [
            'link' => $link,
            'description' => substr(trim((string)($response['description'] ?? '')), 0, 500),
        ];
    }

    /**
     * Where the ERX -> EPCS upgrade stands at LogiCoy.
     *
     * is_epcs is true only on an explicit boolean true from the backend;
     * the status words are passed through for display, never trusted.
     *
     * @param array<string, mixed> $credential the provider scoped row
     * @return array{request_status: string, verification_status: string, message: string,
     *     is_epcs: bool, prescribing_type: string}
     */
    public function upgradeToEpcsStatus(array $credential): array
    {
        $practiceUuid = $this->provisioned($credential, 'erx_practice_uuid');
        $clinician = self::clinicianIdentifiers($credential);
        if ($practiceUuid === null || $clinician === null) {
            throw new BackendException('Register the prescriber with LogiCoy first.');
        }

        $response = $this->backend->post(BackendClient::ENDPOINT_CLINICIAN_UPGRADE_EPCS_STATUS, [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxClinicianUserPKUuid' => $clinician['erx_clinician_user_pk_uuid'],
            'erxPracticePKUuid' => $practiceUuid,
        ], $credential);

        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            throw new BackendException('LogiCoy could not report the EPCS upgrade status: ' . $failure);
        }

        return [
            'request_status' => strtoupper(trim((string)($response['requestStatus'] ?? ''))),
            'verification_status' => strtoupper(trim((string)($response['verificationStatus'] ?? ''))),
            'message' => substr(trim((string)($response['message'] ?? '')), 0, 500),
            'is_epcs' => ($response['isEpcsPrescriber'] ?? null) === true,
            'prescribing_type' => strtoupper(trim((string)($response['clinicianPrescribingTypeRef'] ?? ''))),
        ];
    }

    /**
     * The clinician fields both add and update accept.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function editableDetails(array $user, array $profile, bool $epcs): array
    {
        return [
            'stateLicenseNumber' => (string)($user['state_license_number'] ?? ''),
            'phone' => PayloadNormalizer::phoneNumber($this->userPhone($user), 'prescriber phone number'),
            'gender' => (string)($profile['gender'] ?? ''),
            'address' => $this->address($this->userAddress($user)),
            'serviceLevel' => self::serviceLevels($epcs),
        ];
    }

    /**
     * The transaction types the backend may run for this prescriber.
     *
     * NEW_RX_REQUEST lets the pharmacy send new prescription requests to the
     * prescriber and NEW_RX_RESPONSE_DENIED lets the prescriber deny them;
     * every prescriber carries both, whether registered or updated.
     *
     * CONTROLLED_SUBSTANCE is the level the spec describes as "supports
     * Electronic Prescribing of Controlled Substances", so an EPCS prescriber
     * carries it and an ERX prescriber does not. Taken from the spec's
     * description, not yet confirmed against a live EPCS send.
     *
     * @return array<int, string>
     */
    public static function serviceLevels(bool $epcs): array
    {
        // RX_FILL_INDICATOR_CHANGE lets the prescriber set, after a send,
        // which dispensing events the pharmacy reports back; without it the
        // backend refuses the fill indicator call the send path queues.
        $levels = [
            'NEW', 'CANCEL', 'CHANGE', 'REFILL', 'RX_FILL', 'RX_FILL_INDICATOR_CHANGE',
            'NEW_RX_REQUEST', 'NEW_RX_RESPONSE_DENIED',
        ];
        if ($epcs) {
            $levels[] = 'CONTROLLED_SUBSTANCE';
        }

        return $levels;
    }

    /**
     * The clinician identifiers already held for this prescriber at this
     * facility, or null when they have never been registered.
     *
     * Read from the provider scoped row rather than the practice credential
     * passed in: registerClinician() is handed the practice row, which never
     * carries a clinician of its own.
     *
     * @return array{erx_clinician_user_uuid: string, erx_clinician_user_pk_uuid: string}|null
     */
    private function existingClinician(int $facilityId, int $providerUserId): ?array
    {
        if ($facilityId <= 0 || $providerUserId <= 0) {
            return null;
        }

        $rows = QueryUtils::fetchRecords(
            'SELECT erx_clinician_user_uuid, erx_clinician_user_pk_uuid FROM ' . CredentialStore::TABLE
            . ' WHERE scope_type = ? AND facility_id = ? AND provider_user_id = ? AND active = 1 LIMIT 1',
            [CredentialStore::SCOPE_PROVIDER, $facilityId, $providerUserId]
        );

        return self::clinicianIdentifiers($rows[0] ?? []);
    }

    /**
     * The identifier pair a stored credential row represents, or null when it
     * carries neither.
     *
     * A provider row exists for reasons other than registration — it is also
     * where a prescriber's own client id lives — so only a row that actually
     * holds an identifier counts as already registered.
     *
     * @param array<string, mixed> $row
     * @return array{erx_clinician_user_uuid: string, erx_clinician_user_pk_uuid: string}|null
     */
    public static function clinicianIdentifiers(array $row): ?array
    {
        $pkUuid = CredentialInput::normalizeProvisionedUuid($row['erx_clinician_user_pk_uuid'] ?? null);
        $userUuid = CredentialInput::normalizeProvisionedUuid($row['erx_clinician_user_uuid'] ?? null);

        if ($pkUuid === null && $userUuid === null) {
            return null;
        }

        return [
            'erx_clinician_user_uuid' => (string)($userUuid ?? $pkUuid),
            'erx_clinician_user_pk_uuid' => (string)($pkUuid ?? $userUuid),
        ];
    }

    /**
     * Everything /v1/clinician/add rejects a registration for, gathered into
     * one message.
     *
     * The mandatory set was read off the backend's own validationErrorInfo,
     * not guessed: phone, email, gender, address (all five parts) and a date of
     * birth of at least 18 years. Sending the call without them costs a round
     * trip and returns a list the admin has to translate back into OpenEMR
     * screens, so it is checked here instead.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $profile
     */
    private function requireClinicianFields(array $user, array $profile, bool $epcs): void
    {
        $missing = [];

        if (trim((string)$this->userPhone($user)) === '') {
            $missing[] = 'phone number';
        }
        if (trim((string)($user['email'] ?? '')) === '') {
            $missing[] = 'email address';
        }
        if (empty($profile['gender'])) {
            $missing[] = 'gender';
        }

        foreach (
            [
                'street' => 'street address',
                'city' => 'city',
                'state' => 'state',
                'zip' => 'ZIP code',
            ] as $column => $label
        ) {
            if (trim((string)($user[$column] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        // Mandatory for every prescriber, not only EPCS ones — the identity
        // proofing block below adds to this, it does not replace it.
        $dob = (string)($profile['dob'] ?? '');
        if ($dob === '') {
            $missing[] = 'date of birth';
        }

        if ($missing !== []) {
            throw new BackendException(
                'That prescriber is missing ' . implode(', ', $missing)
                . '. Add it under Administration, Users.'
            );
        }

        if (!$this->isAtLeast18($dob)) {
            throw new BackendException(
                'That prescriber\'s date of birth makes them under 18. '
                . 'The backend refuses the registration. Correct it under Administration, Users.'
            );
        }
    }

    /**
     * The work number first: OpenEMR's address book fills phonew1 for a
     * practice contact, and `phone` is the home number.
     *
     * @param array<string, mixed> $user
     */
    private function userPhone(array $user): string
    {
        foreach (['phonew1', 'phone', 'phonecell'] as $column) {
            $value = trim((string)($user[$column] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * `users` names its address columns differently from `facility`; map them
     * so one address builder serves both.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function userAddress(array $user): array
    {
        return [
            'street' => $user['street'] ?? '',
            'city' => $user['city'] ?? '',
            'state' => $user['state'] ?? '',
            'postal_code' => $user['zip'] ?? '',
            'country_code' => $user['country_code'] ?? '',
        ];
    }

    private function isAtLeast18(string $dob): bool
    {
        $born = \DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        if ($born === false) {
            return false;
        }

        return $born->diff(new \DateTimeImmutable('today'))->y >= 18;
    }

    /**
     * DEA registration and the SSN fragment, on top of the fields every
     * clinician needs. The backend wants all of them to identity proof an EPCS
     * prescriber, so an incomplete EPCS profile is refused here rather than
     * failing halfway through onboarding. A plain eRx prescriber sends whatever
     * is on file and nothing more.
     *
     * Date of birth is not listed here: requireClinicianFields() already makes
     * it mandatory for every prescriber.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $profile
     * @return array<string, string>
     */
    private function identityDetails(int $providerUserId, array $user, array $profile, bool $epcs): array
    {
        $deaNumber = trim((string)($user['federaldrugid'] ?? ''));
        $ssnLast4 = $this->profiles->getSsnLast4($providerUserId);

        if ($epcs) {
            $missing = [];
            if ($deaNumber === '') {
                $missing[] = 'DEA number';
            }
            if (empty($profile['dea_expiration_date'])) {
                $missing[] = 'DEA expiration date';
            }
            if (empty($profile['dea_state'])) {
                $missing[] = 'DEA state';
            }
            if ($ssnLast4 === null) {
                $missing[] = 'SSN last 4';
            }

            if ($missing !== []) {
                throw new BackendException(
                    'That prescriber is set to EPCS but is missing ' . implode(', ', $missing)
                    . '. Add it under Administration, Users.'
                );
            }
        }

        $details = [];
        foreach (
            [
                'dateOfBirth' => (string)($profile['dob'] ?? ''),
                // The schema names it "dea" (ClinicianUserAddModel and
                // ClinicianUserUpdateModel alike). It went out as deaNumber
                // before, which the backend silently dropped.
                'dea' => $deaNumber,
                'deaExpirationDate' => (string)($profile['dea_expiration_date'] ?? ''),
                'deaState' => (string)($profile['dea_state'] ?? ''),
                'ssnLast4Digits' => (string)($ssnLast4 ?? ''),
            ] as $field => $value
        ) {
            // Sending an empty string reads as "clear this" at the backend.
            if ($value !== '') {
                $details[$field] = $value;
            }
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $credential
     * @param array<string, string> $fields
     */
    private function persist(array $credential, array $fields): void
    {
        foreach ($fields as $column => $value) {
            QueryUtils::sqlStatementThrowException(
                'UPDATE ' . CredentialStore::TABLE . ' SET ' . $column . ' = ?, updated_at = NOW() WHERE id = ?',
                [$value, (int)$credential['id']]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function facility(int $facilityId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT name, phone, fax, email, street, city, state, postal_code, country_code
             FROM facility WHERE id = ? LIMIT 1',
            [$facilityId]
        );

        return $rows[0] ?? [];
    }

    /**
     * The OpenEMR user record a clinician payload is built from. Protected so
     * a test can supply one without a database.
     *
     * @return array<string, mixed>|null
     */
    protected function provider(int $userId): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT fname, mname, lname, npi, state_license_number, federaldrugid,
                    phone, phonew1, phonecell, email, street, city, state, zip, country_code
             FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function address(array $row): array
    {
        return PayloadNormalizer::withoutBlanks([
            'addressLine1' => (string)($row['street'] ?? ''),
            'city' => (string)($row['city'] ?? ''),
            'stateProvince' => (string)($row['state'] ?? ''),
            'postalCode' => explode('-', (string)($row['postal_code'] ?? ''))[0],
            'country' => (string)($row['country_code'] ?? '') ?: 'US',
        ]);
    }
}
