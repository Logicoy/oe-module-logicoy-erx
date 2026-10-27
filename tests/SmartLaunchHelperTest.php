<?php

/**
 * Tests for the documented OpenEMR 7.0.x SMART quirks the module works around.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Smart\SmartLaunchHelper;
use PHPUnit\Framework\TestCase;

class SmartLaunchHelperTest extends TestCase
{
    public function testRegistrationAlwaysSendsAnEmptyJwksKeySet(): void
    {
        $body = SmartLaunchHelper::buildRegistrationRequest('https://erx.example.com/', 'LogiCoy eRx');

        // Registration fails with "expecting array, got string" without this.
        $this->assertArrayHasKey('jwks', $body);
        $this->assertSame(['keys' => []], $body['jwks']);
    }

    public function testRegistrationRequestsTheLaunchScope(): void
    {
        $body = SmartLaunchHelper::buildRegistrationRequest('https://erx.example.com', 'LogiCoy eRx');

        // Without `launch` the app never appears in patient context.
        $this->assertStringContainsString('launch', $body['scope']);
        $this->assertSame('https://erx.example.com/launch', $body['initiate_login_uri']);
    }

    public function testRegistrationNeverUsesClientSecretPost(): void
    {
        $body = SmartLaunchHelper::buildRegistrationRequest('https://erx.example.com', 'LogiCoy eRx');

        $this->assertSame('client_secret_basic', $body['token_endpoint_auth_method']);
    }

    public function testLaunchEncounterScopeIsNotRequested(): void
    {
        // launch/encounter is absent from scopes_supported on 7.0.x; the
        // encounter is read from the token response instead.
        $this->assertNotContains('launch/encounter', SmartLaunchHelper::REQUIRED_SCOPES);
    }

    public function testNestedScopesSupportedIsFlattened(): void
    {
        // The discovery document returns [[...]] on this OpenEMR version.
        $nested = [['openid', 'fhirUser'], ['launch', 'user/Patient.rs']];

        $flat = SmartLaunchHelper::flattenSupportedScopes($nested);

        $this->assertSame(['openid', 'fhirUser', 'launch', 'user/Patient.rs'], $flat);
    }

    public function testFlattenHandlesAFlatListAndGarbageAlike(): void
    {
        $this->assertSame(['launch'], SmartLaunchHelper::flattenSupportedScopes(['launch']));
        $this->assertSame([], SmartLaunchHelper::flattenSupportedScopes('launch'));
        $this->assertSame([], SmartLaunchHelper::flattenSupportedScopes(null));
    }

    public function testFlattenDeduplicates(): void
    {
        $flat = SmartLaunchHelper::flattenSupportedScopes([['launch'], ['launch', 'openid']]);

        $this->assertSame(['launch', 'openid'], $flat);
    }
}
