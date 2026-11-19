<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MenuItemResource;
use App\Http\Resources\V1\OutletResource;
use App\Models\CashShift;
use App\Models\MenuItem;
use App\Models\Outlet;
use App\Models\PosDevice;
use App\Support\Pos\OfflineSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Mode offline aplikasi kasir Android.
 *
 * Alur: daftarkan perangkat → unduh bundle (menu, harga, pajak, meja) saat online →
 * catat transaksi di perangkat saat offline → kirim antrean ke /sync saat online kembali.
 */
class OfflineController extends Controller
{
    /**
     * Daftarkan perangkat kasir di outlet. Mengirim ulang device_uid yang sama mengembalikan
     * perangkat yang sudah terdaftar (kode tetap).
     */
    public function register(Request $request, Outlet $outlet): JsonResponse
    {
        Gate::authorize('usePos', $outlet);
        $this->ensureOfflineEnabled($outlet);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'device_uid' => ['required', 'string', 'max:100'],
        ]);

        $device = $outlet->posDevices()->where('device_uid', $data['device_uid'])->first();
        abort_if($device?->isRevoked(), 403, 'Perangkat ini sudah dicabut oleh owner.');

        $device ??= $outlet->posDevices()->create([
            'business_id' => $outlet->business_id,
            'code' => PosDevice::nextCode($outlet),
            'name' => $data['name'],
            'device_uid' => $data['device_uid'],
            'registered_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->deviceData($device)], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Semua data yang dibutuhkan perangkat untuk berjualan tanpa internet.
     * Unduh ulang setiap kali online agar harga & menu selalu terbaru.
     */
    public function bundle(Request $request, PosDevice $device): JsonResponse
    {
        $outlet = $this->authorizeDevice($device);
        $user = $request->user();

        $items = MenuItem::query()
            ->where('business_id', $outlet->business_id)
            ->where('is_active', true)
            ->with(['variants', 'modifierGroups.modifiers', 'outlets' => fn ($q) => $q->whereKey($outlet->id)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $shift = CashShift::currentFor($user, $outlet);

        return response()->json([
            'data' => [
                'server_time' => now()->toIso8601String(),
                'device' => $this->deviceData($device),
                'offline' => [
                    'max_hours' => $outlet->offline_max_hours,
                    // Saat offline hanya pesanan yang langsung dibayar; bayar nanti & gateway butuh internet.
                    'pay_later_allowed' => false,
                    'number_format' => $outlet->code.'-YYMMDD-'.$device->code.'-0001',
                    'last_number' => $device->orders()->latest('id')->value('number'),
                ],
                'cashier' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'can_discount' => $user->hasPermission(Permission::ApproveTransactions),
                ],
                'current_shift' => $shift ? ['id' => $shift->id, 'opening_cash' => $shift->opening_cash, 'opened_at' => $shift->opened_at->toIso8601String()] : null,
                'outlet' => (new OutletResource($outlet->load('business')))->withConfig(),
                'categories' => $outlet->business->menuCategories()->where('is_active', true)->get(['id', 'name', 'sort_order']),
                'items' => $items->map(fn (MenuItem $item) => new MenuItemResource($item, $outlet)),
                'tables' => $outlet->usesTables()
                    ? $outlet->diningTables()->where('is_active', true)->get(['id', 'area', 'name', 'capacity'])
                    : [],
            ],
        ]);
    }

    /**
     * Kirim antrean transaksi offline (urut sesuai kejadian, maks. 100 per kiriman).
     * Setiap operasi dijawab: created / duplicate (sudah pernah diterima) / rejected (dengan errors).
     * Operasi yang created/duplicate boleh dihapus dari antrean perangkat.
     */
    public function sync(Request $request, PosDevice $device, OfflineSync $sync): JsonResponse
    {
        $this->authorizeDevice($device);

        $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:'.OfflineSync::MAX_OPERATIONS],
            'operations.*' => ['array'],
        ]);

        return response()->json(['data' => $sync->handle($request->user(), $device, $request->input('operations'))]);
    }

    private function authorizeDevice(PosDevice $device): Outlet
    {
        $outlet = $device->outlet;

        Gate::authorize('usePos', $outlet);
        abort_if($device->isRevoked(), 403, 'Perangkat ini sudah dicabut oleh owner.');
        $this->ensureOfflineEnabled($outlet);

        return $outlet;
    }

    private function ensureOfflineEnabled(Outlet $outlet): void
    {
        abort_unless($outlet->offline_enabled, 403, 'Mode offline belum diaktifkan untuk outlet ini.');
    }

    /** @return array<string, mixed> */
    private function deviceData(PosDevice $device): array
    {
        return [
            'id' => $device->id,
            'code' => $device->code,
            'name' => $device->name,
            'outlet_id' => $device->outlet_id,
            'last_synced_at' => $device->last_synced_at?->toIso8601String(),
        ];
    }
}
