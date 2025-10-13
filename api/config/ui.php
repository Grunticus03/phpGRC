<?php

declare(strict_types=1);

return [
    'manifest' => [
        'version' => '5.3.8',
        'defaults' => [
            'dark' => 'slate',
            'light' => 'flatly',
        ],
        'themes' => array_map(
            static function (array $theme): array {
                $slug = $theme['slug'];

                return [
                    'slug' => $slug,
                    'name' => $theme['name'],
                    'source' => 'bootswatch',
                    'default_mode' => $theme['default_mode'],
                    'supports' => ['mode' => ['light', 'dark']],
                    'variants' => [
                        'light' => ['slug' => $slug.':primary', 'name' => 'Primary'],
                        'dark' => ['slug' => $slug.':dark', 'name' => 'Dark'],
                    ],
                ];
            },
            [
                ['slug' => 'brite', 'name' => 'Brite', 'default_mode' => 'light'],
                ['slug' => 'cerulean', 'name' => 'Cerulean', 'default_mode' => 'light'],
                ['slug' => 'cosmo', 'name' => 'Cosmo', 'default_mode' => 'light'],
                ['slug' => 'cyborg', 'name' => 'Cyborg', 'default_mode' => 'dark'],
                ['slug' => 'darkly', 'name' => 'Darkly', 'default_mode' => 'dark'],
                ['slug' => 'flatly', 'name' => 'Flatly', 'default_mode' => 'light'],
                ['slug' => 'journal', 'name' => 'Journal', 'default_mode' => 'light'],
                ['slug' => 'litera', 'name' => 'Litera', 'default_mode' => 'light'],
                ['slug' => 'lumen', 'name' => 'Lumen', 'default_mode' => 'light'],
                ['slug' => 'lux', 'name' => 'Lux', 'default_mode' => 'light'],
                ['slug' => 'materia', 'name' => 'Materia', 'default_mode' => 'light'],
                ['slug' => 'minty', 'name' => 'Minty', 'default_mode' => 'light'],
                ['slug' => 'morph', 'name' => 'Morph', 'default_mode' => 'light'],
                ['slug' => 'pulse', 'name' => 'Pulse', 'default_mode' => 'light'],
                ['slug' => 'quartz', 'name' => 'Quartz', 'default_mode' => 'dark'],
                ['slug' => 'sandstone', 'name' => 'Sandstone', 'default_mode' => 'light'],
                ['slug' => 'simplex', 'name' => 'Simplex', 'default_mode' => 'light'],
                ['slug' => 'sketchy', 'name' => 'Sketchy', 'default_mode' => 'light'],
                ['slug' => 'slate', 'name' => 'Slate', 'default_mode' => 'dark'],
                ['slug' => 'solar', 'name' => 'Solar', 'default_mode' => 'dark'],
                ['slug' => 'spacelab', 'name' => 'Spacelab', 'default_mode' => 'light'],
                ['slug' => 'superhero', 'name' => 'Superhero', 'default_mode' => 'dark'],
                ['slug' => 'united', 'name' => 'United', 'default_mode' => 'light'],
                ['slug' => 'vapor', 'name' => 'Vapor', 'default_mode' => 'dark'],
                ['slug' => 'yeti', 'name' => 'Yeti', 'default_mode' => 'light'],
                ['slug' => 'zephyr', 'name' => 'Zephyr', 'default_mode' => 'light'],
            ]
        ),
        'packs' => [],
    ],

    'defaults' => [
        'theme' => [
            'designer' => [
                'storage' => 'filesystem',
                'filesystem_path' => '/opt/phpgrc/shared/themes',
            ],
            'default' => 'slate',
            'mode' => 'dark',
            'allow_user_override' => true,
            'force_global' => false,
            'overrides' => [
                'color.primary' => '#0d6efd',
                'color.surface' => '#1b1e21',
                'color.text' => '#f8f9fa',
                'shadow' => 'default',
                'spacing' => 'default',
                'typeScale' => 'medium',
                'motion' => 'full',
            ],
        ],
        'nav' => [
            'sidebar' => [
                'default_order' => [],
            ],
        ],
        'brand' => [
            'title_text' => 'phpGRC',
            'favicon_asset_id' => null,
            'primary_logo_asset_id' => null,
            'secondary_logo_asset_id' => null,
            'header_logo_asset_id' => null,
            'footer_logo_asset_id' => null,
            'footer_logo_disabled' => false,
            'assets' => [
                'filesystem_path' => '/opt/phpgrc/shared/brands',
            ],
        ],
    ],

    'user_defaults' => [
        'theme' => null,
        'mode' => null,
        'overrides' => [
            'color.primary' => '#0d6efd',
            'color.surface' => '#1b1e21',
            'color.text' => '#f8f9fa',
            'shadow' => 'default',
            'spacing' => 'default',
            'typeScale' => 'medium',
            'motion' => 'full',
        ],
        'sidebar' => [
            'collapsed' => false,
            'width' => 280,
            'order' => [],
        ],
    ],

    'overrides' => [
        'allowed_keys' => [
            'color.primary',
            'color.surface',
            'color.text',
            'shadow',
            'spacing',
            'typeScale',
            'motion',
        ],
        'shadow_presets' => ['none', 'default', 'light', 'heavy', 'custom'],
        'spacing_presets' => ['narrow', 'default', 'wide'],
        'type_scale_presets' => ['small', 'medium', 'large'],
        'motion_presets' => ['none', 'limited', 'full'],
    ],
];
