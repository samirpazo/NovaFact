<?php
namespace App\Services\Documents\Processors;
final class SenderDespatchProcessor extends AbstractDespatchProcessor { protected function expectedType(): string { return '09'; } }
