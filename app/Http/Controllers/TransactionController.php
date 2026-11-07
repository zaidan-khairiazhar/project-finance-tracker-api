<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function __construct(private TransactionService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'wallet_id' => ['sometimes', 'uuid'],
            'category_id' => ['sometimes', 'uuid'],
            'type' => ['sometimes', Rule::in(['income', 'expense', 'transfer_out', 'transfer_in'])],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return TransactionResource::collection(
            Transaction::query()
                ->filter($filters)
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->cursorPaginate((int) ($filters['per_page'] ?? 25))
                ->withQueryString()
        );
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $this->service->create($request->user(), $request->validated());

        return (new TransactionResource($transaction))->response()->setStatusCode(201);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        Gate::authorize('view', $transaction);

        return new TransactionResource($transaction);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): TransactionResource
    {
        Gate::authorize('update', $transaction);

        return new TransactionResource($this->service->update($transaction, $request->validated()));
    }

    public function destroy(Transaction $transaction): Response
    {
        Gate::authorize('delete', $transaction);

        $this->service->delete($transaction);

        return response()->noContent();
    }
}
