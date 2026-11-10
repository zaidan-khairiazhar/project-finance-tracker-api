<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TransactionCsv;

/** Parse isi CSV (tanpa BOM) menjadi baris-baris, benar untuk sel multibaris. */
function csvRows(string $content): array
{
    $h = fopen('php://memory', 'r+');
    fwrite($h, preg_replace('/^\xEF\xBB\xBF/', '', $content));
    rewind($h);
    $rows = [];
    while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    return $rows;
}

function exportTx(User $u, array $attrs = []): Transaction
{
    return Transaction::factory()->create($attrs + [
        'user_id' => $u->id,
        'wallet_id' => Wallet::factory()->create(['user_id' => $u->id, 'name' => 'BCA'])->id,
        'type' => 'expense', 'amount' => 1000, 'occurred_at' => '2026-11-07T10:00:00+07:00',
    ]);
}

function exportGet(string $query = ''): Illuminate\Testing\TestResponse
{
    return test()->withHeaders(['Accept' => 'text/csv'])->get('/api/v1/transactions/export'.$query);
}

afterEach(fn () => TransactionCsv::$maxRows = 50_000);

it('returns csv with BOM, header, headers and WIB timestamps', function () {
    $u = actingUser();
    $cat = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'name' => 'Makanan']);
    exportTx($u, ['category_id' => $cat->id, 'amount' => 25000, 'note' => 'makan', 'occurred_at' => '2026-11-07T16:30:00Z']);
    $this->travelTo('2026-11-09 10:00:00');

    $res = exportGet()->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $content = $res->streamedContent();

    expect($res->headers->get('Content-Disposition'))->toBe('attachment; filename="transaksi-20261109.csv"')
        ->and(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and(csvRows($content))->toBe([
            ['Tanggal', 'Dompet', 'Tipe', 'Kategori', 'Jumlah', 'Catatan'],
            ['2026-11-07 23:30', 'BCA', 'expense', 'Makanan', '25000', 'makan'],
        ]);
});

it('neutralises formula injection in text cells', function (string $note) {
    $u = actingUser();
    exportTx($u, ['note' => $note]);

    $rows = csvRows(exportGet()->streamedContent());

    expect($rows[1][5])->toBe("'".$note);
})->with(['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"]);

it('leaves normal text and amounts untouched', function () {
    expect(TransactionCsv::cell('makan siang'))->toBe('makan siang')
        ->and(TransactionCsv::cell(''))->toBe('')
        ->and(TransactionCsv::cell('a=b'))->toBe('a=b');
});

it('escapes commas, quotes and newlines', function () {
    $u = actingUser();
    $note = "a,\"b\"\nc";
    exportTx($u, ['note' => $note]);

    $rows = csvRows(exportGet()->streamedContent());

    expect($rows)->toHaveCount(2)->and($rows[1][5])->toBe($note);
});

it('applies the same filters as the transaction list', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $mk = fn (array $a) => exportTx($u, $a + ['wallet_id' => $w->id]);
    $mk(['type' => 'income', 'amount' => 111, 'occurred_at' => '2026-11-10T10:00:00+07:00']);
    $mk(['type' => 'expense', 'amount' => 222, 'occurred_at' => '2026-11-10T10:00:00+07:00']);            // tipe lain
    $mk(['type' => 'income', 'amount' => 333, 'occurred_at' => '2026-12-01T00:00:00+07:00']);            // di luar rentang
    $mk(['type' => 'income', 'amount' => 444, 'occurred_at' => '2026-11-30T23:30:00+07:00', 'note' => 'gaji']);
    exportTx($u, ['type' => 'income', 'amount' => 555, 'occurred_at' => '2026-11-10T10:00:00+07:00']);     // dompet lain

    $rows = csvRows(exportGet("?type=income&from=2026-11-01&to=2026-11-30&wallet_id={$w->id}")->streamedContent());
    expect(array_column(array_slice($rows, 1), 4))->toBe(['444', '111']);

    $byNote = csvRows(exportGet('?q=gaji')->streamedContent());
    expect(array_column(array_slice($byNote, 1), 4))->toBe(['444']);
});

it("never includes another user's rows", function () {
    $u = actingUser();
    exportTx($u, ['amount' => 111]);
    exportTx(User::factory()->create(), ['amount' => 999]);

    $rows = csvRows(exportGet()->streamedContent());

    expect(array_column(array_slice($rows, 1), 4))->toBe(['111']);
});

it('orders by occurred_at descending, then id descending', function () {
    $u = actingUser();
    exportTx($u, ['amount' => 1, 'occurred_at' => '2026-11-01T10:00:00+07:00']);
    exportTx($u, ['amount' => 2, 'occurred_at' => '2026-11-03T10:00:00+07:00', 'id' => '0198f0a0-0000-7000-8000-000000000001']);
    exportTx($u, ['amount' => 3, 'occurred_at' => '2026-11-03T10:00:00+07:00', 'id' => '0198f0a0-0000-7000-8000-000000000002']);

    $rows = csvRows(exportGet()->streamedContent());

    expect(array_column(array_slice($rows, 1), 4))->toBe(['3', '2', '1']);
});

it('keeps names of deleted wallets and categories', function () {
    $u = actingUser();
    $cat = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'name' => 'Lama']);
    $t = exportTx($u, ['category_id' => $cat->id]);
    $t->wallet->update(['is_archived' => true]);
    $cat->delete();
    $t->wallet->delete();

    $rows = csvRows(exportGet()->streamedContent());

    expect($rows[1][1])->toBe('BCA')->and($rows[1][3])->toBe('Lama');
});

it('rejects more than the maximum rows with 422', function () {
    $u = actingUser();
    TransactionCsv::$maxRows = 2;
    exportTx($u);
    exportTx($u);
    exportTx($u);

    $this->getJson('/api/v1/transactions/export')->assertUnprocessable()->assertJsonValidationErrors('filters');

    TransactionCsv::$maxRows = 3;
    exportGet()->assertOk();
});

it('requires authentication and validates filters', function () {
    $this->getJson('/api/v1/transactions/export')->assertUnauthorized();

    actingUser();
    foreach (['type=foo', 'from=abc', 'wallet_id=abc', 'category_id=abc'] as $query) {
        $this->getJson("/api/v1/transactions/export?{$query}")->assertUnprocessable();
    }
});
