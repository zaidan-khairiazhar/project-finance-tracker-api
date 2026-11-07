<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu policy untuk semua model yang punya kolom user_id (Wallet, Category, Transaction).
 * Lapisan kedua setelah UserScope: scope menyembunyikan data user lain (404),
 * policy menolak jika somehow lolos.
 */
class OwnedByUserPolicy
{
    public function view(User $user, Model $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }
}
