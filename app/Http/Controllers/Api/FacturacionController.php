<?php

namespace App\Http\Controllers\Api;

use App\Actions\Facturacion\EmitFacturaAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\StoreFacturaRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\DB;
use App\Jobs\EmitFacturaJob;

class FacturacionController extends Controller
{
    public function __construct(
        protected EmitFacturaAction $emitFacturaAction,
        protected \App\Actions\Facturacion\EmitGuiaAction $emitGuiaAction
    ) {}

    public function emitFactura(StoreFacturaRequest $request): JsonResponse
    {
        // Validaciones podrían ir en un FormRequest para desacoplar
        $payload = $request->validated();
        $payload['_idempotency_key'] = $request->header('Idempotency-Key');
        $submissionId=(int)DB::table('McrSunatSubmission')->insertGetId(['McrOperation'=>'emitir','McrTransport'=>'soap','McrAttemptNumber'=>0,'McrStatus'=>'pending','McrMetadata'=>json_encode(['tipoDoc'=>$payload['tipoDoc'],'idempotency_key'=>$payload['_idempotency_key']]),'SecStatus'=>true,'CreateUserId'=>0,'CreateDate'=>now()],'McrSunatSubmissionID');
        EmitFacturaJob::dispatch($payload,$submissionId);
        return response()->json(['success'=>true,'status'=>'pending','submission_id'=>$submissionId],202);
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

    public function descargarArchivo(string $tipo, string $nombre): StreamedResponse|JsonResponse
    {
        $directories = [
            'pdf' => 'facturacion/pdf',
            'xml' => 'facturacion/xml',
            'cdr' => 'facturacion/cdr',
        ];

        if (!isset($directories[$tipo]) || $nombre !== basename($nombre)) {
            return response()->json(['message' => 'Archivo inválido'], 400);
        }

        $path = $directories[$tipo] . '/' . $nombre;
        $disk = Storage::disk('local');
        if (!$disk->exists($path)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        $mime = match ($tipo) {
            'pdf' => 'application/pdf',
            'xml' => 'application/xml; charset=UTF-8',
            'cdr' => 'application/zip',
        };

        return response()->streamDownload(
            static function () use ($disk, $path): void {
                $stream = $disk->readStream($path);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $nombre,
            ['Content-Type' => $mime]
        );
    }

    public function resumenBoletas(Request $request, \App\Services\Facturacion\BoletaSummaryService $service): JsonResponse
    {
        $request->validate(['fecha' => ['required','date_format:Y-m-d']]);
        try { return response()->json($service->send($request->string('fecha')->toString())); }
        catch (\Throwable $e) { return response()->json(['success'=>false,'message'=>'No se pudo enviar el resumen de boletas','error'=>$e->getMessage()], 500); }
    }

    public function baja(Request $request, \App\Services\Facturacion\VoidedDocumentService $service): JsonResponse
    {
        $data=$request->validate(['tipoDoc'=>['nullable','in:01,03'],'numero'=>['required','regex:/^[A-Z0-9]{4}-[0-9]+$/'],'fecha'=>['required','date_format:Y-m-d'],'motivo'=>['required','string','max:500']]);
        try { return response()->json($service->send($data)); } catch (\Throwable $e) { return response()->json(['success'=>false,'message'=>'No se pudo enviar la baja','error'=>$e->getMessage()],500); }
    }

    public function estadoResumen(string $ticket, \App\Services\Sunat\GreenterService $service): JsonResponse
    {
        $status = $service->getStatus($ticket);
        return response()->json(['success' => $status->isSuccess(), 'ticket' => $ticket, 'code' => $status->getCode(), 'message' => $status->getError()?->getMessage()]);
    }

    public function estadoEnvio(int $submissionId): JsonResponse
    {
        $row=DB::table('McrSunatSubmission as s')->leftJoin('McrDocument as d','d.McrDocumentID','=','s.McrDocumentID')->where('s.McrSunatSubmissionID',$submissionId)->select('s.*','d.McrDocumentType','d.McrSeriesCode','d.McrCorrelative','d.McrPdfPath','d.McrXmlPath','d.McrCdrPath')->first();
        if (!$row) return response()->json(['message'=>'Envío no encontrado'],404);
        return response()->json([
            'success'=>true,
            'submission_id'=>$row->McrSunatSubmissionID,
            'document_id'=>$row->McrDocumentID,
            'status'=>$row->McrStatus,
            'ticket'=>$row->McrTicket,
            'error'=>$row->McrError,
            'completed_at'=>$row->McrCompletedAt,
            'document_number'=>$row->McrSeriesCode ? $row->McrSeriesCode.'-'.$row->McrCorrelative : null,
            'pdf_url'=>$row->McrPdfPath ? url('/api/facturacion/archivo/pdf/'.basename($row->McrPdfPath)) : null,
            'xml_url'=>$row->McrXmlPath ? url('/api/facturacion/archivo/xml/'.basename($row->McrXmlPath)) : null,
            'cdr_url'=>$row->McrCdrPath ? url('/api/facturacion/archivo/cdr/'.basename($row->McrCdrPath)) : null,
        ]);
    }
}
