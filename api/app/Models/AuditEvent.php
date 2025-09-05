<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Placeholder model for audit events.
 */
final class AuditEvent extends Model
{
    protected $table = 'audit_events';
    protected $guarded = [];
    public $timestamps = false; // occurred_at acts as primary time field
}
