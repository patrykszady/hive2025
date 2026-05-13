<?php

namespace App\Enums;

enum LienWaiverType: string
{
    case ConditionalProgress = 'conditional_progress';
    case UnconditionalProgress = 'unconditional_progress';
    case ConditionalFinal = 'conditional_final';
    case UnconditionalFinal = 'unconditional_final';

    public function label(): string
    {
        return match ($this) {
            self::ConditionalProgress => 'Conditional Waiver and Release on Progress Payment',
            self::UnconditionalProgress => 'Unconditional Waiver and Release on Progress Payment',
            self::ConditionalFinal => 'Conditional Waiver and Release on Final Payment',
            self::UnconditionalFinal => 'Unconditional Waiver and Release on Final Payment',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::ConditionalProgress => 'Conditional Progress',
            self::UnconditionalProgress => 'Unconditional Progress',
            self::ConditionalFinal => 'Conditional Final',
            self::UnconditionalFinal => 'Unconditional Final',
        };
    }

    public function isConditional(): bool
    {
        return $this === self::ConditionalProgress || $this === self::ConditionalFinal;
    }

    public function isFinal(): bool
    {
        return $this === self::ConditionalFinal || $this === self::UnconditionalFinal;
    }
}
