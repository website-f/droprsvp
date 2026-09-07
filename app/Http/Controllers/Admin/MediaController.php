<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        return response()->json(['url' => $disk->url('cms/'.$name)]);
    }
}
