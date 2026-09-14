<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class McrOutboxEvent extends Model
{
    protected $table = 'McrOutboxEvent';
    protected $primaryKey = 'McrOutboxEventID';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['McrPayload'=>'array','McrOccurredAt'=>'datetime','McrFannedOutAt'=>'datetime','McrClaimedAt'=>'datetime'];
}
