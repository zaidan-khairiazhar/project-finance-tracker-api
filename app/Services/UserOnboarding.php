<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Enums\WalletType;
use App\Models\Category;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;

/**
 * Data awal untuk user baru: satu dompet "Tunai" dan kategori bawaan.
 *
 * user_id diisi manual karena saat registrasi belum ada user yang login,
 * jadi hook BelongsToUser tidak bisa mengisinya. Model disimpan satu per satu
 * (bukan insert massal) agar UUID dan event model tetap berjalan.
 */
class UserOnboarding
{
    private const EXPENSE_CATEGORIES = [
        'Makanan & Minuman', 'Transportasi', 'Belanja', 'Tagihan',
        'Hiburan', 'Kesehatan', 'Pendidikan', 'Lainnya',
    ];

    private const INCOME_CATEGORIES = ['Gaji', 'Bonus', 'Hadiah', 'Lainnya'];

    public function run(User $user): void
    {
        $this->ownedBy($user, new Wallet(['name' => 'Tunai', 'type' => WalletType::Cash]))->save();

        foreach (self::EXPENSE_CATEGORIES as $name) {
            $this->ownedBy($user, new Category(['name' => $name, 'type' => CategoryType::Expense]))->save();
        }

        foreach (self::INCOME_CATEGORIES as $name) {
            $this->ownedBy($user, new Category(['name' => $name, 'type' => CategoryType::Income]))->save();
        }
    }

    private function ownedBy(User $user, Model $model): Model
    {
        $model->user_id = $user->id;

        return $model;
    }
}
