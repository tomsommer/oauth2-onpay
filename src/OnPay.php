<?php

declare(strict_types=1);

namespace Tomsommer\OAuth2\Client\Provider;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;

/**
 * OAuth 2.0 provider client for OnPay.io.
 *
 * OnPay issues public clients: there is no client secret, and no resource owner
 * endpoint. Only the authorization code and refresh token grants are supported.
 *
 * @see https://manage.onpay.io/docs/api_v1.html
 */
class OnPay extends AbstractProvider
{
    public const DEFAULT_BASE_AUTHORIZE_URI = 'https://manage.onpay.io';

    public const DEFAULT_BASE_URI = 'https://api.onpay.io';

    /**
     * The gateway to authorize against. When set, the authorization endpoint is
     * scoped to it. OnPay gateway ids are uppercase alphanumeric; older ones are
     * purely numeric.
     */
    protected ?string $gatewayId = null;

    protected string $baseAuthorizeUri = self::DEFAULT_BASE_AUTHORIZE_URI;

    protected string $baseUri = self::DEFAULT_BASE_URI;

    /**
     * PKCE code challenge method, or null to disable PKCE.
     *
     * OnPay accepts PKCE but does not require it. It is left off by default
     * because the caller has to persist the verifier from getPkceCode() across
     * the redirect and restore it with setPkceCode() before exchanging the code.
     */
    protected ?string $pkceMethod = null;

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $collaborators
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        parent::__construct($options, $collaborators);

        if (null !== $this->gatewayId && !preg_match('/^[A-Z0-9]+$/', $this->gatewayId)) {
            throw new \InvalidArgumentException('gatewayId must be a non-empty alphanumeric value');
        }
    }

    public function getBaseAuthorizationUrl(): string
    {
        if (null === $this->gatewayId) {
            return $this->baseAuthorizeUri . '/oauth2/authorize';
        }

        return $this->baseAuthorizeUri . '/' . $this->gatewayId . '/oauth2/authorize';
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return $this->baseUri . '/oauth2/access_token';
    }

    /**
     * OnPay exposes no resource owner endpoint. An access token authorizes a
     * gateway, not a person, so there are no end-user details to fetch.
     *
     * @throws \BadMethodCallException always
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        throw new \BadMethodCallException('OnPay exposes no resource owner endpoint');
    }

    /**
     * @return string[]
     */
    protected function getDefaultScopes(): array
    {
        return ['full'];
    }

    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * OnPay takes the access token as a bearer token on the Authorization
     * header. Without this, getAuthenticatedRequest() would hand back an
     * unauthenticated request.
     *
     * @param AccessToken|string|null $token
     * @return array<string, string>
     */
    protected function getAuthorizationHeaders($token = null): array
    {
        if (null === $token) {
            return [];
        }

        return ['Authorization' => 'Bearer ' . $token];
    }

    protected function getPkceMethod(): ?string
    {
        return $this->pkceMethod;
    }

    /**
     * @param array<mixed>|string $data
     * @throws IdentityProviderException
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if ($response->getStatusCode() < 400) {
            return;
        }

        $message = $response->getReasonPhrase();
        if (is_array($data)) {
            if (isset($data['errors'][0]['message'])) {
                $message = $data['errors'][0]['message'];
            } elseif (isset($data['error_description'])) {
                $message = $data['error_description'];
            } elseif (isset($data['error'])) {
                $message = $data['error'];
            }
        }

        throw new IdentityProviderException($message, $response->getStatusCode(), $data);
    }

    /**
     * @param array<string, mixed> $response
     * @throws \BadMethodCallException always
     */
    protected function createResourceOwner(array $response, AccessToken $token): ResourceOwnerInterface
    {
        throw new \BadMethodCallException('OnPay exposes no resource owner endpoint');
    }
}
