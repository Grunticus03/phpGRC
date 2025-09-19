<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class UserFactory extends Factory
{
    protected $model = User::class;

    /** @var int */
    private static int $seq = 0;

    public function definition(): array
    {
        $n = ++self::$seq;

        return [
            'name' => "Test User {$n}",
            'email' => sprintf('user%04d@example.test', $n),
            'password' => bcrypt('secret'),
            'remember_token' => Str::random(10),
        ];
    }
}

