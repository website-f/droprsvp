<?php

namespace Tests\Feature\Chat;

use App\Mail\ChatUnreadMail;
use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\Chat\Report;
use App\Models\Chat\UserState;
use App\Models\User;
use App\Services\Chat\Messenger;
use App\Support\Chat\ChatLink;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\IpGuard;
use App\Support\Chat\PollToken;
use App\Support\Chat\Realtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** One-to-one chat: requests, safety, polling, images, moderation, email. */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    private User $ali;

    private User $mei;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
        $this->ali = User::factory()->create(['name' => 'Ali', 'created_at' => now()->subMonth()]);
        $this->mei = User::factory()->create(['name' => 'Mei', 'created_at' => now()->subMonth()]);
    }

    private function send(User $from, User|Conversation $to, ?string $body = 'Hello there', ?UploadedFile $image = null)
    {
        return $this->actingAs($from)->postJson('/chat/messages', array_filter([
            $to instanceof User ? 'recipient_id' : 'conversation_id' => $to->id,
            // As if from a "Message" button: these tests are about what happens next.
            'reach' => $to instanceof User ? ChatLink::key($from->id, $to->id) : null,
            'body' => $body,
            'image' => $image,
        ]));
    }

    private function poll(User $u, array $q = [])
    {
        $token = PollToken::issue($u->id);

        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/chat/poll?'.http_build_query($q));
    }

    // ---- requests ---------------------------------------------------------------

    public function test_a_stranger_starts_a_request_with_limits(): void
    {
        $this->send($this->ali, $this->mei)->assertOk();
        $c = Conversation::firstOrFail();
        $this->assertSame('request', $c->status);
        $this->assertTrue($c->isRequestFor($this->mei->id));

        $this->send($this->ali, $c, 'Second')->assertOk();
        $this->send($this->ali, $c, 'Third')->assertOk();
        $this->send($this->ali, $c, 'Fourth')->assertStatus(422)->assertJsonFragment(['message' => 'Your message request is waiting. You can send more once Mei accepts.']);

        // No images in a request.
        // (Checked before the limit, on a fresh pair.)
        $kim = User::factory()->create(['created_at' => now()->subMonth()]);
        $this->send($this->ali, $kim, null, UploadedFile::fake()->image('a.jpg'))->assertStatus(422);

        // Mei sees it under requests, not the badge.
        $this->assertSame(1, Messenger::requestCount($this->mei->id));
        $this->assertSame(0, Messenger::unreadTotal($this->mei->id));

        // Replying accepts it.
        $this->send($this->mei, $c, 'Hi Ali')->assertOk();
        $this->assertSame('active', $c->fresh()->status);
        $this->send($this->ali, $c, 'Great')->assertOk();
    }

    public function test_followers_and_buyers_skip_the_request(): void
    {
        Role::findOrCreate('organizer', 'web');
        $this->mei->assignRole('organizer');
        $this->ali->following()->attach($this->mei->id);   // Ali follows Mei

        $this->send($this->mei, $this->ali)->assertOk();

        $this->assertSame('active', Conversation::firstOrFail()->status);
    }

    public function test_declined_requests_and_blocks_stop_messages(): void
    {
        $this->send($this->ali, $this->mei)->assertOk();
        $c = Conversation::firstOrFail();

        $this->actingAs($this->mei)->postJson("/chat/conversations/{$c->id}/decline")->assertOk();
        $this->send($this->ali, $c, 'Again?')->assertStatus(422);

        $kim = User::factory()->create(['created_at' => now()->subMonth()]);
        $this->actingAs($kim)->postJson("/chat/users/{$this->ali->id}/block")->assertOk();
        $this->send($this->ali, $kim)->assertStatus(422)->assertJsonFragment(['message' => 'You can’t message this person.']);
    }

    // ---- anti-spam ---------------------------------------------------------------

    public function test_the_same_text_to_many_people_is_refused(): void
    {
        $text = 'Cheap tickets at my site, DM me now!';
        foreach (range(1, 2) as $i) {
            $this->send($this->ali, User::factory()->create(['created_at' => now()->subMonth()]), $text)->assertOk();
        }

        $this->send($this->ali, User::factory()->create(['created_at' => now()->subMonth()]), $text)
            ->assertStatus(422)->assertJsonFragment(['message' => 'This looks like the same message going to many people, so it was not sent.']);
    }

    public function test_brand_new_accounts_can_start_few_conversations(): void
    {
        $fresh = User::factory()->create(['created_at' => now()]);

        foreach (range(1, 3) as $i) {
            $this->send($fresh, User::factory()->create(), "Hi number {$i}, nice to meet you")->assertOk();
        }

        $this->send($fresh, User::factory()->create(), 'Another one here')->assertStatus(422);
    }

    // ---- reading and polling ---------------------------------------------------------

    public function test_a_quiet_poll_touches_no_database(): void
    {
        $this->ali->following()->attach($this->mei->id);
        $this->send($this->mei, $this->ali)->assertOk();
        IpGuard::reload();

        $first = $this->poll($this->ali, ['mode' => 'inbox'])->assertOk()->json();
        $this->assertSame(1, $first['counts']['unread']);
        $this->assertCount(1, $first['inbox']);

        DB::enableQueryLog();
        $quiet = $this->poll($this->ali, ['mode' => 'inbox', 'v' => $first['v'], 'bv' => $first['bv']])->assertOk()->json();
        $this->assertSame([], DB::getQueryLog());
        $this->assertArrayNotHasKey('inbox', $quiet);
        $this->assertGreaterThan(0, $quiet['n']);
    }

    public function test_open_conversation_polls_deliver_messages_and_read_receipts(): void
    {
        $this->ali->following()->attach($this->mei->id);
        $this->send($this->mei, $this->ali, 'One')->assertOk();
        $c = Conversation::firstOrFail();
        $first = Message::firstOrFail();

        $this->send($this->mei, $c, 'Two')->assertOk();

        // Ali has the conversation open and visible: gets "Two", and reads it.
        $r = $this->poll($this->ali, ['mode' => 'open', 'c' => $c->id, 'after' => $first->id, 'vis' => 1])->assertOk()->json();
        $this->assertSame(['Two'], array_column($r['open']['messages'], 'body'));
        $this->assertSame(0, Messenger::unreadTotal($this->ali->id));

        // Mei's next poll shows Ali has read up to the last message.
        $m = $this->poll($this->mei, ['mode' => 'open', 'c' => $c->id, 'after' => 0])->assertOk()->json();
        $this->assertSame((int) $c->fresh()->last_message_id, $m['open']['their_read_id']);
    }

    public function test_typing_shows_on_the_other_side_only(): void
    {
        $this->ali->following()->attach($this->mei->id);
        $this->send($this->mei, $this->ali)->assertOk();
        $c = Conversation::firstOrFail();

        $this->actingAs($this->mei)->postJson("/chat/conversations/{$c->id}/typing")->assertOk();

        $this->assertTrue($this->poll($this->ali, ['mode' => 'open', 'c' => $c->id])->json('t'));
        $this->assertFalse($this->poll($this->mei, ['mode' => 'open', 'c' => $c->id])->json('t'));
    }

    public function test_polling_needs_a_valid_token(): void
    {
        $this->getJson('/chat/poll')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer 1.9999999999.forged')->getJson('/chat/poll')->assertStatus(401);
    }

    public function test_hammering_the_poll_gets_the_ip_banned(): void
    {
        ChatSettings::save(['poll_limit_per_minute' => 20, 'ban_strikes' => 3]);

        $statuses = [];
        for ($i = 0; $i < 26; $i++) {
            $statuses[] = $this->poll($this->ali)->status();
        }

        $this->assertContains(429, $statuses);
        $this->assertSame(403, end($statuses));
        $this->assertTrue(IpGuard::isBanned('127.0.0.1'));

        // Banned from the chat pages too, until lifted.
        $this->actingAs($this->ali)->get('/messages')->assertForbidden();
        IpGuard::unban('127.0.0.1');
        $this->assertFalse(IpGuard::isBanned('127.0.0.1'));
    }

    // ---- images --------------------------------------------------------------------

    public function test_images_are_private_to_the_conversation(): void
    {
        Storage::fake('local');
        $this->ali->following()->attach($this->mei->id);
        $this->send($this->mei, $this->ali)->assertOk();
        $c = Conversation::firstOrFail();

        $r = $this->send($this->ali, $c, null, UploadedFile::fake()->image('beach.jpg', 3000, 2000))->assertOk()->json('message');
        $m = Message::latest('id')->firstOrFail();

        $this->assertNotNull($m->image_path);
        $this->assertStringStartsWith('chat/', $m->image_path);
        $this->assertNotNull($r['image']['thumb']);

        $this->actingAs($this->mei)->get($r['image']['full'])->assertOk();
        $this->actingAs(User::factory()->create())->get($r['image']['full'])->assertNotFound();

        // Unsend removes the file too.
        $this->actingAs($this->ali)->deleteJson("/chat/messages/{$m->id}")->assertOk()->assertJson(['deleted' => true]);
        Storage::disk('local')->assertMissing($m->image_path);
    }

    public function test_non_images_are_refused(): void
    {
        $this->ali->following()->attach($this->mei->id);
        $this->send($this->mei, $this->ali)->assertOk();

        $this->send($this->ali, Conversation::firstOrFail(), null, UploadedFile::fake()->create('virus.jpg', 10, 'application/x-msdownload'))->assertStatus(422);
    }

    // ---- moderation ------------------------------------------------------------------

    public function test_report_then_admin_suspends(): void
    {
        $this->send($this->ali, $this->mei, 'Rude words here')->assertOk();
        $m = Message::firstOrFail();

        $this->actingAs($this->mei)->postJson('/chat/reports', ['user_id' => $this->ali->id, 'message_id' => $m->id, 'reason' => 'harassment', 'block' => true])->assertOk();
        $report = Report::firstOrFail();
        $this->assertTrue(Messenger::blockedBetween($this->mei->id, $this->ali->id));

        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->getJson("/admin/chat/reports/{$report->id}/context")->assertOk()
            ->assertJsonFragment(['body' => 'Rude words here', 'flagged' => true]);

        $this->actingAs($admin)->post("/admin/chat/reports/{$report->id}/resolve", ['action' => 'suspend', 'days' => 7])->assertRedirect();

        $this->assertTrue(UserState::find($this->ali->id)->isSuspended());
        $this->assertSame('actioned', $report->fresh()->status);
        $this->send($this->ali, User::factory()->create())->assertStatus(422);
    }

    public function test_reports_cannot_reach_into_other_conversations(): void
    {
        $this->send($this->ali, $this->mei, 'Private')->assertOk();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->postJson('/chat/reports', ['user_id' => $this->ali->id, 'message_id' => Message::first()->id, 'reason' => 'spam'])->assertNotFound();
    }

    // ---- announcements ---------------------------------------------------------------

    public function test_broadcasts_reach_everyone_and_mark_read(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        IpGuard::reload();

        $before = $this->poll($this->ali, ['mode' => 'inbox'])->json();

        $this->actingAs($admin)->post('/admin/chat/broadcasts', ['title' => 'New feature', 'body' => 'Chat is here!', 'audience' => 'all'])->assertRedirect();

        $after = $this->poll($this->ali, ['mode' => 'inbox', 'v' => $before['v'], 'bv' => $before['bv']])->json();
        $this->assertNotSame($before['bv'], $after['bv']);
        $this->assertSame(1, $after['counts']['announcements']);

        $this->actingAs($this->ali)->getJson('/chat/announcements')->assertOk()->assertJsonPath('items.0.title', 'New feature');
        $this->assertSame(0, Messenger::unreadBroadcasts($this->ali));
    }

    // ---- email -----------------------------------------------------------------------

    public function test_unread_messages_are_emailed_once_while_away(): void
    {
        $this->ali->following()->attach($this->mei->id);
        $this->send($this->mei, $this->ali, 'Are you coming tonight?')->assertOk();

        Carbon::setTestNow(now()->addMinutes(15));
        $this->artisan('chat:notify')->assertSuccessful();
        Mail::assertSent(ChatUnreadMail::class, fn ($m) => $m->hasTo($this->ali->email) && $m->threads[0]['preview'] === 'Are you coming tonight?');

        // Nothing new: no second email.
        $this->artisan('chat:notify')->assertSuccessful();
        Mail::assertSent(ChatUnreadMail::class, 1);
        Carbon::setTestNow();
    }

    public function test_no_email_when_online_or_opted_out(): void
    {
        $this->ali->following()->attach($this->mei->id);
        $this->send($this->mei, $this->ali)->assertOk();

        Carbon::setTestNow(now()->addMinutes(15));
        Realtime::seen($this->ali->id); // polling right now
        $this->artisan('chat:notify');

        $this->ali->forceFill(['notification_preferences' => ['chat_messages' => false]])->save();
        Cache::forget("chat:seen:{$this->ali->id}");
        $this->artisan('chat:notify');

        Mail::assertNothingSent();
        Carbon::setTestNow();
    }

    // ---- search ------------------------------------------------------------------------

    public function test_search_is_not_a_directory_of_every_user(): void
    {
        User::factory()->create(['name' => 'Zara Random']);

        $this->actingAs($this->ali)->getJson('/chat/search?q=Zara')->assertOk()->assertJsonCount(0, 'people');

        $this->ali->following()->attach(User::factory()->create(['name' => 'Zara Followed'])->id);
        $this->actingAs($this->ali)->getJson('/chat/search?q=Zara')->assertJsonCount(1, 'people')->assertJsonPath('people.0.name', 'Zara Followed');
    }

    public function test_signed_in_pages_carry_the_badge_and_token(): void
    {
        $this->ali->following()->attach($this->mei->id);
        $this->send($this->mei, $this->ali)->assertOk();

        $this->actingAs($this->ali)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($p) => $p->where('chat.unread', 1)->has('chat.token'));
    }

    public function test_the_messages_pages_render(): void
    {
        $this->ali->following()->attach($this->mei->id);
        $this->send($this->mei, $this->ali)->assertOk();
        $c = Messenger::between($this->ali->id, $this->mei->id);

        $this->actingAs($this->ali)->get('/messages')->assertOk()
            ->assertInertia(fn ($p) => $p->component('chat/index')->has('inbox', 1)->where('selected', null)->has('realtime.token'));

        $this->actingAs($this->ali)->get('/messages/'.$c->id)->assertOk()
            ->assertInertia(fn ($p) => $p->component('chat/index')->where('selected.id', $c->id)->has('selected.messages', 1));

        // A brand-new chat with someone: a draft, no conversation yet.
        $zed = User::factory()->create();
        $this->actingAs($this->ali)->get(ChatLink::for($this->ali, $zed->id))->assertOk()
            ->assertInertia(fn ($p) => $p->where('draft.id', $zed->id)->where('draft.request', true));

        // An existing one redirects to it; someone else's chat is not found.
        $this->actingAs($this->ali)->get('/messages/new/'.$this->mei->id)->assertRedirect('/messages/'.$c->id);
        $this->actingAs($zed)->get('/messages/'.$c->id)->assertNotFound();
    }

    public function test_the_moderation_screen_renders_every_tab(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->send($this->mei, $this->ali)->assertOk();
        $this->actingAs($this->ali)->postJson('/chat/reports', ['user_id' => $this->mei->id, 'reason' => 'spam'])->assertOk();

        foreach (['reports' => 'reports.data', 'users' => 'suspended', 'ips' => 'ipBans', 'broadcasts' => 'broadcasts', 'settings' => 'settings'] as $tab => $prop) {
            $this->actingAs($admin)->get('/admin/chat?tab='.$tab)->assertOk()
                ->assertInertia(fn ($p) => $p->component('admin/chat/index')->where('tab', $tab)->has($prop));
        }

        $this->actingAs($this->ali)->get('/admin/chat')->assertForbidden();
    }
}
