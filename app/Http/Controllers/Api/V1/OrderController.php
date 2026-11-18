<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use App\Models\Outlet;
use App\Support\Pos\PlaceOrder;
use App\Support\Receipt\EscPosReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /** Riwayat transaksi outlet (default: hari ini, zona waktu bisnis). */
    public function index(Request $request, Outlet $outlet): AnonymousResourceCollection
    {
        Gate::authorize('usePos', $outlet);

        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:64'],
        ]);

        $timezone = $outlet->business->timezone ?: config('app.timezone');
        $day = $request->date('date', 'Y-m-d', $timezone) ?? now($timezone);

        $orders = $outlet->orders()
            ->with(['cashier', 'payments'])
            ->whereBetween('placed_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$request->string('search').'%')
                ->orWhere('customer_name', 'like', '%'.$request->string('search').'%')))
            ->latest('placed_at')
            ->latest('id')
            ->paginate(50);

        return OrderResource::collection($orders);
    }

    /**
     * Buat order + pembayaran (split payment didukung).
     * Kirim client_reference (UUID dari perangkat) agar kiriman ulang tidak membuat order ganda:
     * order yang sudah ada dikembalikan dengan status 200.
     */
    public function store(Request $request, Outlet $outlet, PlaceOrder $placeOrder): JsonResponse
    {
        Gate::authorize('usePos', $outlet);

        $order = $placeOrder->handle($request->user(), $outlet, $request->all());

        abort_unless($order->outlet_id === $outlet->id, 409, 'client_reference sudah dipakai untuk order di outlet lain.');

        return (new OrderResource($order->loadReceipt()))
            ->response()
            ->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource($order->loadReceipt());
    }

    /**
     * Struk untuk dicetak aplikasi kasir: byte ESC/POS (base64), pratinjau teks, dan URL struk web (QR).
     */
    public function receipt(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        $request->validate(['paper_width' => ['nullable', 'integer', Rule::in(Outlet::RECEIPT_PAPER_WIDTHS)]]);

        $receipt = new EscPosReceipt($order, $request->integer('paper_width') ?: null);

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'number' => $order->number,
                'paper_width' => $receipt->columns() === 48 ? 80 : 58,
                'columns' => $receipt->columns(),
                'receipt_url' => $order->receiptUrl(),
                'escpos_base64' => base64_encode($receipt->bytes()),
                'text' => $receipt->text(),
            ],
        ]);
    }
}
