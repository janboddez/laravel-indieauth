# IndieAuth for Laravel

First attempt at an IndieAuth package for Laravel.

After installing, run `php artisan migrate`.

This package adds a URL column to Laravel's default `users` table. It also assumes `App\Models\User` is your application's user model. A future version might include an option to override these values with your own.

It also adds the following routes: `/indieauth`, `/indieauth/token`, and `/indieauth/token/revocation`. These can be used to authorize client apps, and verify (and revoke) tokens, as per the IndieAuth spec.

In order to customize the auth form (and, e.g., have it extend your app's main layout), run `php artisan vendor:publish --provider="janboddez\IndieAuth\IndieAuthServiceProvider"` and edit the resulting file (in `resources/views/vendor/indieauth`).
