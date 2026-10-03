<?php

namespace App\Services\Sunat\Xml;

use Greenter\Model\Despatch\Despatch;
use Greenter\Model\DocumentInterface;
use Greenter\Xml\Builder\DespatchBuilder;

/**
 * Greenter 5.2's sender template lacks the GRE-31 DespatchParty.
 * The local template derives from its MIT-licensed despatch2022.xml.twig;
 * SUNAT GRE validation rules 25.09.2026 require the sender within Delivery/Despatch.
 * Render this structure before Greenter signs it; never patch signed XML.
 */
final class CarrierDespatchBuilder extends DespatchBuilder
{
    public function __construct(array $options = [])
    {
        parent::__construct($options);
        $this->twig->getLoader()->addPath(resource_path('views/xml'));
    }

    public function build(DocumentInterface $document): ?string
    {
        if (! $document instanceof Despatch || $document->getTipoDoc() !== '31' || ! $document->getTercero()) {
            throw new \InvalidArgumentException('GRE carrier XML requires type 31 and a sender.');
        }

        return $this->render('carrier-despatch.xml.twig', $document);
    }
}
