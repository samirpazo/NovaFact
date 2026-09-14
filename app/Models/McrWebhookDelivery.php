<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class McrWebhookDelivery extends Model
{
    protected $table = 'McrWebhookDelivery';
    protected $primaryKey = 'McrWebhookDeliveryID';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['McrNextAttemptAt'=>'datetime','McrLastAttemptAt'=>'datetime','McrDeliveredAt'=>'datetime','McrClaimedAt'=>'datetime'];
}
