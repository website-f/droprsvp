<?php

namespace Tests\Feature\Chat;

use App\Models\Chat\Conversation;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\Order;
use App\Models\OrganizerPost;
use App\Models\User;
use App\Support\Chat\ChatLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Attendees reaching each other: "Message" buttons where people meet, and nowhere else. */
class ChatLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->host = $this->organizer(['name' => 'Host']);
        $this->host->ensureSlug();
        $this->event = Event::create(['user_id' => $this->host->id, 'title' => 'Gig', 'slug' => 'gig', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'show_participants' => true]);
    }

    private function ticket(User $u): void
    {
        Order::create([
            'reference' => 'DRSVP-'.strtoupper(substr(md5($u->email), 0, 6)), 'user_id' => $u->id, 'buyer_name' => $u->name,
            'buyer_email' => $u->email, 'event_id' => $this->event->id, 'status' => 'paid', 'paid_at' => now(), 'total' => 0, 'currency' => 'MYR',
        ]);
    }

    public function test_strangers_cannot_be_reached_by_id(): void
    {
        [$a, $b] = User::factory()->count(2)->create();

        $this->actingAs($a)->get('/messages/new/'.$b->id)->assertRedirect('/messages');
        $this->actingAs($a)->get('/messages/new/'.$b->id.'?k=forged')->assertRedirect('/messages');
        $this->actingAs($a)->postJson('/chat/messages', ['recipient_id' => $b->id, 'body' => 'hi'])->assertStatus(422);
        // Someone else's link is no good either.
        $this->actingAs($a)->postJson('/chat/messages', ['recipient_id' => $b->id, 'body' => 'hi', 'reach' => ChatLink::key($b->id, $a->id)])->assertStatus(422);
        $this->assertSame(0, Conversation::count());
    }

    public function test_a_signed_link_opens_a_request(): void
    {
        [$a, $b] = User::factory()->count(2)->create();

        $this->actingAs($a)->get(ChatLink::for($a, $b->id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('draft.id', $b->id)->where('draft.request', true)->has('draft.reach'));
        $this->actingAs($a)->postJson('/chat/messages', ['recipient_id' => $b->id, 'body' => 'Hi from the gig', 'reach' => ChatLink::key($a->id, $b->id)])->assertOk();

        $this->assertSame('request', Conversation::firstOrFail()->status);
    }

    public function test_event_participants_can_message_each_other(): void
    {
        [$a, $b, $outsider] = User::factory()->count(3)->create();
        $this->ticket($a);
        $this->ticket($b);
        $url = '/en-my/e/gig';

        // A fellow ticket holder gets buttons (never one for themselves).
        $this->actingAs($a)->get($url)->assertInertia(function (Assert $p) use ($a, $b) {
            $list = collect($p->toArray()['props']['participants']['list']);
            $this->assertSame(ChatLink::for($a, $b->id), $list->firstWhere('name', $b->name)['chat']);
            $this->assertNull($list->firstWhere('name', $a->name)['chat']);
        });

        // Someone without a ticket sees no buttons on the list.
        $this->actingAs($outsider)->get($url)->assertInertia(fn (Assert $p) => $this->assertSame(
            [], collect($p->toArray()['props']['participants']['list'])->pluck('chat')->filter()->all()));

        // Guests see none at all.
        auth()->logout();
        $this->get($url)->assertInertia(fn (Assert $p) => $this->assertSame(
            [], collect($p->toArray()['props']['participants']['list'])->pluck('chat')->filter()->all()));
    }

    public function test_discussion_authors_and_members_carry_buttons(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $this->ticket($b);
        $b->following()->attach($this->host->id);
        OrganizerPost::create(['organizer_id' => $this->host->id, 'user_id' => $b->id, 'body' => 'Anyone carpooling?']);
        EventComment::create(['event_id' => $this->event->id, 'user_id' => $b->id, 'body' => 'Is there parking?']);

        $this->actingAs($a)->get('/en-my/o/'.$this->host->slug)->assertInertia(fn (Assert $p) => $p
            ->where('discussion.posts.0.chat', ChatLink::for($a, $b->id))
            ->where('members.attendees.0.chat', ChatLink::for($a, $b->id))
            ->where('members.followers.0.chat', ChatLink::for($a, $b->id)));

        $this->actingAs($a)->get('/en-my/e/gig')->assertInertia(fn (Assert $p) => $p
            ->where('discussion.list.0.chat', ChatLink::for($a, $b->id)));

        // The author sees no button on their own post.
        $this->actingAs($b)->get('/en-my/o/'.$this->host->slug)->assertInertia(fn (Assert $p) => $p->where('discussion.posts.0.chat', null));
    }
}
