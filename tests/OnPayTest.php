<?php

declare(strict_types=1);

namespace TomSommer\OAuth2\Client\Test\Provider;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TomSommer\OAuth2\Client\Provider\OnPay;

class OnPayTest extends TestCase
{
    /** @var RequestInterface[] */
    private array $sentRequests = [];

    /**
     * @param array<string, mixed> $options
     * @param Response[] $responses
     */
    private function provider(array $options = [], array $responses = []): OnPay
    {
        $this->sentRequests = [];
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(function (callable $next): callable {
            return function (RequestInterface $request, array $options) use ($next) {
                $this->sentRequests[] = $request;
                return $next($request, $options);
            };
        });

        return new OnPay(
            array_merge([
                'clientId' => 'example.com',
                'redirectUri' => 'https://example.com/callback',
            ], $options),
            ['httpClient' => new GuzzleClient(['handler' => $handler, 'http_errors' => false])]
        );
    }

    public function testAuthorizationUrlDefaultsToTheManagePortal(): void
    {
        $this->assertSame(
            'https://manage.onpay.io/oauth2/authorize',
            $this->provider()->getBaseAuthorizationUrl()
        );
    }

    public function testAuthorizationUrlIsScopedToTheGateway(): void
    {
        $this->assertSame(
            'https://manage.onpay.io/A5KM3QX7B/oauth2/authorize',
            $this->provider(['gatewayId' => 'A5KM3QX7B'])->getBaseAuthorizationUrl()
        );
    }

    public function testLegacyNumericGatewayIdIsAccepted(): void
    {
        $this->assertSame(
            'https://manage.onpay.io/1234/oauth2/authorize',
            $this->provider(['gatewayId' => '1234'])->getBaseAuthorizationUrl()
        );
    }

    #[DataProvider('invalidGatewayIdProvider')]
    public function testInvalidGatewayIdIsRejected(string $gatewayId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('gatewayId must be a non-empty alphanumeric value');
        $this->provider(['gatewayId' => $gatewayId]);
    }

    /** @return array<string, string[]> */
    public static function invalidGatewayIdProvider(): array
    {
        return [
            'empty' => [''],
            'lowercase' => ['a5km3qx7b'],
            'hyphen' => ['A5-KM3'],
            'space' => ['A5 KM3'],
            'slash' => ['A5/KM3'],
        ];
    }

    public function testAccessTokenUrlDefaultsToTheApiHost(): void
    {
        $this->assertSame(
            'https://api.onpay.io/oauth2/access_token',
            $this->provider()->getBaseAccessTokenUrl([])
        );
    }

    public function testHostsAreOverridable(): void
    {
        $provider = $this->provider([
            'baseAuthorizeUri' => 'https://manage.test.onpay.io',
            'baseUri' => 'https://api.test.onpay.io',
        ]);

        $this->assertSame('https://manage.test.onpay.io/oauth2/authorize', $provider->getBaseAuthorizationUrl());
        $this->assertSame('https://api.test.onpay.io/oauth2/access_token', $provider->getBaseAccessTokenUrl([]));
    }

    public function testAuthorizationUrlCarriesTheExpectedParameters(): void
    {
        $url = $this->provider()->getAuthorizationUrl();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('example.com', $query['client_id']);
        $this->assertSame('https://example.com/callback', $query['redirect_uri']);
        $this->assertSame('full', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertNotEmpty($query['state']);
        $this->assertArrayNotHasKey('code_challenge', $query);
    }

    public function testPkceIsOffByDefaultAndOptIn(): void
    {
        $provider = $this->provider(['pkceMethod' => AbstractProvider::PKCE_METHOD_S256]);
        $url = $provider->getAuthorizationUrl();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($provider->getPkceCode());
    }

    public function testAuthorizationCodeGrant(): void
    {
        $provider = $this->provider([], [
            new Response(200, ['content-type' => 'application/json'], (string) json_encode([
                'access_token' => 'an_access_token',
                'refresh_token' => 'a_refresh_token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
        ]);

        $token = $provider->getAccessToken('authorization_code', ['code' => 'a_code']);

        $this->assertSame('an_access_token', $token->getToken());
        $this->assertSame('a_refresh_token', $token->getRefreshToken());
        $this->assertGreaterThan(time(), $token->getExpires());

        $request = $this->sentRequests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.onpay.io/oauth2/access_token', (string) $request->getUri());
        parse_str((string) $request->getBody(), $body);
        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('a_code', $body['code']);
        $this->assertSame('example.com', $body['client_id']);
    }

    public function testRefreshTokenGrant(): void
    {
        $provider = $this->provider([], [
            new Response(200, ['content-type' => 'application/json'], (string) json_encode([
                'access_token' => 'a_fresh_access_token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
        ]);

        $token = $provider->getAccessToken('refresh_token', ['refresh_token' => 'a_refresh_token']);

        $this->assertSame('a_fresh_access_token', $token->getToken());
        parse_str((string) $this->sentRequests[0]->getBody(), $body);
        $this->assertSame('refresh_token', $body['grant_type']);
        $this->assertSame('a_refresh_token', $body['refresh_token']);
    }

    public function testErrorResponseUsesTheOnPayErrorsEnvelope(): void
    {
        $provider = $this->provider([], [
            new Response(400, ['content-type' => 'application/json'], (string) json_encode([
                'errors' => [['message' => 'Gateway is not active']],
            ])),
        ]);

        $this->expectException(IdentityProviderException::class);
        $this->expectExceptionMessage('Gateway is not active');
        $provider->getAccessToken('authorization_code', ['code' => 'a_code']);
    }

    public function testErrorResponseFallsBackToTheOAuthErrorDescription(): void
    {
        $provider = $this->provider([], [
            new Response(400, ['content-type' => 'application/json'], (string) json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code is invalid',
            ])),
        ]);

        $this->expectException(IdentityProviderException::class);
        $this->expectExceptionMessage('Authorization code is invalid');
        $provider->getAccessToken('authorization_code', ['code' => 'a_code']);
    }

    public function testErrorResponseFallsBackToTheReasonPhrase(): void
    {
        $provider = $this->provider([], [
            new Response(503, ['content-type' => 'application/json'], '{}'),
        ]);

        $this->expectException(IdentityProviderException::class);
        $this->expectExceptionMessage('Service Unavailable');
        $provider->getAccessToken('authorization_code', ['code' => 'a_code']);
    }

    public function testAuthenticatedRequestCarriesTheBearerToken(): void
    {
        $request = $this->provider()->getAuthenticatedRequest(
            'GET',
            'https://api.onpay.io/v1/ping',
            new AccessToken(['access_token' => 'an_access_token'])
        );

        $this->assertSame('Bearer an_access_token', $request->getHeaderLine('Authorization'));
    }

    public function testResourceOwnerDetailsUrlIsUnsupported(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('OnPay exposes no resource owner endpoint');
        $this->provider()->getResourceOwnerDetailsUrl(new AccessToken(['access_token' => 'x']));
    }

    public function testResourceOwnerIsUnsupported(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('OnPay exposes no resource owner endpoint');
        $this->provider()->getResourceOwner(new AccessToken(['access_token' => 'an_access_token']));
    }
}
