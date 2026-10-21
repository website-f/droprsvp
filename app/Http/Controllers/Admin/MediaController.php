<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Support\Dates;
use App\Support\ImageOptimizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaController extends Controller
{
    /** Detected image type => the extension we store it under. */
    private const ALLOWED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];

    /** Upload an image → stored on the public disk, returns its URL. */
    public function store(Request $request): JsonResponse
    {
        // Deliberately NOT using the `mimes`/`image` rules or ->store(): both rely on
        // the fileinfo extension to guess the MIME/extension, which isn't guaranteed
        // on shared hosting ("Unable to guess the MIME type…"). We instead verify the
        // file by reading its actual header bytes with getimagesize() (GD, not
        // fileinfo) — a stronger, content-based check — and store it under an
        // extension we derive ourselves.
        $request->validate([
            'file' => ['required', 'file', 'max:5120'], // ≤ 5 MB
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $info = @getimagesize($file->getRealPath());

        // Raster formats only. SVG (getimagesize → false) stays rejected — it can
        // carry <script>, a stored-XSS vector on this same-origin, any-user endpoint.
        if ($info === false || ! isset(self::ALLOWED[$info[2]])) {
            throw ValidationException::withMessages([
                'file' => 'Please upload a JPG, PNG, WEBP or GIF image.',
            ]);
        }

        $name = Str::random(40).'.'.self::ALLOWED[$info[2]];

        // Write with a native move (move_uploaded_file/rename) rather than
        // Storage::store()/storeAs(), whose Flysystem write path can invoke a
        // finfo-based MIME detector — which throws on hosts that don't load the
        // fileinfo extension for the web SAPI. $disk->path()/url() are plain string
        // ops, and this still respects Storage::fake() in tests.
        $disk = Storage::disk('public');
        $file->move($disk->path('cms'), $name);

        // Shrink oversized uploads in place. A phone photo is 4000px wide and
        // several megabytes; the largest slot we ever render it into is a
        // fraction of that, so everything above ImageOptimizer::MAX_EDGE was
        // pure download cost — which is why galleries of them load slowly.
        //
        // Best effort by design: if GD is missing or the file defeats it, the
        // original stays and the upload still succeeds.
        ImageOptimizer::optimise($disk->path('cms/'.$name));

        // …then a grid-sized copy in cms/thumbs/. Galleries and event cards load
        // that instead of the full image, which is what made them slow to
        // appear: a dozen 2000px tiles is megabytes before anything is visible.
        // Also best effort — the frontend falls back to the original on error.
        ImageOptimizer::thumbnail($disk->path('cms/'.$name));

        // Into the Media Library, after optimising, so the size and dimensions
        // it records are the ones actually served.
        $media = Media::record('cms/'.$name, $request->user()?->id, $originalName);

        // `url` stays first-class: every existing uploader reads just that.
        return response()->json(['url' => $disk->url('cms/'.$name), 'media' => $media->payload($request->user())]);
    }

    /** The Media Library page (Content → Media). The images themselves load from index(). */
    public function page()
    {
        return inertia('admin/cms/media/index');
    }

    /**
     * The library as JSON, newest first, 40 at a time — for the Media page and
     * for the picker in the page and email builders. Admins get every image;
     * anyone else, their own.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $query = Media::visibleTo($user)->with('user:id,name')->latest('created_at')->latest('id');

        if (($q = trim((string) ($data['q'] ?? ''))) !== '') {
            $query->where(fn ($w) => $w->where('original_name', 'like', "%{$q}%")->orWhere('alt', 'like', "%{$q}%"));
        }

        if (! empty($data['month'])) {
            $start = Carbon::createFromFormat('Y-m-d', $data['month'].'-01', Dates::tz())->startOfMonth()->utc();
            $query->whereBetween('created_at', [$start, $start->copy()->addMonth()->subSecond()]);
        }

        $page = $query->paginate(40);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Media $m) => $m->payload($user))->values(),
            'next' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'total' => $page->total(),
            // The "All dates" filter, only with the first page.
            'months' => $page->currentPage() === 1 ? $this->months($user) : null,
        ]);
    }

    /** Rename an image or give it alt text. */
    public function update(Request $request, Media $media): JsonResponse
    {
        abort_unless($media->editableBy($request->user()), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'alt' => ['nullable', 'string', 'max:300'],
        ]);

        $media->update(array_filter([
            'original_name' => isset($data['name']) ? Media::cleanName($data['name']) : null,
        ]) + (array_key_exists('alt', $data) ? ['alt' => $data['alt']] : []));

        return response()->json(['media' => $media->fresh('user')->payload($request->user())]);
    }

    /**
     * Delete an image: the file, its thumbnail and the library entry. Anywhere
     * it is still used shows a missing image from then on — the library page
     * says so before asking.
     */
    public function destroy(Request $request, Media $media): JsonResponse
    {
        abort_unless($media->editableBy($request->user()), 403);

        $disk = Storage::disk('public');
        $disk->delete([
            $media->path,
            trim(dirname($media->path), './').'/'.ImageOptimizer::THUMB_DIR.'/'.basename($media->path),
        ]);
        $media->delete();

        return response()->json(['ok' => true]);
    }

    /** Months that have images in them, newest first: ["2026-10" => "October 2026", …]. */
    private function months($user): array
    {
        return Media::visibleTo($user)
            ->orderByDesc('created_at')
            ->pluck('created_at')
            ->filter()
            ->mapWithKeys(function ($at) {
                $local = Carbon::parse($at)->setTimezone(Dates::tz());

                return [$local->format('Y-m') => $local->format('F Y')];
            })
            ->all();
    }
}
