<?php

namespace App\Http\Controllers;

use App\Support\Reports\Excel\FinanceWorkbook;
use App\Support\Reports\Excel\StockWorkbook;
use App\Support\Reports\Period;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh laporan Excel (.xlsx). Hak akses dijaga di route: keuangan = finance.view, stok = inventory.manage.
 * Outlet dibatasi pada outlet yang boleh diakses pengguna; tanpa ?outlet = semua outlet tersebut.
 */
class ReportExportController extends Controller
{
    private const MAX_DAYS = 366;

    public function finance(Request $request): StreamedResponse
    {
        [$period, $outletIds, $label, $allOutlets] = $this->scope($request);
        $business = $request->user()->activeBusiness();

        return (new FinanceWorkbook($business->name, $business->id, $outletIds, $label, $period, includeShared: $allOutlets))->download();
    }

    public function stock(Request $request): StreamedResponse
    {
        [$period, $outletIds, $label] = $this->scope($request);

        return (new StockWorkbook($request->user()->activeBusiness()->name, $outletIds, $label, $period))->download();
    }

    /**
     * @return array{0: Period, 1: list<int>, 2: string, 3: bool}
     *
     * @throws ValidationException
     */
    private function scope(Request $request): array
    {
        $user = $request->user();
        $outlets = $user->accessibleOutlets();

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'outlet' => ['nullable', 'integer', Rule::in($outlets->pluck('id')->all())],
        ], [], ['from' => 'tanggal awal', 'to' => 'tanggal akhir', 'outlet' => 'outlet']);

        $period = Period::make($data['from'] ?? null, $data['to'] ?? null, $user->activeBusiness()->timezone);
        if ($period->from->diffInDays($period->to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Rentang laporan maksimal '.self::MAX_DAYS.' hari.']);
        }

        if (! empty($data['outlet'])) {
            $outlet = $outlets->firstWhere('id', (int) $data['outlet']);

            return [$period, [$outlet->id], $outlet->name, false];
        }

        return [$period, $outlets->pluck('id')->all(), 'Semua outlet', true];
    }
}
