<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ApiCredential extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
