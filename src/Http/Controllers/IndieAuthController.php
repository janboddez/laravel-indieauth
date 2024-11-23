<?php

namespace janboddez\IndieAuth\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use janboddez\IndieAuth\ClientDiscovery;

class IndieAuthController
{
    protected const SCOPES = [
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

    public function start(Request $request): View
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

        $client = Cache::get("indieauth:client:$clientId", function () use ($clientId) {
            $client = ClientDiscovery::discoverClientData($clientId);

            if ($client) {
                Cache::set("indieauth:client:$clientId", $client, 86400); // Cache for 24 hours.
            }

            return $client;
        });

        session([
            'client_id' => $clientId, /** @todo: Store more than just the ID, to add to token meta. */
            'redirect_uri' => $request->input('redirect_uri'),
            'state' => $request->input('state'),
            'code_challenge' => $request->input('code_challenge'), // May be null.
        ]);

        return view('indieauth::auth', compact('scopes', 'client'));
    }

    /**
     * This method can be called either on submission of the authorization form, or by an IndieAuth client trying to
     * exchange an authorization code for a profile URL (and somehow not using the token endpoint to do so).
     *
     * Unfortunately, this means the default CSRF middleware and `auth` middleware groups must be used only when no
     * `code` query string parameter is present.
     */
    public function approve(Request $request): RedirectResponse
    {
        if ($request->filled('code')) {
            return response()->json(static::generateResponse($request)[0]);
        }

        // In all other cases, assume this is the auth form being submitted. You'll want to make sure CSRF protection is
        // in place.
        abort_unless(Auth::check(), 403, __('Forbidden.'));

        $validated = $request->validate([
            'scope' => 'nullable|array',
            'scope.*' => 'in:' . implode(',', static::SCOPES),
        ]);

        $code = Str::random(64);

        // Using the code as key, and tying the client to it.
        Cache::put("indieauth:code:$code", [
            'user_id' => Auth::id(),
            'client_id' => session('client_id'),
            'redirect_uri' => session('redirect_uri'),
            'code_challenge' => session('code_challenge'),
            'scope' => $validated['scope'] ?? [],
        ], 300);

        $callbackUrl = Request::create(session('redirect_uri'))
            ->fullUrlWithQuery([
                'state' => session('state'),
                'code' => $code,
            ]);

        return redirect($callbackUrl);
    }

    public function verifyAuthorizationCode(Request $request): JsonResponse
    {
        $response = static::generateResponse($request)[0];

        return response()->json($response);
    }

    public function issueToken(Request $request): JsonResponse
    {
        if ($request->input('action') === 'revoke') {
            return static::revokeToken();
        }

        list($response, $codeData, $user) = static::generateResponse($request);

        // Add in an actual auth token.
        if (array_diff($codeData['scope'], [null, 'profile', 'email'])) {
            $token = $user->createToken($codeData['client_id'], $codeData['scope'])->plainTextToken;
            [$id, $token] = explode('|', $token, 2); // The first part is the database ID; we don't need it.

            $response['access_token'] = $token;
            $response['token_type'] = 'Bearer';
            $response['scope'] = implode(' ', $codeData['scope']);
        }

        return response()->json($response);
    }

    public function verifyToken(Request $request): JsonResponse
    {
        // Sanctum actually allows logged in users to visit this route/URL, even if it's behind the `auth:sanctum`
        // middleware. (That's because it tries good old cookie auth first.)
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

    public function revoke(): JsonResponse
    {
        return static::revokeToken();
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

    protected static function revokeToken(): JsonResponse
    {
        if ($user = auth('sanctum')->user()) {
            // User was authenticated using a Sanctum token.
            $user->currentAccessToken()->delete();
        }

        // At least Quill doesn't use the Authorization header for token revocation, not sure about other clients. So we
        // can't just have Sanctum protect this route.
        if (request()->filled('token') && ($token = PersonalAccessToken::findToken(request()->input('token')))) {
            $token->delete();
        }

        return response()
            ->json(new \stdClass(), 200);
    }

    protected static function generateResponse(Request $request): array
    {
        abort_unless($request->filled('code'), 401, __('Missing authorization code.'));
        abort_unless($request->filled('client_id'), 400, __('Missing client ID.'));
        abort_unless($request->filled('redirect_uri'), 400, __('Missing redirect URI.'));

        $code = preg_replace('/[^A-Za-z0-9]/', '', $request->input('code'));

        abort_unless($codeData = Cache::pull("indieauth:code:$code"), 403, __('Unknown authorization code.'));

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

        return [$response, $codeData, $user];
    }
}
