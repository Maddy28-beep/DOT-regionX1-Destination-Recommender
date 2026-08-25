<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EstablishmentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EstablishmentRegistrationController extends Controller
{
    public function showRegister(): View
    {
        return view('auth.establishment-register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'listing_kind' => ['required', 'in:accommodation,restaurant,package,souvenir_center,tour_operator'],
            'claimed_accreditation_number' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:100', 'unique:establishment_accounts,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'contact_person' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:20'],
        ]);

        EstablishmentAccount::create([
            'business_name' => $data['business_name'],
            'listing_kind' => $data['listing_kind'],
            'claimed_accreditation_number' => $data['claimed_accreditation_number'] ?? null,
            'portal_key' => (string) Str::uuid(),
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'contact_person' => $data['contact_person'],
            'contact_number' => $data['contact_number'],
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        return redirect()
            ->route('portal.login')
            ->with('status', 'Your establishment account was submitted and is pending DOT Region XI review. You will be able to sign in once approved.');
    }
}
