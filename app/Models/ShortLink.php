<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ShortLink extends Model
{
    protected $fillable = [
        'code',
        'destination',
        'hits',
        'last_visited_at',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'last_visited_at' => 'datetime',
        ];
    }

    /**
     * Find an existing short link for the destination or create a new one.
     */
    public static function forDestination(string $destination): self
    {
        return static::firstOrCreate(
            ['destination' => $destination],
            ['code' => static::generateUniqueCode()],
        );
    }

    /**
     * 6 lowercase-alphanumeric characters is ~31 bits — small enough that,
     * combined with these links never expiring, brute-forcing one was
     * plausible. New links get 10 characters (~52 bits); existing 6-char
     * codes already issued keep resolving (lookup is by the `code` column,
     * not its length).
     */
    protected static function generateUniqueCode(int $length = 10): string
    {
        do {
            $code = Str::lower(Str::random($length));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
