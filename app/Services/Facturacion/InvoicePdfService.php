<?php

namespace App\Services\Facturacion;

use Dompdf\Dompdf;
use Dompdf\Options;
use Greenter\Model\Sale\Invoice;
use Greenter\Report\HtmlReport;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    public function generate(Invoice $invoice): array
    {
        $htmlReport = new HtmlReport();
        $htmlReport->setTemplate('invoice.html.twig');

        $transparentLogo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLzWQAAAABJRU5ErkJggg=='
        );

        $html = $htmlReport->render($invoice, [
            'system' => [
                'logo' => $transparentLogo,
            ],
            'user' => [
                'header' => '',
                'footer' => 'Consulte la validez del comprobante en SUNAT.',
                'numIGV' => '18',
            ],
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $content = $dompdf->output();
        $path = 'facturacion/pdf/' . $invoice->getName() . '.pdf';
        Storage::disk('local')->put($path, $content);

        return [
            'path' => $path,
            'content' => $content,
        ];
    }
}
