<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Please agree to the terms to continue.',
            'email.unique' => 'You already have a DropRSVP account with this email — please sign in instead.',
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Public sign-up = a free attendee account. They can subscribe to Premium
        // once logged in; hosting/selling is the separate "Register as a vendor"
        // flow (/get-started) which grants the organizer role instead.
        $user->assignRole(Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']));

        // Warm welcome (deferred, non-fatal — a mail hiccup must not block sign-up).
        \App\Support\Mailer::defer($user->email, new WelcomeMail($user));

        return $user;
    }
}
