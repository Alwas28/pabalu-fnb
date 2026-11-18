<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Support\Pos\AddOrderItems;
use App\Support\Pos\CancelOrder;
use App\Support\Pos\MergeOrders;
use App\Support\Pos\MoveOrderTable;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\RefundService;
use App\Support\Pos\SettleOrder;
use App\Support\Pos\VoidOrderItem;
use App\Support\Receipt\EscPosKitchenTicket;
use App\Support\Receipt\EscPosReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /** Riwayat transaksi outlet (default: hari ini, zona waktu bisnis), atau open bill dengan status=open. */
    public function index(Request $request, Outlet $outlet): AnonymousResourceCollection
    {
        Gate::authorize('usePos', $outlet);

        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::in(['open'])],
        ]);

        $timezone = $outlet->business->timezone ?: config('app.timezone');
        $day = $request->date('date', 'Y-m-d', $timezone) ?? now($timezone);

        // status=open: semua open bill outlet (tanpa batas tanggal); selain itu riwayat per hari.
        $orders = $outlet->orders()
            ->with(['cashier', 'payments'])
            ->when($request->input('status') === 'open',
                fn ($q) => $q->open(),
                fn ($q) => $q->whereBetween('placed_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()]))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$request->string('search').'%')
                ->orWhere('customer_name', 'like', '%'.$request->string('search').'%')))
            ->latest('placed_at')
            ->latest('id')
            ->paginate(50);

        return OrderResource::collection($orders);
    }

    /**
     * Buat order + pembayaran (split payment didukung), atau open bill dine-in dengan pay_later=true.
     * Membutuhkan shift kasir yang sedang berjalan di outlet.
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

    /**
     * Tiket dapur ESC/POS (tanpa harga) untuk outlet mode cetak tiket.
     * ?batch=N memilih kiriman tertentu (default: kiriman terakhir).
     */
    public function kitchenTicket(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        $request->validate([
            'batch' => ['nullable', 'integer', 'min:1'],
            'paper_width' => ['nullable', 'integer', Rule::in(Outlet::RECEIPT_PAPER_WIDTHS)],
        ]);

        $ticket = new EscPosKitchenTicket($order, $request->integer('batch') ?: null, $request->integer('paper_width') ?: null);

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'batch' => $ticket->batch(),
                'columns' => $ticket->columns(),
                'escpos_base64' => base64_encode($ticket->bytes()),
                'text' => $ticket->text(),
            ],
        ]);
    }

    /** Tambah item ke open bill. */
    public function addItems(Request $request, Order $order, AddOrderItems $addItems): OrderResource
    {
        Gate::authorize('operate', $order);

        return new OrderResource($addItems->handle($request->user(), $order, $request->only('items'))->fresh()->loadReceipt());
    }

    /** Pelunasan open bill (diskon opsional, split payment). */
    public function settle(Request $request, Order $order, SettleOrder $settle): OrderResource
    {
        Gate::authorize('operate', $order);

        $order = $settle->handle($request->user(), $order, $request->only('payments', 'discount_type', 'discount_value', 'discount_reason'));

        return new OrderResource($order->fresh()->loadReceipt());
    }

    /** Hapus / kurangi item open bill (Supervisor ke atas, wajib alasan). */
    public function voidItem(Request $request, Order $order, OrderItem $item, VoidOrderItem $void): OrderResource
    {
        Gate::authorize('cancel', $order);

        return new OrderResource($void->handle($request->user(), $order, $item, $request->only('quantity', 'reason'))->fresh()->loadReceipt());
    }

    /** Gabungkan open bill lain (source_order_id) ke open bill ini. */
    public function merge(Request $request, Order $order, MergeOrders $merge): OrderResource
    {
        Gate::authorize('operate', $order);

        $request->validate(['source_order_id' => ['required', 'integer']]);
        $source = Order::where('outlet_id', $order->outlet_id)->findOrFail($request->integer('source_order_id'));

        return new OrderResource($merge->handle($request->user(), $order, $source)->fresh()->loadReceipt());
    }

    /** Pindah meja open bill. */
    public function moveTable(Request $request, Order $order, MoveOrderTable $move): OrderResource
    {
        Gate::authorize('operate', $order);

        $request->validate(['dining_table_id' => ['required', 'integer']]);

        return new OrderResource($move->handle($request->user(), $order, $request->integer('dining_table_id'))->fresh()->loadReceipt());
    }

    /** Batalkan open bill (Supervisor ke atas, wajib alasan). */
    public function cancel(Request $request, Order $order, CancelOrder $cancel): OrderResource
    {
        Gate::authorize('cancel', $order);

        return new OrderResource($cancel->handle($request->user(), $order, $request->only('reason'))->fresh()->loadReceipt());
    }

    /**
     * Ajukan void / refund pesanan lunas. Butuh persetujuan owner (status "pending"),
     * kecuali diajukan oleh owner sendiri.
     */
    public function requestRefund(Request $request, Order $order, RefundService $refunds): JsonResponse
    {
        Gate::authorize('view', $order);

        $refundRequest = $refunds->request($request->user(), $order, $request->only('type', 'amount', 'method', 'reason'));

        return response()->json(['data' => [
            'id' => $refundRequest->id,
            'type' => $refundRequest->type->value,
            'amount' => $refundRequest->amount,
            'status' => $refundRequest->status->value,
        ]], 201);
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
