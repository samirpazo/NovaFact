<?php

namespace App\Services\Sunat;

use Greenter\Model\Despatch\Despatch;
use Greenter\Sunat\GRE\Api\AuthApi;
use Greenter\Sunat\GRE\Api\CpeApi;
use Greenter\Sunat\GRE\Configuration;
use Greenter\Sunat\GRE\Model\ApiToken;
use Greenter\Sunat\GRE\Model\CpeDocument;
use Greenter\Sunat\GRE\Model\CpeDocumentArchivo;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

/**
 * Servicio para enviar Guías de Remisión mediante la nueva plataforma
 * GRE REST de SUNAT.
 *
 * Documentación oficial: https://cpe.sunat.gob.pe/sites/default/files/inline-files/Manual_Servicios_GRE.pdf
 *
 * IMPORTANTE: Esta nueva API REST NO tiene ambiente beta separado.
 * Requiere credenciales reales (client_id / client_secret) obtenidas
 * desde el Menú SOL de SUNAT (Empresas > Menú SOL > APIs > GRE).
 *
 * Endpoints:
 *  - Auth Token:  https://api-seguridad.sunat.gob.pe/v1/clientessol/{client_id}/oauth2/token/
 *  - Envío CPE:   https://api.sunat.gob.pe/v1/contribuyente/gem/comprobantes/{filename}
 *  - Consulta:    https://api.sunat.gob.pe/v1/contribuyente/gem/comprobantes/envios/{numTicket}
 */
class SunatRestService
{
    // Los HOSTs se obtienen dinámicamente de la configuración config('sunat.endpoints')

    public function __construct(
        protected GreenterService $greenterService,
        protected \App\Repositories\EmpresaRepository $empresaRepository
    ) {}

    /**
     * Crea un cliente Guzzle correctamente configurado.
     * En entornos de test con certificados no verificables, desactiva SSL.
     */
    private function makeHttpClient(): Client
    {
        return new Client([
            'verify' => config('sunat.production'),  // Solo verificar SSL en producción
        ]);
    }

