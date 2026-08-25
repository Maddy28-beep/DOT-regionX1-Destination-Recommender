<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tourist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class TouristAuthController extends Controller
{
    public function showRegister(): View
    {
        return view('auth.tourist-register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', 'unique:tourists,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'nationality' => ['required', 'string', 'max:80'],
            'age_range' => ['required', 'string', 'max:20'],
            'gender' => ['nullable', 'string', 'max:20'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'privacy_consent' => ['accepted'],
        ]);

        $tourist = Tourist::create([
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'nationality' => $data['nationality'],
            'age_range' => $data['age_range'],
            'gender' => $data['gender'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
            'privacy_consent' => true,
            'privacy_consent_at' => now(),
        ]);

        Auth::guard('tourist')->login($tourist);
        $request->session()->regenerate();

        // intended(), not a hard redirect: a tourist who scanned a QR code
        // and registered on the spot (rather than logging into an existing
        // account) should still land back on the check-in route afterward,
        // so their very first visit gets recorded too.
        return redirect()->intended(route('tourist.dashboard'));
    }

    public function showLogin(): View
    {
        return view('auth.tourist-login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('tourist')->attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Those credentials do not match our records.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('tourist.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('tourist')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tourist.login');
    }
}
