<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRecurringRuleRequest;
use App\Http\Requests\UpdateRecurringRuleRequest;
use App\Http\Resources\RecurringRuleResource;
use App\Models\RecurringRule;
use App\Models\Wallet;
use App\Services\RecurringGenerator;
use App\Services\TransactionService;
use App\Services\WalletGuard;
use App\Support\MonthRange;
use App\Support\RecurrenceSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * @group Recurring Rules
 *
 * Aturan pemasukan atau pengeluaran berulang (harian, mingguan, bulanan). Pada tanggalnya aturan
 * otomatis menjadi transaksi biasa; kemunculan yang terlewat disusul dengan tanggal aslinya.
 */
class RecurringRuleController extends Controller
{
    public function __construct(private RecurringGenerator $generator, private TransactionService $transactions) {}

    /**
     * Daftar aturan berulang
     *
     * Diurutkan dari kemunculan berikutnya yang paling dekat. `status` dihitung: active, paused, atau finished.
     */
    public function index(): AnonymousResourceCollection
    {
        return RecurringRuleResource::collection(
            RecurringRule::with(['wallet', 'category'])->orderBy('next_due_date')->orderBy('created_at')->get()
        );
    }

    /**
     * Buat aturan berulang
     *
     * Jika `start_date` hari ini atau lebih awal (maksimal 366 hari ke belakang), kemunculan yang sudah lewat
     * langsung dibuat.
     */
    public function store(StoreRecurringRuleRequest $request): JsonResponse
    {
        $data = $request->validated();
        WalletGuard::assertUsable(Wallet::findOrFail($data['wallet_id']), 'wallet_id');
        $this->transactions->assertCategoryMatches($data['category_id'] ?? null, $data['type']);

        // Atomik: kalau susulan gagal, aturan ikut dibatalkan agar klien tidak mengulang dan membuat aturan ganda.
        $rule = DB::transaction(function () use ($data) {
            $rule = RecurringRule::create($data + ['next_due_date' => $data['start_date']]);
            $this->generator->catchUpRule($rule);

            return $rule;
        });

        return RecurringRuleResource::make($rule->fresh(['wallet', 'category']))->response()->setStatusCode(201);
    }

    /**
     * Ubah aturan
     *
     * Dompet, kategori, nominal, catatan, `end_date`, dan `is_active` bisa diubah; frekuensi dan `start_date` tidak.
     * Perubahan nominal hanya berlaku untuk kemunculan berikutnya. Mengaktifkan kembali aturan memvalidasi ulang
     * dompet dan kategori, dan menggeser kemunculan berikutnya ke hari ini atau sesudahnya (masa jeda tidak disusul).
     */
    public function update(UpdateRecurringRuleRequest $request, RecurringRule $rule): RecurringRuleResource
    {
        Gate::authorize('update', $rule);
        $data = $request->validated();
        // Rule `boolean` menerima 1/"1"; samakan jadi bool agar cabang aktivasi ulang tidak terlewat.
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $request->boolean('is_active');
        }

        if (isset($data['wallet_id'])) {
            WalletGuard::assertUsable(Wallet::findOrFail($data['wallet_id']), 'wallet_id');
        }
        if (array_key_exists('category_id', $data)) {
            $this->transactions->assertCategoryMatches($data['category_id'], $rule->type->value);
        }

        if (($data['is_active'] ?? false) === true && ! $rule->is_active) {
            $data = $this->reactivation($rule, $data);
        }

        $rule->update($data);

        return RecurringRuleResource::make($rule->fresh(['wallet', 'category']));
    }

    /** Validasi ulang dompet dan kategori, kosongkan alasan jeda, dan geser `next_due_date` agar tidak ada banjir transaksi lama. */
    private function reactivation(RecurringRule $rule, array $data): array
    {
        $wallet = isset($data['wallet_id']) ? Wallet::findOrFail($data['wallet_id']) : $rule->wallet;
        if ($wallet === null || $wallet->trashed()) {
            throw ValidationException::withMessages(['wallet_id' => 'Dompet sudah dihapus.']);
        }
        WalletGuard::assertUsable($wallet, 'wallet_id');

        $categoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $rule->category_id;
        if ($categoryId !== null && ! array_key_exists('category_id', $data) && ($rule->category === null || $rule->category->trashed())) {
            throw ValidationException::withMessages(['category_id' => 'Kategori sudah dihapus.']);
        }

        $today = CarbonImmutable::parse(CarbonImmutable::now(MonthRange::TIMEZONE)->toDateString());
        $current = CarbonImmutable::parse($rule->next_due_date->toDateString());
        $next = RecurrenceSchedule::firstOnOrAfter($rule->frequency, CarbonImmutable::parse($rule->start_date->toDateString()), $today);

        return $data + [
            'paused_reason' => null,
            'next_due_date' => ($next->gt($current) ? $next : $current)->toDateString(),
        ];
    }

    /**
     * Hapus aturan
     *
     * Soft delete. Transaksi yang sudah dibuat tetap ada.
     */
    public function destroy(RecurringRule $rule): Response
    {
        Gate::authorize('delete', $rule);

        $rule->delete();

        return response()->noContent();
    }

    /**
     * Jalankan sekarang
     *
     * Membuat semua kemunculan yang sudah jatuh tempo untuk user yang sedang masuk.
     */
    public function run(Request $request): JsonResponse
    {
        return response()->json(['created' => $this->generator->catchUp($request->user())]);
    }
}
