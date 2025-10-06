<?php

declare(strict_types=1);

namespace App\Jobs\Avatar;

use App\Services\Avatar\AvatarProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class TranscodeAvatar implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public string $sourcePath,
        public int $targetSize
    ) {}

    public function handle(AvatarProcessor $processor): void
    {
        $sizes = [32, 64, $this->targetSize];
        $processor->process($this->userId, $this->sourcePath, $sizes);
    }
}
