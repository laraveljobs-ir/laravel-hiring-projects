<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ProviderCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  Request  $request
     * @return array|Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'providers' =>$this->collection->transform(function($provider){
                return [
                    'id' => $provider->id,
                    'title' => $provider->title,
                    'created_at' => $provider->created_at ? $provider->created_at->format('Y/m/d') : '',
                    'updated_at' => $provider->updated_at ?  $provider->updated_at->format('Y/m/d') : ''
                ];
            }),
            'pagination' => [
                'total' => $this->resource->total(),
                'count' => $this->resource->count(),
                'per_page' => $this->resource->perPage(),
                'current_page' => $this->resource->currentPage(),
                'total_pages' => $this->resource->lastPage()
            ]
        ];
    }
}
