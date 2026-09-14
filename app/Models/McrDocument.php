<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class McrDocument extends Model
{
    protected $table = 'McrDocument';

    protected $primaryKey = 'McrDocumentID';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['McrEstablishmentSnapshot' => 'array'];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(McrSunatSubmission::class, 'McrDocumentID', 'McrDocumentID');
    }
}
