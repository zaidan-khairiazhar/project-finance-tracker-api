<?php

namespace App\Services\Receipts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class GeminiReceiptScanner implements ReceiptScanner
{
    public function scan(string $bytes, string $mime, array $categories): ReceiptSuggestion
    {
        $model = config('receipts.scanner.gemini.model');

        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) config('receipts.scanner.gemini.key')])
                ->timeout((int) config('receipts.scanner.timeout'))
                ->acceptJson()
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'contents' => [[
                        'parts' => [
                            ['text' => ReceiptPrompt::build($categories)],
                            ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                        ],
                    ]],
                    'generationConfig' => ['responseMimeType' => 'application/json'],
                ])
                ->throw();
        } catch (ConnectionException) {
            throw new ReceiptScanException('Tidak dapat terhubung ke penyedia pembaca struk.');
        } catch (RequestException $e) {
            throw new ReceiptScanException('Penyedia pembaca struk menolak permintaan (HTTP '.$e->response->status().').');
        }

        return ReceiptAnswer::parse($response->json('candidates.0.content.parts.0.text'), $categories);
    }
}
