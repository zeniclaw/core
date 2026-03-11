@php
$models = [
    'claude-sonnet-4-5' => 'Claude Sonnet 4.5 (Claude Max)',
    'claude-opus-4-5'   => 'Claude Opus 4.5 (Claude Max)',
    'claude-haiku-4-5'  => 'Claude Haiku 4.5 (Claude Max)',
    'qwen2.5:3b'        => 'Qwen 2.5 3B (On-Prem — 4 GB RAM, 2 CPU)',
    'qwen2.5:7b'        => 'Qwen 2.5 7B (On-Prem — 8 GB RAM, 4 CPU)',
    'qwen2.5-coder:7b'  => 'Qwen 2.5 Coder 7B (On-Prem — 8 GB RAM, 4 CPU)',
];
@endphp

<div class="space-y-5">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" value="{{ old('name', $agent->name ?? '') }}"
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
               placeholder="My awesome agent" required>
        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <input type="text" name="description" value="{{ old('description', $agent->description ?? '') }}"
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
               placeholder="What does this agent do?">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">System Prompt</label>
        <textarea name="system_prompt" rows="6"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none font-mono"
                  placeholder="You are a helpful assistant...">{{ old('system_prompt', $agent->system_prompt ?? '') }}</textarea>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Model <span class="text-red-500">*</span></label>
            <select name="model" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none bg-white">
                @foreach($models as $value => $label)
                    <option value="{{ $value }}" {{ old('model', $agent->model ?? 'claude-sonnet-4-5') === $value ? 'selected' : '' }}>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-400">Les modèles Claude Max nécessitent une clé API Anthropic dans les Settings.</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none bg-white">
                <option value="active" {{ old('status', $agent->status ?? 'active') === 'active' ? 'selected' : '' }}>✅ Active</option>
                <option value="inactive" {{ old('status', $agent->status ?? 'active') === 'inactive' ? 'selected' : '' }}>⏸ Inactive</option>
            </select>
        </div>
    </div>
</div>
