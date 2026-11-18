<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MenuItemResource;
use App\Models\MenuItem;
use App\Models\Outlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MenuController extends Controller
{
    /**
     * Katalog kasir untuk satu outlet: kategori aktif & menu aktif dengan harga outlet.
     * Menu yang habis tetap dikirim dengan is_available = false agar bisa ditampilkan redup.
     */
    public function index(Outlet $outlet): JsonResponse
    {
        Gate::authorize('usePos', $outlet);

        $categories = $outlet->business->menuCategories()->where('is_active', true)->get(['id', 'name', 'sort_order']);

        $items = MenuItem::query()
            ->where('business_id', $outlet->business_id)
            ->where('is_active', true)
            ->with(['variants', 'modifierGroups.modifiers', 'outlets' => fn ($q) => $q->whereKey($outlet->id)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'categories' => $categories,
                'items' => $items->map(fn (MenuItem $item) => new MenuItemResource($item, $outlet)),
            ],
        ]);
    }
}
