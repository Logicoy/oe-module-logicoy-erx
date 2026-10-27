<?php

/**
 * Where the eRx signing key lives on disk.
 *
 * Uploaded keys are written to sites/<site>/documents/certificates/, the same
 * directory OpenEMR uses for its own OAuth2 private key. Apache denies that
 * path outright (`<Directory ".../sites/*&#47;documents"> Require all denied`),
 * so a key there is not web reachable.
 *
 * An externally managed key — one placed by configuration management — may be
 * referenced by path instead, but only after it passes the checks below. The
 * one that matters is refusing any path a web server could serve.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

class KeyStore
{
    private string $siteDir;
    private string $webRoot;

    public function __construct(?string $siteDir = null, ?string $webRoot = null)
    {
        // Both are resolved, because every comparison below is against a
        // realpath(). Comparing a resolved path to an unresolved one silently
        // fails the moment any component is a symlink — and a false negative
        // here means a signing key gets accepted into a web reachable
        // directory.
        $this->siteDir = $this->resolve($siteDir ?? (string)($GLOBALS['OE_SITE_DIR'] ?? ''));
        $this->webRoot = $this->resolve($webRoot ?? (string)($GLOBALS['webserver_root'] ?? ''));
    }

    private function resolve(string $path): string
    {
        $trimmed = rtrim($path, '/');
        if ($trimmed === '') {
            return '';
        }

        $real = realpath($trimmed);

        return $real === false ? $trimmed : rtrim($real, '/');
    }

    public function getCertificatesDir(): string
    {
        return $this->siteDir . '/documents/certificates';
    }

    /**
     * Write an uploaded or pasted key and return its path.
     *
     * @throws KeyStoreException
     */
    public function store(string $pem, string $scope, int $facilityId, ?int $providerUserId = null): string
    {
        $dir = $this->getCertificatesDir();

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new KeyStoreException('Cannot create ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new KeyStoreException(
                $dir . ' is not writable by the web server user. Check ownership and permissions.'
            );
        }

        $name = sprintf(
            'logicoy_erx_%s_%d%s.key',
            preg_replace('/[^a-z]/', '', $scope) ?: 'practice',
            $facilityId,
            $providerUserId ? '_u' . $providerUserId : ''
        );
        $path = $dir . '/' . $name;

        // Create with restrictive permissions before writing, so the key is
        // never briefly world readable.
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new KeyStoreException('Cannot write ' . $path);
        }
        @chmod($path, 0600);
        fwrite($handle, $pem);
        fclose($handle);
        @chmod($path, 0600);

        return $path;
    }

    /**
     * Validate a path supplied by an administrator.
     *
     * @return array<int, string> problems found; empty means usable
     */
    public function validatePath(string $path): array
    {
        $errors = [];

        $real = realpath($path);
        if ($real === false) {
            return ['The file does not exist, or the web server user cannot see it: ' . $path];
        }
        if (!is_file($real)) {
            return ['That path is not a file: ' . $real];
        }
        if (!is_readable($real)) {
            $errors[] = 'The web server user cannot read ' . $real;
        }

        // The check that matters: a key anywhere the web server can serve is
        // one request away from being downloaded.
        if ($this->isWebReachable($real)) {
            $errors[] = 'That path is inside the web root and could be served over HTTP. '
                . 'Move the key outside the web root, or into ' . $this->getCertificatesDir() . '.';
        }

        $perms = @fileperms($real);
        if ($perms !== false && ($perms & 0o044)) {
            $errors[] = sprintf(
                'The key is readable by other users on this server (mode %04o). Set it to 0600.',
                $perms & 0o777
            );
        }

        if ($errors === []) {
            $pem = (string)@file_get_contents($real);
            if (!$this->looksLikeRsaPrivateKey($pem)) {
                $errors[] = 'That file is not an RSA private key in PEM format.';
            }
        }

        return $errors;
    }

    /**
     * Read a stored key. Revalidates first — the path lives in the database and
     * an attacker with SQL access could otherwise point it at any readable file.
     *
     * @throws KeyStoreException
     */
    public function read(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new KeyStoreException('The private key file is missing or unreadable: ' . $path);
        }
        if ($this->isWebReachable($real)) {
            throw new KeyStoreException('Refusing to read a private key from a web reachable path: ' . $real);
        }

        $pem = (string)@file_get_contents($real);
        if (!$this->looksLikeRsaPrivateKey($pem)) {
            throw new KeyStoreException('The private key file is not an RSA private key: ' . $real);
        }

        return $pem;
    }

    public function delete(string $path): void
    {
        $real = realpath($path);
        // Only ever remove files this module wrote.
        if ($real !== false && str_starts_with($real, $this->getCertificatesDir() . '/')) {
            @unlink($real);
        }
    }

    /**
     * Anything under the document root is web reachable, except the documents
     * tree, which Apache denies outright.
     */
    public function isWebReachable(string $realPath): bool
    {
        if ($this->webRoot === '' || !str_starts_with($realPath, $this->webRoot . '/')) {
            return false;
        }

        return !preg_match('#/sites/[^/]+/documents/#', $realPath);
    }

    /**
     * True when the key sits on container storage that a rebuild will wipe.
     *
     * In Docker only the mounted volumes survive `docker compose up
     * --force-recreate` or an image update. sites/ is a named volume in the
     * standard OpenEMR compose file, so the certificates directory persists;
     * a path like /etc/logicoy is in the container's writable layer and does
     * not. Losing the key silently at upgrade time is a bad way to find out.
     */
    public function isEphemeralInContainer(string $path): bool
    {
        if (!is_file('/.dockerenv')) {
            return false;
        }

        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        // sites/ is the mounted volume; anything under it is safe.
        return $this->siteDir === '' || !str_starts_with($real, dirname($this->siteDir) . '/');
    }

    private function looksLikeRsaPrivateKey(string $pem): bool
    {
        if (!str_contains($pem, 'PRIVATE KEY')) {
            return false;
        }

        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            return false;
        }

        $details = @openssl_pkey_get_details($key);

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA;
    }
}
