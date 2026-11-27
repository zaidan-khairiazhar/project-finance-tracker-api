<?php

namespace App\Services\Receipts;

final class NullReceiptScanner implements ReceiptScanner
{
    public function scan(string $bytes, string $mime, array $categories): ReceiptSuggestion
    {
        throw new ReceiptScannerDisabled('Pembaca struk dimatikan.');
    }
}
