<?php

/**
 * Where ID.me sends the prescriber afterwards, and where that lands.
 *
 * ID.me returns the browser with a cross-site navigation, and OpenEMR's
 * session cookie is SameSite=Strict, so the request arrives with no session.
 * A module screen reached that way without a site parameter is answered by
 * OpenEMR core with an empty HTTP 400 before the module runs. So the return
 * goes to public/idme_return.php, which needs no session, always carries the
 * site, and links onward to the screen that checks the result. That click is
 * same-site, so the cookie travels with it.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Identity;

final class IdmeReturn
{
    /** The prescriber's own screen, public/idme.php. */
    public const TARGET_SCREEN = 'screen';
    /** The credentials screen, moduleConfig.php, where an administrator started it. */
    public const TARGET_CONFIG = 'config';

    public const LANDING_PATH = '/interface/modules/custom_modules/oe-module-logicoy-erx/public/idme_return.php';

    /**
     * The URL ID.me is told to send the browser to.
     *
     * @param string $origin site_addr_oath: the origin a browser can reach
     * @param string $siteId the OpenEMR site; blank reads as default, and it is
     *     always sent because core refuses a sessionless request without it
     */
    public static function landingUrl(
        string $origin,
        string $webroot,
        string $siteId,
        string $target,
        int $facilityId,
        int $providerUserId = 0,
        bool $epcsUpgrade = false
    ): string {
        $query = ['to' => $target, 'facility_id' => $facilityId];
        if ($providerUserId > 0) {
            $query['provider_user_id'] = $providerUserId;
        }
        if ($epcsUpgrade) {
            $query['epcs'] = 1;
        }
        $query['site'] = self::siteId($siteId) ?? 'default';

        return rtrim($origin, '/') . rtrim($webroot, '/') . self::LANDING_PATH . '?' . http_build_query($query);
    }

    /**
     * The link from the landing page to the screen that asks LogiCoy for the
     * result, relative to public/, or null when the query does not name one.
     *
     * Built from the query ID.me brought back, which is untrusted and may be
     * mangled: the backend has been seen appending its own "?email=..." to a
     * URL that already had a query string. Only whole-number ids, a site id
     * in the character set core accepts, and a known target are used.
     *
     * @param array<string, mixed> $query
     */
    public static function continuePath(array $query): ?string
    {
        $facilityId = self::int($query['facility_id'] ?? null);
        if ($facilityId <= 0) {
            return null;
        }
        $site = self::siteId((string)($query['site'] ?? ''));
        $target = (string)($query['to'] ?? '');

        if ($target === self::TARGET_SCREEN) {
            $params = ['facility_id' => $facilityId, 'returned' => 1];
            if ($site !== null) {
                $params['site'] = $site;
            }

            return 'idme.php?' . http_build_query($params);
        }

        if ($target === self::TARGET_CONFIG) {
            $providerUserId = self::int($query['provider_user_id'] ?? null);
            if ($providerUserId <= 0) {
                return null;
            }
            $params = ['idme_returned' => 1, 'facility_id' => $facilityId, 'provider_user_id' => $providerUserId];
            if (!empty($query['epcs'])) {
                $params['epcs'] = 1;
            }
            if ($site !== null) {
                $params['site'] = $site;
            }

            return '../moduleConfig.php?' . http_build_query($params);
        }

        return null;
    }

    /**
     * Leading digits only: "5?email=x" is the backend's mangling of 5.
     */
    private static function int(mixed $value): int
    {
        if (!is_scalar($value) || preg_match('/^\s*(\d{1,9})/', (string)$value, $m) !== 1) {
            return 0;
        }

        return (int)$m[1];
    }

    /**
     * A site id core would accept, or null.
     */
    private static function siteId(string $siteId): ?string
    {
        $siteId = trim($siteId);

        return $siteId !== '' && preg_match('/^[A-Za-z0-9\-.]+$/', $siteId) === 1 ? $siteId : null;
    }
}
