<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TouristDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $tourist = $request->user('tourist');

        $savedDestinations = $tourist->savedDestinations()
            ->with(['destination.region', 'destination.tags', 'destination.photos'])
            ->latest('saved_at')
            ->get()
            ->pluck('destination')
            ->filter();

        $healthProfile = $tourist->healthProfile()->with('conditions')->first();

        return view('dashboard.tourist', compact('tourist', 'savedDestinations', 'healthProfile'));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $tourist = $request->user('tourist');

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:100'],
            'nationality' => ['required', 'string', 'max:80'],
            'age_range' => ['required', 'string', 'max:20'],
            'gender' => ['nullable', 'string', 'max:20'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'preferred_language' => ['nullable', 'string', 'max:50'],
        ]);

        $tourist->update($data);

        return back()->with('status', 'Your profile has been updated.');
    }
}
