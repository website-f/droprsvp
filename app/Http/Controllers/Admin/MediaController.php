<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    /** Upload an image → stored on the public disk, returns its URL. */
    public function store(Request $request): JsonResponse
    {
        // Raster formats only. Laravel's generic `image` rule ALSO allows SVG,
        // which can carry <script> — a stored-XSS vector since this endpoint is
        // open to any signed-in user and returns a same-origin URL.
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'], // ≤ 5 MB
        ]);

        $path = $request->file('file')->store('cms', 'public');

        return response()->json(['url' => Storage::disk('public')->url($path)]);
    }
}
