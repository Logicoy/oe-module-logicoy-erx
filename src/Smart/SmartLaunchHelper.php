<?php

/**
 * Builds the SMART EHR launch into the LogiCoy prescribing UI.
 *
 * OpenEMR mints the opaque launch token and owns the authorization flow; this
 * helper only assembles the launch URL and the context that goes with it.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Smart;

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\FHIR\Config\ServerConfig;
use OpenEMR\FHIR\SMART\SMARTLaunchToken;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\PatientService;

class SmartLaunchHelper
{
    /**
     * The scopes this module's SMART client must be registered with.
     *
     * `launch` is what makes OpenEMR surface the app in patient context;
     * without it the app is invisible in the chart. `launch/encounter` is
     * deliberately absent — it is not in scopes_supported on 7.0.x even though
     * context-ehr-encounter is advertised, so the encounter is read from the
     * token response opportunistically instead.
     */
    public const REQUIRED_SCOPES = [
        'openid',
        'fhirUser',
        'launch',
        'launch/patient',
        'user/Patient.rs',
        'user/Observation.rs',
        'user/Encounter.rs',
    ];

    private PatientService $patientService;

    public function __construct(?PatientService $patientService = null)
    {
        $this->patientService = $patientService ?? new PatientService();
    }

    /**
     * The FHIR issuer, which doubles as the audience in an EHR launch.
     */
    public function getIssuer(): string
    {
        return (new ServerConfig())->getFhirUrl();
    }

    /**
     * Build the launch query string for a patient.
     */
    public function buildLaunchParams(int $pid, ?int $encounterId = null): string
    {
        UuidRegistry::createMissingUuidsForTables(['patient_data']);
        $puuid = UuidRegistry::uuidToString($this->patientService->getUuid($pid));

        $euuid = null;
        if (!empty($encounterId)) {
            $euuid = UuidRegistry::uuidToString(
                EncounterService::getUuidById($encounterId, 'form_encounter', 'encounter')
            );
        }

        $token = new SMARTLaunchToken($puuid, $euuid);
        $token->setIntent(SMARTLaunchToken::INTENT_PATIENT_DEMOGRAPHICS_DIALOG);

        $issuer = $this->getIssuer();

        return '?launch=' . urlencode($token->serialize())
            . '&iss=' . urlencode($issuer)
            . '&aud=' . urlencode($issuer);
    }

    /**
     * The registration body for the LogiCoy SMART client.
     *
     * OpenEMR 7.0.x rejects a missing jwks with an "expecting array, got
     * string" error, so an empty key set is always sent. There is no advertised
     * registration_endpoint either, hence the hardcoded path in the caller.
     *
     * @return array<string, mixed>
     */
    public static function buildRegistrationRequest(string $appBaseUrl, string $clientName): array
    {
        $base = rtrim($appBaseUrl, '/');

        return [
            'application_type' => 'private',
            'client_name' => $clientName,
            'redirect_uris' => [$base . '/callback'],
            'post_logout_redirect_uris' => [$base . '/logout'],
            'initiate_login_uri' => $base . '/launch',
            // client_secret_post is not supported by this OpenEMR.
            'token_endpoint_auth_method' => 'client_secret_basic',
            'jwks' => ['keys' => []],
            'scope' => implode(' ', self::REQUIRED_SCOPES),
        ];
    }

    /**
     * scopes_supported comes back as a nested array on this OpenEMR version, so
     * flatten defensively rather than typing it as a flat list.
     *
     * @param mixed $scopesSupported
     * @return array<int, string>
     */
    public static function flattenSupportedScopes($scopesSupported): array
    {
        if (!is_array($scopesSupported)) {
            return [];
        }

        $flat = [];
        array_walk_recursive($scopesSupported, static function ($scope) use (&$flat): void {
            if (is_string($scope) && $scope !== '') {
                $flat[] = $scope;
            }
        });

        return array_values(array_unique($flat));
    }
}
