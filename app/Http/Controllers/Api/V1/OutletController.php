<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OutletResource;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OutletController extends Controller
{
    /** Outlet aktif yang ditugaskan ke kasir (untuk pemilihan outlet saat login). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $outlets = $request->user()->accessibleOutlets()->where('is_active', true)->values();

        return OutletResource::collection($outlets);
    }

    /** Konfigurasi POS satu outlet: layanan, meja, dapur, pajak, pembayaran. */
    public function show(Outlet $outlet): OutletResource
    {
        Gate::authorize('usePos', $outlet);

        return (new OutletResource($outlet->load('business')))->withConfig();
    }
}
