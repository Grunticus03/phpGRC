<?php

declare(strict_types=1);

namespace App\Services\Metrics;

interface MetricsCalculator
{
    /**
     * Implementations return a typed associative array (see concrete docs).
     *
     * The parameter denotes how many calendar days are included in the window.
     *
     * @return array<string,mixed>
     */
    public function compute(int $windowDays): array;
}