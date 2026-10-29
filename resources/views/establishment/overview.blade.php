@extends('layouts.establishment')

@section('title', 'Overview — Partner Dashboard')
@section('page-title', $establishment->business_name)
@section('page-sub', 'Account status and listing summary')

@if ($listing)
    @section('page-actions')
        <a href="{{ route('establishment.listing.form', 'details') }}" class="btn btn-primary" data-modal-form data-title="Edit details" data-sub="What travellers read about you and where they can find you online"><x-icon name="pencil" /> Edit listing</a>
    @endsection
@endif

@section('content')

@php $portalStatus = $establishment->portalStatus(); @endphp

@if ($establishment->status === 'pending')
    <div class="panel">
        <div class="empty-panel">
            <div class="icon"><x-icon name="clock" /></div>
            <h3>Your account is pending review</h3>
            <p>A DOT Region XI admin is verifying your submitted accreditation details. You'll be able to manage
                your listing once approved.</p>
        </div>
    </div>
@elseif ($establishment->status === 'rejected')
    <div class="panel">
        <div class="empty-panel">
            <div class="icon"><x-icon name="x-circle" /></div>
            <h3>Your request was not approved</h3>
            <p>{{ $establishment->review_note ?? 'Please contact DOT Region XI for more information.' }}</p>
        </div>
    </div>
@elseif (! $listing)
    <div class="panel">
        <div class="empty-panel">
            <div class="icon"><x-icon name="link" /></div>
            <h3>Not yet linked to a catalog listing</h3>
            <p>Your account is approved, but a DOT Admin hasn't linked it to an existing catalog entry yet.
                Once linked, you'll be able to edit your listing details here.</p>
        </div>
    </div>
