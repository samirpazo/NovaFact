<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McrSunatSubmission extends Model
{
    protected $table = 'McrSunatSubmission';
    protected $primaryKey = 'McrSunatSubmissionID';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'McrSentAt' => 'datetime',
        'McrCompletedAt' => 'datetime',
        'McrMetadata' => 'array',
        'McrNextAttemptAt' => 'datetime',
        'McrLastReconciledAt' => 'datetime',
        'McrClaimedAt' => 'datetime',
        'McrIsAmbiguous' => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(McrDocument::class, 'McrDocumentID', 'McrDocumentID');
    }
}
