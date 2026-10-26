<?php

namespace App\Support\Windows;

/**
 * The small elevation drawing a window order carries, as inline SVG: a white
 * frame with a black border, blue glass, and the standard opening symbols —
 * a V whose point marks the hinge side, a second V from the bottom on a
 * panel that tilts, an arrow on the lite that slides. Drawn to the unit's
 * real proportions (clamped so a transom or a tall door still reads), from
 * the spec a supplier quote line carries (estimate_line_items.window_spec).
 *
 * Spec shape (every key optional but `type`):
 *   type:   picture | casement | awning | hopper | double_hung | single_hung |
 *           slider | patio_door | french_door | tilt_turn | product
 *   width_in, height_in: numbers
 *   hinge:  left | right          (casement, single-panel tilt_turn)
 *   tilt:   bool                  (single-panel tilt_turn)
 *   active_panel: left | right    (slider, patio_door)
 *   slide_direction: left | right (slider, patio_door)
 *   panels: list<array{hinge: string, tilt: bool}>  (french_door / tilt_turn pairs)
 */
final class WindowDiagram
{
    public const TYPES = ['picture', 'casement', 'awning', 'hopper', 'double_hung', 'single_hung', 'slider', 'patio_door', 'french_door', 'tilt_turn', 'product'];

    private const FRAME = '#000000';

    private const FRAME_FILL = '#ffffff';

    private const GLASS = '#d6e8f5';

    private const LINE = '#1b1f23';

    /** @var list<string> */
    private array $parts = [];

    /** @param  array<string, mixed>  $spec */
    public static function svg(array $spec, int $widthPx = 72, ?string $class = null): string
    {
        return (new self)->render($spec, $widthPx, $class);
    }

    /** The type as the reader names it, or 'product' for anything we cannot draw. */
    public static function normaliseType(?string $type): string
    {
        $type = strtolower(trim((string) $type));
        $aliases = ['fixed' => 'picture', 'pw' => 'picture', 'sliding_door' => 'patio_door', 'sliding_patio_door' => 'patio_door', 'patio' => 'patio_door', 'gliding' => 'slider', 'glider' => 'slider', 'double-hung' => 'double_hung', 'single-hung' => 'single_hung', 'tilt-turn' => 'tilt_turn', 'tilt_and_turn' => 'tilt_turn', 'french' => 'french_door', 'hinged_door' => 'french_door', 'entry_door' => 'french_door'];
        $type = $aliases[$type] ?? $type;

        return in_array($type, self::TYPES, true) ? $type : 'product';
    }

    /** @param  array<string, mixed>  $spec */
    private function render(array $spec, int $widthPx, ?string $class): string
    {
        $this->parts = [];
        $type = self::normaliseType($spec['type'] ?? null);
        $w = (float) ($spec['width_in'] ?? 0) ?: 1.0;
        $h = (float) ($spec['height_in'] ?? 0) ?: 1.0;
        $ratio = max(0.45, min(2.2, $h / $w));
        $box = ['x' => 4.0, 'y' => 4.0, 'w' => 100.0, 'h' => round(100 * $ratio, 1)];
        $viewW = $box['w'] + 8;
        $viewH = $box['h'] + 8;

        match ($type) {
            'picture' => $this->sash($box),
            'casement' => $this->casement($box, (string) ($spec['hinge'] ?? 'left'), (bool) ($spec['tilt'] ?? false)),
            'awning' => $this->sashWithV($box, 'top'),
            'hopper' => $this->sashWithV($box, 'bottom'),
            'double_hung' => $this->hung($box, both: true),
            'single_hung' => $this->hung($box, both: false),
            'slider', 'patio_door' => $this->sliding($box, (string) ($spec['active_panel'] ?? 'right'), (string) ($spec['slide_direction'] ?? 'left'), $type === 'patio_door'),
            'french_door', 'tilt_turn' => $this->panels($box, $spec),
            default => $this->product($box),
        };

        $label = htmlspecialchars(str_replace('_', ' ', $type).' '.self::inches($w).' by '.self::inches($h), ENT_QUOTES);
        $classAttr = $class !== null ? ' class="'.htmlspecialchars($class, ENT_QUOTES).'"' : '';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$viewW.' '.$viewH.'" width="'.$widthPx.'" height="'.round($widthPx * $viewH / $viewW).'" role="img" aria-label="'.$label.'"'.$classAttr.'>'
            .implode('', $this->parts).'</svg>';
    }

