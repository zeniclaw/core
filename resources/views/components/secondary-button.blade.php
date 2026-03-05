<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 rounded-lg font-semibold text-xs uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150', 'style' => 'background:#1a1f2e;border:1px solid #1e293b;color:#f1f5f9;']) }}>
    {{ $slot }}
</button>
