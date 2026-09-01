<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_new_email_creates_the_account(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Fresh User', 'email' => 'fresh@example.com',
            'password' => 'password', 'password_confirmation' => 'password', 'consent' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'fresh@example.com']);
        $this->assertAuthenticated();
    }

    public function test_registering_an_existing_email_is_rejected_not_auto_logged_in(): void
    {
        User::factory()->create(['email' => 'taken@example.com', 'password' => Hash::make('secret-pass')]);

        $this->post(route('register.store'), [
            'name' => 'Impersonator', 'email' => 'taken@example.com',
            'password' => 'password', 'password_confirmation' => 'password', 'consent' => true,
        ])->assertSessionHasErrors('email');

        // Critically: typing an existing email must NOT log you in.
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }
}
