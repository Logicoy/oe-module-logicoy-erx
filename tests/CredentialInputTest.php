<?php

/**
 * Validation rules for loading a credential. No database, no real keys — the
 * RSA key here is generated in-process and thrown away.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Config\CredentialInput;
use LogiCoy\ERx\Config\CredentialStore;
use PHPUnit\Framework\TestCase;

class CredentialInputTest extends TestCase
{
    private static ?string $rsaKey = null;
    private static ?string $ecKey = null;

    private static function rsaKey(): string
    {
        if (self::$rsaKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            self::$rsaKey = $pem;
        }

        return self::$rsaKey;
    }

    private static function ecKey(): string
    {
        if (self::$ecKey === null) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            openssl_pkey_export($key, $pem);
            self::$ecKey = $pem;
        }

        return self::$ecKey;
    }

    /** @return array<string, mixed> */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'scope_type' => 'practice',
            'facility_id' => 1,
            'client_id' => 'client-0001',
            'signing_key' => self::rsaKey(),
            'erx_practice_uuid' => 'practice-uuid-0001',
        ], $overrides);
    }

    public function testAcceptsACompletePracticeCredential(): void
    {
        $input = new CredentialInput($this->validInput());

        $this->assertTrue($input->isValid(), implode('; ', $input->getErrors()));
        $this->assertSame(1, $input->toArray()['facility_id']);
        $this->assertSame(CredentialStore::NO_PROVIDER, $input->toArray()['provider_user_id']);
        $this->assertSame(1, $input->toArray()['active']);
    }

    public function testRequiresFacilityAndClientId(): void
    {
        $input = new CredentialInput(['scope_type' => 'practice']);

        $this->assertFalse($input->isValid());
        $errors = implode('; ', $input->getErrors());
        $this->assertStringContainsString('facility', $errors);
        $this->assertStringContainsString('client id', $errors);
    }

    /**
     * A credential is saved before onboarding runs, so the backend minted
     * identifiers cannot be required up front.
     */
    public function testSavesWithoutTheProvisionedUuids(): void
    {
        $input = new CredentialInput($this->validInput(['erx_practice_uuid' => null]));

        $this->assertTrue($input->isValid(), implode('; ', $input->getErrors()));
        $this->assertNull($input->toArray()['erx_practice_uuid']);
    }

    /**
     * 'pending' was written by earlier versions and made provisioning skip the
     * backend call, reporting the placeholder back as a real identifier.
     */
    public function testPlaceholderUuidNormalizesToNull(): void
    {
        $input = new CredentialInput($this->validInput(['erx_practice_uuid' => 'pending']));

        $this->assertTrue($input->isValid(), implode('; ', $input->getErrors()));
        $this->assertNull($input->toArray()['erx_practice_uuid']);
        $this->assertNull(CredentialInput::normalizeProvisionedUuid('PENDING'));
        $this->assertSame('real-uuid', CredentialInput::normalizeProvisionedUuid('  real-uuid  '));
    }

    public function testProviderScopeRequiresAProviderId(): void
    {
        $input = new CredentialInput($this->validInput(['scope_type' => 'provider']));

        $this->assertFalse($input->isValid());
        $this->assertStringContainsString('provider user id', implode('; ', $input->getErrors()));
    }

    public function testPracticeScopeRejectsAProviderId(): void
    {
        // Otherwise the row silently collides with a provider credential on the
        // unique key and the wrong one wins.
        $input = new CredentialInput($this->validInput(['provider_user_id' => 7]));

        $this->assertFalse($input->isValid());
        $this->assertStringContainsString('must not carry a provider user id', implode('; ', $input->getErrors()));
    }

    public function testProviderScopedCredentialIsAccepted(): void
    {
        $input = new CredentialInput($this->validInput([
            'scope_type' => 'provider',
            'provider_user_id' => 7,
        ]));

        $this->assertTrue($input->isValid(), implode('; ', $input->getErrors()));
        $this->assertSame(7, $input->toArray()['provider_user_id']);
    }

    public function testRejectsAnUnknownScope(): void
    {
        $input = new CredentialInput($this->validInput(['scope_type' => 'clinic']));

        $this->assertFalse($input->isValid());
    }

    public function testRejectsGarbageMasqueradingAsAKey(): void
    {
        $input = new CredentialInput($this->validInput(['signing_key' => 'not a key at all']));

        $this->assertFalse($input->isValid());
        $this->assertStringContainsString('private key', implode('; ', $input->getErrors()));
    }

    public function testRejectsANonRsaKey(): void
    {
        // The gateway signs with RS256; an EC key would fail at signing time.
        $input = new CredentialInput($this->validInput(['signing_key' => self::ecKey()]));

        $this->assertFalse($input->isValid());
    }

    public function testAnEmptyKeyIsAllowedSoUpdatesCanKeepTheStoredOne(): void
    {
        $input = new CredentialInput($this->validInput(['signing_key' => '']));

        $this->assertTrue($input->isValid(), implode('; ', $input->getErrors()));
        $this->assertSame('', $input->toArray()['signing_key']);
    }

    public function testBlankOptionalIdentifiersBecomeNull(): void
    {
        $input = new CredentialInput($this->validInput([
            'erx_location_uuid' => '   ',
            'jwt_kid' => '',
        ]));

        $this->assertTrue($input->isValid());
        $this->assertNull($input->toArray()['erx_location_uuid']);
        $this->assertNull($input->toArray()['jwt_kid']);
    }

    public function testPracticeCredentialUsesTheNoProviderSentinelNotNull(): void
    {
        // A NULL here would defeat the unique key — MySQL treats every NULL in
        // a unique index as distinct, so each save would insert a new row.
        $provider = (new CredentialInput($this->validInput()))->toArray()['provider_user_id'];

        $this->assertNotNull($provider);
        $this->assertSame(0, $provider);
    }

    public function testInactiveFlagIsHonoured(): void
    {
        $input = new CredentialInput($this->validInput(['active' => false]));

        $this->assertSame(0, $input->toArray()['active']);
    }
}
