<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReceiptRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\ReceiptStorage;
use App\Services\WalletGuard;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * @group Receipts
 *
 * Foto struk pada transaksi pemasukan dan pengeluaran. Satu transaksi satu foto; mengunggah lagi akan mengganti foto lama.
 */
class ReceiptController extends Controller
{
    public function __construct(private ReceiptStorage $receipts) {}

    /**
     * Unggah foto struk
     *
     * Kirim sebagai multipart/form-data. Transaksi transfer ditolak (422).
     */
    public function store(StoreReceiptRequest $request, Transaction $transaction): TransactionResource
    {
        Gate::authorize('update', $transaction);
        WalletGuard::assertUsable($transaction->wallet, 'wallet_id');

        $this->receipts->store($transaction, $request->file('image'));

        return new TransactionResource($transaction->refresh());
    }

    /**
     * Lihat foto struk
     *
     * Mengembalikan berkas gambar; 404 bila transaksi tidak punya struk.
     *
     * @response 404 {"message": "Struk tidak ditemukan."}
     */
    public function show(Transaction $transaction): SymfonyResponse
    {
        Gate::authorize('view', $transaction);

        $path = $transaction->receipt_path;
        $disk = $this->receipts->disk();
        abort_if($path === null || ! $disk->exists($path), 404, 'Struk tidak ditemukan.');

        return $disk->response($path, null, [
            'Content-Type' => $transaction->receipt_mime,
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Hapus foto struk
     *
     * Idempoten: tetap 204 bila transaksi tidak punya struk.
     */
    public function destroy(Transaction $transaction): Response
    {
        Gate::authorize('update', $transaction);
        WalletGuard::assertUsable($transaction->wallet, 'wallet_id');

        $this->receipts->delete($transaction);

        return response()->noContent();
    }
}
