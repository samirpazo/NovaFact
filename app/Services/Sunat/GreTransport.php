<?php
namespace App\Services\Sunat;
use App\Models\Empresa;
interface GreTransport {
    public function send(Empresa $company, string $name, string $zip): GreSendResult;
    public function poll(Empresa $company, string $ticket): GrePollResult;
}
