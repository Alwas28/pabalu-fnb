<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Order;
use App\Support\Receipt\EscPosKitchenTicket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Tiket dapur untuk dicetak dari browser (printer thermal yang terpasang di perangkat kasir).
 */
class KitchenTicketController extends Controller
{
    public function show(Request $request, Order $order): View
    {
        $user = $request->user();
        abort_unless(
            $user->canAccessOutlet($order->outlet)
            && ($user->hasPermission(Permission::ViewOrders) || $user->hasPermission(Permission::ViewKitchen)),
            403,
        );

        $order->loadMissing(['outlet.business', 'items.modifiers']);
        $batch = $request->integer('batch') ?: (int) $order->items->max('kitchen_batch');
        $items = EscPosKitchenTicket::itemsFor($order, $batch);
        abort_if($items->isEmpty(), 404, 'Pesanan ini tidak berisi item yang perlu diproses dapur.');

        return view('receipts.kitchen-ticket', [
            'order' => $order,
            'batch' => $batch,
            'items' => $items,
            'paperWidth' => $order->outlet->receipt_paper_width,
            'autoPrint' => $request->boolean('print', true),
        ]);
    }
}