@else
    @php
        $daysLeft = $accreditation && $accreditation->expiration_date ? (int) round(now()->startOfDay()->diffInDays($accreditation->expiration_date->copy()->startOfDay(), false)) : null;
        $tracksStatus = method_exists($listing, 'operatingNotice');
        $closedNotice = $tracksStatus ? $listing->operatingNotice() : null;
    @endphp

    {{--
        One effective status, from EstablishmentAccount::portalStatus(): it folds account approval and accreditation
        validity together, so the alert, the card and the top bar can never disagree. Visibility is not its own card
        for the same reason.
    --}}
    @if ($portalStatus['actionRequired'] && $portalStatus['detail'])
        <div class="adm-alert {{ $portalStatus['tone'] === 'danger' ? 'adm-alert--danger' : '' }}" role="status">
            <x-icon name="alert-triangle" />
            <div><strong>{{ $portalStatus['label'] }}.</strong> {{ $portalStatus['detail'] }}</div>
        </div>
    @endif

    <div class="adm-kpis ptr-kpis">
        <div class="adm-kpi">
            <span class="adm-kpi__ic"><x-icon name="shield-check" /></span>
            <div style="flex:1; min-width:0;">
                <div class="adm-kpi__val" style="font-size:1.1rem;">{{ $portalStatus['label'] }}</div>
                <div class="adm-kpi__lab">
                    @if ($accreditation && $daysLeft !== null)
                        {{ $daysLeft >= 0 ? $daysLeft.' '.\Illuminate\Support\Str::plural('day', $daysLeft).' left' : 'Expired '.$accreditation->expiration_date->format('M j, Y') }}
                    @else
                        {{ ucwords(str_replace('_', ' ', $establishment->listing_kind)) }}
                    @endif
                </div>
                @if ($accreditation && $daysLeft !== null && $daysLeft >= 0)
                    <div class="ptr-bar {{ $daysLeft <= 60 ? 'is-warn' : '' }}"><i style="width:{{ max(4, min(100, round($daysLeft / 365 * 100))) }}%"></i></div>
                @endif
            </div>
        </div>
        <div class="adm-kpi">
            <span class="adm-kpi__ic"><x-icon name="star" /></span>
            <div>
                {{-- Guard on review_count, not rating: rating is cast to decimal:2, so an unrated listing hands the view
                     the truthy string "0.0". --}}
                <div class="adm-kpi__val">{{ ($listing->review_count ?? 0) > 0 ? number_format($listing->rating, 1) : '—' }}</div>
                <div class="adm-kpi__lab">{{ $listing->review_count ?? 0 }} {{ \Illuminate\Support\Str::plural('review', $listing->review_count ?? 0) }}</div>
                @if ($unrepliedCount > 0)<div class="adm-kpi__flag">{{ $unrepliedCount }} awaiting reply</div>@endif
            </div>
        </div>
        <div class="adm-kpi">
            <span class="adm-kpi__ic"><x-icon name="locate" /></span>
            <div>
                <div class="adm-kpi__val">{{ number_format($visits['total']) }}</div>
                <div class="adm-kpi__lab">QR check-ins, last 30 days</div>
                @php $visitChange = \App\Services\Establishment\ListingInsights::change($visits['total'], $visits['before']); @endphp
                @if ($visitChange !== null)<div class="adm-kpi__flag ptr-flag--{{ $visitChange >= 0 ? 'up' : 'down' }}">{{ $visitChange >= 0 ? '↑' : '↓' }} {{ abs($visitChange) }}% on the 30 days before</div>@endif
            </div>
        </div>
        <div class="adm-kpi">
            <span class="adm-kpi__ic"><x-icon name="route" /></span>
            <div>
                @if ($tripPlans)
                    <div class="adm-kpi__val">{{ number_format($tripPlans['total']) }}</div>
                    <div class="adm-kpi__lab">trip plans included you</div>
                    @php $planChange = \App\Services\Establishment\ListingInsights::change($tripPlans['total'], $tripPlans['before']); @endphp
                    @if ($planChange !== null)<div class="adm-kpi__flag ptr-flag--{{ $planChange >= 0 ? 'up' : 'down' }}">{{ $planChange >= 0 ? '↑' : '↓' }} {{ abs($planChange) }}% on the 30 days before</div>@endif
                @else
                    <div class="adm-kpi__val">{{ $photoCount }}</div>
                    <div class="adm-kpi__lab">{{ \Illuminate\Support\Str::plural('photo', $photoCount) }} on your page</div>
                @endif
            </div>
        </div>
    </div>

    <div class="adm-cols ptr-cols2">
        <div class="adm-stack">
            @include('establishment.partials.readiness', ['readiness' => $readiness])

            <div class="adm-card">
                <div class="adm-card__head">
                    <div><h2>Recent reviews</h2><p>What travellers said about your listing</p></div>
                    <a href="{{ route('establishment.reviews') }}" class="adm-link">View all</a>
                </div>
                <div class="adm-card__body">
                    @forelse ($recentReviews as $review)
                        @include('establishment.partials.review', ['review' => $review])
                    @empty
                        <p class="ptr-empty">No reviews yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="adm-stack">
            @if ($tracksStatus)
                <div class="adm-card ptr-status {{ $closedNotice ? 'is-closed' : '' }}">
                    <span class="ptr-status__dot" aria-hidden="true"></span>
                    <div>
                        <h2>{{ $closedNotice ? 'Closed' : 'Open for visitors' }}</h2>
                        <p>{{ $closedNotice ?? 'Travellers see you as open.' }}</p>
                    </div>
                    <a href="{{ route('establishment.listing.form', 'status') }}" class="btn btn-outline btn-sm" data-modal-form data-title="Operating status" data-sub="Tell travellers whether you are open" data-size="narrow">Change</a>
                </div>
            @endif

            <div class="adm-card">
                <div class="adm-card__head">
                    <div><h2>How travellers see you</h2><p>Updates as you edit</p></div>
                    <a href="{{ route('establishment.listing.edit') }}" class="adm-link">My Listing</a>
                </div>
                <div class="adm-card__body ptr-pad">
                    @include('establishment.partials.preview')
                </div>
            </div>
        </div>
    </div>
@endif

@endsection
