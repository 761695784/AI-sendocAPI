<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TerritoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code'   => $this->code,
            'name'   => $this->name,
            'level'  => $this->level,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'code' => $this->parent->code,
                'name' => $this->parent->name,
            ] : null),
        ];
    }
}
