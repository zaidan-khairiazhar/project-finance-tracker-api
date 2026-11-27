<?php

return [
    // Disk privat untuk foto struk; tidak pernah disajikan langsung lewat URL.
    'disk' => env('RECEIPT_DISK', 'receipts'),

    // Ukuran maksimal dalam KB.
    'max_kb' => 5120,

    // Tipe MIME (dari isi berkas) yang diizinkan => ekstensi penyimpanan.
    'mimes' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ],

    // Batas permintaan scan per pengguna per jam.
    'scan_per_hour' => env('RECEIPT_SCAN_PER_HOUR', 20),

    // Pembaca struk (scan). Driver: null | gemini | claude. Tanpa kunci, scan mati.
    'scanner' => [
        'driver' => env('RECEIPT_SCANNER_DRIVER', 'null'),
        'timeout' => 20,
        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'model' => env('RECEIPT_GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        ],
        'claude' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'model' => env('RECEIPT_CLAUDE_MODEL', 'claude-haiku-5-5'),
        ],
    ],
];
