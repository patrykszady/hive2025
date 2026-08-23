<?php

namespace App\Support;

/**
 * Turns marked-up blanks in a Chromium-rendered PDF into real, fillable
 * AcroForm text fields.
 *
 * Chromium's print-to-PDF flattens HTML <input> elements into static graphics —
 * there is no way to make it emit form fields directly. But it DOES preserve
 * <a href> elements as /Link annotations, complete with a /Rect in final
 * print-layout coordinates. So the document marks each blank as
 *
 *     <a href="hivefield:NAME">…</a>
 *
 * and this class rewrites those links into /Widget text fields in place. The
 * coordinates therefore come from the real rendered layout — pagination,
 * wrapping and content length are all already accounted for, which fixed
 * offsets could never survive.
 *
 * Written as a PDF incremental update: the original bytes are untouched and a
 * new generation of the catalog, the affected pages, and the new field objects
 * is appended. Chromium emits classic xref tables (no object streams), which is
 * what makes appending viable.
 */
class PdfAcroFormOverlay
{
    /** Marker scheme the document uses to tag a fillable blank. */
    public const SCHEME = 'hivefield:';

    /**
     * @param  array<string, array{multiline?: bool, size?: float, align?: int, maxlen?: int}>  $options
     *         Per-field overrides, keyed by the name after the scheme.
     */
    public static function apply(string $pdf, array $options = []): string
    {
        // Object streams would need inflating before any of this works; Chromium
        // does not use them, but a PDF from anywhere else might.
        if (str_contains($pdf, '/ObjStm')) {
            return $pdf;
        }

        $fields = self::collectFields($pdf);

        if ($fields === []) {
            return $pdf;
        }

        return self::appendUpdate($pdf, $fields, $options);
    }

    /**
     * Locate every hivefield link: its name, rectangle and owning page object.
     *
     * @return array<int, array{obj:int, name:string, rect:string, page:int}>
     */
    protected static function collectFields(string $pdf): array
    {
        $pages = self::pageObjects($pdf);
        $fields = [];

        foreach (self::objects($pdf) as $num => $body) {
            if (! str_contains($body, '/Subtype') || ! str_contains($body, '/Link')) {
                continue;
            }

            if (! preg_match('~/URI\s*\((' . preg_quote(self::SCHEME, '~') . '[^)]*)\)~', $body, $uri)) {
                continue;
            }

            if (! preg_match('~/Rect\s*\[([^\]]*)\]~', $body, $rect)) {
                continue;
            }

            $page = null;
            foreach ($pages as $pageNum => $annots) {
                if (in_array($num, $annots, true)) {
                    $page = $pageNum;
                    break;
                }
            }

            if ($page === null) {
                continue;
            }

            $fields[] = [
                'obj' => $num,
                'name' => substr($uri[1], strlen(self::SCHEME)),
                'rect' => trim($rect[1]),
                'page' => $page,
            ];
        }

        return $fields;
    }

    /**
     * Page object number => the annotation object numbers it references.
     *
     * @return array<int, array<int, int>>
     */
    protected static function pageObjects(string $pdf): array
    {
        $pages = [];

        foreach (self::objects($pdf) as $num => $body) {
            if (! preg_match('~/Type\s*/Page[^s]~', $body)) {
                continue;
            }

            $annots = [];
            if (preg_match('~/Annots\s*\[([^\]]*)\]~', $body, $m)) {
                preg_match_all('~(\d+)\s+\d+\s+R~', $m[1], $refs);
                $annots = array_map('intval', $refs[1]);
            }

            $pages[$num] = $annots;
        }

        return $pages;
    }

    /**
     * Every "N 0 obj … endobj" body, keyed by object number.
     *
     * @return array<int, string>
     */
    protected static function objects(string $pdf): array
    {
        preg_match_all('~(\d+)\s+(\d+)\s+obj(.*?)endobj~s', $pdf, $m, PREG_SET_ORDER);

        $objects = [];
        foreach ($m as $match) {
            // Later generations win, matching how a reader resolves them.
            $objects[(int) $match[1]] = $match[3];
        }

        return $objects;
    }

