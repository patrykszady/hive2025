<?php

namespace App\Support\Pdf;

use setasign\Fpdi\Fpdi;

/**
 * FPDI that can paint like a highlighter: a colour laid over a page with the
 * Multiply blend mode tints the paper and leaves the text on it untouched.
 * FPDF has no transparency of its own; this adds ExtGState resources (the
 * FPDF "Transparency" add-on, kept to the one blend mode we need).
 */
class MarkerFpdi extends Fpdi
{
    /** @var array<int, array{n?: int, alpha: float, blend: string}> */
    protected array $extGStates = [];

    /** Paint what follows as a highlighter would (Multiply), or normally again. */
    public function setMarker(bool $on = true): void
    {
        $key = count($this->extGStates) + 1;

        foreach ($this->extGStates as $index => $state) {
            if ($state['blend'] === ($on ? 'Multiply' : 'Normal')) {
                $key = $index;
                break;
            }
        }

        $this->extGStates[$key] ??= ['alpha' => 1.0, 'blend' => $on ? 'Multiply' : 'Normal'];
        $this->_out(sprintf('/GS%d gs', $key));
    }

    protected function _enddoc()
    {
        if ($this->extGStates !== [] && $this->PDFVersion < '1.4') {
            $this->PDFVersion = '1.4';
        }

        parent::_enddoc();
    }

    protected function _putresources()
    {
        foreach ($this->extGStates as $index => $state) {
            $this->_newobj();
            $this->extGStates[$index]['n'] = $this->n;
            $this->_put(sprintf('<</Type /ExtGState /ca %.3F /CA %.3F /BM /%s>>', $state['alpha'], $state['alpha'], $state['blend']));
            $this->_put('endobj');
        }

        parent::_putresources();
    }

    protected function _putresourcedict()
    {
        parent::_putresourcedict();

        if ($this->extGStates === []) {
            return;
        }

        $this->_put('/ExtGState <<');
        foreach ($this->extGStates as $index => $state) {
            $this->_put(sprintf('/GS%d %d 0 R', $index, $state['n']));
        }
        $this->_put('>>');
    }
}
