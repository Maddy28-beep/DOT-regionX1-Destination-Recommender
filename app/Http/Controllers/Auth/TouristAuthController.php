<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tourist\TouristSavedDestinationController;
use App\Models\TouristAccount;
use App\Models\TouristSavedDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use App\Support\Toast;

/**
 * Login/registration for the optional tourist account (2.2.1.15-style
 * self-service, but far lighter than the establishment flow: no approval
 * queue, no business data, nothing to review). Separate from
 * PortalAuthController on purpose -- that one is DOT/partner staff signing
 * into a back office; this is a traveler optionally keeping a trip plan.
 */
class TouristAuthController extends Controller
{
    public function showRegister(): View
    {
        return view('auth.tourist-register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'alias' => ['required', 'string', 'min:3', 'max:30', 'alpha_dash', 'unique:tourist_accounts,alias', function ($attribute, $value, $fail) {
                // Exact comparison would let "Maddy" and "maddy" both exist, and one could pass for the other.
                if (TouristAccount::whereRaw('lower(alias) = ?', [mb_strtolower((string) $value)])->exists()) {
                    $fail('That alias is already taken.');
                }
            }],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $account = TouristAccount::create([
            'alias' => $data['alias'],
            'password_hash' => Hash::make($data['password']),
        ]);

        Auth::guard('tourist')->login($account);
        $request->session()->regenerate();

        return $this->postAuthRedirect($request, 'Account created', 'Your saved itineraries are now available across sessions.');
    }

    public function showLogin(): View
    {
        return view('auth.tourist-login');
    }

    /**
     * The sign-in page posts here and is sent on; the sign-in modal (opened by the heart on a listing) posts here over
     * fetch and gets JSON back, staying on the page it was opened from. The modal may also say which place the visitor
     * was trying to save: it is saved to their account right after they sign in (never toggled off), so the click
     * that asked them to sign in is not lost.
     */
    public function login(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'alias' => ['required', 'string'],
            'password' => ['required', 'string'],
            'save_type' => ['nullable', 'string', 'max:40'],
            'save_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $credentials = ['alias' => $data['alias'], 'password' => $data['password']];

        if (! Auth::guard('tourist')->attempt($credentials, $request->boolean('remember'))) {
            $message = 'Those credentials do not match our records.';

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => ['alias' => [$message]]], 422)
                : back()->withErrors(['alias' => $message])->onlyInput('alias');
        }

        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'saved' => $this->saveFromModal($request, $data['save_type'] ?? null, $data['save_id'] ?? null)]);
        }

        return $this->postAuthRedirect($request, 'Welcome back', null);
    }

    /**
     * Saves the place the modal was opened for, once, and flashes the message the page shows after it reloads. A type or
     * id that is not a public listing is ignored: signing in still works, and nothing is saved.
     */
    private function saveFromModal(Request $request, ?string $type, ?int $id): bool
    {
        $config = $type !== null ? (TouristSavedDestinationController::TYPES[$type] ?? null) : null;
        $listing = ($config && $id) ? $config['model']::find($id) : null;

        if (! $listing || ! $listing->is_accredited || $listing->archived_at) {
            foreach (Toast::success('Welcome back', null) as $key => $value) {
                $request->session()->flash($key, $value);
            }

            return false;
        }

        TouristSavedDestination::firstOrCreate([
            'tourist_account_id' => Auth::guard('tourist')->id(),
            'listing_kind' => $config['kind'],
            'listing_id' => $listing->id,
        ], ['saved_at' => now()]);

        foreach (Toast::success('Added to Saved Places', "{$listing->name} is now in your list.") as $key => $value) {
            $request->session()->flash($key, $value);
        }

        return true;
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('tourist')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /**
     * A guest who clicked "Save Itinerary" is sent here with a session flag
     * (see TouristItineraryController::store()) rather than losing the trip
     * they were trying to keep. Once signed in, send them back to it so the
     * save prompt they were promised is actually still there.
     */
    private function postAuthRedirect(Request $request, string $title, ?string $detail): RedirectResponse
    {
        if ($request->session()->get('pending_save_itinerary')) {
            return redirect()->route('plan.itinerary')->with(Toast::success($title, $detail));
        }

        return redirect()->route('account.itineraries')->with(Toast::success($title, $detail));
    }
}
