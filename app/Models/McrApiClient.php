<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McrApiClient extends Model
{
    protected $table = 'McrApiClient';

    protected $primaryKey = 'McrApiClientID';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'McrIsActive' => 'boolean',
            'SecStatus' => 'boolean',
        ];
    }
}
