<?php

use App\Http\Requests\StoreReceiptRequest;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('receipts.disk'));
    $this->disk = Storage::disk(config('receipts.disk'));
    $this->user = actingUser();
    $this->tx = Transaction::factory()->create(['user_id' => $this->user->id]);
    $this->url = "/api/v1/transactions/{$this->tx->id}/receipt";
});

it('uploads a jpeg and flags has_receipt', function () {
    $this->post($this->url, ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.has_receipt', true);

    $this->disk->assertExists($this->tx->fresh()->receipt_path);
});

it('replaces the receipt leaving a single file', function () {
    $h = ['Accept' => 'application/json'];
    $this->post($this->url, ['image' => UploadedFile::fake()->image('a.jpg')], $h)->assertOk();
    $this->post($this->url, ['image' => UploadedFile::fake()->image('b.png')], $h)->assertOk();

    expect($this->disk->allFiles())->toHaveCount(1)
        ->and($this->tx->fresh()->receipt_mime)->toBe('image/png');
});

it('rejects a text file named struk.jpg', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'struk');
    file_put_contents($tmp, 'bukan gambar');
    $file = new UploadedFile($tmp, 'struk.jpg', 'image/jpeg', null, true);

    $this->post($this->url, ['image' => $file], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('image');
});

it('rejects a png larger than 5 MB', function () {
    $this->post($this->url, ['image' => UploadedFile::fake()->image('big.png')->size(5121)], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('image');
});

it('requires an image', function () {
    $this->postJson($this->url)->assertStatus(422)->assertJsonValidationErrors('image');
});

it('rejects transfer transactions', function () {
    $t = Transaction::factory()->create(['user_id' => $this->user->id, 'type' => 'transfer_out']);

    $this->post("/api/v1/transactions/{$t->id}/receipt", ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

it('returns 404 for another user\'s transaction', function () {
    $other = Transaction::factory()->create();
    $url = "/api/v1/transactions/{$other->id}/receipt";

    $this->post($url, ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertNotFound();
    $this->getJson($url)->assertNotFound();
    $this->deleteJson($url)->assertNotFound();
});

it('serves the same bytes with the stored content type', function () {
    $file = UploadedFile::fake()->image('a.jpg');
    $bytes = file_get_contents($file->getRealPath());
    $this->post($this->url, ['image' => $file], ['Accept' => 'application/json'])->assertOk();

    $response = $this->get($this->url);

    $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->toBe($bytes);
});

it('returns 404 when there is no receipt', function () {
    $this->getJson($this->url)->assertNotFound();

    $t = Transaction::factory()->create(['user_id' => $this->user->id, 'type' => 'transfer_in']);
    $this->getJson("/api/v1/transactions/{$t->id}/receipt")->assertNotFound();
});

it('deletes the receipt idempotently', function () {
    $this->post($this->url, ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
    $path = $this->tx->fresh()->receipt_path;

    $this->deleteJson($this->url)->assertNoContent();
    $this->disk->assertMissing($path);
    $this->deleteJson($this->url)->assertNoContent();
    expect($this->tx->fresh()->receipt_path)->toBeNull();
});

it('StoreReceiptRequest rules() needs no route binding', function () {
    expect((new StoreReceiptRequest)->rules())->toHaveKey('image');
});

it('rejects upload and delete for a transaction in an archived wallet', function () {
    $h = ['Accept' => 'application/json'];
    $this->post($this->url, ['image' => UploadedFile::fake()->image('a.jpg')], $h)->assertOk();

    $this->tx->wallet->forceFill(['is_archived' => true])->save();

    $this->post($this->url, ['image' => UploadedFile::fake()->image('b.jpg')], $h)
        ->assertUnprocessable()->assertJsonValidationErrors('wallet_id');
    $this->deleteJson($this->url)->assertUnprocessable()->assertJsonValidationErrors('wallet_id');
});
