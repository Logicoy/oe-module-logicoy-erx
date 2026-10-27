<?php

/**
 * Which facility a prescribing request belongs to.
 *
 * Only the steps that answer before any database lookup are covered here; unit
 * tests never touch a database.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Config\FacilityResolver;
use PHPUnit\Framework\TestCase;

class FacilityResolverTest extends TestCase
{
    public function testThePrescribersOwnChoiceWins(): void
    {
        // A prescriber registered at two practices picks one on the screen. It
        // has to outrank users.facility_id, which holds only one of them.
        $resolver = new FacilityResolver();

        $this->assertSame(7, $resolver->resolve([
            FacilityResolver::SESSION_KEY => 7,
            'facilityId' => 3,
            'authUserID' => 5,
        ]));
    }

    public function testLoginFacilityIsUsedWhenNothingWasPicked(): void
    {
        $resolver = new FacilityResolver();

        $this->assertSame(3, $resolver->resolve(['facilityId' => 3, 'authUserID' => 5]));
        $this->assertSame(3, $resolver->resolve([FacilityResolver::SESSION_KEY => 0, 'facilityId' => 3]));
        $this->assertSame(4, $resolver->resolve([], ['facilityId' => 4]));
    }
}
