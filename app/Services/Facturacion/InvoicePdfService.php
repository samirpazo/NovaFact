<?php

namespace App\Services\Facturacion;

use App\Models\Empresa;
use Dompdf\Dompdf;
use Dompdf\Options;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Note;
use Greenter\Report\HtmlReport;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    public function generate(Invoice|Note $invoice, ?string $filename = null): array
    {
        $htmlReport = new HtmlReport(resource_path('views/pdf'));
        $htmlReport->setTemplate('invoice.html.twig');

        $logoContent = null;
        $companyConfig = Empresa::where('McrRuc', $invoice->getCompany()?->getRuc())->first();
        if ($companyConfig?->McrLogoPath && Storage::disk('local')->exists($companyConfig->McrLogoPath)) {
            $logoContent = Storage::disk('local')->get($companyConfig->McrLogoPath);
        } elseif (Storage::disk('local')->exists('facturacion/logo/company-logo.jpg')) {
            $logoContent = Storage::disk('local')->get('facturacion/logo/company-logo.jpg');
        } else {
            $logoContent = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLzWQAAAABJRU5ErkJggg=='
            );
        }

        $firstDetail = ($invoice->getDetails() ?? [])[0] ?? null;
        $taxRate = $firstDetail?->getPorcentajeIgv();
        if ($taxRate === null) {
            $taxRate = (float) $invoice->getMtoOperGravadas() > 0
                ? round(((float) $invoice->getMtoIGV() / (float) $invoice->getMtoOperGravadas()) * 100, 2)
                : 0;
        }
        $html = $htmlReport->render($invoice, [
            'system' => [
                'logo' => $logoContent,
            ],
            'user' => [
                'header' => '',
                'footer' => 'Consulte la validez del comprobante en SUNAT.',
                'numIGV' => (string) $taxRate,
            ],
        ]);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $content = $dompdf->output();
        if ($filename !== null && $filename !== basename($filename)) {
            throw new \InvalidArgumentException('Invalid PDF filename.');
        }
        $path = 'facturacion/pdf/'.($filename ?? $invoice->getName().'.pdf');
        if (! Storage::disk('local')->put($path, $content)) {
            throw new \RuntimeException('Could not persist PDF.');
        }

        return [
            'path' => $path,
            'content' => $content,
        ];
    }

}
