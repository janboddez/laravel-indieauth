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
