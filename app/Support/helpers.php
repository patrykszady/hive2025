<?php

use Regulus\TetraText\Facade as TetraText;

if (! function_exists('display_money')) {
    function display_money(float|int|string $value): string
    {
        $formatted = TetraText::money($value);

        return str_ends_with($formatted, '.00')
            ? substr($formatted, 0, -3)
            : $formatted;
    }
}
