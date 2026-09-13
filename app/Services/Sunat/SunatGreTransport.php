<?php

namespace App\Services\Sunat;

use App\Models\Empresa;
use Greenter\Sunat\GRE\Api\AuthApi;
use Greenter\Sunat\GRE\Api\CpeApi;
use Greenter\Sunat\GRE\Configuration;
use Greenter\Sunat\GRE\Model\CpeDocument;
use Greenter\Sunat\GRE\Model\CpeDocumentArchivo;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

final class SunatGreTransport implements GreTransport
{
    public function send(Empresa $company, string $name, string $zip): GreSendResult
    {
        $response = $this->api($company)->enviarCpe($name, (new CpeDocument)->setArchivo(
            (new CpeDocumentArchivo)->setNomArchivo($name.'.zip')->setArcGreZip(base64_encode($zip))->setHashZip(hash('sha256', $zip))
        ));
        if (! $response->getNumTicket()) throw new \RuntimeException('SUNAT GRE did not return a ticket.');
        return new GreSendResult($response->getNumTicket(), $response->getFecRecepcion()?->format(DATE_ATOM));
    }

    public function poll(Empresa $company, string $ticket): GrePollResult
    {
        $response = $this->api($company)->consultarEnvio($ticket);
        return new GrePollResult((string) $response->getCodRespuesta(), $response->getArcCdr(),
            $response->getError()?->getNumError(), $response->getError()?->getDesError());
    }

    private function api(Empresa $company): CpeApi
    {
        $config = (new Configuration)->setAccessToken($this->token($company))->setHost(config('sunat.endpoints.rest_cpe'));
        return new CpeApi($this->http(), $config);
    }

    private function token(Empresa $company): string
    {
        $cacheKey = 'sunat:gre:oauth:'.$company->getKey().':'.hash('sha256', (string) $company->McrClientId);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') return $cached;
        foreach (['McrClientId','McrClientSecret','McrRuc','McrSolUser','McrSolPassword'] as $field) {
            if (empty($company->{$field})) throw new \RuntimeException('Incomplete SUNAT GRE credentials.');
        }
        $auth = new AuthApi($this->http());
        $auth->getConfig()->setHost(config('sunat.endpoints.rest_auth'));
        $token = $auth->getToken('password', 'https://api-cpe.sunat.gob.pe', $company->McrClientId,
            $company->McrClientSecret, $company->McrRuc.$company->McrSolUser, $company->McrSolPassword);
        if (! $token->getAccessToken()) throw new \RuntimeException('SUNAT GRE authentication failed.');
        Cache::put($cacheKey, $token->getAccessToken(), now()->addSeconds(max(60, (int)$token->getExpiresIn() - 300)));
        return $token->getAccessToken();
    }

    private function http(): Client
    {
        return new Client(['verify' => true, 'connect_timeout' => 10, 'timeout' => 30]);
    }
}
