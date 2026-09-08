<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EstablishmentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use App\Support\Toast;

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
            /*
             * `accepted` rather than `boolean`: it rejects an unticked box and a
             * missing key alike. The checkbox carries HTML `required` too, but
             * that is a courtesy to the browser -- an unchecked checkbox simply
             * is not posted, so without this rule the account would be created
             * with no record of consent at all.
             */
            'terms_accepted' => ['accepted'],
        ], [
            'terms_accepted.accepted' => 'Please accept the Terms of Service and Privacy Policy to continue.',
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
            ->with(Toast::success('Request submitted', 'DOT Region XI will review it. You can sign in once approved.'));
    }
}
