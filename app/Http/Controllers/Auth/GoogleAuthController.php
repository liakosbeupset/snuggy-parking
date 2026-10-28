<?php

namespace App\Http\Controllers\Auth;

use App\Actions\CreatePersonalHousehold;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SignInAllowlist;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Send the user to Google. `prompt=select_account` always shows the account chooser (FM29).
     */
    public function redirect(): RedirectResponse
    {
        /** @var GoogleProvider $google */
        $google = Socialite::driver('google');

        return $google->with(['prompt' => 'select_account'])->redirect();
    }

    /**
     * Handle Google's callback: every failure ends on /login with an error toast, never a 500 (FM5).
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return $this->refuse('Google sign-in was cancelled.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->refuse('Google sign-in failed, please try again.');
        } catch (Throwable $e) {
            report($e);

            return $this->refuse('Google sign-in failed, please try again.');
        }

        $raw = $googleUser instanceof AbstractUser ? $googleUser->getRaw() : [];

        if (($raw['email_verified'] ?? false) !== true || blank($googleUser->getEmail())) {
            return $this->refuse("Your Google account email isn't verified.");
        }

        // TEMPORARY allowlist: refuse before any user row is touched.
        if (! SignInAllowlist::allows((string) $googleUser->getEmail())) {
            return $this->refuse("This Google account isn't allowed to sign in.");
        }

        // A parallel first login may win the insert (FM17). Retried outside the failed
        // transaction, the lookup then finds the row the other request created.
        try {
            $user = $this->resolveUser($googleUser);
        } catch (UniqueConstraintViolationException) {
            $user = $this->resolveUser($googleUser);
        }

        if (is_string($user)) {
            return $this->refuse($user);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    /**
     * Log the user out and return to the login page. clearHistory writes to the session,
     * so it must follow invalidate(), which would otherwise flush it (I11).
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return to_route('login');
    }

    /**
     * Find, link or create the user for a verified Google account. A string is a refusal:
     * a Google account never takes over a row linked to a different google_id (FM16).
     */
    private function resolveUser(SocialiteUser $googleUser): User|string
    {
        $email = Str::lower((string) $googleUser->getEmail());
        $refusal = 'This email is linked to another Google account.';

        $user = User::where('google_id', $googleUser->getId())->first();

        if ($user !== null) {
            if (User::where('email', $email)->whereKeyNot($user->id)->exists()) {
                return $refusal;
            }

            $user->forceFill(['email' => $email, 'avatar' => $googleUser->getAvatar()])->save();

            return $user;
        }

        $user = User::where('email', $email)->first();

        if ($user !== null) {
            if ($user->google_id !== null) {
                return $refusal;
            }

            $user->forceFill(['google_id' => $googleUser->getId(), 'avatar' => $googleUser->getAvatar()])->save();

            return $user;
        }

        return DB::transaction(function () use ($googleUser, $email): User {
            $user = User::forceCreate([
                'name' => $googleUser->getName() ?: $email,
                'email' => $email,
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
            ]);

            app(CreatePersonalHousehold::class)($user);

            return $user;
        });
    }

    private function refuse(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('login');
    }
}
