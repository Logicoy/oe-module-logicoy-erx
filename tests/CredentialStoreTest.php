<?php

/**
 * How a credential row answers "which clinician is prescribing".
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Config\CredentialStore;
use PHPUnit\Framework\TestCase;

class CredentialStoreTest extends TestCase
{
    public function testEitherNameResolvesToTheOneClinicianIdentifier(): void
    {
        // What registration actually stores: /v1/clinician/add answers with the
        // PK uuid only, and the draft endpoints want that same value under
        // erxClinicianUserUuid.
        $pkOnly = ['erx_clinician_user_pk_uuid' => 'clinician-uuid'];
        $this->assertSame('clinician-uuid', CredentialStore::clinicianUuid($pkOnly));
        $this->assertSame('clinician-uuid', CredentialStore::clinicianPkUuid($pkOnly));

        // A credential loaded by hand may carry only the other name.
        $userOnly = ['erx_clinician_user_uuid' => 'clinician-uuid'];
        $this->assertSame('clinician-uuid', CredentialStore::clinicianUuid($userOnly));
        $this->assertSame('clinician-uuid', CredentialStore::clinicianPkUuid($userOnly));
    }

    public function testDistinctStoredValuesAreBothKept(): void
    {
        // LogiCoy issues some practices two values; neither may be overwritten
        // by the other.
        $credential = [
            'erx_clinician_user_uuid' => 'draft-uuid',
            'erx_clinician_user_pk_uuid' => 'send-uuid',
        ];

        $this->assertSame('draft-uuid', CredentialStore::clinicianUuid($credential));
        $this->assertSame('send-uuid', CredentialStore::clinicianPkUuid($credential));
    }

    public function testNothingStoredResolvesToEmpty(): void
    {
        // The practice row a non-prescriber falls back to. Callers refuse on
        // this rather than posting a blank the backend rejects.
        $blank = [
            [],
            ['erx_clinician_user_uuid' => null, 'erx_clinician_user_pk_uuid' => ''],
            ['erx_clinician_user_pk_uuid' => '   '],
        ];

        foreach ($blank as $credential) {
            $this->assertSame('', CredentialStore::clinicianUuid($credential));
            $this->assertSame('', CredentialStore::clinicianPkUuid($credential));
        }
    }
}
