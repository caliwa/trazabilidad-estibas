{{--
    Company logo. Drop the file at public/images/logo.png (square, transparent
    background works best, at least 128x128) and it shows everywhere this
    component is used: the sidebar, the top brand and the auth screens.
--}}
@props([
    'alt' => config('app.name', 'Trazabilidad de estibas'),
])

<img src="{{ asset('images/logo.png') }}" alt="{{ $alt }}" {{ $attributes->class('h-full w-full object-contain') }} />
