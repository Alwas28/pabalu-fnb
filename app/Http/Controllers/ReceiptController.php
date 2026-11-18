<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\Receipt\QrCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Struk digital publik — dibuka dari QR code di struk cetak, tanpa login.
 * Diakses lewat token acak (bukan ID berurutan) agar struk tidak bisa ditebak.
 */
class ReceiptController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $order = Order::where('receipt_token', $token)->firstOrFail()->loadReceipt();

        return view('receipts.show', [
            'order' => $order,
            'qr' => QrCode::svg($order->receiptUrl()),
            'autoPrint' => $request->boolean('print'),
            'paperWidth' => in_array($request->integer('width'), [58, 80], true)
                ? $request->integer('width')
                : $order->outlet->receipt_paper_width,
        ]);
    }
}
