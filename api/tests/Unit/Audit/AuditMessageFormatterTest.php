<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Models\AuditEvent;
use App\Services\Audit\AuditMessageFormatter;
use Tests\TestCase;

final class AuditMessageFormatterTest extends TestCase
{
    public function test_formats_ui_brand_update_message(): void
    {
        $event = new AuditEvent([
            'action' => 'ui.brand.updated',
            'entity_type' => 'core.setting',
            'entity_id' => 'ui.brand.title_text',
            'meta' => [
                'actor_username' => 'Alice Admin',
                'setting_label' => 'ui.brand.title_text',
                'change_type' => 'update',
                'old_value' => 'Old Title',
                'new_value' => 'New Title',
            ],
        ]);

        $message = AuditMessageFormatter::format($event);

        self::assertSame('Alice Admin updated ui.brand.title_text; Old: Old Title - New: New Title', $message);
    }

    public function test_formats_theme_pack_deleted_message(): void
    {
        $event = new AuditEvent([
            'action' => 'ui.theme.pack.deleted',
            'entity_type' => 'core.setting',
            'entity_id' => 'ui.theme.pack.custom',
            'meta' => [
                'actor_username' => 'Alice Admin',
                'setting_label' => 'ui.theme.pack.custom',
                'change_type' => 'delete',
                'old_value' => 'Custom Pack',
                'new_value' => null,
            ],
        ]);

        $message = AuditMessageFormatter::format($event);

        self::assertSame('Alice Admin deleted ui.theme.pack.custom; Old: Custom Pack - New: null', $message);
    }

    public function test_formats_sidebar_saved_message(): void
    {
        $event = new AuditEvent([
            'action' => 'ui.nav.sidebar.saved',
            'entity_type' => 'core.setting',
            'entity_id' => 'ui.nav.sidebar.default_order',
            'meta' => [
                'actor_username' => 'Alice Admin',
                'setting_label' => 'ui.nav.sidebar.default_order',
                'change_type' => 'update',
                'old_value' => '["dashboard","audit"]',
                'new_value' => '["dashboard","audit","evidence"]',
            ],
        ]);

        $message = AuditMessageFormatter::format($event);

        self::assertSame(
            'Alice Admin saved ui.nav.sidebar.default_order; Old: ["dashboard","audit"] - New: ["dashboard","audit","evidence"]',
            $message
        );
    }
}
