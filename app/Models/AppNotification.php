<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * An in-app notification for one user — system events (policy flags, appeal
 * outcomes), and fanned-out broadcasts from admins. Shown in the bell inbox.
 */
class AppNotification extends Model
{
    protected $table = 'app_notifications';

    protected $fillable = ['user_id', 'type', 'title', 'body', 'url', 'level', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Notify a single user. */
    public static function notify(User|int $user, array $data): self
    {
        return self::create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'type' => $data['type'] ?? 'system',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'level' => $data['level'] ?? 'info',
        ]);
    }

    /**
     * Fan a notification out to many users at once (broadcast). Returns the count.
     *
     * @param  Collection<int, int>|array<int, int>  $userIds
     */
    public static function notifyMany($userIds, array $data): int
    {
        $ids = collect($userIds)->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $ids->map(fn ($id) => [
            'user_id' => $id,
            'type' => $data['type'] ?? 'broadcast',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'level' => $data['level'] ?? 'info',
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // Chunked insert keeps a large broadcast off a single giant query.
        foreach (array_chunk($rows, 500) as $chunk) {
            self::insert($chunk);
        }

        return $ids->count();
    }
}
