<?php

namespace App\Services;

use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class WalletGuard
{
    /** Dompet yang diarsipkan bersifat baca-saja. Melempar 422 pada field $field. */
    public static function assertUsable(Wallet $wallet, string $field): void
    {
        if ($wallet->is_archived) {
            throw ValidationException::withMessages([$field => 'Dompet sudah diarsipkan.']);
        }
    }
}
