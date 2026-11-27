<?php

use App\Models\Category;
use App\Services\Receipts\ReceiptScanException;
use App\Services\Receipts\ReceiptScanner;
use App\Services\Receipts\ReceiptScannerDisabled;
use App\Services\Receipts\ReceiptSuggestion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function fakeScanner(Closure|Throwable $result): object
{
    $fake = new class($result) implements ReceiptScanner
    {
        public array $calls = [];

        public function __construct(private Closure|Throwable $result) {}

        public function scan(string $bytes, string $mime, array $categories): ReceiptSuggestion
        {
            $this->calls[] = compact('bytes', 'mime', 'categories');
            if ($this->result instanceof Throwable) {
                throw $this->result;
            }

            return ($this->result)();
        }
    };
    app()->instance(ReceiptScanner::class, $fake);

    return $fake;
}

beforeEach(function () {
    Storage::fake(config('receipts.disk'));
    $this->user = actingUser();
    $this->url = '/api/v1/receipts/scan';
    $this->img = fn () => UploadedFile::fake()->image('struk.jpg');
    $this->h = ['Accept' => 'application/json'];
});

it('returns the suggestion', function () {
    fakeScanner(fn () => new ReceiptSuggestion(45000, '2026-11-20', 'Indomaret', 'cat-1'));

    $this->post($this->url, ['image' => ($this->img)()], $this->h)
        ->assertOk()
        ->assertExactJson(['data' => [
            'total' => 45000, 'date' => '2026-11-20', 'merchant' => 'Indomaret', 'category_id' => 'cat-1',
        ]]);
});

it('gives the scanner only the user\'s expense categories with parent names', function () {
    $parent = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense', 'name' => 'Makanan']);
    $child = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense', 'name' => 'Kopi', 'parent_id' => $parent->id]);
    Category::factory()->create(['user_id' => $this->user->id, 'type' => 'income', 'name' => 'Gaji']);
    Category::factory()->create(['type' => 'expense', 'name' => 'Milik Orang Lain']);
    $fake = fakeScanner(fn () => new ReceiptSuggestion);

    $this->post($this->url, ['image' => ($this->img)()], $this->h)->assertOk();

    $sent = collect($fake->calls[0]['categories'])->keyBy('id');
    expect($sent->keys()->sort()->values()->all())->toBe(collect([$parent->id, $child->id])->sort()->values()->all())
        ->and($sent[$parent->id])->toBe(['id' => $parent->id, 'name' => 'Makanan', 'parent' => null])
        ->and($sent[$child->id])->toBe(['id' => $child->id, 'name' => 'Kopi', 'parent' => 'Makanan'])
        ->and($fake->calls[0]['mime'])->toBe('image/jpeg');
});

it('returns 503 when the scanner is disabled', function () {
    fakeScanner(new ReceiptScannerDisabled);

    $this->post($this->url, ['image' => ($this->img)()], $this->h)
        ->assertStatus(503)
        ->assertExactJson(['message' => 'Pembacaan struk otomatis tidak aktif.', 'code' => 'scan_unavailable']);
});

it('returns 502 when the scan fails', function () {
    fakeScanner(new ReceiptScanException('boom'));

    $this->post($this->url, ['image' => ($this->img)()], $this->h)
        ->assertStatus(502)
        ->assertExactJson(['message' => 'Struk tidak dapat dibaca. Isi manual.', 'code' => 'scan_failed']);
});

it('rejects a text file named struk.jpg', function () {
    $fake = fakeScanner(fn () => new ReceiptSuggestion);
    $tmp = tempnam(sys_get_temp_dir(), 'struk');
    file_put_contents($tmp, 'bukan gambar');
    $file = new UploadedFile($tmp, 'struk.jpg', 'image/jpeg', null, true);

    $this->post($this->url, ['image' => $file], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('image');
    expect($fake->calls)->toBeEmpty();
});

it('rejects an image larger than 5 MB', function () {
    $this->post($this->url, ['image' => UploadedFile::fake()->image('big.png')->size(5121)], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('image');
});

it('requires an image', function () {
    $this->postJson($this->url)->assertStatus(422)->assertJsonValidationErrors('image');
});

it('requires authentication', function () {
    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->h)->post($this->url, ['image' => ($this->img)()])->assertUnauthorized();
});

it('throttles after the configured number of scans per hour', function () {
    config(['receipts.scan_per_hour' => 2]);
    fakeScanner(fn () => new ReceiptSuggestion);

    $this->post($this->url, ['image' => ($this->img)()], $this->h)->assertOk();
    $this->post($this->url, ['image' => ($this->img)()], $this->h)->assertOk();
    $this->post($this->url, ['image' => ($this->img)()], $this->h)->assertStatus(429);
});

it('stores nothing', function () {
    fakeScanner(fn () => new ReceiptSuggestion(1000));

    $this->post($this->url, ['image' => ($this->img)()], $this->h)->assertOk();

    expect(Storage::disk(config('receipts.disk'))->allFiles())->toBeEmpty();
});