    /** A sash: white frame with a black border, the glass inset in blue. @param array{x: float, y: float, w: float, h: float} $b */
    private function sash(array $b): void
    {
        $f = $this->frameWidth($b);
        $this->parts[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="%s" stroke="%s" stroke-width="2"/>', $b['x'], $b['y'], $b['w'], $b['h'], self::FRAME_FILL, self::FRAME);
        $this->parts[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="%s" stroke="%s" stroke-width="1"/>', $b['x'] + $f, $b['y'] + $f, max(2, $b['w'] - 2 * $f), max(2, $b['h'] - 2 * $f), self::GLASS, self::FRAME);
    }

    private function sashWithV(array $b, string $side): void
    {
        $this->sash($b);
        $this->hingeV($b, $side);
    }

    private function casement(array $b, string $hinge, bool $tilt): void
    {
        $this->sash($b);
        $this->hingeV($b, $hinge === 'right' ? 'right' : 'left');
        if ($tilt) {
            $this->hingeV($b, 'bottom');
        }
    }

    /** Two sashes stacked; the moving ones carry a vertical arrow. */
    private function hung(array $b, bool $both): void
    {
        $top = ['x' => $b['x'], 'y' => $b['y'], 'w' => $b['w'], 'h' => $b['h'] / 2];
        $bottom = ['x' => $b['x'], 'y' => $b['y'] + $b['h'] / 2, 'w' => $b['w'], 'h' => $b['h'] / 2];
        $this->sash($top);
        $this->sash($bottom);
        $this->verticalArrow($bottom, up: true);
        if ($both) {
            $this->verticalArrow($top, up: false);
        }
    }

    /** Two lites side by side; the active one carries an arrow in its travel direction, a door a handle. */
    private function sliding(array $b, string $active, string $direction, bool $door): void
    {
        $left = ['x' => $b['x'], 'y' => $b['y'], 'w' => $b['w'] / 2, 'h' => $b['h']];
        $right = ['x' => $b['x'] + $b['w'] / 2, 'y' => $b['y'], 'w' => $b['w'] / 2, 'h' => $b['h']];
        $this->sash($left);
        $this->sash($right);
        $moving = $active === 'left' ? $left : $right;
        $this->arrow($moving, $direction === 'right' ? 'right' : 'left');
        if ($door) {
            $hx = $active === 'left' ? $moving['x'] + $moving['w'] - 9 : $moving['x'] + 6;
            $this->parts[] = sprintf('<rect x="%s" y="%s" width="3" height="14" fill="%s"/>', $hx, $moving['y'] + $moving['h'] / 2 - 7, self::FRAME);
        }
    }

    /** French and tilt-turn pairs: each panel its own hinge V, plus a bottom V where it tilts. @param array<string, mixed> $spec */
    private function panels(array $b, array $spec): void
    {
        $panels = is_array($spec['panels'] ?? null) && $spec['panels'] !== [] ? array_values($spec['panels']) : [['hinge' => $spec['hinge'] ?? 'left', 'tilt' => (bool) ($spec['tilt'] ?? false)]];
        $count = max(1, min(2, count($panels)));
        for ($i = 0; $i < $count; $i++) {
            $panel = ['x' => $b['x'] + $i * $b['w'] / $count, 'y' => $b['y'], 'w' => $b['w'] / $count, 'h' => $b['h']];
            $this->sash($panel);
            $hinge = (string) ($panels[$i]['hinge'] ?? ($i === 0 ? 'left' : 'right'));
            $this->hingeV($panel, $hinge === 'right' ? 'right' : 'left');
            if (! empty($panels[$i]['tilt'])) {
                $this->hingeV($panel, 'bottom');
            }
        }
    }

    /** Anything that is not a window: a dashed box. */
    private function product(array $b): void
    {
        $this->parts[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="none" stroke="#6b7280" stroke-width="2" stroke-dasharray="6 4"/>', $b['x'], $b['y'], $b['w'], $b['h']);
    }

    /** The opening symbol: lines from the two far corners meeting at the middle of the hinge side, drawn over the glass. */
    private function hingeV(array $b, string $side): void
    {
        $f = $this->frameWidth($b);
        $x = $b['x'] + $f;
        $y = $b['y'] + $f;
        $w = $b['w'] - 2 * $f;
        $h = $b['h'] - 2 * $f;
        $points = match ($side) {
            'left' => [[$x + $w, $y], [$x, $y + $h / 2], [$x + $w, $y + $h]],
            'right' => [[$x, $y], [$x + $w, $y + $h / 2], [$x, $y + $h]],
            'top' => [[$x, $y + $h], [$x + $w / 2, $y], [$x + $w, $y + $h]],
            default => [[$x, $y], [$x + $w / 2, $y + $h], [$x + $w, $y]],
        };
        $this->parts[] = '<polyline points="'.implode(' ', array_map(fn ($p) => round($p[0], 1).','.round($p[1], 1), $points)).'" fill="none" stroke="'.self::LINE.'" stroke-width="1.25"/>';
    }

    private function arrow(array $b, string $direction): void
    {
        $f = $this->frameWidth($b);
        $x = $b['x'] + $f;
        $w = $b['w'] - 2 * $f;
        $cy = $b['y'] + $b['h'] / 2;
        $inset = $w * 0.18;
        [$x1, $x2] = $direction === 'left' ? [$x + $w - $inset, $x + $inset] : [$x + $inset, $x + $w - $inset];
        $head = $direction === 'left' ? 6 : -6;
        $this->parts[] = sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="1.5"/>', round($x1, 1), round($cy, 1), round($x2, 1), round($cy, 1), self::LINE);
        $this->parts[] = sprintf('<polyline points="%s,%s %s,%s %s,%s" fill="none" stroke="%s" stroke-width="1.5"/>', round($x2 + $head, 1), round($cy - 5, 1), round($x2, 1), round($cy, 1), round($x2 + $head, 1), round($cy + 5, 1), self::LINE);
    }

    private function verticalArrow(array $b, bool $up): void
    {
        $cx = $b['x'] + $b['w'] / 2;
        [$y1, $y2] = $up ? [$b['y'] + $b['h'] * 0.85, $b['y'] + $b['h'] * 0.25] : [$b['y'] + $b['h'] * 0.15, $b['y'] + $b['h'] * 0.75];
        $head = $up ? 6 : -6;
        $this->parts[] = sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="1.5"/>', round($cx, 1), round($y1, 1), round($cx, 1), round($y2, 1), self::LINE);
        $this->parts[] = sprintf('<polyline points="%s,%s %s,%s %s,%s" fill="none" stroke="%s" stroke-width="1.5"/>', round($cx - 5, 1), round($y2 + $head, 1), round($cx, 1), round($y2, 1), round($cx + 5, 1), round($y2 + $head, 1), self::LINE);
    }

    private function frameWidth(array $b): float
    {
        return max(4.0, min($b['w'], $b['h']) * 0.12);
    }

    /** 59.25 → 59¼, 79.375 → 79⅜, 24 → 24. */
    public static function inches(float $value): string
    {
        $whole = (int) floor($value);
        $fraction = $value - $whole;
        $glyphs = [[0.125, '⅛'], [0.25, '¼'], [0.375, '⅜'], [0.5, '½'], [0.625, '⅝'], [0.75, '¾'], [0.875, '⅞']];
        foreach ($glyphs as [$f, $glyph]) {
            if (abs($fraction - $f) < 0.02) {
                return ($whole > 0 ? $whole : '').$glyph;
            }
        }

        return $fraction < 0.02 ? (string) $whole : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
