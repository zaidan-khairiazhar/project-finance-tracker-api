<?php

namespace App\Services\Receipts;

interface ReceiptScanner
{
    /**
     * @param  list<array{id: string, name: string, parent: ?string}>  $categories
     *
     * @throws ReceiptScannerDisabled bila pembaca struk dimatikan
     * @throws ReceiptScanException bila penyedia gagal atau jawabannya tak dapat dipakai
     */
    public function scan(string $bytes, string $mime, array $categories): ReceiptSuggestion;
}
