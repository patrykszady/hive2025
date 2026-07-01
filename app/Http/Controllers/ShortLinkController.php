<?php

namespace App\Http\Controllers;

use App\Models\ShortLink;
use Illuminate\Http\RedirectResponse;

class ShortLinkController extends Controller
{
    public function __invoke(string $code): RedirectResponse
    {
        $link = ShortLink::where('code', $code)->firstOrFail();

        $link->forceFill([
            'hits' => $link->hits + 1,
            'last_visited_at' => now(),
        ])->save();

        return redirect()->away($link->destination);
    }
}
