<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResetBetaBilling extends Command
{
    protected $signature = 'billing:reset-beta {--confirm : Confirma la eliminación de comprobantes beta y archivos generados}';
    protected $description = 'Limpia comprobantes beta, envíos y reinicia correlativos';

    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->error('Operación destructiva. Ejecuta nuevamente con --confirm después de verificar el respaldo.');
            return self::FAILURE;
        }

        DB::transaction(function (): void {
            DB::table('McrDocumentLine')->whereIn('McrDocumentID', DB::table('McrDocument')->pluck('McrDocumentID'))->delete();
            DB::table('McrSunatSubmission')->update(['McrDocumentID' => null]);
            DB::table('McrSunatSubmission')->delete();
            DB::table('McrDocument')->delete();
            DB::table('McrSeries')->update(['McrNextCorrelative' => 1]);
        });

        Storage::disk('local')->deleteDirectory('facturacion');
        Storage::disk('local')->makeDirectory('facturacion');
        $this->info('Datos beta de facturación eliminados y series reiniciadas en 1.');
        return self::SUCCESS;
    }
}
