# laravel-indieauth

Adds the following routes to your Laravel application:
```
/indieauth (GET/POST)
├── /metadata (GET)
└── /token (GET/POST)
    └── /revocation (POST)
```
After installation, run `php artisan migrate`. This will add a `url` column to Laravel's (default) `users` table, and nothing more.

To modify the simple authorization form, publish it to `resources/views/vendor/indieauth`:
```
php artisan vendor:publish --provider="janboddez\IndieAuth\IndieAuthServiceProvider" --tag="views"
```

Finally, for IndieAuth clients to be able to use your (token) endpoint, add the following to your Laravel application's `head`:
```
<link rel="authorization_endpoint" href="/indieauth">
<link rel="token_endpoint" href="/indieauth/token">
```

## Sanctum
This package uses [Laravel Sanctum](https://laravel.com/docs/9.x/sanctum) to issue and verify tokens. By default, tokens never expire. It is, however, possible to [define an expiration time](https://laravel.com/docs/9.x/sanctum#token-expiration).

## Token Revocation
Tokens can be revoked simply by sending a POST request to `/token/revocation`, using the token (i.e., as a bearer token in an authorization header) you wish to revoke.

Alternatively, sending a POST request to the token endpoint itself (i.e., `/token`) with the following two parameters in its body will work as well:
```
action=revoke&token=<the-token-in-question>
```
