<?php

namespace App\Services\Receipts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class ClaudeReceiptScanner implements ReceiptScanner
{
    public function scan(string $bytes, string $mime, array $categories): ReceiptSuggestion
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('receipts.scanner.claude.key'),
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout((int) config('receipts.scanner.timeout'))
                ->acceptJson()
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('receipts.scanner.claude.model'),
                    'max_tokens' => 300,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]],
                            ['type' => 'text', 'text' => ReceiptPrompt::build($categories)],
                        ],
                    ]],
                ])
                ->throw();
        } catch (ConnectionException) {
            throw new ReceiptScanException('Tidak dapat terhubung ke penyedia pembaca struk.');
        } catch (RequestException $e) {
            throw new ReceiptScanException('Penyedia pembaca struk menolak permintaan (HTTP '.$e->response->status().').');
        }

        return ReceiptAnswer::parse($response->json('content.0.text'), $categories);
    }
}
