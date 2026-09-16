<?php

namespace App\Services\EstimateAI;

/**
 * Pulls finished objects out of the "line_items" array of a JSON document
 * that is still arriving. Feed it the text as it streams and it hands back
 * each line item the moment its closing brace lands, so the estimator sees
 * real rows while the model is still writing the rest of the draft.
 *
 * Only the top-level "line_items" array is watched: braces inside strings
 * (the reasoning, a note) and objects nested inside an item never count.
 */
final class LineItemStreamParser
{
    private string $buffer = '';

    private int $cursor = 0;

    private bool $inString = false;

    private bool $escaped = false;

    private string $currentString = '';

    private ?string $pendingKey = null;

    private ?string $awaitingValueFor = null;

    private int $depth = 0;

    private ?int $arrayDepth = null;

    private ?int $objectStart = null;

    private bool $finished = false;

    /**
     * @return list<array<string, mixed>> the line items completed by this chunk, in order
     */
    public function push(string $chunk): array
    {
        if ($this->finished || $chunk === '') {
            return [];
        }

        $this->buffer .= $chunk;
        $items = [];
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
                    if ($this->arrayDepth === null && $this->depth === 1) {
                        $this->pendingKey = $this->currentString;
                    }
                } elseif ($this->arrayDepth === null && $this->depth === 1) {
                    $this->currentString .= $char;
                }

                continue;
            }

            switch ($char) {
                case '"':
                    $this->inString = true;
                    $this->currentString = '';
                    break;

                case ':':
                    if ($this->arrayDepth === null && $this->depth === 1) {
                        $this->awaitingValueFor = $this->pendingKey;
                    }
                    break;

                case ',':
                    if ($this->arrayDepth === null && $this->depth === 1) {
                        $this->awaitingValueFor = null;
                    }
                    break;

                case '[':
                    $this->depth++;
                    if ($this->arrayDepth === null && $this->depth === 2 && $this->awaitingValueFor === 'line_items') {
                        $this->arrayDepth = $this->depth;
                    }
                    break;

                case ']':
                    if ($this->arrayDepth !== null && $this->depth === $this->arrayDepth) {
                        $this->finished = true;

                        return $items;
                    }
                    $this->depth--;
                    break;

                case '{':
                    $this->depth++;
                    if ($this->arrayDepth !== null && $this->depth === $this->arrayDepth + 1) {
                        $this->objectStart = $this->cursor;
                    }
                    break;

                case '}':
                    if ($this->arrayDepth !== null && $this->depth === $this->arrayDepth + 1 && $this->objectStart !== null) {
                        $json = substr($this->buffer, $this->objectStart, $this->cursor - $this->objectStart + 1);
                        $decoded = json_decode($json, true);
                        if (is_array($decoded)) {
                            $items[] = $decoded;
                        }
                        $this->objectStart = null;
                    }
                    $this->depth--;
                    break;
            }
        }

        return $items;
    }

    public function finished(): bool
    {
        return $this->finished;
    }
}
