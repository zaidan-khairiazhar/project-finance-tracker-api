<?php

namespace App\Services;

use App\Models\Transaction;
use App\Support\MonthRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionCsv
{
    /** Batas baris per ekspor; dapat diubah test agar tidak perlu membuat puluhan ribu baris. */
    public static int $maxRows = 50_000;

    public const HEADER = ['Tanggal', 'Dompet', 'Tipe', 'Kategori', 'Jumlah', 'Catatan'];

    /**
     * Netralkan formula injection di Excel/Sheets: sel teks yang diawali = + - @ tab atau CR
     * diberi awalan petik tunggal supaya dibaca sebagai teks.
     */
    public static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    public function stream(Builder $query, string $filename): StreamedResponse
    {
        if ((clone $query)->count() > self::$maxRows) {
            throw ValidationException::withMessages([
                'filters' => 'Terlalu banyak transaksi untuk diekspor (maksimum '.number_format(self::$maxRows, 0, ',', '.').' baris). Persempit filter.',
            ]);
        }

        $query = $query
            // Dompet/kategori yang sudah dihapus tetap tampil namanya di riwayat.
            ->with(['wallet' => fn ($q) => $q->withTrashed(), 'category' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('occurred_at')->orderByDesc('id');

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 supaya Excel membaca huruf non-ASCII dengan benar
            $this->put($out, self::HEADER);

            foreach ($query->lazy(1000) as $t) {
                $this->put($out, [
                    $t->occurred_at->setTimezone(MonthRange::TIMEZONE)->format('Y-m-d H:i'),
                    self::cell($t->wallet?->name ?? ''),
                    $t->type->value,
                    self::cell($t->category?->name ?? ''),
                    $t->amount,
                    self::cell($t->note ?? ''),
                ]);
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function put($handle, array $row): void
    {
        // escape '' mematuhi RFC 4180 (kutip digandakan, tanpa backslash khusus).
        fputcsv($handle, $row, ',', '"', '');
    }
}
