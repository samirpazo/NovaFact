<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('POST') || !$request->hasHeader('Idempotency-Key')) {
            return $next($request);
        }
        $key = trim($request->header('Idempotency-Key'));
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) {
            return response()->json(['message' => 'Idempotency-Key inválida'], 422);
        }
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
        $row = DB::table('McrIdempotency')->where('McrKey', $key)->first();
        if ($row) {
            if (!hash_equals($row->McrRequestHash, $hash)) {
                return response()->json(['message' => 'La Idempotency-Key ya fue usada con otro payload'], 409);
            }
            if ($row->McrStatus === 'processing') {
                return response()->json(['message' => 'Operación duplicada en curso'], 409);
            }
            return response($row->McrResponseBody, $row->McrResponseStatus, ['Content-Type' => 'application/json']);
        }
        try {
            DB::table('McrIdempotency')->insert([
                'McrKey' => $key, 'McrRequestHash' => $hash, 'McrStatus' => 'processing',
                'McrCreatedAt' => now(), 'McrUpdatedAt' => now(),
            ]);
        } catch (\Throwable $e) {
            $row = DB::table('McrIdempotency')->where('McrKey', $key)->first();
            if ($row && hash_equals($row->McrRequestHash, $hash)) {
                return response()->json(['message' => 'Operación duplicada en curso'], 409);
            }
            throw $e;
        }
        $response = $next($request);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            DB::table('McrIdempotency')->where('McrKey', $key)->update([
                'McrStatus' => 'completed', 'McrResponseStatus' => $response->getStatusCode(),
                'McrResponseBody' => $response->getContent(), 'McrUpdatedAt' => now(),
            ]);
        } else {
            DB::table('McrIdempotency')->where('McrKey', $key)->delete();
        }
        return $response;
    }
}
