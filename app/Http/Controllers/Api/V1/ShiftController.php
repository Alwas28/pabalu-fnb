<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\Outlet;
use App\Support\Pos\ShiftManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Shift kasir lewat aplikasi POS. Shift wajib dibuka sebelum membuat / melunasi order.
 */
class ShiftController extends Controller
{
    public function __construct(private ShiftManager $shifts) {}

    /** Shift berjalan milik kasir di outlet (data null bila belum dibuka). */
    public function current(Request $request, Outlet $outlet): JsonResponse
    {
        Gate::authorize('usePos', $outlet);

        $shift = CashShift::currentFor($request->user(), $outlet);

        return response()->json(['data' => $shift ? $this->present($shift) : null]);
    }

    public function open(Request $request, Outlet $outlet): JsonResponse
    {
        Gate::authorize('usePos', $outlet);

        $shift = $this->shifts->open($request->user(), $outlet, $request->only('opening_cash', 'opening_note'));

        return response()->json(['data' => $this->present($shift)], 201);
    }

    /** Kas masuk / keluar. */
    public function movement(Request $request, CashShift $shift): JsonResponse
    {
        Gate::authorize('update', $shift);

        $this->shifts->recordMovement($request->user(), $shift, $request->only('type', 'amount', 'reason'));

        return response()->json(['data' => $this->present($shift->refresh())], 201);
    }

    public function close(Request $request, CashShift $shift): JsonResponse
    {
        Gate::authorize('update', $shift);

        $shift = $this->shifts->close($request->user(), $shift, $request->only('actual_cash', 'closing_note'));

        return response()->json(['data' => $this->present($shift)]);
    }

    public function show(CashShift $shift): JsonResponse
    {
        Gate::authorize('view', $shift);

        return response()->json(['data' => $this->present($shift)]);
    }

    /** @return array<string, mixed> */
    private function present(CashShift $shift): array
    {
        $shift->loadMissing(['user', 'movements']);

        return [
            'id' => $shift->id,
            'outlet_id' => $shift->outlet_id,
            'cashier' => ['id' => $shift->user->id, 'name' => $shift->user->name],
            'status' => $shift->status->value,
            'opening_cash' => $shift->opening_cash,
            'opened_at' => $shift->opened_at->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'expected_cash' => $shift->expected_cash,
            'actual_cash' => $shift->actual_cash,
            'cash_difference' => $shift->cash_difference,
            'closing_note' => $shift->closing_note,
            'summary' => $this->shifts->summary($shift),
            'movements' => $shift->movements->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type->value,
                'amount' => $m->amount,
                'reason' => $m->reason,
                'created_at' => $m->created_at->toIso8601String(),
            ])->values(),
        ];
    }
}
