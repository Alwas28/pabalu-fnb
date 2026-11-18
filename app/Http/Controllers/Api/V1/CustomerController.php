<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Pencarian & penambahan cepat pelanggan dari aplikasi kasir. */
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => ['required', 'string', 'min:2', 'max:64']]);

        $customers = Customer::applySearch(Customer::where('business_id', $request->user()->activeBusiness()->id), $request->string('search'))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone', 'notes']);

        return response()->json(['data' => $customers]);
    }

    public function store(Request $request): JsonResponse
    {
        $businessId = $request->user()->activeBusiness()->id;
        $input = $request->only('name', 'phone', 'notes');
        $input['phone'] = Customer::normalizePhone($input['phone'] ?? null);

        $data = validator($input, Arr::only(Customer::rules($businessId), ['name', 'phone', 'notes']))->validate();
        $customer = $request->user()->activeBusiness()->customers()->create($data);

        return response()->json(['data' => $customer->only('id', 'name', 'phone', 'notes')], 201);
    }
}
