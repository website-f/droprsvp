<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
            // They have just chosen their own password, so the "set your
            // password" gate has nothing left to ask for.
            //
            // This flag is raised for accounts we create FOR someone — at
            // checkout, or by an admin — who then sign in with a password they
            // did not choose. It was only ever cleared by the set-password
            // screen itself and by Google sign-in, so anyone who used "forgot
            // password" instead reset it successfully, signed in, and was still
            // sent straight back to "Set your password".
            'must_set_password' => false,
        ])->save();
    }
}
