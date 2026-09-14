<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class McrWebhookSubscription extends Model
{
    protected $table = 'McrWebhookSubscription';
    protected $primaryKey = 'McrWebhookSubscriptionID';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['McrEncryptedSecret'];
    protected $casts = ['McrEventTypes'=>'array','McrIsEnabled'=>'boolean','McrCreatedAt'=>'datetime','McrUpdatedAt'=>'datetime'];
}
