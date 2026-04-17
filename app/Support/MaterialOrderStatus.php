<?php

namespace App\Support;

class MaterialOrderStatus
{
    /**
     * Normalize a raw material-order status string to a canonical form.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = strtolower(trim($raw));

        return match (true) {
            in_array($raw, ['back ord', 'back order', 'bo', 'backorder', 'b/o'], true) => 'back order',
            str_starts_with($raw, 'received (was') => 'available',
            str_starts_with($raw, 'availabl') || $raw === 'available' => 'available',
            in_array($raw, ['open', 'open item', 'open line'], true) => 'open',
            str_starts_with($raw, 'received') || in_array($raw, ['recv', 'rec', 'delivered'], true) => 'received',
            str_starts_with($raw, 'transfer arrived') || str_starts_with($raw, 'transfer') => 'received',
            in_array($raw, ['shipped', 'ship'], true) => 'shipped',
            in_array($raw, ['partial', 'partially shipped'], true) => 'partial',
            in_array($raw, ['cancelled', 'cancel', 'canceled'], true) => 'cancelled',
            default => $raw,
        };
    }

    /**
     * Whether the normalized status represents a "pending" (unresolved) state.
     */
    public static function isPending(?string $normalizedStatus): bool
    {
        return in_array($normalizedStatus, ['back order', 'open', 'partial', null], true);
    }

    /**
     * Whether the normalized status represents a "resolved" state.
     */
    public static function isResolved(?string $normalizedStatus): bool
    {
        return in_array($normalizedStatus, ['available', 'received', 'shipped'], true);
    }
}
