<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PostcardSlide;
use App\Services\PostcardImageService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Slides of the homepage "Popular Right Now" carousel: caption, call to
 * action, photo (with generated variants), alt text and focal point.
 */
class AdminPostcardSlideController extends Controller
{
    public function __construct(private readonly PostcardImageService $images)
    {
    }

    public function index(): View
    {
        return view('admin.postcard-slides.index', [
            'slides' => PostcardSlide::ordered()->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.postcard-slides.form', [
            'slide' => new PostcardSlide(['focal_x' => 50, 'focal_y' => 50, 'is_active' => true, 'sort_order' => (int) PostcardSlide::max('sort_order') + 1]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, imageRequired: true);
        $data += $this->images->process($request->file('image'));
        unset($data['image']);

        $slide = PostcardSlide::create($data);

        return redirect()->route('admin.postcard-slides.index')
            ->with(Toast::success('Slide added', "\"{$slide->title}\" is now in the Popular Right Now carousel."));
    }

    public function edit(PostcardSlide $postcardSlide): View
    {
        return view('admin.postcard-slides.form', ['slide' => $postcardSlide]);
    }

    public function update(Request $request, PostcardSlide $postcardSlide): RedirectResponse
    {
        $data = $this->validated($request, imageRequired: false);

        if ($request->hasFile('image')) {
            $previous = clone $postcardSlide;
            $data += $this->images->process($request->file('image'));
            $postcardSlide->update($data);
            $this->images->delete($previous);
        } else {
            $postcardSlide->update($data);
        }

        return redirect()->route('admin.postcard-slides.index')
            ->with(Toast::success('Slide updated', "Changes to \"{$postcardSlide->title}\" have been saved."));
    }

    public function destroy(PostcardSlide $postcardSlide): RedirectResponse
    {
        $title = $postcardSlide->title;
        $this->images->delete($postcardSlide);
        $postcardSlide->delete();

        return back()->with(Toast::success('Slide removed', "\"{$title}\" is no longer in the carousel."));
    }

    private function validated(Request $request, bool $imageRequired): array
    {
        $data = $request->validate([
            'kicker' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:100'],
            'cta_label' => ['required', 'string', 'max:60'],
            // A path on this site ("/destinations/mount-apo-natural-park") or a full web address.
            'cta_url' => ['required', 'string', 'max:255', 'regex:#^(/|https?://)#'],
            // Real alt text describing the picture, not its category.
            'alt_text' => ['required', 'string', 'min:10', 'max:255'],
            'focal_x' => ['required', 'integer', 'between:0,100'],
            'focal_y' => ['required', 'integer', 'between:0,100'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            'image' => [
                $imageRequired ? 'required' : 'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
                'dimensions:min_width='.PostcardImageService::MIN_WIDTH.',min_height='.PostcardImageService::MIN_HEIGHT,
            ],
        ], [
            'image.dimensions' => 'The photo must be at least '.PostcardImageService::MIN_WIDTH.' x '.PostcardImageService::MIN_HEIGHT.' pixels so it stays sharp on a full-width stage.',
            'cta_url.regex' => 'Enter a path starting with / (for example /destinations) or a full https:// address.',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
