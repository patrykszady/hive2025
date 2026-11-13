<?php

namespace App\Services\EstimateAI;

/**
 * Reads a whole-estimate draft while it is still arriving:
 *
 *   {"reasoning": "...", "sections": [{"key": "S1", "name": "Kitchen", "scope": "...", "line_items": [{...}, ...]}, ...]}
 *
 * Feed it the text as it streams and it hands back what that text finished,
 * in order: a section opening (as soon as its line items start, with the
 * key, name and scope read so far), each line item the moment its closing
 * brace lands, and the section closing with its final header. The estimator
 * therefore sees real rows, under the right section, while the model is
 * still writing the rest.
 *
 * Only the top-level "sections" array is watched; braces inside strings
 * never count. A section whose header arrives after its items (the schema
 * asks for the header first, so this should not happen) is still handed
 * over whole when it closes.
 */
final class SectionStreamParser
{
    private string $buffer = '';

    private int $cursor = 0;

    private bool $inString = false;

    private bool $escaped = false;

    private int $stringStart = 0;

    private int $depth = 0;

    private ?string $rootKey = null;

    private bool $rootAwaitingValue = false;

    private ?int $sectionsDepth = null;

    private ?int $sectionStart = null;

    private int $sectionIndex = -1;

    /** @var array<string, string> */
    private array $header = [];

    private ?string $sectionKey = null;

    private bool $sectionAwaitingValue = false;

    private ?int $itemsDepth = null;

    private ?int $itemStart = null;

    private bool $announced = false;

    /** The line items opened before the section said which one it is: held back until it closes. */
    private bool $deferred = false;

    private int $itemsEmitted = 0;

    private bool $finished = false;

    /**
     * @return list<array{event: 'section'|'item'|'section_end', index: int, key?: ?string, name?: ?string, scope?: ?string, item?: array<string, mixed>}>
     */
    public function push(string $chunk): array
    {
        if ($this->finished || $chunk === '') {
            return [];
        }

        $this->buffer .= $chunk;
        $events = [];
        $length = strlen($this->buffer);

        for (; $this->cursor < $length; $this->cursor++) {
            $char = $this->buffer[$this->cursor];

            if ($this->inString) {
                if ($this->escaped) {
                    $this->escaped = false;
                } elseif ($char === '\\') {
                    $this->escaped = true;
                } elseif ($char === '"') {
                    $this->inString = false;
                    $this->stringClosed();
                }

                continue;
            }

            switch ($char) {
                case '"':
                    $this->inString = true;
                    $this->stringStart = $this->cursor;
                    break;

                case ':':
                    if ($this->atRoot()) {
                        $this->rootAwaitingValue = true;
                    } elseif ($this->atSection()) {
                        $this->sectionAwaitingValue = true;
                    }
                    break;

                case ',':
                    if ($this->atRoot()) {
                        $this->rootAwaitingValue = false;
                    } elseif ($this->atSection()) {
                        $this->sectionAwaitingValue = false;
                    }
                    break;

                case '[':
                    $this->depth++;
                    if ($this->sectionsDepth === null && $this->depth === 2 && $this->rootAwaitingValue && $this->rootKey === 'sections') {
                        $this->sectionsDepth = $this->depth;
                    } elseif ($this->sectionStart !== null && $this->itemsDepth === null && $this->depth === $this->sectionsDepth + 2
                        && $this->sectionAwaitingValue && $this->sectionKey === 'line_items') {
                        $this->itemsDepth = $this->depth;
                        if (isset($this->header['key']) || isset($this->header['name'])) {
                            $events[] = $this->announce();
                        } else {
                            $this->deferred = true;
                        }
                    }
                    break;

                case ']':
                    if ($this->itemsDepth !== null && $this->depth === $this->itemsDepth) {
                        $this->itemsDepth = null;
                        $this->sectionAwaitingValue = false;
                    } elseif ($this->sectionsDepth !== null && $this->depth === $this->sectionsDepth && $this->sectionStart === null) {
                        $this->finished = true;

                        return $events;
                    }
                    $this->depth--;
                    break;

                case '{':
                    $this->depth++;
                    if ($this->sectionsDepth !== null && $this->sectionStart === null && $this->depth === $this->sectionsDepth + 1) {
                        $this->sectionStart = $this->cursor;
                        $this->sectionIndex++;
                        $this->header = [];
                        $this->sectionKey = null;
                        $this->sectionAwaitingValue = false;
                        $this->announced = false;
                        $this->deferred = false;
                        $this->itemsEmitted = 0;
                    } elseif ($this->itemsDepth !== null && $this->depth === $this->itemsDepth + 1) {
                        $this->itemStart = $this->cursor;
                    }
                    break;

                case '}':
                    if ($this->itemsDepth !== null && $this->depth === $this->itemsDepth + 1 && $this->itemStart !== null) {
                        if (! $this->deferred) {
                            $item = json_decode(substr($this->buffer, $this->itemStart, $this->cursor - $this->itemStart + 1), true);
                            if (is_array($item)) {
                                $events[] = ['event' => 'item', 'index' => $this->sectionIndex, 'item' => $item];
                            }
                            $this->itemsEmitted++;
                        }
                        $this->itemStart = null;
                    } elseif ($this->sectionStart !== null && $this->sectionsDepth !== null && $this->depth === $this->sectionsDepth + 1) {
                        array_push($events, ...$this->closeSection());
                    }
                    $this->depth--;
                    break;
            }
        }

        return $events;
    }

