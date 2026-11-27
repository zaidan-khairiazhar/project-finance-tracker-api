<?php

namespace App\Services\Receipts;

final readonly class ReceiptSuggestion
{
    public function __construct(
        public ?int $total = null,
        public ?string $date = null,
        public ?string $merchant = null,
        public ?string $categoryId = null,
    ) {}

    /**
     * @return array{total: ?int, date: ?string, merchant: ?string, category_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'date' => $this->date,
            'merchant' => $this->merchant,
            'category_id' => $this->categoryId,
        ];
    }
}
