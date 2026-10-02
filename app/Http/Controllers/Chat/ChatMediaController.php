<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\Message;
use App\Services\Chat\Attachments;
use App\Support\RolePermissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A chat image, to the two people in the conversation — or to an admin with
 * the Chat section, looking at a report. Never public, never cached by a
 * shared proxy.
 */
class ChatMediaController extends Controller
{
    public function __invoke(Request $request, Message $message, string $variant, Attachments $attachments): BinaryFileResponse
    {
        $user = $request->user();
        $allowed = $message->conversation->involves($user->id) || RolePermissions::can($user, 'chat');

        abort_unless($allowed && $message->image_path && ! $message->deleted_at, 404);

        $path = $attachments->absolute($message->image_path, $variant === 'thumb' ? 'thumb' : 'full');
        abort_unless($path, 404);

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
