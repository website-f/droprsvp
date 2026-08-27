<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    /** Upload an image (superadmin) → stored on the public disk, returns its URL. */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'image', 'max:5120']]); // ≤ 5 MB

        $path = $request->file('file')->store('cms', 'public');

        return response()->json(['url' => Storage::disk('public')->url($path)]);
    }
}