    public function finished(): bool
    {
        return $this->finished;
    }

    private function atRoot(): bool
    {
        return $this->sectionsDepth === null && $this->depth === 1;
    }

    /** Directly inside the open section object, outside its line items. */
    private function atSection(): bool
    {
        return $this->sectionStart !== null && $this->itemsDepth === null && $this->depth === $this->sectionsDepth + 1;
    }

    private function stringClosed(): void
    {
        if (! $this->atRoot() && ! $this->atSection()) {
            return;
        }

        $value = json_decode(substr($this->buffer, $this->stringStart, $this->cursor - $this->stringStart + 1));
        $value = is_string($value) ? $value : '';

        if ($this->atRoot()) {
            if (! $this->rootAwaitingValue) {
                $this->rootKey = $value;
            }

            return;
        }

        if (! $this->sectionAwaitingValue) {
            $this->sectionKey = $value;
        } elseif ($this->sectionKey !== null) {
            $this->header[$this->sectionKey] = $value;
        }
    }

    /**
     * @return array{event: 'section', index: int, key: ?string, name: ?string, scope: ?string}
     */
    private function announce(): array
    {
        $this->announced = true;

        return ['event' => 'section', 'index' => $this->sectionIndex] + $this->headerOf($this->header);
    }

    /**
     * The section's closing brace: whatever its opening or its items had not
     * been handed over yet goes now, then the final header.
     *
     * @return list<array<string, mixed>>
     */
    private function closeSection(): array
    {
        $events = [];
        $section = json_decode(substr($this->buffer, (int) $this->sectionStart, $this->cursor - (int) $this->sectionStart + 1), true);
        $section = is_array($section) ? $section : [];
        $header = $this->headerOf($section + $this->header);

        if (! $this->announced) {
            $events[] = ['event' => 'section', 'index' => $this->sectionIndex] + $header;
        }

        $items = is_array($section['line_items'] ?? null) ? array_values($section['line_items']) : [];
        foreach (array_slice($items, $this->itemsEmitted) as $item) {
            if (is_array($item)) {
                $events[] = ['event' => 'item', 'index' => $this->sectionIndex, 'item' => $item];
            }
        }

        $events[] = ['event' => 'section_end', 'index' => $this->sectionIndex] + $header;

        $this->sectionStart = null;
        $this->itemsDepth = null;
        $this->itemStart = null;

        return $events;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{key: ?string, name: ?string, scope: ?string}
     */
    private function headerOf(array $source): array
    {
        $text = fn (string $field) => is_string($source[$field] ?? null) && trim($source[$field]) !== '' ? trim($source[$field]) : null;

        return ['key' => $text('key'), 'name' => $text('name'), 'scope' => $text('scope')];
    }
}
