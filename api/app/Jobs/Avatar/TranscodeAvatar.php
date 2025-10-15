<?php

declare(strict_types=1);

namespace App\Jobs\Avatar;

use App\Services\Avatar\AvatarProcessor;
use App\Support\Laravel\BusDispatchable;
use App\Support\Laravel\InteractsWithQueue;
use App\Support\Laravel\Queueable;
use App\Support\Laravel\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

final class TranscodeAvatar implements ShouldQueue
{
    use BusDispatchable;
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
