<?php

namespace App\Services\Facturacion;

use App\Models\Empresa;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Note;
use Greenter\Report\HtmlReport;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    public function generateTicketFromStored(object $document, iterable $lines, ?object $company = null): string
    {
        $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $rows = '';
        $count = 0;
        foreach ($lines as $line) {
            $count++;
            $rows .= '<tr><td class="qty">'.number_format((float) $line->McrQuantity, 2).'</td><td class="desc">'.$e($line->McrDescription).'<br><small>'.$e($line->McrProductCode).'</small></td><td class="num">'.number_format((float) $line->McrUnitPrice, 2).'</td><td class="num">'.number_format((float) $line->McrLineTotal, 2).'</td></tr>';
        }
        $total = number_format((float) $document->McrTotalAmount, 2);
        $qr = $this->ticketQr(implode('|', [$document->McrDocumentType, $document->McrSeriesCode, $document->McrCorrelative, $document->McrTaxAmount, $document->McrTotalAmount, $document->McrIssueDate, $document->McrCustomerDocumentType, $document->McrCustomerDocumentNumber]));
        $businessName = $company?->McrBusinessName ?? 'COMPROBANTE ELECTRÓNICO';
        $ruc = $company?->McrRuc ?? '';
        $address = $company?->McrAddress ?? '';
        $contact = trim(($company?->McrPhone ? 'Tel: '.$company->McrPhone : '').' '.($company?->McrEmail ? 'Correo: '.$company->McrEmail : ''));
        $logo = '';
        if (! empty($company?->McrLogoPath) && Storage::disk('local')->exists($company->McrLogoPath)) {
            $mime = mime_content_type(Storage::disk('local')->path($company->McrLogoPath)) ?: 'image/png';
            $logo = '<div class="c"><img class="logo" src="data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($company->McrLogoPath)).'"></div>';
        }
        $documentTaxRate = (float) $document->McrTaxableAmount > 0
            ? round(((float) $document->McrTaxAmount / (float) $document->McrTaxableAmount) * 100, 2)
            : (float) ($company?->McrTotalTaxRate ?? 18);
        $taxLabel = ($company?->McrSpecialTaxRegime ?? false) ? 'Tributos '.number_format($documentTaxRate, 2).'%' : 'I.G.V. '.number_format($documentTaxRate, 2).'%';
        $html = '<html><head><style>@page{margin:4mm 3mm}body{font-family:DejaVu Sans;font-size:8.5px;margin:0}.c{text-align:center}.b{font-weight:bold}.logo{max-width:34mm;max-height:20mm}.title{font-size:11px;margin-top:7px}.num{text-align:right}.rule{border-top:1px solid #111;margin:5px 0}table{width:100%;border-collapse:collapse}th{border-bottom:1px solid #111;text-align:left;font-size:8px}td{padding:3px 0;border-bottom:.3px solid #aaa}.qty{width:12%}.desc{width:52%}.totals{margin-left:auto;width:58%}.totals td{border:0}.grand{font-size:14px;font-weight:bold;border-top:1px solid #111!important}.qr{text-align:center;margin:7px}.qr img{width:28mm;height:28mm}.foot{text-align:center;font-size:7px;margin-top:5px}</style></head><body>'.$logo.'<div class="c b">'.$e($businessName).'</div><div class="c b">RUC: '.$e($ruc).'</div><div class="c">'.$e($address).'</div><div class="c">'.$e($contact).'</div><div class="c title b">'.($document->McrDocumentType === '01' ? 'FACTURA' : 'BOLETA').' DE VENTA ELECTRÓNICA</div><div class="c b">'.$e($document->McrSeriesCode).' - '.$e($document->McrCorrelative).'</div><div class="rule"></div><div><span class="b">CLIENTE:</span> '.$e($document->McrCustomerName).'</div><div><span class="b">DOC:</span> '.$e($document->McrCustomerDocumentNumber).'</div><div><span class="b">FECHA:</span> '.$e($document->McrIssueDate).'</div><div class="rule"></div><table><tr><th>Cant.</th><th>Descripción</th><th class="num">P.U.</th><th class="num">Total</th></tr>'.$rows.'</table><table class="totals"><tr><td>Op. gravadas</td><td class="num">S/ '.number_format((float) $document->McrTaxableAmount, 2).'</td></tr><tr><td>'.$e($taxLabel).'</td><td class="num">S/ '.number_format((float) $document->McrTaxAmount, 2).'</td></tr><tr><td class="grand">TOTAL</td><td class="num grand">S/ '.$total.'</td></tr></table><div class="rule"></div><div class="b">SON: '.$total.' SOLES</div><div class="b">FORMA DE PAGO: '.$e($document->McrPaymentTerms).'</div><div class="b">Observaciones:</div><div class="qr"><img src="data:image/svg+xml;base64,'.base64_encode($qr).'"></div><div class="foot">Representación impresa del comprobante electrónico<br>Consulte la validez del comprobante en SUNAT</div></body></html>';
        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $height = 620 + ($count * 30) + (! empty($company?->McrLogoPath) ? 70 : 0);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper([0, 0, 226.77, $height]);
        $pdf->render();

        return $pdf->output();
    }

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

        $taxRate = (float) $invoice->getMtoOperGravadas() > 0
            ? round(((float) $invoice->getMtoIGV() / (float) $invoice->getMtoOperGravadas()) * 100, 2)
            : 0;
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
            'ticket_path' => null,
        ];
    }

    private function renderTicket80mm(Invoice $invoice): string
    {
        $e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $company = $invoice->getCompany();
        $client = $invoice->getClient();
        $address = $company?->getAddress();
        $documentType = $invoice->getTipoDoc() === '01' ? 'FACTURA DE VENTA ELECTRÓNICA' : 'BOLETA DE VENTA ELECTRÓNICA';
        $date = $invoice->getFechaEmision()?->format('d/m/Y H:i') ?? '';
        $details = '';
        foreach ($invoice->getDetails() ?? [] as $detail) {
            $quantity = number_format((float) $detail->getCantidad(), 2);
            $price = number_format((float) $detail->getMtoPrecioUnitario(), 2);
            $total = number_format((float) $detail->getMtoValorVenta() + (float) $detail->getTotalImpuestos(), 2);
            $details .= '<tr><td class="qty">'.$quantity.'</td><td class="desc">'.$e($detail->getDescripcion()).'<br><small>'.$e($detail->getCodProducto()).'</small></td><td class="num">'.$price.'</td><td class="num">'.$total.'</td></tr>';
        }
        $taxable = number_format((float) $invoice->getMtoOperGravadas(), 2);
        $igv = number_format((float) $invoice->getMtoIGV(), 2);
        $taxRate = (float) $invoice->getMtoOperGravadas() > 0
            ? round(((float) $invoice->getMtoIGV() / (float) $invoice->getMtoOperGravadas()) * 100, 2)
            : 0;
        $total = number_format((float) $invoice->getMtoImpVenta(), 2);
        $legend = $invoice->getLegends()[0]->getValue() ?? 'SON: '.$total.' SOLES';
        $qrPayload = implode('|', [
            $company?->getRuc(), $invoice->getTipoDoc(), $invoice->getSerie(), $invoice->getCorrelativo(),
            $invoice->getMtoIGV(), $invoice->getMtoImpVenta(), $invoice->getFechaEmision()?->format('Y-m-d'),
            $client?->getTipoDoc(), $client?->getNumDoc(),
        ]);
        $qrSvg = $this->ticketQr($qrPayload);

        return '<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:4mm 3mm}body{font-family:DejaVu Sans,Arial,sans-serif;color:#111;font-size:8.5px;line-height:1.25;margin:0}.center{text-align:center}.company{font-size:10px;font-weight:bold}.title{font-size:11px;font-weight:bold;margin-top:8px}.number{font-size:12px;font-weight:bold}.rule{border-top:1px solid #111;margin:5px 0}table{width:100%;border-collapse:collapse}th{font-size:8px;text-align:left;border-bottom:1px solid #111;padding:2px 0}td{vertical-align:top;padding:3px 0;border-bottom:.3px solid #bbb}.qty{width:12%}.desc{width:52%}.num{text-align:right;width:18%}small{font-size:6.5px}.totals{margin-left:auto;width:58%;font-size:9px}.totals td{border:0;padding:1px 0}.grand{font-size:14px;font-weight:bold;border-top:1px solid #111!important}.footer{margin-top:7px;font-size:7.5px;text-align:center}.label{font-weight:bold}.qr{margin:7px auto 2px;width:28mm;text-align:center}.qr img{display:block;width:28mm;height:28mm;margin:auto}</style></head><body>
<div class="center company">'.$e($company?->getRazonSocial()).'</div><div class="center">RUC '.$e($company?->getRuc()).'</div><div class="center">'.$e($address?->getDireccion()).'</div>
<div class="center title">'.$documentType.'</div><div class="center number">'.$e($invoice->getSerie()).' - '.$e($invoice->getCorrelativo()).'</div><div class="rule"></div>
<div><span class="label">CLIENTE:</span> '.$e($client?->getRznSocial()).'</div><div><span class="label">DOC:</span> '.$e($client?->getNumDoc()).'</div><div><span class="label">FECHA:</span> '.$e($date).'</div><div class="rule"></div>
<table><thead><tr><th>Cant.</th><th>Descripción</th><th class="num">P.U.</th><th class="num">Total</th></tr></thead><tbody>'.$details.'</tbody></table>
<table class="totals"><tr><td>Op. gravadas</td><td class="num">S/ '.$taxable.'</td></tr><tr><td>I.G.V. '.$taxRate.'%</td><td class="num">S/ '.$igv.'</td></tr><tr><td class="grand">TOTAL</td><td class="num grand">S/ '.$total.'</td></tr></table>
<div class="rule"></div><div class="label">'.$e($legend).'</div><div class="label">FORMA DE PAGO: CONTADO</div><div class="label">Observaciones:</div><div class="qr"><img src="data:image/svg+xml;base64,'.base64_encode($qrSvg).'" alt="QR"></div><div class="footer">Representación impresa del comprobante electrónico<br>Consulte la validez del comprobante en SUNAT</div></body></html>';
    }

    private function ticketHeight(Invoice $invoice): float
    {
        return 620 + (count($invoice->getDetails() ?? []) * 30);
    }

    private function ticketQr(string $payload): string
    {
        $renderer = new ImageRenderer(new RendererStyle(110), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($payload);
    }
}
