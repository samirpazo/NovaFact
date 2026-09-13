<?php

use App\Enums\DocumentState;
use App\Services\Documents\PayloadCodec;
use App\Services\Documents\ProcessingResult;
use App\Services\Documents\SalesPayloadNormalizer;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\CdrResponse;

require_once __DIR__.'/../Support/PipelineDatabase.php';

it('classifies CDR fiscal outcomes independently from transport success', function (?string $code, array $notes, DocumentState $expected) {
    $bill = (new BillResult)->setSuccess(true)->setCdrResponse((new CdrResponse)->setCode($code)->setNotes($notes));
    expect(ProcessingResult::fromBillResult($bill)->state)->toBe($expected);
})->with([
    ['0', [], DocumentState::Accepted], ['0', ['Observation'], DocumentState::AcceptedWithObservations],
    ['4001', [], DocumentState::AcceptedWithObservations], ['2335', [], DocumentState::Rejected],
    [null, [], DocumentState::Failed], ['invalid', [], DocumentState::Failed],
]);

it('does not classify a missing CDR as accepted', function () {
    expect(ProcessingResult::fromBillResult((new BillResult)->setSuccess(true))->state)->toBe(DocumentState::Failed);
});

it('rejects unsafe state transitions', function () {
    expect(DocumentState::Accepted->canTransitionTo(DocumentState::Processing))->toBeFalse()
        ->and(DocumentState::Rejected->canTransitionTo(DocumentState::Accepted))->toBeFalse()
        ->and(DocumentState::Created->canTransitionTo(DocumentState::Accepted))->toBeFalse()
        ->and(DocumentState::Queued->canTransitionTo(DocumentState::Processing))->toBeTrue();
});

it('canonicalizes object keys but preserves line order', function () {
    expect(PayloadCodec::hash(['a' => 1, 'b' => ['z' => 3, 'y' => 4]]))->toBe(PayloadCodec::hash(['b' => ['y' => 4, 'z' => 3], 'a' => 1]));
    expect(PayloadCodec::hash(['lines' => [1, 2]]))->not->toBe(PayloadCodec::hash(['lines' => [2, 1]]));
});

it('canonicalizes equivalent sales values and distinguishes item order', function () {
    $normalizer = app(SalesPayloadNormalizer::class);
    $integerPayload = pipelinePayload();
    $decimalPayload = pipelinePayload();
    $decimalPayload['mtoTotal'] = '118.00';
    $decimalPayload['items'][0]['cantidad'] = '1.000';
    $decimalPayload['items'][0]['codigo'] = null;

    expect(PayloadCodec::hash($normalizer->request($integerPayload)))
        ->toBe(PayloadCodec::hash($normalizer->request($decimalPayload)));

    $twoLines = pipelinePayload();
    $twoLines['items'][] = [...$twoLines['items'][0], 'descripcion' => 'Second'];
    $reversed = $twoLines;
    $reversed['items'] = array_reverse($reversed['items']);
    expect(PayloadCodec::hash($normalizer->request($twoLines)))
        ->not->toBe(PayloadCodec::hash($normalizer->request($reversed)));
});
