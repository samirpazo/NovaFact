<?php

use App\Services\Facturacion\SignedXmlDigestValue;

it('extracts the fiscal digest value from the signed UBL extension', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
         xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"
         xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
  <ext:UBLExtensions>
    <ext:UBLExtension>
      <ext:ExtensionContent>
        <ds:Signature>
          <ds:SignedInfo>
            <ds:Reference URI="">
              <ds:DigestValue>DIGEST-FISCAL-BASE64</ds:DigestValue>
            </ds:Reference>
          </ds:SignedInfo>
        </ds:Signature>
      </ext:ExtensionContent>
    </ext:UBLExtension>
  </ext:UBLExtensions>
</Invoice>
XML;

    expect(app(SignedXmlDigestValue::class)->extract($xml))->toBe('DIGEST-FISCAL-BASE64');
});

it('returns null when the signed XML has no fiscal digest', function () {
    expect(app(SignedXmlDigestValue::class)->extract('<Invoice/>'))->toBeNull();
});
