<?php

namespace App\Jobs;

use App\Services\Fiscal\FiscalOperationProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class ProcessFiscalOperationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $operationId) {}

    public function handle(FiscalOperationProcessor $processor): void
    {
        $processor->process($this->operationId);
    }
}
