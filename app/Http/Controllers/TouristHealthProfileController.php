<?php

namespace App\Http\Controllers;

use App\Models\TouristHealthCondition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TouristHealthProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $tourist = $request->user('tourist');
        $healthProfile = $tourist->healthProfile()->with('conditions')->first();

        return view('dashboard.health-profile', compact('healthProfile'));
    }

    public function update(Request $request): RedirectResponse
    {
        $tourist = $request->user('tourist');

        $data = $request->validate([
            'conditions' => ['nullable', 'array'],
            'conditions.*' => ['string', 'max:20'],
            'other_text' => ['nullable', 'string', 'max:300'],
        ]);

        if (! $request->boolean('consent')) {
            $tourist->healthProfile()->delete();

            return redirect()->route('tourist.dashboard')->with('status', 'Your health information has been cleared.');
        }

        $profile = $tourist->healthProfile()->first() ?? $tourist->healthProfile()->make();
        $profile->tourist_id = $tourist->id;
        $profile->consent = true;
        $profile->consent_at = now();
        $profile->other_text = $data['other_text'] ?? null;
        $profile->save();

        $profile->conditions()->delete();
        foreach ($data['conditions'] ?? [] as $condition) {
            TouristHealthCondition::create(['health_profile_id' => $profile->id, 'condition' => $condition]);
        }

        return redirect()->route('tourist.dashboard')->with('status', 'Your health and accessibility information has been saved.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user('tourist')->healthProfile()->delete();

        return redirect()->route('tourist.dashboard')->with('status', 'Your health information has been deleted.');
    }
}
