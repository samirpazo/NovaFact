<?php

namespace App\Http\Controllers\Api;

use App\Actions\Facturacion\EmitFacturaAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FacturacionController extends Controller
{
    public function __construct(
        protected EmitFacturaAction $emitFacturaAction,
        protected \App\Actions\Facturacion\EmitGuiaAction $emitGuiaAction
    ) {}

    public function emitFactura(Request $request): JsonResponse
    {
        // Validaciones podrían ir en un FormRequest para desacoplar
        $result = $this->emitFacturaAction->execute($request->all());

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result);
    }

    public function emitGuia(Request $request): JsonResponse
    {
        $result = $this->emitGuiaAction->execute($request->all());

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result);
    }

    public function consultarHistorialGuia(string $ticket, \App\Services\Sunat\SunatRestService $restService): JsonResponse
    {
        try {
            $status = $restService->getStatus($ticket);

            if ($status['success']) {
                $response = [
                    'success' => true,
                    'cod_respuesta' => $status['cod_respuesta'],
                    'message' => 'Historial consultado exitosamente'
                ];

                if (!empty($status['arc_cdr'])) {
                    $response['cdr_base64'] = $status['arc_cdr']; // Retornamos el Base64 listo para guardar
                }

                if (isset($status['error'])) {
                    $response['sunat_errors'] = $status['error'];
                }

                return response()->json($response);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar el ticket en SUNAT',
                'error' => $status['error'] ?? 'Desconocido'
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Excepción al consultar historial',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
