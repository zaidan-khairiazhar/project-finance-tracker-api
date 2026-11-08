<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\TransferService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @group Transfers
 *
 * Transfer antar dompet. Satu transfer = dua baris transaksi dengan transfer_group_id yang sama.
 */
class TransferController extends Controller
{
    public function __construct(private TransferService $service) {}

    /**
     * Buat transfer
     */
    public function store(StoreTransferRequest $request): JsonResponse
    {
        $data = $request->validated();

        $groupId = $this->service->create(
            $request->user(),
            $data['from_wallet_id'],
            $data['to_wallet_id'],
            $data['amount'],
            Carbon::parse($data['occurred_at'] ?? now()),
            $data['note'] ?? null,
        );

        $rows = Transaction::where('transfer_group_id', $groupId)->get();
        $resource = fn (TransactionType $type) => (new TransactionResource(
            $rows->firstWhere('type', $type)
        ))->resolve();

        return response()->json(['data' => [
            'transfer_group_id' => $groupId,
            'out' => $resource(TransactionType::TransferOut),
            'in' => $resource(TransactionType::TransferIn),
        ]], 201);
    }

    /**
     * Hapus transfer (kedua sisi)
     */
    public function destroy(Request $request, string $groupId): Response
    {
        $this->service->delete($request->user(), $groupId);

        return response()->noContent();
    }
}
