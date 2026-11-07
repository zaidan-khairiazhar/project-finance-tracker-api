<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Membatasi query ke data milik user yang sedang login.
 * Tanpa user terautentikasi (queue, scheduler, seeder) scope ini tidak melakukan apa-apa.
 */
class UserScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $userId = auth()->id();

        if ($userId !== null) {
            $builder->where($model->qualifyColumn('user_id'), $userId);
        }
    }
}
