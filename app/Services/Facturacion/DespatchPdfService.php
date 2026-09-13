<?php

namespace App\Services\Facturacion;

use Dompdf\Dompdf;
use Dompdf\Options;
use Greenter\Model\Despatch\Despatch;
use Greenter\Report\HtmlReport;

class DespatchPdfService
{
    public function render(Despatch $document): string
    {
        $report = new HtmlReport;
        $report->setTemplate('despatch.html.twig');
        $html = $report->render($document, ['system'=>['logo'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLzWQAAAABJRU5ErkJggg==')], 'user'=>['header'=>'','footer'=>'Representación impresa de GRE']]);
        $pdf = new Dompdf(new Options(['defaultFont'=>'DejaVu Sans','isRemoteEnabled'=>false]));
        $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4'); $pdf->render();
        return $pdf->output();
    }
}
