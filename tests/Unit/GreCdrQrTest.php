<?php

use App\Services\Sunat\GreCdrParser;

function greQrFixture(string $url): string
{
    $xml = '<ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"><cbc:ResponseCode>0</cbc:ResponseCode><cbc:Description>ACEPTADA</cbc:Description><cac:DocumentResponse><cac:DocumentReference><cbc:DocumentDescription>'.htmlspecialchars($url, ENT_XML1).'</cbc:DocumentDescription></cac:DocumentReference></cac:DocumentResponse></ApplicationResponse>';
    $file = tempnam(sys_get_temp_dir(), 'gre_qr_');
    $zip = new ZipArchive;
    $zip->open($file, ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('R-demo.xml', $xml);
    $zip->close();
    $bytes = file_get_contents($file);
    unlink($file);
    return $bytes;
}

it('preserves the GRE verification URL from the CDR for PDF QR rendering', function () {
    $url = 'https://url-test?hashqr=test';
    $result = (new GreCdrParser)->parse(greQrFixture($url));
    expect($result->metadata['qr_url'] ?? null)->toBe($url);
});

it('does not turn a non HTTP document description into a QR URL', function () {
    $result = (new GreCdrParser)->parse(greQrFixture('javascript:alert(1)'));
    expect($result->metadata)->not->toHaveKey('qr_url');
});
