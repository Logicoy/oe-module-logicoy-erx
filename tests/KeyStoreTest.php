<?php

/**
 * Signing key file handling. Keys here are generated in-process and deleted.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Config\KeyStore;
use LogiCoy\ERx\Config\KeyStoreException;
use PHPUnit\Framework\TestCase;

class KeyStoreTest extends TestCase
{
    private string $root;
    private string $webRoot;
    private string $siteDir;
    private KeyStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/erx-keystore-' . bin2hex(random_bytes(6));
        $this->webRoot = $this->root . '/openemr';
        $this->siteDir = $this->webRoot . '/sites/default';
        mkdir($this->siteDir . '/documents/certificates', 0700, true);
        mkdir($this->root . '/outside', 0700, true);

        $this->store = new KeyStore($this->siteDir, $this->webRoot);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function rsaPem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    public function testStoredKeyLandsInTheCertificatesDirectory(): void
    {
        $path = $this->store->store($this->rsaPem(), 'practice', 3);

        $this->assertStringStartsWith($this->store->getCertificatesDir(), $path);
        $this->assertFileExists($path);
    }

    public function testStoredKeyIsNotReadableByOtherUsers(): void
    {
        $path = $this->store->store($this->rsaPem(), 'practice', 3);

        $this->assertSame(0600, fileperms($path) & 0777);
    }

    public function testStoredKeyRoundTripsExactly(): void
    {
        $pem = $this->rsaPem();
        $path = $this->store->store($pem, 'practice', 3);

        $this->assertSame($pem, $this->store->read($path));
    }

    public function testPracticeAndProviderKeysDoNotCollide(): void
    {
        $a = $this->store->store($this->rsaPem(), 'practice', 3);
        $b = $this->store->store($this->rsaPem(), 'provider', 3, 7);

        $this->assertNotSame($a, $b);
        $this->assertNotSame(file_get_contents($a), file_get_contents($b));
    }

    /* ── the checks that matter for an operator supplied path ── */

    public function testAKeyInsideTheWebRootIsRejected(): void
    {
        // This is the failure that would put a prescribing key one HTTP request
        // away from being downloaded.
        $exposed = $this->webRoot . '/interface/exposed.key';
        mkdir(dirname($exposed), 0700, true);
        file_put_contents($exposed, $this->rsaPem());
        chmod($exposed, 0600);

        $errors = $this->store->validatePath($exposed);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('web root', implode(' ', $errors));
    }

    public function testTheCertificatesDirectoryIsAcceptedDespiteBeingUnderTheWebRoot(): void
    {
        // Apache denies sites/*/documents outright, so it is not reachable.
        $path = $this->store->store($this->rsaPem(), 'practice', 3);

        $this->assertSame([], $this->store->validatePath($path));
    }

    public function testAKeyOutsideTheWebRootIsAccepted(): void
    {
        $external = $this->root . '/outside/ops.key';
        file_put_contents($external, $this->rsaPem());
        chmod($external, 0600);

        $this->assertSame([], $this->store->validatePath($external));
    }

    public function testAWorldReadableKeyIsRejected(): void
    {
        $external = $this->root . '/outside/loose.key';
        file_put_contents($external, $this->rsaPem());
        chmod($external, 0644);

        $errors = $this->store->validatePath($external);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('readable by other users', implode(' ', $errors));
    }

    public function testAMissingFileIsReportedClearly(): void
    {
        $errors = $this->store->validatePath($this->root . '/outside/nope.key');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not exist', implode(' ', $errors));
    }

    public function testANonKeyFileIsRejected(): void
    {
        $junk = $this->root . '/outside/notes.txt';
        file_put_contents($junk, 'just some text');
        chmod($junk, 0600);

        $errors = $this->store->validatePath($junk);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not an RSA private key', implode(' ', $errors));
    }

    public function testAnEcKeyIsRejected(): void
    {
        // The gateway signs with RS256; an EC key would fail at signing time.
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);

        $path = $this->root . '/outside/ec.key';
        file_put_contents($path, $pem);
        chmod($path, 0600);

        $this->assertNotEmpty($this->store->validatePath($path));
    }

    public function testReadRefusesAWebReachablePathEvenIfTheDatabaseSaysOtherwise(): void
    {
        // Guards against someone editing the stored path directly in SQL.
        $exposed = $this->webRoot . '/public/sneaky.key';
        mkdir(dirname($exposed), 0700, true);
        file_put_contents($exposed, $this->rsaPem());

        $this->expectException(KeyStoreException::class);
        $this->store->read($exposed);
    }

    public function testReadFailsLoudlyWhenTheFileHasGoneMissing(): void
    {
        $path = $this->store->store($this->rsaPem(), 'practice', 3);
        unlink($path);

        $this->expectException(KeyStoreException::class);
        $this->store->read($path);
    }

    public function testDeleteOnlyRemovesFilesTheModuleWrote(): void
    {
        $external = $this->root . '/outside/ops.key';
        file_put_contents($external, $this->rsaPem());

        $this->store->delete($external);

        // An operator managed key must survive; the module did not create it.
        $this->assertFileExists($external);

        $owned = $this->store->store($this->rsaPem(), 'practice', 3);
        $this->store->delete($owned);
        $this->assertFileDoesNotExist($owned);
    }
}
