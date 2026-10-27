<?php

/**
 * Loads a LogiCoy credential for a practice or a prescriber.
 *
 * The signing key is read from a file, never from the command line — arguments
 * are visible to every user on the box via `ps`. It is encrypted with OpenEMR's
 * CryptoGen before storage and is never echoed back.
 *
 *   php scripts/load_credential.php --list
 *   php scripts/load_credential.php \
 *       --facility=1 \
 *       --client-id=ce9f5dd7-00c2-42a4-a6b8-7b3578aae436 \
 *       --key=/secure/path/partner_private.key \
 *       --practice-uuid=8d1d7c62-e895-4647-b4cd-8d56be850369 \
 *       --location-uuid=16373c5f-5a6e-4df3-b622-78d6bac89210 \
 *       --clinician-uuid=8398d9e1-b463-4cf4-911b-a9e9a53b4b52 \
 *       --clinician-pk-uuid=0c398278-14ea-4ee2-a9d9-fe3567431d6f
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/cli_bootstrap.php';

use LogiCoy\ERx\Config\CredentialInput;
use LogiCoy\ERx\Config\CredentialStore;

$usage = static function (): void {
    echo <<<TXT
    Load a LogiCoy eRx credential.

      --list                        show loaded credentials (never shows keys)
      --deactivate=ID               deactivate a credential

      --facility=N                  OpenEMR facility id            (required)
      --client-id=UUID              LogiCoy client id              (required)
      --practice-uuid=UUID          eRx practice uuid              (required)
      --key=PATH                    PEM private key file; omit on update to
                                    keep the stored key
      --scope=practice|provider     defaults to practice
      --provider=N                  OpenEMR user id, provider scope only
      --kid=STRING                  JWT kid header, if LogiCoy issued one
      --client-registration-uuid=UUID
      --location-uuid=UUID          eRx practice service location uuid
      --clinician-uuid=UUID         used by draft add / list / update
      --clinician-pk-uuid=UUID      used by RxNew / RxCancel
      --inactive                    store the credential deactivated
      --site=NAME                   OpenEMR site, defaults to "default"

    The key is read from a file rather than an argument because command line
    arguments are visible to other users through ps.

    TXT;
};

$listCredentials = static function (CredentialStore $store): void {
    $rows = $store->listCredentials();

    if ($rows === []) {
        echo "No credentials loaded.\n";
        return;
    }

    printf("%-4s %-9s %-9s %-9s %-38s %-6s %s\n", 'ID', 'SCOPE', 'FACILITY', 'PROVIDER', 'CLIENT ID', 'KEY', 'ACTIVE');
    foreach ($rows as $row) {
        printf(
            "%-4s %-9s %-9s %-9s %-38s %-6s %s\n",
            $row['id'],
            $row['scope_type'],
            $row['facility_id'],
            ((int)$row['provider_user_id'] === CredentialStore::NO_PROVIDER) ? '-' : $row['provider_user_id'],
            $row['client_id'],
            $row['has_signing_key'] ? 'yes' : 'NO',
            $row['active'] ? 'yes' : 'no'
        );
    }
};

$options = getopt('h', [
    'help',
    'list',
    'deactivate::',
    'facility::',
    'scope::',
    'provider::',
    'client-id::',
    'key::',
    'kid::',
    'client-registration-uuid::',
    'practice-uuid::',
    'location-uuid::',
    'clinician-uuid::',
    'clinician-pk-uuid::',
    'inactive',
]);

if (isset($options['h']) || isset($options['help']) || $options === []) {
    $usage();
    exit(0);
}

$store = new CredentialStore();

if (isset($options['list'])) {
    $listCredentials($store);
    exit(0);
}

if (isset($options['deactivate'])) {
    $id = (int)$options['deactivate'];
    if ($id <= 0) {
        fwrite(STDERR, "--deactivate needs a credential id (see --list)\n");
        exit(1);
    }
    $store->deactivate($id);
    echo "Credential {$id} deactivated.\n";
    exit(0);
}

$signingKey = '';
if (!empty($options['key'])) {
    $keyPath = (string)$options['key'];
    if (!is_readable($keyPath)) {
        fwrite(STDERR, "Cannot read the key file: {$keyPath}\n");
        exit(1);
    }
    $signingKey = (string)file_get_contents($keyPath);
}

$input = new CredentialInput([
    'scope_type' => $options['scope'] ?? CredentialStore::SCOPE_PRACTICE,
    'facility_id' => $options['facility'] ?? 0,
    'provider_user_id' => $options['provider'] ?? null,
    'client_id' => $options['client-id'] ?? '',
    'signing_key' => $signingKey,
    'jwt_kid' => $options['kid'] ?? null,
    'erx_client_registration_uuid' => $options['client-registration-uuid'] ?? null,
    'erx_practice_uuid' => $options['practice-uuid'] ?? '',
    'erx_location_uuid' => $options['location-uuid'] ?? null,
    'erx_clinician_user_uuid' => $options['clinician-uuid'] ?? null,
    'erx_clinician_user_pk_uuid' => $options['clinician-pk-uuid'] ?? null,
    'active' => !isset($options['inactive']),
]);

if (!$input->isValid()) {
    fwrite(STDERR, "Credential rejected:\n");
    foreach ($input->getErrors() as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

try {
    $store->save($input->toArray());
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to save the credential: ' . $e->getMessage() . "\n");
    exit(1);
}

$data = $input->toArray();
echo "Credential saved.\n";
echo '  scope       ' . $data['scope_type'] . "\n";
echo '  facility    ' . $data['facility_id'] . "\n";
if ((int)$data['provider_user_id'] !== CredentialStore::NO_PROVIDER) {
    echo '  provider    ' . $data['provider_user_id'] . "\n";
}
echo '  client id   ' . $data['client_id'] . "\n";
echo '  signing key ' . ($signingKey === '' ? 'unchanged' : 'stored, encrypted') . "\n";

exit(0);
