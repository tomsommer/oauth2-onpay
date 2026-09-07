# OnPay Provider for the PHP League's OAuth 2.0 Client

[![Tests](https://github.com/tomsommer/oauth2-onpay/actions/workflows/tests.yml/badge.svg)](https://github.com/tomsommer/oauth2-onpay/actions/workflows/tests.yml)
[![Latest Stable Version](https://poser.pugx.org/tomsommer/oauth2-onpay/v/stable)](https://packagist.org/packages/tomsommer/oauth2-onpay)
[![License](https://poser.pugx.org/tomsommer/oauth2-onpay/license)](https://packagist.org/packages/tomsommer/oauth2-onpay)

This package provides [OnPay.io](https://onpay.io/) OAuth 2.0 support for the PHP League's [OAuth 2.0 Client](https://github.com/thephpleague/oauth2-client).

## Installation

```bash
composer require tomsommer/oauth2-onpay
```

## Usage

Usage is the same as the League's OAuth client, using `Tomsommer\OAuth2\Client\Provider\OnPay` as the provider.

### Authorization Code Flow

```php
$provider = new Tomsommer\OAuth2\Client\Provider\OnPay([
    'clientId'    => 'example.com',                     // your integration's identifier; the domain it runs on is conventional
    'redirectUri' => 'https://example.com/callback',
    'gatewayId'   => 'A5KM3QX7B',                       // optional, scopes authorization to one gateway
]);

if (!isset($_GET['code'])) {

    // Step 1. Send the merchant to OnPay to authorize.
    $authUrl = $provider->getAuthorizationUrl();
    $_SESSION['oauth2state'] = $provider->getState();

    header('Location: ' . $authUrl);
    exit;

} elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {

    // Step 2. Reject a callback whose state does not match what we sent.
    unset($_SESSION['oauth2state']);
    exit('Invalid state');

} else {

    // Step 3. Exchange the authorization code for an access token.
    $token = $provider->getAccessToken('authorization_code', [
        'code' => $_GET['code'],
    ]);

    // Step 4. Use the token against the API.
    $request = $provider->getAuthenticatedRequest(
        'GET',
        'https://api.onpay.io/v1/ping',
        $token
    );
}
```

### Refreshing a token

```php
$newToken = $provider->getAccessToken('refresh_token', [
    'refresh_token' => $token->getRefreshToken(),
]);
```

OnPay may leave `refresh_token` out of a refresh response, which per [RFC 6749 §6](https://datatracker.ietf.org/doc/html/rfc6749#section-6) means the old one remains valid. Keep it rather than overwriting it with null:

```php
if (null === $newToken->getRefreshToken()) {
    $newToken->setRefreshToken($token->getRefreshToken());
}
```

### PKCE

PKCE is supported and off by default, because the verifier has to survive the redirect and only the calling application can store it:

```php
$provider = new Tomsommer\OAuth2\Client\Provider\OnPay([
    'clientId'    => 'example.com',
    'redirectUri' => 'https://example.com/callback',
    'pkceMethod'  => League\OAuth2\Client\Provider\AbstractProvider::PKCE_METHOD_S256,
]);

// Before redirecting, store the verifier alongside the state.
$authUrl = $provider->getAuthorizationUrl();
$_SESSION['oauth2state'] = $provider->getState();
$_SESSION['oauth2pkce']  = $provider->getPkceCode();

// On the callback, restore it before exchanging the code.
$provider->setPkceCode($_SESSION['oauth2pkce']);
$token = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);
```

## Options

| Option | Default | Description |
| --- | --- | --- |
| `clientId` | *required* | Your integration's identifier. OnPay recommends the domain the integration runs on. |
| `redirectUri` | *required* | Where OnPay sends the merchant back to. |
| `gatewayId` | `null` | Scopes authorization to one gateway. Uppercase alphanumeric; older gateway ids are numeric. |
| `baseAuthorizeUri` | `https://manage.onpay.io` | Authorization host. |
| `baseUri` | `https://api.onpay.io` | API host, which also serves the token endpoint. |
| `pkceMethod` | `null` | Set to `S256` to enable PKCE. |

## Things to know about OnPay's OAuth

- **There is no client secret.** OnPay issues public clients, so `clientSecret` is left unset and the token request carries an empty one.
- **There is no resource owner endpoint.** An access token authorizes a *gateway*, not a person, so `getResourceOwner()` and `getResourceOwnerDetailsUrl()` throw `BadMethodCallException` rather than pretending otherwise. There is likewise no `ACCESS_TOKEN_RESOURCE_OWNER_ID`.
- **Only the authorization code and refresh token grants are supported.**
- **The scope is `full`**, which is the only scope OnPay defines.

## API client

This package handles authorization only. For the OnPay API itself, see [tomsommer/onpay-php-sdk](https://github.com/tomsommer/onpay-php-sdk), which uses this provider.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). See [LICENSE](LICENSE).
