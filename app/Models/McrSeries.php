<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class McrSeries extends Model {
    protected $table = 'McrSeries';
    protected $primaryKey = 'McrSeriesID';
    public $timestamps = false;
    protected $guarded = [];
}
