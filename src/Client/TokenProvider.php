<?php

/**
 * OAuth2 client-credentials tokens for the LogiCoy backend.
 *
 * Authentication is private_key_jwt: an RS256 assertion with iss = sub = client
 * id and aud = the token endpoint, signed with the practice signing key.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Client;

use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\GlobalConfig;

class TokenProvider
{
    private const ASSERTION_LIFETIME_SECONDS = 300;
    private const TOKEN_SAFETY_MARGIN_SECONDS = 60;

    private GlobalConfig $config;
    private CredentialStore $credentials;

    /** @var array<int, array{token: string, expires_at: int}> */
    private array $cache = [];

    public function __construct(GlobalConfig $config, CredentialStore $credentials)
    {
        $this->config = $config;
        $this->credentials = $credentials;
    }

    /**
     * @param array<string, mixed> $credential a row from CredentialStore
     */
    public function getAccessToken(array $credential, bool $forceRefresh = false): string
    {
        $credentialId = (int)$credential['id'];

        if (!$forceRefresh && isset($this->cache[$credentialId])) {
            $cached = $this->cache[$credentialId];
            if ($cached['expires_at'] > time()) {
                return $cached['token'];
            }
        }

        $tokenUrl = $this->config->getBackendTokenUrl();
        if ($tokenUrl === '') {
            throw new BackendException('LogiCoy backend token URL is not configured');
        }

        $clientId = (string)($credential['client_id'] ?? '');
        if ($clientId === '') {
            throw new BackendException('LogiCoy credential is missing a client id');
        }

        $signingKey = $this->credentials->getSigningKey($credentialId);
        if (empty($signingKey)) {
            throw new BackendException('LogiCoy credential has no usable private key');
        }

        $assertion = $this->buildClientAssertion(
            $clientId,
            $tokenUrl,
            $signingKey,
            $credential['jwt_kid'] ?? null
        );

        $token = $this->requestToken($tokenUrl, $clientId, $assertion);

        $this->cache[$credentialId] = [
            'token' => $token['access_token'],
            'expires_at' => time() + max(60, $token['expires_in'] - self::TOKEN_SAFETY_MARGIN_SECONDS),
        ];

        return $token['access_token'];
    }

    public function invalidate(int $credentialId): void
    {
        unset($this->cache[$credentialId]);
    }

    private function buildClientAssertion(
        string $clientId,
        string $tokenUrl,
        string $signingKeyPem,
        ?string $kid
    ): string {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if (!empty($kid)) {
            $header['kid'] = $kid;
        }

        $now = time();
        $payload = [
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $tokenUrl,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + self::ASSERTION_LIFETIME_SECONDS,
        ];

        $signingInput = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $privateKey = openssl_pkey_get_private($signingKeyPem);
        if ($privateKey === false) {
            throw new BackendException('LogiCoy private key could not be read; upload the PEM file from the LogiCoy Vendor portal again');
        }

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new BackendException('Failed to sign the LogiCoy client assertion');
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    private function requestToken(string $tokenUrl, string $clientId, string $assertion): array
    {
        $form = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
        ]);

        $curl = curl_init($tokenUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $form,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config->getHttpTimeout(),
            CURLOPT_USERAGENT => BackendClient::userAgent(),
        ]);

        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($curl);

        if ($body === false) {
            throw new BackendException('Token request to the LogiCoy backend failed: ' . $curlError);
        }

        // A gateway or server error is not a credential problem. Reporting one
        // as the other sends whoever is configuring this off checking keys and
        // client ids while the backend is simply down.
        if ($status >= 500) {
            throw new BackendException(
                'LogiCoy backend is unavailable — its token endpoint returned HTTP ' . $status
                . '. Nothing is wrong with the credential; retry once LogiCoy is back.',
                $status
            );
        }

        // The OAuth server answers in JSON. An HTML 403 is the firewall in
        // front of it, and the credential was never looked at.
        if ($status === 403 && stripos($contentType, 'json') === false) {
            throw new BackendException(
                'A firewall in front of the LogiCoy token endpoint blocked the request (HTTP 403)'
                . ' before it reached LogiCoy. The credential was not checked. Ask LogiCoy whether'
                . ' this server\'s outbound IP address or request is being blocked.',
                $status
            );
        }

        if ($status >= 400) {
            // The body can echo the assertion back, so take only the two
            // fields RFC 6749 defines for the error case rather than the whole
            // response. Without them the caller has nothing to act on.
            throw new BackendException(
                'LogiCoy token endpoint rejected the credential (HTTP ' . $status . ')'
                . self::describeOauthError((string)$body),
                $status
            );
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            throw new BackendException('LogiCoy token response did not contain an access token', $status);
        }

        return [
            'access_token' => (string)$decoded['access_token'],
            'expires_in' => (int)($decoded['expires_in'] ?? 600),
        ];
    }

    /**
     * The RFC 6749 error fields, and nothing else from the body.
     */
    private static function describeOauthError(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }

        $parts = [];
        foreach (['error', 'error_description'] as $field) {
            $value = trim((string)($decoded[$field] ?? ''));
            if ($value !== '') {
                $parts[] = mb_substr($value, 0, 500);
            }
        }

        return $parts === [] ? '' : ': ' . implode(' — ', $parts);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
