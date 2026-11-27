<?php

use App\Services\Receipts\ClaudeReceiptScanner;
use App\Services\Receipts\GeminiReceiptScanner;
use App\Services\Receipts\NullReceiptScanner;
use App\Services\Receipts\ReceiptScanException;
use App\Services\Receipts\ReceiptScanner;
use App\Services\Receipts\ReceiptScannerDisabled;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gem-model:generateContent';
const CLAUDE_URL = 'https://api.anthropic.com/v1/messages';

function receiptCategories(): array
{
    return [
        ['id' => 'c1', 'name' => 'Makanan', 'parent' => null],
        ['id' => 'c2', 'name' => 'Kopi', 'parent' => 'Makanan'],
    ];
}

function geminiBody(string $text): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
}

function claudeBody(string $text): array
{
    return ['content' => [['type' => 'text', 'text' => $text]]];
}

function geminiScanner(): GeminiReceiptScanner
{
    config(['receipts.scanner.gemini.key' => 'gkey', 'receipts.scanner.gemini.model' => 'gem-model']);

    return new GeminiReceiptScanner;
}

function claudeScanner(): ClaudeReceiptScanner
{
    config(['receipts.scanner.claude.key' => 'ckey', 'receipts.scanner.claude.model' => 'cl-model']);

    return new ClaudeReceiptScanner;
}

$json = '{"total":45000,"date":"2026-11-20","merchant":"Kopi Kenangan","category_id":"c2"}';

it('gemini sends the expected request and parses the answer', function () use ($json) {
    Http::fake([GEMINI_URL => Http::response(geminiBody($json))]);

    $s = geminiScanner()->scan('IMGBYTES', 'image/jpeg', receiptCategories());

    expect($s->toArray())->toBe(['total' => 45000, 'date' => '2026-11-20', 'merchant' => 'Kopi Kenangan', 'category_id' => 'c2']);

    Http::assertSent(function (Request $r) {
        $parts = $r['contents'][0]['parts'];

        return $r->method() === 'POST'
            && $r->url() === GEMINI_URL
            && $r->hasHeader('x-goog-api-key', 'gkey')
            && ! str_contains($r->url(), 'gkey')
            && str_contains($parts[0]['text'], 'Makanan') && str_contains($parts[0]['text'], 'c2')
            && $parts[1]['inline_data'] === ['mime_type' => 'image/jpeg', 'data' => base64_encode('IMGBYTES')]
            && $r['generationConfig']['responseMimeType'] === 'application/json';
    });
});

it('claude sends the expected request and parses the answer', function () use ($json) {
    Http::fake([CLAUDE_URL => Http::response(claudeBody($json))]);

    $s = claudeScanner()->scan('IMGBYTES', 'image/png', receiptCategories());

    expect($s->total)->toBe(45000)->and($s->categoryId)->toBe('c2');

    Http::assertSent(function (Request $r) {
        $content = $r['messages'][0]['content'];
        $image = collect($content)->firstWhere('type', 'image');
        $text = collect($content)->firstWhere('type', 'text');

        return $r->url() === CLAUDE_URL
            && $r->hasHeader('x-api-key', 'ckey')
            && $r->hasHeader('anthropic-version', '2023-06-01')
            && $r['model'] === 'cl-model'
            && $r['max_tokens'] === 300
            && $image['source'] === ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('IMGBYTES')]
            && str_contains($text['text'], 'Kopi');
    });
});

dataset('providers', [
    'gemini' => [fn () => geminiScanner(), GEMINI_URL, fn (string $t) => geminiBody($t)],
    'claude' => [fn () => claudeScanner(), CLAUDE_URL, fn (string $t) => claudeBody($t)],
]);

it('reads json wrapped in code fences', function ($make, $url, $body) use ($json) {
    Http::fake([$url => Http::response($body("```json\n{$json}\n```"))]);

    expect($make()->scan('x', 'image/jpeg', receiptCategories())->total)->toBe(45000);
})->with('providers');

it('drops an invented category', function ($make, $url, $body) {
    Http::fake([$url => Http::response($body('{"total":1000,"category_id":"zzz"}'))]);

    expect($make()->scan('x', 'image/jpeg', receiptCategories())->categoryId)->toBeNull();
})->with('providers');

it('throws ReceiptScanException on non-2xx', function ($make, $url, $body) {
    Http::fake([$url => Http::response(['error' => 'boom'], 500)]);

    expect(fn () => $make()->scan('x', 'image/jpeg', receiptCategories()))->toThrow(ReceiptScanException::class);
})->with('providers');

it('throws ReceiptScanException on connection failure', function ($make, $url, $body) {
    Http::fake([$url => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => $make()->scan('x', 'image/jpeg', receiptCategories()))->toThrow(ReceiptScanException::class);
})->with('providers');

it('throws ReceiptScanException on non-json or empty answers', function ($make, $url, $body) {
    Http::fake([$url => Http::sequence()->push($body('bukan json'))->push([])]);

    $scanner = $make();
    expect(fn () => $scanner->scan('x', 'image/jpeg', receiptCategories()))->toThrow(ReceiptScanException::class);
    expect(fn () => $scanner->scan('x', 'image/jpeg', receiptCategories()))->toThrow(ReceiptScanException::class);
})->with('providers');

it('does not leak the key in the exception message', function ($make, $url, $body) {
    Http::fake([$url => Http::response('gkey ckey', 401)]);

    try {
        $make()->scan('x', 'image/jpeg', receiptCategories());
    } catch (ReceiptScanException $e) {
        expect($e->getMessage())->not->toContain('gkey')->not->toContain('ckey');
    }
})->with('providers');

it('binds the scanner by driver', function (string $driver, ?string $key, string $expected) {
    config([
        'receipts.scanner.driver' => $driver,
        'receipts.scanner.gemini.key' => $driver === 'gemini' ? $key : null,
        'receipts.scanner.claude.key' => $driver === 'claude' ? $key : null,
    ]);
    app()->forgetInstance(ReceiptScanner::class);

    expect(app(ReceiptScanner::class))->toBeInstanceOf($expected);
})->with([
    'gemini without key' => ['gemini', null, NullReceiptScanner::class],
    'gemini empty key' => ['gemini', '', NullReceiptScanner::class],
    'gemini with key' => ['gemini', 'k', GeminiReceiptScanner::class],
    'claude with key' => ['claude', 'k', ClaudeReceiptScanner::class],
    'null driver' => ['null', 'k', NullReceiptScanner::class],
]);

it('null scanner throws ReceiptScannerDisabled', function () {
    expect(fn () => (new NullReceiptScanner)->scan('x', 'image/jpeg', []))->toThrow(ReceiptScannerDisabled::class);
});
