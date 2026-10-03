<?php

namespace App\Services\Sunat\Xml;

use Greenter\Model\DocumentInterface;
use Greenter\Xml\Builder\InvoiceBuilder;
use Twig\Loader\ArrayLoader;

// Greenter 5.x's invoice template uses Twig's deprecated spaceless filter.
// Keep the vendor template and its filters; remove only its outer whitespace wrapper.
final class ModernInvoiceBuilder extends InvoiceBuilder
{
    public function build(DocumentInterface $document): ?string
    {
        $name = 'invoice'.$document->getUblVersion().'.xml.twig';
        $loader = $this->twig->getLoader();
        $source = $loader->getSourceContext($name)->getCode();
        $source = preg_replace('/^\{% apply spaceless %\}\s*/', '', $source);
        $source = preg_replace('/\s*\{% endapply %\}\s*$/', '', $source);
        $this->twig->setLoader(new ArrayLoader([$name => $source]));
        try {
            return trim((string) preg_replace('/>\s+</', '><', parent::build($document)));
        } finally {
            $this->twig->setLoader($loader);
        }
    }
}
