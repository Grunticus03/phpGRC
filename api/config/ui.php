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
                'slug' => 'slate',
                'name' => 'Slate',
                'source' => 'bootswatch',
                'supports' => ['mode' => ['dark']],
            ],
            [
                'slug' => 'flatly',
                'name' => 'Flatly',
                'source' => 'bootswatch',
                'supports' => ['mode' => ['light']],
            ],
            [
                'slug' => 'darkly',
                'name' => 'Darkly',
                'source' => 'bootswatch',
                'supports' => ['mode' => ['dark']],
            ],
            [
                'slug' => 'cosmo',
                'name' => 'Cosmo',
                'source' => 'bootswatch',
                'supports' => ['mode' => ['light']],
            ],
        ],
        'packs' => [],
    ],

    'defaults' => [
        'theme' => [
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
