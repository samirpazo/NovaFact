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
        $htmlReport = new HtmlReport;
        $htmlReport->setTemplate('invoice.html.twig');

        $transparentLogo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLzWQAAAABJRU5ErkJggg=='
        );
        $companyConfig = Empresa::where('McrRuc', $invoice->getCompany()?->getRuc())->first();
        if ($companyConfig?->McrLogoPath && Storage::disk('local')->exists($companyConfig->McrLogoPath)) {
            $transparentLogo = Storage::disk('local')->get($companyConfig->McrLogoPath);
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
                'logo' => $transparentLogo,
            ],
            'user' => [
                'header' => '',
                'footer' => 'Consulte la validez del comprobante en SUNAT.',
                'numIGV' => (string) $taxRate,
            ],
        ]);

        // La plantilla de Greenter deja demasiado espacio en el encabezado para A4.
        // Compactamos el layout sin modificar la dependencia externa.
        $html = str_replace([
            'padding:30px; !important',
            'height="200px"',
            'height="200"',
            'height="90"',
            'height="40"',
            'height="80"',
            'cellpadding="9"',
            'cellpadding="6"',
            'margin: 20px 0',
            '<br><br><span style="font-family:Tahoma, Geneva, sans-serif; font-size:12px"',
        ], [
            'padding:8px !important',
            'height="108px"',
            'height="108"',
            'height="52"',
            'height="18"',
            'height="48"',
            'cellpadding="4"',
            'cellpadding="3"',
            'margin: 8px 0',
            '<span style="font-family:Tahoma, Geneva, sans-serif; font-size:12px"',
        ], $html);

        // Ajuste final de densidad: la plantilla oficial de Greenter está
        // pensada para impresión con mucho aire. Estas reglas mantienen la
        // estructura SUNAT, pero reducen márgenes y espacios improductivos.
        $html = str_replace('</head>', '<style>
            @page { margin: 8mm 10mm; }
            body { font-size: 10px !important; line-height: 1.15 !important; }
            table { margin-bottom: 4px !important; }
            td, th { padding-top: 2px !important; padding-bottom: 2px !important; }
            h1, h2, h3, h4, h5, h6, p { margin-top: 2px !important; margin-bottom: 2px !important; }
        </style></head>', $html);

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
