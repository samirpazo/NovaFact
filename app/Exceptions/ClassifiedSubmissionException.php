<?php

namespace App\Exceptions;

use App\Enums\FailureCategory;
use App\Enums\ProcessingCheckpoint;
use RuntimeException;
use Throwable;

final class ClassifiedSubmissionException extends RuntimeException
{
    public function __construct(
        public readonly FailureCategory $category,
        public readonly ProcessingCheckpoint $checkpoint,
        public readonly bool $retryable,
        public readonly bool $ambiguous,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Classified fiscal processing failure.', 0, $previous);
    }

    public static function ambiguous(ProcessingCheckpoint $checkpoint, Throwable $previous): self
    {
        return new self(FailureCategory::AmbiguousSubmission, $checkpoint, false, true, $previous);
    }
}
