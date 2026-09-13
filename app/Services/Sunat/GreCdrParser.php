<?php

namespace App\Services\Sunat;

use App\Enums\DocumentState;
use App\Services\Documents\ProcessingResult;

final class GreCdrParser
{
    public function parse(string $zipBytes): ProcessingResult
    {
        $temp = tempnam(sys_get_temp_dir(), 'gre_cdr_');
        file_put_contents($temp, $zipBytes);
        $zip = new \ZipArchive;
        if ($zip->open($temp) !== true) { @unlink($temp); throw new \RuntimeException('Invalid GRE CDR ZIP.'); }
        $xml = null;
        for ($i=0; $i<$zip->numFiles; $i++) if (str_ends_with(strtolower($zip->getNameIndex($i)), '.xml')) { $xml=$zip->getFromIndex($i); break; }
        $zip->close(); @unlink($temp);
        if (! is_string($xml)) throw new \RuntimeException('GRE CDR ZIP has no XML.');
        $dom = new \DOMDocument; if (! @$dom->loadXML($xml)) throw new \RuntimeException('Invalid GRE CDR XML.');
        $xp = new \DOMXPath($dom); $xp->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $code = trim((string) $xp->evaluate('string(//cbc:ResponseCode[1])'));
        $description = trim((string) $xp->evaluate('string(//cbc:Description[1])'));
        $notes=[]; foreach ($xp->query('//cbc:Note') ?: [] as $node) $notes[] = trim($node->textContent);
        if ($code === '' || ! ctype_digit($code)) throw new \RuntimeException('GRE CDR has no verifiable response code.');
        $state = ((int)$code === 0) ? ($notes ? DocumentState::AcceptedWithObservations : DocumentState::Accepted) : DocumentState::Rejected;
        return new ProcessingResult($state, $code, $description, $notes);
    }
}
