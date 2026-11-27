<?php

namespace App\Http\Controllers;

use App\Enums\CategoryType;
use App\Http\Requests\ScanReceiptRequest;
use App\Models\Category;
use App\Services\Receipts\ReceiptScanException;
use App\Services\Receipts\ReceiptScanner;
use App\Services\Receipts\ReceiptScannerDisabled;
use Illuminate\Http\JsonResponse;

/**
 * @group Receipts
 */
class ReceiptScanController extends Controller
{
    public function __construct(private ReceiptScanner $scanner) {}

    /**
     * Baca struk (saran isian)
     *
     * Kirim foto struk sebagai multipart/form-data. Hasilnya hanya saran untuk mengisi formulir transaksi:
     * foto tidak disimpan dan tidak ada transaksi yang dibuat. Setiap bidang bisa `null` bila tidak terbaca.
     * Dibatasi per pengguna per jam (429 bila terlampaui).
     *
     * @response 200 {"data": {"total": 45000, "date": "2026-11-20", "merchant": "Indomaret", "category_id": "0196e5a0-0000-7000-8000-000000000000"}}
     * @response 502 {"message": "Struk tidak dapat dibaca. Isi manual.", "code": "scan_failed"}
     * @response 503 {"message": "Pembacaan struk otomatis tidak aktif.", "code": "scan_unavailable"}
     */
    public function __invoke(ScanReceiptRequest $request): JsonResponse
    {
        $file = $request->file('image');

        $categories = Category::query()
            ->where('type', CategoryType::Expense)
            ->with('parent:id,name')
            ->get(['id', 'name', 'parent_id'])
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'parent' => $c->parent?->name,
            ])
            ->values()
            ->all();

        try {
            $suggestion = $this->scanner->scan($file->get(), (string) $file->getMimeType(), $categories);
        } catch (ReceiptScannerDisabled) {
            return response()->json([
                'message' => 'Pembacaan struk otomatis tidak aktif.',
                'code' => 'scan_unavailable',
            ], 503);
        } catch (ReceiptScanException) {
            return response()->json([
                'message' => 'Struk tidak dapat dibaca. Isi manual.',
                'code' => 'scan_failed',
            ], 502);
        }

        return response()->json(['data' => $suggestion->toArray()]);
    }
}
