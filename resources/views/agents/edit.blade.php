@extends('layouts.app')
@section('title', 'Edit Agent')

@section('content')
<div class="max-w-2xl">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-6">Edit: {{ $agent->name }}</h2>
        <form method="POST" action="{{ route('agents.update', $agent) }}">
            @csrf @method('PUT')
            @include('agents._form')
            <div class="mt-6 flex gap-3">
                <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                    Save Changes
                </button>
                <a href="{{ route('agents.show', $agent) }}" class="px-5 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