    /**
     * Envía una Guía de Remisión (Despatch) a SUNAT mediante la API REST.
     * Retorna un array con 'success' => true y 'ticket' si fue exitoso,
     * o 'success' => false y 'error' en caso de fallo.
     */
    public function send(Despatch $despatch): array
    {
        try {
            // if (!config('sunat.client_id') || !config('sunat.client_secret')) {
            //     return [
            //         'success' => false,
            //         'error' => 'SUNAT_CLIENT_ID y SUNAT_CLIENT_SECRET son obligatorios. '
            //                   . 'Regístrate en el Menú SOL de SUNAT para obtenerlos.',
            //     ];
            // }

            $token = $this->getToken();

            $config = new Configuration();
            $config->setAccessToken($token->getAccessToken());
            $config->setHost(config('sunat.endpoints.rest_cpe'));

            $api = new CpeApi($this->makeHttpClient(), $config);

            $xml = $this->greenterService->getXml($despatch);
            $filename = $despatch->getName();

            // La API REST requiere enviar el XML dentro de un archivo ZIP
            $zipContent = $this->createZip($filename . '.xml', $xml);

            $archivo = (new CpeDocumentArchivo())
                ->setNomArchivo($filename . '.zip')
                ->setArcGreZip(base64_encode($zipContent))
                ->setHashZip(hash('sha256', $zipContent));

            $cpeDoc = (new CpeDocument())->setArchivo($archivo);

            $res    = $api->enviarCpe($filename, $cpeDoc);
            $ticket = $res->getNumTicket();

            $result = [
                'success' => true,
                'ticket'  => $ticket,
                'fec_recepcion' => $res->getFecRecepcion()?->format('Y-m-d H:i:s'),
                'cdr_base64' => null,
                'description' => null,
            ];

            // Proceso automático de consulta de ticket
            // SUNAT recomienda esperar al menos un par de segundos
            $maxIntentos = 6; 
            $ruc = trim($despatch->getCompany()->getRuc());
            for ($i = 0; $i < $maxIntentos; $i++) {
                sleep(2); // Esperar 2 segundos antes de cada consulta
                
                $status = $this->getStatus($ticket, $ruc);
                if ($status['success']) {
                    // 1. Si ya tenemos el CDR, terminamos con éxito
                    if (!empty($status['arc_cdr'])) {
                        $result['cdr_base64'] = $status['arc_cdr'];
                        $result['description'] = $status['description'] ?? null;
                        break; 
                    }
                    // 2. Si el código de respuesta es 99 (ERROR/RECHAZADO), rompemos
                    if ($status['cod_respuesta'] === '99') {
                        $result['success'] = false;
                        $result['error'] = $status['error'] ?? 'Documento rechazado por SUNAT';
                        break;
                    }
                    
                    // Si el código es 98 (En proceso) o nulo, seguimos el bucle
                }
            }

            return $result;

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Consulta el estado de un envío previo por su número de ticket.
     * Si SUNAT ya procesó el documento, retorna el CDR en base64.
     */
    public function getStatus(string $ticket): array
    {
        try {
            $token  = $this->getToken();
            $config = new Configuration();
            $config->setAccessToken($token->getAccessToken());
            $config->setHost(config('sunat.endpoints.rest_cpe'));

            $api = new CpeApi($this->makeHttpClient(), $config);
            $res = $api->consultarEnvio($ticket);

            $errorMsg = null;
            if ($res->getError()) {
                $errorMsg = $res->getError()->getDesError();
            }

            return [
                'success'     => true,
                'cod_respuesta' => $res->getCodRespuesta(),
                'arc_cdr'     => $res->getArcCdr(), 
                'description' => $this->extractDescriptionFromCdr($res->getArcCdr()),
                'error'       => $errorMsg,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtiene el token de acceso OAuth2 de SUNAT.
     * El token se cachea por 50 minutos (3000 segundos).
     */
    protected function getToken(): ApiToken
    {
        $empresa = $this->empresaRepository->getActive();
        $authApi = new AuthApi($this->makeHttpClient());

        // $endpoint = config('sunat.production') ? 'https://gre-test.nubefact.com/v1/clientessol/' . $empresa->CpyClientId . '/oauth2/token' : 'https://api-seguridad.sunat.gob.pe/v1/clientessol/' . $empresa->CpyClientId . '/oauth2/token';
        $authApi->getConfig()->setHost(config('sunat.endpoints.rest_auth'));

        // if (!config('sunat.production')) {
        //     $solRuc  = '20000000001'; // Usando el RUC de pruebas estándar
        //     $solUser = 'MODDATOS';
        //     $solPass = 'moddatos';
        // }

        try {
            $token = $authApi->getToken(
                grant_type:    'password',
                scope:         'https://api-cpe.sunat.gob.pe',
                client_id:     $empresa->CpyClientId,
                client_secret: $empresa->CpyClientSecret,
                username:      $empresa->CpyRuc . $empresa->CpyUserSol,
                password:      $empresa->CpyPasswordSol
            );

            if (empty($token->getAccessToken())) {
                \Illuminate\Support\Facades\Log::error("SUNAT Token Vacío", [
                    'solRuc' => $empresa->CpyRuc,
                    'solUser' => $empresa->CpyUserSol,
                    'clientId' => substr($empresa->CpyClientId, 0, 10) . '...',
                    'host' => config('sunat.endpoints.rest_auth')
                ]);
            }

            return $token;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error obteniendo token SUNAT: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Crea un archivo ZIP en memoria que contiene el XML de la Guía.
     */
    protected function createZip(string $filename, string $content): string
    {
        $zip      = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'gre_');

        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $zip->addFromString($filename, $content);
            $zip->close();
            $data = file_get_contents($tempFile);
            unlink($tempFile);
            return $data;
        }

        throw new \RuntimeException('No se pudo crear el archivo ZIP temporal para GRE');
    }

    /**
     * Extrae la descripción (mensaje de SUNAT) desde el XML contenido en el ZIP del CDR.
     */
    private function extractDescriptionFromCdr(?string $zipBase64): ?string
    {
        try {
            if (empty($zipBase64)) return null;
            
            $zipData = base64_decode($zipBase64);
            $tempFile = tempnam(sys_get_temp_dir(), 'cdr_');
            file_put_contents($tempFile, $zipData);

            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === true) {
                $xmlContent = null;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($filename), '.xml')) {
                        $xmlContent = $zip->getFromIndex($i);
                        break;
                    }
                }
                $zip->close();
                unlink($tempFile);

                if ($xmlContent) {
                    $dom = new \DOMDocument();
                    if (@$dom->loadXML($xmlContent)) {
                        $xpath = new \DOMXPath($dom);
                        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
                        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
                        
                        $nodes = $xpath->query('//cac:DocumentResponse/cac:Response/cbc:Description');
                        if ($nodes->length > 0) {
                            return $nodes->item(0)->nodeValue;
                        }
                    }
                }
            } else {
                unlink($tempFile);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("No se pudo extraer descripción del CDR: " . $e->getMessage());
        }

        return null;
    }
}
