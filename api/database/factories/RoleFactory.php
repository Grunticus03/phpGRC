<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->word()) . ' ' . ucfirst($this->faker->unique()->word());
        return [
            'id'   => 'role_' . Str::slug($name, '_'),
            'name' => $name,
        ];
    }

    public function named(string $name): self
    {
        return $this->state(fn () => [
            'id'   => 'role_' . Str::slug($name, '_'),
            'name' => $name,
        ]);
    }
}

