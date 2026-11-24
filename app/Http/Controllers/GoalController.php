<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGoalRequest;
use App\Http\Requests\UpdateGoalRequest;
use App\Http\Resources\GoalResource;
use App\Models\Goal;
use App\Models\Wallet;
use App\Services\WalletGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * @group Goals
 *
 * Target tabungan. Progres tidak disimpan: dihitung setiap kali dibaca dari saldo satu dompet.
 * Satu dompet hanya bisa dipakai satu target aktif.
 */
class GoalController extends Controller
{
    /**
     * Daftar target
     *
     * Diurutkan dari tenggat terdekat (target tanpa tenggat di akhir), lalu waktu dibuat. `current_amount` adalah saldo
     * dompet saat ini; `status` dihitung: in_progress, reached, atau overdue. `wallet_status` bernilai ok, archived,
     * atau deleted. `monthly_needed` hanya terisi untuk target in_progress yang punya tenggat.
     */
    public function index(): AnonymousResourceCollection
    {
        return GoalResource::collection(
            Goal::with(['wallet' => fn ($q) => $q->withBalance()])
                ->orderByRaw('deadline ASC NULLS LAST')
                ->orderBy('created_at')->orderBy('id')
                ->get()
        );
    }

    /**
     * Buat target
     *
     * Dompet harus milik user, tidak diarsipkan, dan belum dipakai target lain. `deadline` opsional (Y-m-d) dan
     * tidak boleh sebelum hari ini (Asia/Jakarta).
     */
    public function store(StoreGoalRequest $request): JsonResponse
    {
        $data = $request->validated();
        WalletGuard::assertUsable(Wallet::findOrFail($data['wallet_id']), 'wallet_id');

        try {
            $goal = Goal::create($data);
        } catch (UniqueConstraintViolationException) {
            // Balapan: dompet dipakai target lain di antara validasi dan insert.
            throw $this->walletTaken();
        }

        return GoalResource::make($this->load($goal))->response()->setStatusCode(201);
    }

    /**
     * Ubah target
     *
     * Nama, dompet, nominal, dan tenggat bisa diubah. Tenggat hanya divalidasi bila nilainya berubah, sehingga target
     * yang sudah lewat tenggat tetap bisa diganti namanya. Dompet baru harus bisa dipakai dan belum punya target.
     */
    public function update(UpdateGoalRequest $request, Goal $goal): GoalResource
    {
        Gate::authorize('update', $goal);
        $data = $request->validated();

        // Hanya dompet yang baru dipilih yang diperiksa; dompet lama yang kini diarsipkan tidak menghalangi ganti nama.
        if (isset($data['wallet_id']) && $data['wallet_id'] !== $goal->wallet_id) {
            WalletGuard::assertUsable(Wallet::findOrFail($data['wallet_id']), 'wallet_id');
        }

        try {
            $goal->update($data);
        } catch (UniqueConstraintViolationException) {
            throw $this->walletTaken();
        }

        return GoalResource::make($this->load($goal));
    }

    /**
     * Hapus target
     *
     * Soft delete. Dompetnya bebas dipakai target baru.
     */
    public function destroy(Goal $goal): Response
    {
        Gate::authorize('delete', $goal);

        $goal->delete();

        return response()->noContent();
    }

    private function load(Goal $goal): Goal
    {
        return Goal::with(['wallet' => fn ($q) => $q->withBalance()])->findOrFail($goal->id);
    }

    private function walletTaken(): ValidationException
    {
        return ValidationException::withMessages(['wallet_id' => 'Dompet ini sudah dipakai target lain.']);
    }
}
