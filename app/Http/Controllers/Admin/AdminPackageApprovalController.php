<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ModalForms;
use App\Http\Controllers\Controller;
use App\Models\EstablishmentAccount;
use App\Models\Notification;
use App\Models\Package;
use App\Services\Amenities\AmenityStore;
use App\Services\Packages\PackageChecks;
use App\Support\Toast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Package approvals: the packages tour operators have sent in. They stay hidden from travellers until a DOT admin
 * approves them. DOT approves (it goes live) or asks for changes with a note the operator reads word for word. An
 * operator whose own accreditation has lapsed cannot have a package approved.
 */
class AdminPackageApprovalController extends Controller
{
    use ModalForms;

    /** A package waiting longer than this many days is marked overdue. */
    public const OVERDUE_DAYS = 3;

    private const TABS = ['waiting' => Package::STATUS_IN_REVIEW, 'changes' => Package::STATUS_CHANGES, 'approved' => Package::STATUS_LIVE];

    public function index(Request $request): View
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) || $request->query('tab') === 'all' ? $request->query('tab') : 'waiting';
        $term = trim((string) $request->query('q', ''));

        // Packages that went through review: everything an operator sent in, whatever became of it. DOT's own
        // packages (never submitted) are not part of this queue.
        $sent = Package::query()->whereNotNull('submitted_at')->with(['tourOperator.accreditationRecords', 'itineraryDays'])->get();

        $counts = [
            'waiting' => $sent->where('status', Package::STATUS_IN_REVIEW)->count(),
            'changes' => $sent->where('status', Package::STATUS_CHANGES)->count(),
            'approved' => $sent->whereIn('status', [Package::STATUS_LIVE, Package::STATUS_PAUSED])->count(),
            'all' => $sent->count(),
        ];

        $rows = $sent
            ->when($tab === 'approved', fn ($c) => $c->whereIn('status', [Package::STATUS_LIVE, Package::STATUS_PAUSED]))
            ->when(isset(self::TABS[$tab]) && $tab !== 'approved', fn ($c) => $c->where('status', self::TABS[$tab]))
            ->when($term !== '', fn ($c) => $c->filter(fn (Package $p) => str_contains(mb_strtolower($p->name.' '.$p->tourOperator?->name), mb_strtolower($term))))
            ->sortBy(fn (Package $p) => $p->status === Package::STATUS_IN_REVIEW ? $p->submitted_at->timestamp : PHP_INT_MAX - ($p->reviewed_at?->timestamp ?? 0))
            ->values();

        $checks = app(PackageChecks::class);
        $failing = $rows->mapWithKeys(fn (Package $p) => [$p->id => count(array_filter($checks->for($p), fn ($c) => ! $c['ok']))]);

        $decided = $sent->filter(fn (Package $p) => $p->reviewed_at && $p->submitted_at && $p->reviewed_at->gte($p->submitted_at));
        $averageDays = $decided->isEmpty() ? null : round($decided->avg(fn (Package $p) => $p->submitted_at->diffInHours($p->reviewed_at) / 24), 1);

        return view('admin.package-approvals.index', [
            'rows' => $rows, 'tab' => $tab, 'term' => $term, 'counts' => $counts, 'failing' => $failing,
            'overdue' => $sent->where('status', Package::STATUS_IN_REVIEW)->filter(fn (Package $p) => $p->submitted_at->diffInDays(now()) > self::OVERDUE_DAYS)->count(),
            'approvedThisMonth' => $sent->where('status', Package::STATUS_LIVE)->filter(fn (Package $p) => $p->reviewed_at && $p->reviewed_at->isSameMonth(Carbon::now()))->count(),
            'averageDays' => $averageDays,
        ]);
    }

    public function review(Request $request, Package $package): View|Response
    {
        abort_if($package->submitted_at === null, 404);

        $package->load(['tourOperator.accreditationRecords', 'itineraryDays', 'inclusions', 'events', 'photos']);

        return $this->modalView($request, 'admin.package-approvals.review', 'admin.package-approvals._review', [
            'package' => $package, 'featureKeys' => app(AmenityStore::class)->keys('package', $package), 'checks' => app(PackageChecks::class)->for($package), 'tab' => (string) $request->query('tab', 'waiting'),
        ]);
    }

    public function decide(Request $request, Package $package): RedirectResponse|JsonResponse
    {
        abort_unless($package->status === Package::STATUS_IN_REVIEW, 422);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,changes'],
            'note' => ['nullable', 'string', 'max:400', 'required_if:decision,changes'],
        ], ['note.required_if' => 'Tell the operator what to fix, in a sentence or two.', 'decision.required' => 'Choose approve or ask for changes.']);

        $admin = $request->user('admin');
        $package->load('tourOperator.accreditationRecords');

        if ($data['decision'] === 'approve') {
            [$accredited, $why] = app(PackageChecks::class)->operator($package->tourOperator);
            if (! $accredited) {
                throw ValidationException::withMessages(['decision' => "Cannot approve yet: {$why}. Ask the operator to renew first."]);
            }

            $package->transitionTo(Package::STATUS_LIVE, 'approved', 'admin', $admin->full_name ?: $admin->email);
            $this->tell($package, 'Package approved', "{$package->name} is approved and live on ExploreDVO.");
            $toast = Toast::success('Package approved', "It is live now and {$package->tourOperator?->name} was told.");
        } else {
            $note = trim($data['note']);
            $package->transitionTo(Package::STATUS_CHANGES, 'changes_requested', 'admin', $admin->full_name ?: $admin->email, $note);
            $this->tell($package, 'DOT asked for a change to a package', "{$package->name}: {$note}");
            $toast = Toast::success('Note sent', ($package->tourOperator?->name ?? 'The operator').' can edit it and send it again.');
        }

        return $this->modalSaved($request, route('admin.package-approvals', ['tab' => $request->input('tab', 'waiting')]), $toast);
    }

    public function pause(Request $request, Package $package): RedirectResponse|JsonResponse
    {
        abort_unless($package->status === Package::STATUS_LIVE, 422);
        $admin = $request->user('admin');

        $package->transitionTo(Package::STATUS_PAUSED, 'paused', 'admin', $admin->full_name ?: $admin->email);
        $this->tell($package, 'A package was paused by DOT', "{$package->name} is hidden from travellers. Contact DOT Region XI to find out why.");

        return $this->modalSaved($request, route('admin.package-approvals', ['tab' => 'approved']), Toast::success('Paused', 'It is hidden from travellers and the operator was told.'));
    }

    public function resume(Request $request, Package $package): RedirectResponse|JsonResponse
    {
        abort_unless($package->status === Package::STATUS_PAUSED, 422);
        $admin = $request->user('admin');

        $package->transitionTo(Package::STATUS_LIVE, 'resumed', 'admin', $admin->full_name ?: $admin->email);
        $this->tell($package, 'A package is live again', "{$package->name} is visible to travellers again.");

        return $this->modalSaved($request, route('admin.package-approvals', ['tab' => 'approved']), Toast::success('Live again', 'Travellers can see it.'));
    }

    /** A notification for every account that manages the package's operator. */
    private function tell(Package $package, string $title, string $message): void
    {
        if (! $package->tour_operator_id) {
            return;
        }

        EstablishmentAccount::where('listing_kind', 'tour_operator')->where('matched_listing_id', $package->tour_operator_id)->get()
            ->each(fn (EstablishmentAccount $account) => Notification::create([
                'user_type' => 'establishment', 'user_id' => $account->id, 'title' => $title, 'message' => $message, 'is_read' => false,
            ]));
    }
}
