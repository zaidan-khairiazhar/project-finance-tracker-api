<?php

use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\ReceiptStorage;
use App\Services\TransactionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake(config('receipts.disk'));
    $this->disk = Storage::disk(config('receipts.disk'));
    $this->storage = app(ReceiptStorage::class);
});

it('stores a jpeg under receipts/{user_id}/{uuid}.jpg', function () {
    $t = Transaction::factory()->create();

    $this->storage->store($t, UploadedFile::fake()->image('a.jpg'));

    $t->refresh();
    expect($t->receipt_path)->toMatch('#^receipts/'.$t->user_id.'/[0-9a-f-]{36}\.jpg$#')
        ->and($t->receipt_mime)->toBe('image/jpeg');
    $this->disk->assertExists($t->receipt_path);
});

it('replaces an existing receipt and removes the old file', function () {
    $t = Transaction::factory()->create();
    $this->storage->store($t, UploadedFile::fake()->image('a.jpg'));
    $old = $t->fresh()->receipt_path;

    $this->storage->store($t->fresh(), UploadedFile::fake()->image('b.png'));

    $t->refresh();
    expect($t->receipt_path)->not->toBe($old)->and($t->receipt_mime)->toBe('image/png');
    $this->disk->assertMissing($old);
    $this->disk->assertExists($t->receipt_path);
});

it('deletes the file and nulls columns, idempotently', function () {
    $t = Transaction::factory()->create();
    $this->storage->store($t, UploadedFile::fake()->image('a.jpg'));
    $path = $t->fresh()->receipt_path;

    $this->storage->delete($t->fresh());
    $this->storage->delete($t->fresh());

    $t->refresh();
    expect($t->receipt_path)->toBeNull()->and($t->receipt_mime)->toBeNull();
    $this->disk->assertMissing($path);
});

it('rejects a text file named struk.jpg by content', function () {
    $t = Transaction::factory()->create();

    // UploadedFile::fake() menebak tipe dari nama berkas, jadi pakai berkas sungguhan agar finfo yang menentukan.
    $tmp = tempnam(sys_get_temp_dir(), 'struk');
    file_put_contents($tmp, 'bukan gambar');
    $file = new UploadedFile($tmp, 'struk.jpg', 'image/jpeg', null, true);

    try {
        $this->storage->store($t, $file);
        $this->fail('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('image');
    }
    expect($t->fresh()->receipt_path)->toBeNull();
});

it('rejects files larger than 5120 KB', function () {
    $t = Transaction::factory()->create();

    try {
        $this->storage->store($t, UploadedFile::fake()->image('big.jpg')->size(5121));
        $this->fail('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('image');
    }
});

it('rejects transfer transactions', function (string $type) {
    $t = Transaction::factory()->create(['type' => $type]);

    try {
        $this->storage->store($t, UploadedFile::fake()->image('a.jpg'));
        $this->fail('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('transaction');
    }
})->with(['transfer_out', 'transfer_in']);

it('removes the file when the transaction is deleted via the service', function () {
    $t = Transaction::factory()->create();
    $this->storage->store($t, UploadedFile::fake()->image('a.jpg'));
    $path = $t->fresh()->receipt_path;

    app(TransactionService::class)->delete($t->fresh());

    $this->disk->assertMissing($path);
    expect(Transaction::withTrashed()->find($t->id)->receipt_path)->toBeNull();
});

it('exposes has_receipt on the resource', function () {
    $t = Transaction::factory()->create();
    expect((new TransactionResource($t))->toArray(request())['has_receipt'])->toBeFalse();

    $this->storage->store($t, UploadedFile::fake()->image('a.jpg'));
    expect((new TransactionResource($t->fresh()))->toArray(request())['has_receipt'])->toBeTrue();
});
