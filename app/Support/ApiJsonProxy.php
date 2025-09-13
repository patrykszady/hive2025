<?php

namespace App\Support;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\DB;
use JsonSerializable;

/**
 * Lightweight proxy around api_json enabling:
 *  $model->api_json->update(['sync_cursors' => ['inbox' => 123]]);
 *  $model->api_json->set('sync_cursors.inbox', 123);
 * Without overwriting unrelated keys.
 */
class ApiJsonProxy implements ArrayAccess, Arrayable, JsonSerializable
{
    public function __construct(
        protected $model,
        protected string $attribute,
        protected array $data
    ) {}

    /**
     * Patch multiple top-level keys (deep merged) while protecting 'folders' unless allowed.
     */
    public function update(array $fragment, bool $allowFolders = false): static
    {
        if (!$allowFolders && array_key_exists('folders', $fragment)) {
            unset($fragment['folders']);
        }
        if (empty($fragment)) {
            return $this;
        }
        $json = json_encode($fragment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $table = $this->model->getTable();
        $keyName = $this->model->getKeyName();
        DB::table($table)
            ->where($keyName, $this->model->getKey())
            ->update([
                $this->attribute => DB::raw("JSON_MERGE_PATCH(COALESCE(`{$this->attribute}`, JSON_OBJECT()), CAST('" . addslashes($json) . "' AS JSON))"),
            ]);

        // Update local cache
        foreach ($fragment as $k => $v) {
            $this->data[$k] = is_array($this->data[$k] ?? null) && is_array($v)
                ? $this->recursiveMerge($this->data[$k], $v)
                : $v;
        }
        // Reflect back onto model attribute for further chained usage
        $this->model->setAttribute($this->attribute, $this->data);
        return $this;
    }

    /** Set a single dot-path value using JSON_SET for atomicity. */
    public function set(string $path, $value, bool $allowFolders = false): static
    {
        if (!$allowFolders && str_starts_with($path, 'folders')) {
            return $this; // protected
        }
        $jsonPath = '$';
        foreach (explode('.', $path) as $segment) {
            $jsonPath .= '.' . $segment;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $table = $this->model->getTable();
        $keyName = $this->model->getKeyName();
        DB::table($table)
            ->where($keyName, $this->model->getKey())
            ->update([
                $this->attribute => DB::raw("JSON_SET(COALESCE(`{$this->attribute}`, JSON_OBJECT()), '{$jsonPath}', CAST('" . addslashes($encoded) . "' AS JSON))"),
            ]);
        // Update in-memory
        $this->dataSet($this->data, $path, $value);
        $this->model->setAttribute($this->attribute, $this->data);
        return $this;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }

    /* ---------- ArrayAccess (read) ----------- */
    public function offsetExists(mixed $offset): bool { return array_key_exists($offset, $this->data); }
    public function offsetGet(mixed $offset): mixed { return $this->data[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Treat top-level assignment like set() (atomic JSON_SET)
        if ($offset === null) { return; }
        $this->set($offset, $value, $offset === 'folders' ? false : true);
    }
    public function offsetUnset(mixed $offset): void
    {
        // Use JSON_REMOVE
        if (! array_key_exists($offset, $this->data)) { return; }
        if ($offset === 'folders') { return; } // protect
        $table = $this->model->getTable();
        $keyName = $this->model->getKeyName();
        DB::table($table)
            ->where($keyName, $this->model->getKey())
            ->update([
                $this->attribute => DB::raw("JSON_REMOVE(COALESCE(`{$this->attribute}`, JSON_OBJECT()), '$.{$offset}')"),
            ]);
        unset($this->data[$offset]);
        $this->model->setAttribute($this->attribute, $this->data);
    }

    /* --------- helpers --------- */
    private function recursiveMerge(array $base, array $frag): array
    {
        foreach ($frag as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = $this->recursiveMerge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private function dataSet(array &$array, string $path, $value): void
    {
        $segments = explode('.', $path);
        $ref =& $array;
        foreach ($segments as $seg) {
            if (! isset($ref[$seg]) || ! is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref =& $ref[$seg];
        }
        $ref = $value;
    }
}
