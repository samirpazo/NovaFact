<?php

namespace App\Services\Documents;

final class RetryBackoffPolicy
{
    public const MAX_PROCESSING_RETRIES = 3;
    public const MAX_POLLS = 8;
    public const MAX_RECONCILIATIONS = 4;

    private const PROCESSING = [10, 30, 120];
    private const POLLING = [5, 15, 30, 60, 120, 300, 600, 900];
    private const RECONCILIATION = [30, 120, 600, 1800];

    public function processing(int $completed): int { return $this->at(self::PROCESSING, $completed); }
    public function polling(int $completed): int { return $this->at(self::POLLING, $completed); }
    public function reconciliation(int $completed): int { return $this->at(self::RECONCILIATION, $completed); }

    private function at(array $values, int $completed): int
    {
        return $values[min(max($completed, 0), count($values) - 1)];
    }
}
