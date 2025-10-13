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
            [
                'slug' => 'cerulean',
                'name' => 'Cerulean',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light', 'dark']],
                'variants' => [
                    'light' => ['slug' => 'cerulean', 'name' => 'Cerulean'],
                    'dark' => ['slug' => 'slate', 'name' => 'Slate'],
                ],
            ],
            [
                'slug' => 'cosmo',
                'name' => 'Cosmo',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light', 'dark']],
                'variants' => [
                    'light' => ['slug' => 'cosmo', 'name' => 'Cosmo'],
                    'dark' => ['slug' => 'cyborg', 'name' => 'Cyborg'],
                ],
            ],
            [
                'slug' => 'cyborg',
                'name' => 'Cyborg',
                'source' => 'bootswatch',
                'default_mode' => 'dark',
                'supports' => ['mode' => ['dark', 'light']],
                'variants' => [
                    'dark' => ['slug' => 'cyborg', 'name' => 'Cyborg'],
                    'light' => ['slug' => 'cosmo', 'name' => 'Cosmo'],
                ],
            ],
            [
                'slug' => 'darkly',
                'name' => 'Darkly',
                'source' => 'bootswatch',
                'default_mode' => 'dark',
                'supports' => ['mode' => ['dark', 'light']],
                'variants' => [
                    'dark' => ['slug' => 'darkly', 'name' => 'Darkly'],
                    'light' => ['slug' => 'flatly', 'name' => 'Flatly'],
                ],
            ],
            [
                'slug' => 'flatly',
                'name' => 'Flatly',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light', 'dark']],
                'variants' => [
                    'light' => ['slug' => 'flatly', 'name' => 'Flatly'],
                    'dark' => ['slug' => 'darkly', 'name' => 'Darkly'],
                ],
            ],
            [
                'slug' => 'journal',
                'name' => 'Journal',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light', 'dark']],
                'variants' => [
                    'light' => ['slug' => 'journal', 'name' => 'Journal'],
                    'dark' => ['slug' => 'quartz', 'name' => 'Quartz'],
                ],
            ],
            [
                'slug' => 'litera',
                'name' => 'Litera',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light', 'dark']],
                'variants' => [
                    'light' => ['slug' => 'litera', 'name' => 'Litera'],
                    'dark' => ['slug' => 'vapor', 'name' => 'Vapor'],
                ],
            ],
            [
                'slug' => 'lumen',
                'name' => 'Lumen',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light', 'dark']],
                'variants' => [
                    'light' => ['slug' => 'lumen', 'name' => 'Lumen'],
                    'dark' => ['slug' => 'solar', 'name' => 'Solar'],
                ],
            ],
            [
                'slug' => 'lux',
                'name' => 'Lux',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'lux', 'name' => 'Lux'],
                ],
            ],
            [
                'slug' => 'materia',
                'name' => 'Materia',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'materia', 'name' => 'Materia'],
                ],
            ],
            [
                'slug' => 'minty',
                'name' => 'Minty',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'minty', 'name' => 'Minty'],
                ],
            ],
            [
                'slug' => 'morph',
                'name' => 'Morph',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'morph', 'name' => 'Morph'],
                ],
            ],
            [
                'slug' => 'pulse',
                'name' => 'Pulse',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'pulse', 'name' => 'Pulse'],
                ],
            ],
            [
                'slug' => 'quartz',
                'name' => 'Quartz',
                'source' => 'bootswatch',
                'default_mode' => 'dark',
                'supports' => ['mode' => ['dark', 'light']],
                'variants' => [
                    'dark' => ['slug' => 'quartz', 'name' => 'Quartz'],
                    'light' => ['slug' => 'journal', 'name' => 'Journal'],
                ],
            ],
            [
                'slug' => 'sandstone',
                'name' => 'Sandstone',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'sandstone', 'name' => 'Sandstone'],
                ],
            ],
            [
                'slug' => 'simplex',
                'name' => 'Simplex',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'simplex', 'name' => 'Simplex'],
                ],
            ],
            [
                'slug' => 'sketchy',
                'name' => 'Sketchy',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'sketchy', 'name' => 'Sketchy'],
                ],
            ],
            [
                'slug' => 'slate',
                'name' => 'Slate',
                'source' => 'bootswatch',
                'default_mode' => 'dark',
                'supports' => ['mode' => ['dark', 'light']],
                'variants' => [
                    'dark' => ['slug' => 'slate', 'name' => 'Slate'],
                    'light' => ['slug' => 'cerulean', 'name' => 'Cerulean'],
                ],
            ],
            [
                'slug' => 'solar',
                'name' => 'Solar',
                'source' => 'bootswatch',
                'default_mode' => 'dark',
                'supports' => ['mode' => ['dark', 'light']],
                'variants' => [
                    'dark' => ['slug' => 'solar', 'name' => 'Solar'],
                    'light' => ['slug' => 'lumen', 'name' => 'Lumen'],
                ],
            ],
            [
                'slug' => 'spacelab',
                'name' => 'Spacelab',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'spacelab', 'name' => 'Spacelab'],
                ],
            ],
            [
                'slug' => 'superhero',
                'name' => 'Superhero',
                'source' => 'bootswatch',
                'default_mode' => 'dark',
                'supports' => ['mode' => ['dark', 'light']],
                'variants' => [
                    'dark' => ['slug' => 'superhero', 'name' => 'Superhero'],
                    'light' => ['slug' => 'united', 'name' => 'United'],
                ],
            ],
            [
                'slug' => 'united',
                'name' => 'United',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light', 'dark']],
                'variants' => [
                    'light' => ['slug' => 'united', 'name' => 'United'],
                    'dark' => ['slug' => 'superhero', 'name' => 'Superhero'],
                ],
            ],
            [
                'slug' => 'vapor',
                'name' => 'Vapor',
                'source' => 'bootswatch',
                'default_mode' => 'dark',
                'supports' => ['mode' => ['dark', 'light']],
                'variants' => [
                    'dark' => ['slug' => 'vapor', 'name' => 'Vapor'],
                    'light' => ['slug' => 'litera', 'name' => 'Litera'],
                ],
            ],
            [
                'slug' => 'yeti',
                'name' => 'Yeti',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'yeti', 'name' => 'Yeti'],
                ],
            ],
            [
                'slug' => 'zephyr',
                'name' => 'Zephyr',
                'source' => 'bootswatch',
                'default_mode' => 'light',
                'supports' => ['mode' => ['light']],
                'variants' => [
                    'light' => ['slug' => 'zephyr', 'name' => 'Zephyr'],
                ],
            ],
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
