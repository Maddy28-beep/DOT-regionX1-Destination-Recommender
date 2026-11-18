<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListingPhoto;
use App\Models\ListingSubmission;
use App\Models\Promotion;
use App\Services\Listings\ListingDraft;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Listing approvals: what establishments changed on their listings. One queue for every kind of listing. Nothing here is public
 * until an admin approves it; the admin sees each bundle in full, the old value beside the new one, and approves all of it or
 * returns it with a note the establishment reads word for word. Drafts that were never sent are not shown.
 */
class AdminListingApprovalController extends Controller
{
    private const TABS = ['waiting' => ListingSubmission::PENDING, 'returned' => ListingSubmission::RETURNED, 'approved' => ListingSubmission::APPROVED];

    /** The columns a change can touch, with a plain name for each. */
    private const FIELD_NAMES = [
        'description' => 'Description', 'price_tier' => 'Price level', 'price_per_night' => 'Price per night', 'price_per_pax' => 'Price per person',
        'price_per_day' => 'Day-use price', 'website_url' => 'Website', 'facebook_url' => 'Facebook', 'instagram_url' => 'Instagram',
        'tiktok_url' => 'TikTok', 'latitude' => 'Latitude', 'longitude' => 'Longitude',
    ];

    public function index(Request $request): View
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'waiting';
        $type = (string) $request->query('type', '');
        $term = trim((string) $request->query('q', ''));

        $all = ListingSubmission::whereIn('status', array_values(self::TABS))->with(['changes', 'account'])->get();
        $counts = collect(self::TABS)->map(fn ($status) => $all->where('status', $status)->count())->all();

        $rows = $all->where('status', self::TABS[$tab])
            ->when($type !== '', fn ($c) => $c->where('listing_kind', $type))
            ->sortBy(fn (ListingSubmission $s) => $tab === 'waiting' ? $s->submitted_at?->timestamp : PHP_INT_MAX - ($s->reviewed_at?->timestamp ?? 0))
            ->values()
            ->map(fn (ListingSubmission $s) => ['submission' => $s, 'listing' => $s->listing(), 'changes' => $this->describe($s)])
            ->when($term !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower(($r['listing']?->name ?? '').' '.($r['submission']->account?->business_name ?? '')), mb_strtolower($term))))
            ->values();

        return view('admin.listing-approvals.index', [
            'rows' => $rows, 'tab' => $tab, 'type' => $type, 'term' => $term, 'counts' => $counts,
            'types' => $all->pluck('listing_kind')->unique()->sort()->values(),
            'overdue' => $all->where('status', ListingSubmission::PENDING)->filter->isOverdue()->count(),
        ]);
    }

    public function decide(Request $request, ListingSubmission $submission): RedirectResponse
    {
        abort_unless($submission->status === ListingSubmission::PENDING, 422);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,return'],
            'note' => ['nullable', 'string', 'max:400', 'required_if:decision,return'],
        ], ['note.required_if' => 'Tell the establishment what to fix, in a sentence or two.', 'decision.required' => 'Choose approve or return.']);

        $admin = $request->user('admin');
        $by = $admin->full_name ?: $admin->email;
        $drafts = app(ListingDraft::class);

        if ($data['decision'] === 'approve') {
            $drafts->approve($submission, $by);
            $toast = Toast::success('Approved', 'The changes are live now and the establishment was told.');
        } else {
            $drafts->returnWith($submission, $by, trim($data['note']));
            $toast = Toast::success('Sent back', 'The establishment can read your note and send it again.');
        }

        return redirect()->route('admin.listing-approvals', ['tab' => $data['decision'] === 'approve' ? 'approved' : 'returned'])->with($toast);
    }

    /**
     * What each change in a bundle says, ready to show: a field with its old and new value, a picture, an offer.
     *
     * @return list<array<string, mixed>>
     */
    private function describe(ListingSubmission $submission): array
    {
        $listing = $submission->listing();
        $out = [];

        foreach ($submission->changes as $change) {
            $payload = $change->payload;
            switch ($change->kind) {
                case 'details':
                case 'position':
                case 'hours':
                    foreach ($payload['attributes'] ?? [] as $column => $new) {
                        $old = $listing?->{$column};
                        $label = self::FIELD_NAMES[$column] ?? ($change->kind === 'hours' ? 'Opening hours' : ucfirst(str_replace('_', ' ', $column)));
                        if ((string) $old !== (string) $new) {
                            $out[] = ['type' => 'field', 'label' => $label, 'old' => $old, 'new' => $new];
                        }
                    }
                    if (isset($payload['itinerary'])) {
                        $out[] = ['type' => 'field', 'label' => 'Day-by-day itinerary', 'old' => null, 'new' => $payload['itinerary']];
                    }
                    break;
                case 'amenities':
                    $out[] = ['type' => 'field', 'label' => 'Amenities', 'old' => null, 'new' => count($payload['amenities'] ?? []).' selected: '.implode(', ', $payload['amenities'] ?? [])];
                    break;
                case 'photo':
                    if ($photo = ListingPhoto::withoutGlobalScopes()->find($payload['id'] ?? 0)) {
                        $out[] = ['type' => 'photo', 'photo' => $photo];
                    }
                    break;
                case 'promotion':
                    if ($promotion = Promotion::withoutGlobalScopes()->find($payload['id'] ?? 0)) {
                        $out[] = ['type' => 'promotion', 'promotion' => $promotion];
                    }
                    break;
            }
        }

        return $out;
    }
}
