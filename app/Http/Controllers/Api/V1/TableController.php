<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Outlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TableController extends Controller
{
    /** Denah meja aktif outlet beserta status & open bill-nya. */
    public function index(Outlet $outlet): JsonResponse
    {
        Gate::authorize('usePos', $outlet);

        $tables = $outlet->diningTables()
            ->where('is_active', true)
            ->with(['openOrders' => fn ($q) => $q->select('id', 'dining_table_id', 'number', 'total')])
            ->get();

        return response()->json([
            'data' => [
                'tables_enabled' => $outlet->usesTables(),
                'status_tracking' => $outlet->tracksTableStatus(),
                'tables' => $tables->map(fn (DiningTable $t) => [
                    'id' => $t->id,
                    'area' => $t->area,
                    'name' => $t->name,
                    'capacity' => $t->capacity,
                    'is_occupied' => $outlet->tracksTableStatus() ? $t->isOccupied() : null,
                    'open_orders' => $t->openOrders->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'total' => $o->total])->values(),
                ])->values(),
            ],
        ]);
    }

    /** Kosongkan meja manual (tamu sudah pergi). */
    public function clear(DiningTable $table): JsonResponse
    {
        Gate::authorize('usePos', $table->outlet);

        if ($table->openOrders()->exists()) {
            throw ValidationException::withMessages(['table' => 'Meja masih punya pesanan yang belum dibayar.']);
        }

        $table->forceFill(['occupied_at' => null])->save();

        return response()->json(['data' => ['id' => $table->id, 'is_occupied' => false]]);
    }
}
