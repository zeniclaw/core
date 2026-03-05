@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-lg shadow-sm w-full', 'style' => 'background:#0a0e17;border:1px solid #1e293b;color:#f1f5f9;padding:0.625rem 0.875rem;']) }}>
