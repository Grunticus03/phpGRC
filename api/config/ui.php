<?php

declare(strict_types=1);

return [
    'manifest' => [
        'version' => '5.3.3',
        'defaults' => [
            'dark' => 'slate',
            'light' => 'flatly',
        ],
        'themes' => [
            ['slug' => 'cerulean', 'name' => 'Cerulean', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'cosmo', 'name' => 'Cosmo', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'cyborg', 'name' => 'Cyborg', 'source' => 'bootswatch', 'supports' => ['mode' => ['dark']]],
            ['slug' => 'darkly', 'name' => 'Darkly', 'source' => 'bootswatch', 'supports' => ['mode' => ['dark']]],
            ['slug' => 'flatly', 'name' => 'Flatly', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'journal', 'name' => 'Journal', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'litera', 'name' => 'Litera', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'lumen', 'name' => 'Lumen', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'lux', 'name' => 'Lux', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'materia', 'name' => 'Materia', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'minty', 'name' => 'Minty', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'morph', 'name' => 'Morph', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'pulse', 'name' => 'Pulse', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'quartz', 'name' => 'Quartz', 'source' => 'bootswatch', 'supports' => ['mode' => ['dark']]],
            ['slug' => 'sandstone', 'name' => 'Sandstone', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'simplex', 'name' => 'Simplex', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'sketchy', 'name' => 'Sketchy', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'slate', 'name' => 'Slate', 'source' => 'bootswatch', 'supports' => ['mode' => ['dark']]],
            ['slug' => 'solar', 'name' => 'Solar', 'source' => 'bootswatch', 'supports' => ['mode' => ['dark']]],
            ['slug' => 'spacelab', 'name' => 'Spacelab', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'superhero', 'name' => 'Superhero', 'source' => 'bootswatch', 'supports' => ['mode' => ['dark']]],
            ['slug' => 'united', 'name' => 'United', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'vapor', 'name' => 'Vapor', 'source' => 'bootswatch', 'supports' => ['mode' => ['dark']]],
            ['slug' => 'yeti', 'name' => 'Yeti', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
            ['slug' => 'zephyr', 'name' => 'Zephyr', 'source' => 'bootswatch', 'supports' => ['mode' => ['light']]],
        ],
        'packs' => [],
    ],

    'defaults' => [
        'theme' => [
            'designer' => [
                'storage' => 'filesystem',
                'filesystem_path' => '/opt/phpgrc/shared/themes',
            ],
            'default' => 'slate',
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
            'title_text' => 'phpGRC — Dashboard',
            'favicon_asset_id' => null,
            'primary_logo_asset_id' => null,
            'secondary_logo_asset_id' => null,
            'header_logo_asset_id' => null,
            'footer_logo_asset_id' => null,
            'footer_logo_disabled' => false,
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
