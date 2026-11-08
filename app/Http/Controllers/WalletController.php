<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWalletRequest;
use App\Http\Requests\UpdateWalletRequest;
use App\Http\Resources\WalletResource;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * @group Wallets
 *
 * Dompet. Saldo dihitung dari transaksi, tidak disimpan.
 */
class WalletController extends Controller
{
    /**
     * Daftar dompet
     */
    public function index(): AnonymousResourceCollection
    {
        return WalletResource::collection(
            Wallet::withBalance()->orderBy('is_archived')->orderBy('name')->get()
        );
    }

    /**
     * Buat dompet
     */
    public function store(StoreWalletRequest $request): JsonResponse
    {
        $wallet = Wallet::create($request->validated());

        return $this->present($wallet)->response()->setStatusCode(201);
    }

    /**
     * Detail dompet
     */
    public function show(Wallet $wallet): WalletResource
    {
        Gate::authorize('view', $wallet);

        return $this->present($wallet);
    }

    /**
     * Ubah / arsipkan dompet
     */
    public function update(UpdateWalletRequest $request, Wallet $wallet): WalletResource
    {
        Gate::authorize('update', $wallet);

        $wallet->update($request->validated());

        return $this->present($wallet);
    }

    /**
     * Dompet yang sudah punya transaksi hanya diarsipkan (riwayat tetap utuh);
     * dompet yang masih kosong benar-benar dihapus (soft delete).
     */
    public function destroy(Wallet $wallet): JsonResponse|Response
    {
        Gate::authorize('delete', $wallet);

        if ($wallet->transactions()->exists()) {
            $wallet->update(['is_archived' => true]);

            return $this->present($wallet)->response();
        }

        $wallet->delete();

        return response()->noContent();
    }

    // Selalu kembalikan dompet lengkap dengan saldo terhitung.
    private function present(Wallet $wallet): WalletResource
    {
        return new WalletResource(Wallet::withBalance()->findOrFail($wallet->id));
    }
}
