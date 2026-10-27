<?php

/**
 * Reads and writes the OpenEMR session.
 *
 * OpenEMR moved the session behind a Symfony SessionInterface, obtained from
 * SessionWrapperFactory and stored under an `OpenEMR` namespace. `$_SESSION`
 * therefore has no `authUserID` at all, and reading it returns nothing:
 * prescribing resolved no provider credential, fell back to the lowest facility
 * id, and wrote a blank username into the audit trail — all of it silently,
 * because an absent user reads exactly like a user with nothing configured.
 *
 * Writes have to go through the wrapper too. Its session may be opened
 * read_and_close, in which case only the wrapper's own setter reopens the file
 * and flushes; assigning to `$_SESSION` is discarded.
 *
 * Falls back to `$_SESSION` so the module keeps working on OpenEMR versions
 * from before the wrapper existed. See docs/COMPATIBILITY.md.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

use OpenEMR\Common\Session\SessionWrapperFactory;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Throwable;

class SessionContext
{
    private static ?SessionInterface $override = null;

    /** @var array<string, mixed>|null Set while running as the worker. */
    private static ?array $worker = null;

    /**
     * Point the module at a session directly. Tests only.
     */
    public static function useSession(?SessionInterface $session): void
    {
        self::$override = $session;
    }

    /**
     * Run as the background worker rather than as whoever is signed in.
     *
     * On OpenEMR 7.0 background services run inside a signed-in user's
     * request, so without this a queued send would be audited under that
     * user. Only the site id is carried over; everything else reads as
     * absent, and writes stay inside the worker run.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function runAsWorker(callable $fn)
    {
        $previous = self::$worker;
        self::$worker = ['site_id' => self::get('site_id')];
        try {
            return $fn();
        } finally {
            self::$worker = $previous;
        }
    }

    private static function session(): ?SessionInterface
    {
        if (self::$override !== null) {
            return self::$override;
        }

        if (!class_exists(SessionWrapperFactory::class)) {
            return null;
        }

        try {
            $factory = SessionWrapperFactory::getInstance();

            // Never start a session just to read one. A cron worker has no user,
            // and asking for one there would hand back an empty session that
            // looks the same as a logged out browser.
            return $factory->isSessionActive() ? $factory->getActiveSession() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        if (self::$worker !== null) {
            return self::$worker[$key] ?? $default;
        }

        $session = self::session();
        $value = $session !== null ? $session->get($key) : ($_SESSION[$key] ?? null);

        return $value ?? $default;
    }

    public static function getInt(string $key): int
    {
        return (int)self::get($key, 0);
    }

    public static function getString(string $key): string
    {
        return trim((string)self::get($key, ''));
    }

    /**
     * @param mixed $value
     */
    public static function set(string $key, $value): void
    {
        if (self::$worker !== null) {
            self::$worker[$key] = $value;
            return;
        }

        $session = self::session();
        if ($session !== null) {
            $session->set($key, $value);
            return;
        }

        $_SESSION[$key] = $value;
    }

    /**
     * The session values a collaborator needs, as a plain array.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public static function values(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = self::get($key);
        }

        return $values;
    }
}
