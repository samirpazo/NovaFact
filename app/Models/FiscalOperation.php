<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FiscalOperation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'remote_started_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }

    public function response(): array
    {
        return ['operation_id' => $this->id, 'kind' => $this->kind, 'protocol' => $this->protocol,
            'identifier' => $this->identifier, 'status' => $this->state, 'ticket' => $this->ticket,
            'error' => $this->error, 'attempts' => (int) $this->attempts,
            'xml_url' => $this->xml_path ? url('/api/facturacion/operations/'.$this->id.'/files/xml') : null,
            'cdr_url' => $this->cdr_path ? url('/api/facturacion/operations/'.$this->id.'/files/cdr') : null];
    }
}
