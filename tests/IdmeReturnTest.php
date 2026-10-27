<?php

/**
 * The ID.me return trip: the landing URL given to LogiCoy, and the link the
 * landing page builds from whatever ID.me brings back.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Identity\IdmeReturn;
use PHPUnit\Framework\TestCase;

class IdmeReturnTest extends TestCase
{
    public function testLandingUrlAlwaysCarriesTheSite(): void
    {
        $url = IdmeReturn::landingUrl('https://emr.example.test/', '', '', IdmeReturn::TARGET_SCREEN, 3);

        $this->assertSame(
            'https://emr.example.test/interface/modules/custom_modules/oe-module-logicoy-erx/public/idme_return.php'
            . '?to=screen&facility_id=3&site=default',
            $url
        );
    }

    public function testLandingUrlForTheCredentialsScreenNamesThePrescriberAndTheUpgrade(): void
    {
        $url = IdmeReturn::landingUrl(
            'https://emr.example.test',
            '/openemr',
            'clinic-two',
            IdmeReturn::TARGET_CONFIG,
            3,
            5,
            true
        );

        $this->assertSame(
            'https://emr.example.test/openemr/interface/modules/custom_modules/oe-module-logicoy-erx/public/'
            . 'idme_return.php?to=config&facility_id=3&provider_user_id=5&epcs=1&site=clinic-two',
            $url
        );
    }

    public function testContinueLinkGoesToTheScreenThatChecksTheResult(): void
    {
        $this->assertSame(
            'idme.php?facility_id=3&returned=1&site=default',
            IdmeReturn::continuePath(['to' => 'screen', 'facility_id' => '3', 'site' => 'default'])
        );
        $this->assertSame(
            '../moduleConfig.php?idme_returned=1&facility_id=3&provider_user_id=5&epcs=1&site=default',
            IdmeReturn::continuePath([
                'to' => 'config', 'facility_id' => '3', 'provider_user_id' => '5', 'epcs' => '1', 'site' => 'default',
            ])
        );
    }

    public function testMangledReturnQueryStillLinksOnward(): void
    {
        // The backend appended its own query to ours: provider_user_id=5?email=...
        $path = IdmeReturn::continuePath([
            'to' => 'config',
            'facility_id' => '3',
            'provider_user_id' => '5?email=someone@example.test',
            'site' => 'default',
        ]);

        $this->assertSame('../moduleConfig.php?idme_returned=1&facility_id=3&provider_user_id=5&site=default', $path);
    }

    public function testUntrustedValuesAreNotPassedThrough(): void
    {
        $this->assertNull(IdmeReturn::continuePath(['to' => 'screen']), 'no facility');
        $this->assertNull(IdmeReturn::continuePath(['to' => 'elsewhere', 'facility_id' => '3']), 'unknown target');
        $this->assertNull(IdmeReturn::continuePath(['to' => 'config', 'facility_id' => '3']), 'no prescriber');

        $path = IdmeReturn::continuePath(['to' => 'screen', 'facility_id' => '3', 'site' => '../other']);
        $this->assertSame('idme.php?facility_id=3&returned=1', $path, 'a site id core would refuse is dropped');
    }
}
