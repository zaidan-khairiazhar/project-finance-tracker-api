<?php

namespace App\Services\Receipts;

use RuntimeException;

/** Kegagalan penyedia; pesan tidak boleh memuat kunci API atau isi gambar. */
class ReceiptScanException extends RuntimeException {}
