<?php
namespace App\Services\Facturacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ManagedFileService
{
    public function register(string $path, string $originalName, string $mime): int
    {
        $disk=Storage::disk('local');
        $bytes=$disk->size($path);
        return (int) DB::table('GenFile')->insertGetId([
            'FilOriginalName'=>$originalName,'FilStoredName'=>$path,'FilRouteParameter'=>'ROUTE_BILLING',
            'FilExtension'=>pathinfo($originalName,PATHINFO_EXTENSION),'FilMimeType'=>$mime,'FilSizeBytes'=>$bytes,
            'FilSha256'=>hash('sha256',$disk->get($path)),'FilUploadStatus'=>1,'FilPreviewStatus'=>1,
            'SyncId'=>(string)Str::uuid(),'SyncVersion'=>hex2bin(str_repeat('00',8)),'SecStatus'=>true,
            'CreateUserId'=>0,'CreateDate'=>now(),
        ], 'FilID');
    }
}