    protected static function appendUpdate(string $pdf, array $fields, array $options): string
    {
        $objects = self::objects($pdf);
        $maxObj = max(array_keys($objects));
        $rootNum = self::rootObjectNumber($pdf);

        if ($rootNum === null) {
            return $pdf;
        }

        $next = $maxObj + 1;
        $fontNum = $next++;
        $acroNum = $next++;

        $updates = [];          // object number => full body to append
        $fieldRefs = [];
        $widgetsByPage = [];

        foreach ($fields as $f) {
            $opt = $options[$f['name']] ?? [];
            $widgetNum = $next++;
            $fieldRefs[] = "{$widgetNum} 0 R";
            $widgetsByPage[$f['page']][] = $widgetNum;

            // Merged field/widget dictionary — one object is both the form field
            // and its on-page annotation, which is what a single-widget field is
            // meant to look like.
            $flags = 0;
            if (! empty($opt['multiline'])) {
                $flags |= 1 << 12;
            }

            $size = $opt['size'] ?? 9;
            $align = $opt['align'] ?? 0;
            $maxLen = isset($opt['maxlen']) ? ' /MaxLen ' . (int) $opt['maxlen'] : '';
            $name = self::escapeString($f['name']);

            $updates[$widgetNum] = "<< /Type /Annot /Subtype /Widget /FT /Tx"
                . " /T ({$name}) /Rect [{$f['rect']}]"
                . " /F 4 /Ff {$flags} /Q {$align}{$maxLen}"
                . " /DA (/Helv {$size} Tf 0 g)"
                . " /P {$f['page']} 0 R >>";
        }

        // Rewrite each touched page: drop the links we replaced, add the widgets.
        $pages = self::pageObjects($pdf);
        $replacedByPage = [];
        foreach ($fields as $f) {
            $replacedByPage[$f['page']][] = $f['obj'];
        }

        foreach ($widgetsByPage as $pageNum => $widgets) {
            $keep = array_values(array_diff($pages[$pageNum] ?? [], $replacedByPage[$pageNum] ?? []));
            $refs = array_map(fn ($n) => "{$n} 0 R", array_merge($keep, $widgets));
            $body = $objects[$pageNum];

            $annots = '/Annots [' . implode(' ', $refs) . ']';
            $body = preg_match('~/Annots\s*\[[^\]]*\]~', $body)
                ? preg_replace('~/Annots\s*\[[^\]]*\]~', $annots, $body, 1)
                : preg_replace('~/Type\s*/Page~', '/Type /Page ' . $annots, $body, 1);

            $updates[$pageNum] = trim($body);
        }

        // Helvetica for field text, and the AcroForm dictionary itself.
        $updates[$fontNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $updates[$acroNum] = '<< /Fields [' . implode(' ', $fieldRefs) . ']'
            . ' /DA (/Helv 9 Tf 0 g) /DR << /Font << /Helv ' . $fontNum . ' 0 R >> >>'
            // Let the reader build field appearances. Without this an empty field
            // renders blank in some viewers until it is focused.
            . ' /NeedAppearances true >>';

        $rootBody = trim($objects[$rootNum]);
        $rootBody = preg_replace('~/AcroForm\s+\d+\s+\d+\s+R~', '', $rootBody);
        $rootBody = preg_replace('~>>\s*$~', '/AcroForm ' . $acroNum . ' 0 R >>', $rootBody, 1);
        $updates[$rootNum] = $rootBody;

        // ── Append the incremental update ───────────────────────────────────
        $out = $pdf;
        if (! str_ends_with($out, "\n")) {
            $out .= "\n";
        }

        $offsets = [];
        ksort($updates);
        foreach ($updates as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $startxref = strlen($out);
        $out .= "xref\n";
        foreach (self::contiguousRuns(array_keys($offsets)) as $run) {
            $out .= $run[0] . ' ' . count($run) . "\n";
            foreach ($run as $num) {
                $out .= sprintf("%010d %05d n \n", $offsets[$num], 0);
            }
        }

        $prev = self::lastStartXref($pdf);
        $size = max(array_keys($updates)) + 1;
        $idPart = preg_match('~/ID\s*\[([^\]]*)\]~s', $pdf, $idm) ? ' /ID [' . trim($idm[1]) . ']' : '';

        $out .= "trailer\n<< /Size {$size} /Root {$rootNum} 0 R /Prev {$prev}{$idPart} >>\n";
        $out .= "startxref\n{$startxref}\n%%EOF\n";

        return $out;
    }

    protected static function rootObjectNumber(string $pdf): ?int
    {
        if (preg_match_all('~/Root\s+(\d+)\s+\d+\s+R~', $pdf, $m)) {
            return (int) end($m[1]);
        }

        foreach (self::objects($pdf) as $num => $body) {
            if (str_contains($body, '/Type') && str_contains($body, '/Catalog')) {
                return $num;
            }
        }

        return null;
    }

    protected static function lastStartXref(string $pdf): int
    {
        return preg_match_all('~startxref\s+(\d+)~', $pdf, $m)
            ? (int) end($m[1])
            : 0;
    }

    /** @return array<int, array<int, int>> */
    protected static function contiguousRuns(array $numbers): array
    {
        sort($numbers);
        $runs = [];
        foreach ($numbers as $n) {
            if ($runs !== [] && $n === end($runs[count($runs) - 1]) + 1) {
                $runs[count($runs) - 1][] = $n;
            } else {
                $runs[] = [$n];
            }
        }

        return $runs;
    }

    protected static function escapeString(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
