<?php declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Evidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EvidenceResource
 *
 * @property-read Evidence $resource
 */
final class EvidenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var Evidence $evidence */
        $evidence = $this->resource;

        return [
            'id'         => $evidence->id,
            'owner_id'   => $evidence->owner_id,
            'filename'   => $evidence->filename,
            'mime'       => $evidence->mime,
            'size'       => $evidence->size_bytes,
            'sha256'     => $evidence->sha256,
            'version'    => $evidence->version,
            'created_at' => $evidence->created_at->toIso8601String(),
            'updated_at' => $evidence->updated_at->toIso8601String(),
        ];
    }
}
