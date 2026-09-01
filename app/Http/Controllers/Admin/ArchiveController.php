<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsCategory;
use App\Models\CmsPage;
use App\Models\CmsPost;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The Archive: everything that's been soft-deleted, grouped by type, where a
 * superadmin can restore items or permanently remove them. All destroy actions
 * across the app are soft-deletes, so nothing is truly gone until it's purged here.
 */
class ArchiveController extends Controller
{
    /** type key => [model class, label column, display name]. */
    private const TYPES = [
        'events' => [Event::class, 'title', 'Events'],
        'posts' => [CmsPost::class, 'title', 'Posts'],
        'pages' => [CmsPage::class, 'title', 'Pages'],
        'users' => [User::class, 'name', 'Users'],
        'event-categories' => [EventCategory::class, 'name', 'Event categories'],
        'post-categories' => [CmsCategory::class, 'name', 'Post categories'],
    ];

    public function index(Request $request)
    {
        $type = $request->query('type', 'events');
        if (! isset(self::TYPES[$type])) {
            $type = 'events';
        }

        $tabs = collect(self::TYPES)->map(fn ($t, $key) => [
            'key' => $key,
            'label' => $t[2],
            'count' => $t[0]::onlyTrashed()->count(),
        ])->values();

        [$class] = self::TYPES[$type];
        $items = $class::onlyTrashed()->orderByDesc('deleted_at')->limit(500)->get()
            ->map(fn (Model $m) => $this->row($type, $m))->values();

        return inertia('admin/archive/index', [
            'type' => $type,
            'tabs' => $tabs,
            'items' => $items,
        ]);
    }

    /** Restore one or more trashed items. */
    public function restore(Request $request, string $type)
    {
        [$class] = $this->resolve($type);
        $ids = $this->ids($request);

        $restored = 0;
        $class::onlyTrashed()->whereIn('id', $ids)->get()->each(function (Model $m) use (&$restored) {
            $m->restore();
            $restored++;
        });

        return back()->with('flash_success', "{$restored} item(s) restored.");
    }

    /** Permanently delete one or more trashed items. FK-protected rows are skipped. */
    public function destroy(Request $request, string $type)
    {
        [$class] = $this->resolve($type);
        $ids = $this->ids($request);

        $deleted = 0;
        $blocked = 0;
        $class::onlyTrashed()->whereIn('id', $ids)->get()->each(function (Model $m) use (&$deleted, &$blocked) {
            try {
                $m->forceDelete();
                $deleted++;
            } catch (\Throwable $e) {
                // Still referenced by other records (e.g. an event with paid orders).
                report($e);
                $blocked++;
            }
        });

        $msg = "{$deleted} item(s) permanently deleted.";
        if ($blocked > 0) {
            $msg .= " {$blocked} couldn’t be removed — still referenced by other records.";
        }

        return back()->with($blocked > 0 ? 'flash_error' : 'flash_success', $msg);
    }

    /** @return array{0:class-string<Model>,1:string,2:string} */
    private function resolve(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }

    /** @return array<int,int> */
    private function ids(Request $request): array
    {
        return $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])['ids'];
    }

    private function row(string $type, Model $m): array
    {
        [, $labelCol] = self::TYPES[$type];

        $sublabel = match ($type) {
            'users' => $m->email,
            'event-categories', 'post-categories' => '/'.$m->slug,
            'events', 'posts', 'pages' => $m->slug ? '/'.$m->slug : null,
            default => null,
        };

        return [
            'id' => $m->id,
            'label' => $m->{$labelCol} ?: '(untitled)',
            'sublabel' => $sublabel,
            'deleted_at' => $m->deleted_at?->diffForHumans(),
        ];
    }
}
