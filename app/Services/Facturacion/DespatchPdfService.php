<?php

namespace App\Services\Facturacion;

use App\Models\Empresa;
use Dompdf\Dompdf;
use Dompdf\Options;
use Greenter\Model\Despatch\Despatch;
use Greenter\Report\HtmlReport;
use Illuminate\Support\Facades\Storage;

class DespatchPdfService
{
    public function render(Despatch $document, ?string $qrUrl = null): string
    {
        $company = Empresa::where('McrRuc', $document->getCompany()?->getRuc())
            ->where('McrEnvironment', config('sunat.production') ? 'production' : 'beta')->first();
        $logo = $company?->McrLogoPath && Storage::disk('local')->exists($company->McrLogoPath)
            ? Storage::disk('local')->get($company->McrLogoPath) : null;
        $system = ['logo' => $logo, 'logo_mime' => $logo
            ? str_replace('image/', '', (new \finfo(FILEINFO_MIME_TYPE))->buffer($logo)) : 'png'];
        if ($qrUrl !== null && filter_var($qrUrl, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($qrUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $system['qr'] = $qrUrl;
        }
        $report = new HtmlReport(resource_path('views/pdf'));
        $report->setTemplate('despatch.html.twig');
        $html = $report->render($document, ['system' => $system,
            'user' => ['header' => '', 'footer' => $company?->McrEnvironment === 'beta' ? 'Documento de prueba · sin validez tributaria.' : '']]);
        $pdf = new Dompdf(new Options(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]));
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
