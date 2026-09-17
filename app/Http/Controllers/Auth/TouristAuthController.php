<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TouristAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
            'alias' => ['required', 'string', 'min:3', 'max:30', 'alpha_dash', 'unique:tourist_accounts,alias'],
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

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'alias' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('tourist')->attempt($data, $request->boolean('remember'))) {
            return back()->withErrors(['alias' => 'Those credentials do not match our records.'])->onlyInput('alias');
        }

        $request->session()->regenerate();

        return $this->postAuthRedirect($request, 'Welcome back', null);
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
