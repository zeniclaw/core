@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm', 'style' => 'color:#94a3b8;']) }}>
    {{ $value ?? $slot }}
</label>
