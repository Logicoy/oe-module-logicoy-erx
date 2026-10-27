<?php

/**
 * Works out which facility a prescribing request belongs to.
 *
 * Credentials are held per facility, so getting this wrong reports "no
 * credential is loaded" on an install where one is loaded perfectly well.
 *
 * OpenEMR only puts `facilityId` in the session when the `login_into_facility`
 * global is on, which it is not by default. Falling straight from there to the
 * lowest facility id — as the prescribing screen used to — picks whichever
 * facility happens to sort first, which on a stock install is the unconfigured
 * "Your Clinic Name Here" sample rather than the practice the user works at.
 *
 * A prescriber registered at more than one practice needs to say which one they
 * are prescribing under, and `users.facility_id` holds only one. The choice made
 * on the prescribing screen is kept in the session and outranks everything else.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

use OpenEMR\Common\Database\QueryUtils;

class FacilityResolver
{
    /**
     * Where the prescribing screen keeps the facility the user picked. Only ever
     * written after checking the user actually has a credential for it.
     */
    public const SESSION_KEY = 'logicoy_erx_facility_id';

    /**
     * @param array<string, mixed> $session Normally $_SESSION.
     * @param array<string, mixed> $globals Normally $GLOBALS.
     */
    public function resolve(array $session, array $globals = []): int
    {
        // 1. The prescriber's own choice on the prescribing screen.
        $chosen = (int)($session[self::SESSION_KEY] ?? 0);
        if ($chosen > 0) {
            return $chosen;
        }

        // 2. An explicit choice at login, when the site asks for one.
        $facilityId = (int)($session['facilityId'] ?? $globals['facilityId'] ?? 0);
        if ($facilityId > 0) {
            return $facilityId;
        }

        // 3. The logged in user's own default facility. This is the one that
        //    matches what the user sees on their own record under
        //    Administration, Users, so it is the answer they expect.
        $userId = (int)($session['authUserID'] ?? 0);
        if ($userId > 0) {
            $facilityId = (int)(QueryUtils::fetchSingleValue(
                'SELECT facility_id FROM users WHERE id = ? AND facility_id > 0 LIMIT 1',
                'facility_id',
                [$userId]
            ) ?? 0);
            if ($facilityId > 0) {
                return $facilityId;
            }
        }

        // 4. Last resort. A single facility install is unambiguous; anything
        //    else is a guess, which is why it comes last rather than second.
        return (int)(QueryUtils::fetchSingleValue(
            'SELECT id FROM facility ORDER BY id LIMIT 1',
            'id',
            []
        ) ?? 0);
    }
}
