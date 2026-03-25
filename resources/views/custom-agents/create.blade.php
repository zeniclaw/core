@extends('layouts.app')
@section('title', 'Creer un agent - ' . $agent->name)

@section('content')
<div class="max-w-2xl mx-auto space-y-6">

    <div class="flex items-center gap-3">
        <a href="{{ route('custom-agents.index', $agent) }}" class="text-gray-400 hover:text-gray-600 transition-colors">&larr;</a>
        <h2 class="text-xl font-bold text-gray-900">Creer un agent prive</h2>
    </div>

    @if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm">
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('custom-agents.store', $agent) }}" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nom de l'agent *</label>
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="100"
                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="Ex: Expert Comptabilite, Assistant RH, Support Technique...">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea name="description" rows="2" maxlength="500"
                      class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                      placeholder="Decrivez le role de cet agent...">{{ old('description') }}</textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Avatar (emoji)</label>
            <input type="text" name="avatar" value="{{ old('avatar', '🤖') }}" maxlength="10"
                   class="w-20 px-4 py-2.5 border border-gray-200 rounded-lg text-2xl text-center focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">System Prompt</label>
            <textarea name="system_prompt" rows="6" maxlength="5000"
                      class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                      placeholder="Instructions pour l'agent. Ex: Tu es un expert comptable francais...">{{ old('system_prompt') }}</textarea>
            <p class="text-xs text-gray-400 mt-1">Optionnel. Definit la personnalite et les instructions de base de l'agent.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Modele LLM</label>
            <select name="model" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <option value="default">Par defaut (modele de l'agent parent)</option>
                @foreach(\App\Services\ModelResolver::allModels() as $modelId => $modelLabel)
                <option value="{{ $modelId }}" {{ old('model') === $modelId ? 'selected' : '' }}>{{ $modelLabel }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex justify-end gap-3 pt-2">
            <a href="{{ route('custom-agents.index', $agent) }}" class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Annuler</a>
            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">Creer l'agent</button>
        </div>
    </form>

</div>
@endsection
