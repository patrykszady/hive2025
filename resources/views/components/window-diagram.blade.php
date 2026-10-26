{{-- The window or door drawing for a line that carries a window_spec
     (App\Support\Windows\WindowDiagram): inline SVG, so it prints in the
     estimate, invoice and work-order PDFs exactly as on screen. --}}
@props(['spec' => null, 'width' => 72])
@if (is_array($spec) && ! empty($spec['type']))
    <span {{ $attributes->merge(['class' => 'inline-block shrink-0 leading-none']) }} data-window-diagram="{{ $spec['type'] }}">{!! \App\Support\Windows\WindowDiagram::svg($spec, (int) $width) !!}</span>
@endif
