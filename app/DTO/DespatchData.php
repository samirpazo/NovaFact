<?php

namespace App\DTO;

final readonly class DespatchData
{
    public function __construct(public array $data) {}

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function type(): string { return $this->data['tipoDoc']; }
    public function series(): string { return $this->data['serie']; }
    public function correlative(): string { return $this->data['correlativo']; }
    public function recipient(): array { return $this->data['destinatario']; }
    public function shipment(): array { return $this->data['traslado']; }
    public function items(): array { return $this->data['bienes']; }
}
