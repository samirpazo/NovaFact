<?php

use App\Models\Empresa;
use App\Services\Sunat\GreenterService;
use Greenter\Model\Response\SummaryResult;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Voided;
use Greenter\See;

it('selects the Greenter summary sender class instead of treating RC or RA as a bill', function (string $protocol, string $type) {
    $see = Mockery::mock(See::class);
    $see->shouldReceive('sendXml')->once()->with($type, 'fiscal-name', '<xml/>')->andReturn(new SummaryResult);
    $service = new class($see) extends GreenterService
    {
        public function __construct(See $see)
        {
            $this->see = $see;
        }

        protected function configure(Empresa $company): void {}
    };
    expect($service->sendFiscalXml($protocol, 'fiscal-name', '<xml/>', new Empresa))->toBeInstanceOf(SummaryResult::class);
})->with([['RC', Summary::class], ['RA', Voided::class]]);
