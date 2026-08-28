<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PortalAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.portal-login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'portal' => ['required', 'in:establishment,admin'],
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if ($data['portal'] === 'admin') {
            $ok = Auth::guard('admin')->attempt([
                'email' => $data['identifier'],
                'password' => $data['password'],
            ], $request->boolean('remember'));

            if (! $ok) {
                return back()->withErrors(['identifier' => 'Invalid DOT Admin credentials.'])->onlyInput('identifier');
            }

            $request->session()->regenerate();

            return redirect()->intended(route('admin.overview'));
        }

        $account = \App\Models\EstablishmentAccount::where('email', $data['identifier'])->first();

        if (! $account || $account->status !== 'approved') {
            return back()
                ->withErrors(['identifier' => $account
                    ? 'This establishment account is still '.$account->status.' and cannot sign in yet.'
                    : 'Those credentials do not match our records.'])
                ->onlyInput('identifier');
        }

        if (! Auth::guard('establishment')->attempt([
            'email' => $data['identifier'],
            'password' => $data['password'],
        ], $request->boolean('remember'))) {
            return back()->withErrors(['identifier' => 'Those credentials do not match our records.'])->onlyInput('identifier');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('establishment.overview'));
    }

    public function logout(Request $request): RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            Auth::guard('admin')->logout();
        }

        if (Auth::guard('establishment')->check()) {
            Auth::guard('establishment')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
