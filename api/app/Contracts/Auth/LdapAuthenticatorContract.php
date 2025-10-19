<?php

declare(strict_types=1);

namespace App\Contracts\Auth;

use App\Models\IdpProvider;
use App\Models\User;
use Illuminate\Http\Request;

interface LdapAuthenticatorContract
{
    /**
     * @param  array<string,mixed>  $input
     */
    public function authenticate(IdpProvider $provider, array $input, Request $request): User;
}
