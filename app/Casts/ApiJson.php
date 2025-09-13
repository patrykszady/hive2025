<?php

namespace App\Casts;

use App\Support\ApiJsonProxy;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class ApiJson implements CastsAttributes
{
    /**
     * Cast the given value.
     */
    public function get($model, string $key, $value, array $attributes): ApiJsonProxy
    {
        $decoded = [];
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true) ?: [];
        } elseif (is_array($value)) {
            $decoded = $value; // when model already mutated in memory
        }

        // Provide stable defaults
        $normalized = [
            'folders' => $decoded['folders'] ?? [],
            'sync_cursors' => $decoded['sync_cursors'] ?? [],
            'failures' => $decoded['failures'] ?? [],
        ] + $decoded;

        return new ApiJsonProxy($model, $key, $normalized);
    }

    /**
     * Prepare the given value for storage (when assigning $model->api_json = [...]).
     */
    public function set($model, string $key, $value, array $attributes): array
    {
        if ($value instanceof ApiJsonProxy) {
            $value = $value->toArray();
        }
        if (! is_array($value)) {
            $value = [];
        }
        return [$key => json_encode($value, JSON_UNESCAPED_UNICODE)];
    }
}
