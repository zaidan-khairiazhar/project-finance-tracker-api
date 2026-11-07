<?php

use App\Models\Category;
use App\Models\Transaction;

it('filters categories by type', function () {
    $u = actingUser();
    Category::factory()->create(['user_id' => $u->id, 'type' => 'income']);
    Category::factory()->create(['user_id' => $u->id, 'type' => 'expense']);

    $this->getJson('/api/v1/categories?type=income')->assertOk()->assertJsonCount(1, 'data');
});

it('rejects invalid parents', function () {
    $u = actingUser();
    $root = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'parent_id' => null]);
    $child = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'parent_id' => $root->id]);
    $other = Category::factory()->create(['type' => 'expense']);
    $income = Category::factory()->create(['user_id' => $u->id, 'type' => 'income', 'parent_id' => null]);
    $base = ['name' => 'Sub', 'type' => 'expense', 'icon' => 'x', 'color' => '#000000'];

    foreach ([$child->id, $other->id, $income->id] as $bad) {
        $this->postJson('/api/v1/categories', $base + ['parent_id' => $bad])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    $this->patchJson("/api/v1/categories/{$root->id}", ['parent_id' => $root->id])->assertUnprocessable();
    $this->postJson('/api/v1/categories', $base + ['parent_id' => $root->id])->assertCreated();
    // $root sudah punya anak, jadi tidak boleh diberi induk
    $this->patchJson("/api/v1/categories/{$root->id}", ['parent_id' => $income->id])->assertUnprocessable();
});

it('refuses to delete a category in use or with children, deletes an unused one', function () {
    $u = actingUser();
    $used = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense']);
    Transaction::factory()->create(['user_id' => $u->id, 'category_id' => $used->id, 'type' => 'expense']);
    $parent = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense']);
    Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'parent_id' => $parent->id]);
    $free = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense']);

    $this->deleteJson("/api/v1/categories/{$used->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/categories/{$parent->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/categories/{$free->id}")->assertNoContent();
});

it('creates a category and returns it', function () {
    actingUser();

    $this->postJson('/api/v1/categories', ['name' => 'Kopi', 'type' => 'expense', 'icon' => 'coffee', 'color' => '#A1B2C3'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Kopi')
        ->assertJsonPath('data.type', 'expense')
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.color', '#A1B2C3');
});

it('validates category input', function () {
    actingUser();

    $this->postJson('/api/v1/categories', ['name' => '', 'type' => 'transfer', 'color' => 'merah'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'type', 'color']);
});

it('renames a category but never changes its type', function () {
    $u = actingUser();
    $cat = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'name' => 'Lama']);

    $this->patchJson("/api/v1/categories/{$cat->id}", ['name' => 'Baru', 'type' => 'income'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Baru')
        ->assertJsonPath('data.type', 'expense');
});

it("returns 404 for another user's category", function () {
    actingUser();
    $cat = Category::factory()->create();

    $this->patchJson("/api/v1/categories/{$cat->id}", ['name' => 'X'])->assertNotFound();
    $this->deleteJson("/api/v1/categories/{$cat->id}")->assertNotFound();
});
