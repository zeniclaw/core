@extends('layouts.app')
@section('title', 'Nouveau projet')

@section('content')
<div class="max-w-2xl">

    <div class="mb-6">
        <a href="{{ route('projects.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Retour aux projets</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-1">Creer un projet</h2>
        <p class="text-sm text-gray-500 mb-6">Le projet sera automatiquement approuve.</p>

        <form method="POST" action="{{ route('projects.store') }}" class="space-y-5"
              x-data="projectForm()" @submit.prevent="submitForm($event)">
            @csrf

            {{-- GitLab Repo --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Repo GitLab</label>
                <div class="relative" @click.away="showRepos = false">
                    <input type="text" x-model="repoSearch" @input.debounce.300ms="searchRepos()"
                           @focus="showRepos = repos.length > 0"
                           placeholder="Rechercher un repo GitLab..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                    <input type="hidden" name="gitlab_url" :value="selectedRepoUrl">
                    <input type="hidden" name="name" :value="selectedRepoName">

                    {{-- Loading --}}
                    <div x-show="loadingRepos" class="absolute right-3 top-1/2 -translate-y-1/2">
                        <svg class="animate-spin h-4 w-4 text-gray-400" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </div>

                    {{-- Dropdown --}}
                    <div x-show="showRepos && repos.length > 0" x-transition
                         class="absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                        <template x-for="repo in repos" :key="repo.id">
                            <button type="button" @click="selectRepo(repo)"
                                    class="w-full text-left px-3 py-2 hover:bg-indigo-50 text-sm border-b border-gray-50 last:border-0">
                                <span class="font-medium text-gray-900" x-text="repo.name"></span>
                                <span class="block text-xs text-gray-500" x-text="repo.path_with_namespace"></span>
                            </button>
                        </template>
                    </div>

                    {{-- Error --}}
                    <div x-show="repoError" class="mt-1 text-xs text-red-500" x-text="repoError"></div>
                </div>

                {{-- Selected repo badge --}}
                <div x-show="selectedRepoUrl" class="mt-2 flex items-center gap-2">
                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-indigo-50 text-indigo-700 rounded-lg text-xs font-medium">
                        <span x-text="selectedRepoName"></span>
                        <button type="button" @click="clearRepo()" class="ml-1 text-indigo-400 hover:text-indigo-600">&times;</button>
                    </span>
                    <span class="text-xs text-gray-400 font-mono truncate" x-text="selectedRepoUrl"></span>
                </div>
            </div>

            {{-- Description --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description (optionnel)</label>
                <textarea name="request_description" rows="3"
                          placeholder="Description du projet ou de la premiere tache..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none resize-none"></textarea>
            </div>

            {{-- Allowed WhatsApp contacts --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Contacts WhatsApp autorises (optionnel)</label>
                <p class="text-xs text-gray-400 mb-2">Les numeros selectionnes pourront envoyer des taches directement sur ce projet via WhatsApp.</p>

                <div class="relative" @click.away="showContacts = false">
                    <input type="text" x-model="contactSearch" @input="filterContacts()"
                           @focus="loadContacts(); showContacts = true"
                           placeholder="Rechercher un contact..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">

                    {{-- Dropdown --}}
                    <div x-show="showContacts && filteredContacts.length > 0" x-transition
                         class="absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                        <template x-for="contact in filteredContacts" :key="contact.peer_id">
                            <button type="button" @click="toggleContact(contact)"
                                    class="w-full text-left px-3 py-2 hover:bg-indigo-50 text-sm border-b border-gray-50 last:border-0 flex items-center justify-between">
                                <div>
                                    <span class="font-medium text-gray-900" x-text="contact.name"></span>
                                    <span class="block text-xs text-gray-500" x-text="contact.peer_id"></span>
                                </div>
                                <span x-show="isContactSelected(contact.peer_id)" class="text-indigo-600 text-xs font-bold">&#10003;</span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Selected contacts --}}
                <div x-show="selectedContacts.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                    <template x-for="(contact, i) in selectedContacts" :key="contact.peer_id">
                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-green-50 text-green-700 rounded-lg text-xs font-medium">
                            <span x-text="contact.name"></span>
                            <button type="button" @click="removeContact(i)" class="ml-0.5 text-green-400 hover:text-green-600">&times;</button>
                            <input type="hidden" name="allowed_phones[]" :value="contact.peer_id">
                        </span>
                    </template>
                </div>
            </div>

            {{-- Notify WhatsApp groups --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Groupes WhatsApp a notifier (optionnel)</label>
                <p class="text-xs text-gray-400 mb-2">Les groupes selectionnes recevront une notification quand le projet est configure.</p>

                <div class="relative" @click.away="showGroups = false">
                    <input type="text" x-model="groupSearch" @input="filterGroups()"
                           @focus="loadGroups(); showGroups = true"
                           placeholder="Rechercher un groupe..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">

                    {{-- Dropdown --}}
                    <div x-show="showGroups && filteredGroups.length > 0" x-transition
                         class="absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                        <template x-for="group in filteredGroups" :key="group.peer_id">
                            <button type="button" @click="toggleGroup(group)"
                                    class="w-full text-left px-3 py-2 hover:bg-purple-50 text-sm border-b border-gray-50 last:border-0 flex items-center justify-between">
                                <div>
                                    <span class="font-medium text-gray-900" x-text="group.name"></span>
                                    <span class="block text-xs text-gray-500" x-text="group.peer_id"></span>
                                </div>
                                <span x-show="isGroupSelected(group.peer_id)" class="text-purple-600 text-xs font-bold">&#10003;</span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Selected groups --}}
                <div x-show="selectedGroups.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                    <template x-for="(group, i) in selectedGroups" :key="group.peer_id">
                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-purple-50 text-purple-700 rounded-lg text-xs font-medium">
                            <span x-text="group.name"></span>
                            <button type="button" @click="removeGroup(i)" class="ml-0.5 text-purple-400 hover:text-purple-600">&times;</button>
                            <input type="hidden" name="notify_groups[]" :value="group.peer_id">
                        </span>
                    </template>
                </div>
            </div>

            {{-- Submit --}}
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" :disabled="!selectedRepoUrl"
                        :class="selectedRepoUrl ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-gray-300 cursor-not-allowed'"
                        class="text-white px-5 py-2 rounded-lg text-sm font-medium transition-colors">
                    Creer le projet
                </button>
                <a href="{{ route('projects.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annuler</a>
            </div>
        </form>
    </div>
</div>

<script>
function projectForm() {
    return {
        // Repos
        repoSearch: '',
        repos: [],
        showRepos: false,
        loadingRepos: false,
        repoError: '',
        selectedRepoUrl: '',
        selectedRepoName: '',

        // Contacts
        contactSearch: '',
        allContacts: [],
        filteredContacts: [],
        selectedContacts: [],
        showContacts: false,
        contactsLoaded: false,

        // Groups
        groupSearch: '',
        allGroups: [],
        filteredGroups: [],
        selectedGroups: [],
        showGroups: false,
        groupsLoaded: false,

        async searchRepos() {
            if (this.repoSearch.length < 1) { this.repos = []; this.showRepos = false; return; }
            this.loadingRepos = true;
            this.repoError = '';
            try {
                const r = await fetch(`{{ route('api.gitlab-projects') }}?q=${encodeURIComponent(this.repoSearch)}`);
                const data = await r.json();
                if (data.error) { this.repoError = data.error; this.repos = []; }
                else { this.repos = data; this.showRepos = true; }
            } catch(e) { this.repoError = 'Erreur de connexion'; }
            this.loadingRepos = false;
        },

        selectRepo(repo) {
            this.selectedRepoUrl = repo.web_url;
            this.selectedRepoName = repo.name;
            this.repoSearch = repo.path_with_namespace;
            this.showRepos = false;
        },

        clearRepo() {
            this.selectedRepoUrl = '';
            this.selectedRepoName = '';
            this.repoSearch = '';
        },

        async loadContacts() {
            if (this.contactsLoaded) return;
            try {
                const r = await fetch('{{ route('api.contacts') }}');
                this.allContacts = await r.json();
                this.filteredContacts = this.allContacts;
                this.contactsLoaded = true;
            } catch(e) {}
        },

        filterContacts() {
            const q = this.contactSearch.toLowerCase();
            this.filteredContacts = this.allContacts.filter(c =>
                c.name.toLowerCase().includes(q) || c.peer_id.toLowerCase().includes(q)
            );
            this.showContacts = true;
        },

        toggleContact(contact) {
            const idx = this.selectedContacts.findIndex(c => c.peer_id === contact.peer_id);
            if (idx >= 0) { this.selectedContacts.splice(idx, 1); }
            else { this.selectedContacts.push(contact); }
        },

        removeContact(i) {
            this.selectedContacts.splice(i, 1);
        },

        isContactSelected(peerId) {
            return this.selectedContacts.some(c => c.peer_id === peerId);
        },

        async loadGroups() {
            if (this.groupsLoaded) return;
            try {
                const r = await fetch('{{ route('api.groups') }}');
                this.allGroups = await r.json();
                this.filteredGroups = this.allGroups;
                this.groupsLoaded = true;
            } catch(e) {}
        },

        filterGroups() {
            const q = this.groupSearch.toLowerCase();
            this.filteredGroups = this.allGroups.filter(g =>
                g.name.toLowerCase().includes(q) || g.peer_id.toLowerCase().includes(q)
            );
            this.showGroups = true;
        },

        toggleGroup(group) {
            const idx = this.selectedGroups.findIndex(g => g.peer_id === group.peer_id);
            if (idx >= 0) { this.selectedGroups.splice(idx, 1); }
            else { this.selectedGroups.push(group); }
        },

        removeGroup(i) {
            this.selectedGroups.splice(i, 1);
        },

        isGroupSelected(peerId) {
            return this.selectedGroups.some(g => g.peer_id === peerId);
        },

        submitForm(e) {
            if (!this.selectedRepoUrl) return;
            e.target.submit();
        }
    }
}
</script>
@endsection
