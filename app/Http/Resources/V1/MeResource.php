<?php

namespace App\Http\Resources\V1;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->currentRole();
        $business = $this->activeBusiness();

        return [
            'user' => [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
            ],
            'business' => $business ? [
                'id' => $business->id,
                'name' => $business->name,
                'currency' => $business->currency,
                'timezone' => $business->timezone,
            ] : null,
            'role' => $role ? ['code' => $role->value, 'label' => $role->label()] : null,
            'permissions' => $role ? array_map(fn (Permission $p) => $p->value, $role->permissions()) : [],
            'outlets' => OutletResource::collection($this->accessibleOutlets()->where('is_active', true)->values()),
        ];
    }
}
