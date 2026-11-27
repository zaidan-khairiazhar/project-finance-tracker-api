<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReceiptStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('receipts.disk'));
    }

    public function store(Transaction $transaction, UploadedFile $file): void
    {
        if (in_array($transaction->type, [TransactionType::TransferOut, TransactionType::TransferIn], true)) {
            throw ValidationException::withMessages([
                'transaction' => 'Struk hanya untuk pemasukan dan pengeluaran.',
            ]);
        }

        // Tipe dibaca dari isi berkas (finfo), bukan dari nama atau MIME kiriman klien.
        $mime = $file->getMimeType();
        $extension = config('receipts.mimes')[$mime] ?? null;
        if ($extension === null) {
            throw ValidationException::withMessages([
                'image' => 'Struk hanya boleh berupa foto JPEG, PNG, atau WebP.',
            ]);
        }

        if ($file->getSize() > config('receipts.max_kb') * 1024) {
            throw ValidationException::withMessages([
                'image' => 'Ukuran foto maksimal 5 MB.',
            ]);
        }

        $oldPath = $transaction->receipt_path;

        // Tulis berkas baru dulu; berkas lama baru dihapus setelah yang baru aman.
        $path = $this->disk()->putFileAs(
            'receipts/'.$transaction->user_id,
            $file,
            Str::uuid().'.'.$extension,
        );
        if ($path === false || $path === '') {
            throw new RuntimeException('Gagal menyimpan foto struk.');
        }

        $transaction->forceFill(['receipt_path' => $path, 'receipt_mime' => $mime])->save();

        if ($oldPath !== null && $oldPath !== $path) {
            $this->disk()->delete($oldPath);
        }
    }

    /** Idempoten: aman dipanggil pada transaksi tanpa struk. */
    public function delete(Transaction $transaction): void
    {
        $path = $transaction->receipt_path;

        if ($path !== null) {
            $this->disk()->delete($path);
        }

        if ($path !== null || $transaction->receipt_mime !== null) {
            $transaction->forceFill(['receipt_path' => null, 'receipt_mime' => null])->save();
        }
    }
}
