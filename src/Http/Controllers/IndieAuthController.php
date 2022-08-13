<?php

namespace janboddez\IndieAuth\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class IndieAuthController
{
    const SCOPES = [
        'profile',
        'email',
        'create',
        'draft',
        'update',
        'delete',
        'media',
        'read',
        'follow',
        'channels',
        'mute',
        'block',
    ];

    public function start(Request $request)
    {
        abort_unless($request->filled('client_id'), 400, __('Missing client ID.'));
        abort_unless(
            filter_var($request->input('client_id'), FILTER_VALIDATE_URL),
            400,
            __('Invalid client ID.')
        );

        abort_unless($request->filled('redirect_uri'), 400, __('Missing redirect URI.'));
        abort_unless(
            filter_var($request->input('redirect_uri'), FILTER_VALIDATE_URL),
            400,
            __('Invalid redirect URI.')
        );

        abort_unless($request->filled('state'), 400, __('Missing state parameter.'));

        $scopes = [];

        if ($request->filled('scope')) {
            $scopes = array_filter(
                explode(' ', $request->input('scope'))
            );

            /** @todo: Maybe we shouldn't be this strict? */
            abort_if(array_diff($scopes, static::SCOPES), 400, __('Unsupported scopes'));
        }

        /** @todo: Normalize URL? */
        $clientId = $request->input('client_id');

        // $client = Cache::get("indieauth:client:$clientId", function () use ($clientId) {
        //     $client = static::discoverClientData($clientId);
        //
        //     if ($client) {
        //         Cache::set("indieauth:client:$clientId", $client, 86400); // Cache for 24 hours.
        //     }
        //
        //     return $client;
        // }

        session([
            'client_id' => $clientId, /** @todo: Store more than just the ID, to add to token meta. */
            'redirect_uri' => $request->input('redirect_uri'),
            'state' => $request->input('state'),
            'code_challenge' => $request->input('code_challenge'), // May be null.
        ]);

        return view('indieauth::auth', compact('scopes'));
    }

    public function approve(Request $request)
    {
        $request->validate([
            'scope' => 'array',
            'scope.*' => 'in:create,update,delete,media,read,follow,channels,mute,block',
        ]);

        $code = Str::random(64);

        $scopes = is_array($request->input('scope'))
            ? $request->input('scope')
            : [];

        // Using the code as key, and tying the client to it.
        Cache::put("indieauth:code:$code", [
            'user_id' => Auth::id(),
            'client_id' => session('client_id'),
            'redirect_uri' => session('redirect_uri'),
            'code_challenge' => session('code_challenge'),
            'scope' => $scopes,
        ], 300);

        $callbackUrl = Request::create(session('redirect_uri'))
            ->fullUrlWithQuery([
                'state' => session('state'),
                'code' => $code,
            ]);

        return redirect($callbackUrl);
    }

    public function verifyAuthorizationCode(Request $request)
    {
        abort_unless($request->filled('code'), 401, __('Missing authorization code.'));

        $code = preg_replace('/[^A-Za-z0-9]/', '', $request->input('code'));

        abort_unless($codeData = Cache::get("indieauth:code:$code"), 403, __('Unknown authorization code.'));

        if ($request->has('code_verifier')) {
            abort_unless(
                static::isValidCode($request->input('code_verifier'), $codeData['code_challenge']),
                419,
                __('PKCE validation failed.')
            );
        }

        $user = User::findOrFail($codeData['user_id']);

        $response = ['me' => $user->url];

        if (in_array('profile', (array) $codeData['scope'], true)) {
            $response['profile'] = array_filter([
                'name' => $user->name,
                'url' => $user->url,
                'photo' => null,
                'email' => in_array('email', (array) $codeData['scope'], true) ? $user->email : null,
            ]);
        } elseif (in_array('email', (array) $codeData['scope'], true)) {
            $response['email'] = [
                'email' => $user->email,
            ];
        }

        return response()->json($response);
    }

    public function issueToken(Request $request)
    {
        abort_unless($request->filled('client_id'), 400, __('Missing client ID.'));
        abort_unless($request->filled('redirect_uri'), 400, __('Missing redirect URI.'));
        abort_unless($request->filled('code'), 401, __('Missing authorization code.'));

        $code = preg_replace('/[^A-Za-z0-9]/', '', $request->input('code'));

        abort_unless($codeData = Cache::get("indieauth:code:$code"), 403, __('Unknown authorization code.')); // Why would this fail even when `$codeData` is obviously NOT empty?

        abort_unless($request->input('client_id') === $codeData['client_id'], 400, __('Invalid client ID.'));
        abort_unless($request->input('redirect_uri') === $codeData['redirect_uri'], 400, __('Invalid redirect URI.'));

        if ($request->has('code_verifier')) {
            abort_unless(
                static::isValidCode($request->input('code_verifier'), $codeData['code_challenge']),
                419,
                __('PKCE validation failed.')
            );
        }

        $user = User::findOrFail($codeData['user_id']);

        $response = [
            'me' => $user->url,
        ];

        if (array_diff($codeData['scope'], [null, 'profile', 'email'])) {
            $response['access_token'] = $user->createToken($codeData['client_id'], $codeData['scope'])->plainTextToken;
            $response['token_type'] = 'Bearer';
            $response['scope'] = implode(' ', $codeData['scope']);
        }

        if (in_array('profile', $codeData['scope'], true)) {
            $response['profile'] = array_filter([
                'name' => $user->name,
                'url' => $user->url,
                'photo' => null,
                'email' => in_array('email', $codeData['scope'], true) ? $user->email : null,
            ]);
        } elseif (in_array('email', $codeData['scope'], true)) {
            $response['email'] = [
                'email' => $user->email,
            ];
        }

        return response()->json($response);
    }

    public function verifyToken(Request $request)
    {
        // Sanctum actually allows logged in users to visit this route/URL, even
        // if it's behind the `auth:sanctum` middleware. (That's because it
        // tries good old cookie auth first.)
        /** @todo: Look into removing Sanctum's `web` guard. */
        abort_unless($request->bearerToken(), 401, __('Missing bearer token.'));

        // So, unauthenticated users should never make it this far.
        $user = $request->user();
        $token = $user->currentAccessToken();

        // Force updating this value as we don't actually use Sanctum's
        // `tokenCan()`.
        $token->forceFill(['last_used_at' => now()])
            ->save();

        return response()
            ->json([
                'me' => $user->url,
                'client_id' => $token->name, // For now.
                'scope' => implode(' ', $token->abilities),
            ], 200);
    }

    public function revokeToken(Request $request)
    {
        // Same remark as above.
        abort_unless($request->bearerToken(), 401, __('Missing bearer token.'));

        $request->user()
            ->currentAccessToken()
            ->delete();

        return response()
            ->json(new \stdClass, 200);
    }

    public function metadata()
    {
        return response()->json([
            'issuer' => url('/indieauth'),
            'token_endpoint' => url('/indieauth/token'),
            'revocation_endpoint' => url('/indieauth/token/revocation'),
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => static::SCOPES,
            'code_challenge_methods_supported' => ['S256'],
        ]); // And so on.
    }

    public static function isValidCode(
        string $codeVerifier,
        string $codeChallenge,
        string $codeChallengeMethod = 'sha256'
    ): bool {
        return $codeChallenge === static::base64UrlEncode(hash($codeChallengeMethod, $codeVerifier, true));
    }

    public static function base64UrlEncode(string $string): string
    {
        return rtrim(strtr(base64_encode($string), '+/', '-_'), '=');
    }

    public static function discoverClientData(string $url): array
    {
        /** @todo: Actually implement. */
        return [];
    }
}
