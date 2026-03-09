<?php

namespace App\Services\Agents;

use App\Models\MusicWishlist;
use App\Models\MusicListenHistory;
use App\Models\UserMusicPreference;
use App\Services\AgentContext;
use App\Services\Formatters\MusicFormatter;
use App\Services\SpotifyService;
use Illuminate\Support\Facades\Log;

class MusicAgent extends BaseAgent
{
    private SpotifyService $spotify;

    public function __construct()
    {
        parent::__construct();
        $this->spotify = new SpotifyService();
    }

    public function name(): string
    {
        return 'music';
    }

    public function description(): string
    {
        return 'Agent musical connecte a Spotify. Recherche de chansons/artistes, recommandations par humeur/genre, playlists thematiques, top charts (FR/US/GB/...), paroles, historique d\'ecoute, et gestion d\'une wishlist musicale personnelle.';
    }

    public function keywords(): array
    {
        return [
            'musique', 'music', 'chanson', 'song', 'morceau', 'track',
            'cherche musique', 'search music', 'trouve chanson', 'find song',
            'artiste', 'artist', 'chanteur', 'chanteuse', 'groupe', 'band',
            'info artiste', 'qui est', 'discographie',
            'album', 'single', 'titre',
            'recommande', 'recommend', 'suggestion', 'recommandation',
            'playlist', 'playlists', 'mix',
            'top charts', 'top musique', 'charts', 'classement', 'hit', 'hits',
            'populaire', 'popular', 'tendance', 'trending',
            'paroles', 'lyrics', 'parole de',
            'spotify', 'ecouter', 'listen', 'jouer', 'play',
            'chill', 'relax', 'energique', 'triste', 'joyeux', 'party', 'fete',
            'pour courir', 'pour bosser', 'pour dormir', 'pour se concentrer',
            'musique triste', 'musique joyeuse', 'musique calme',
            'wishlist', 'favoris', 'favori', 'ma wishlist', 'mes favoris',
            'ajoute a ma wishlist', 'ajoute en favori',
            'supprime favori', 'retire favori',
            'historique musique', 'mes ecoutes', 'derniere musique', 'musique recente',
            'rap', 'rock', 'jazz', 'electro', 'pop', 'classique', 'rnb', 'reggae', 'metal',
            'daft punk', 'eminem', 'queen',
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'music';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = $context->body ?? '';

        $parsed = $this->parseCommand($body);

        if (!$parsed) {
            $reply = $this->buildHelpMessage();
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_help']);
        }

        $action = $parsed['action'];
        $query = $parsed['query'];

        $result = match ($action) {
            'search'          => $this->handleSearch($context, $query),
            'recommend'       => $this->handleRecommend($context, $query),
            'playlist'        => $this->handlePlaylist($context, $query),
            'top'             => $this->handleTopCharts($context, $query),
            'lyrics'          => $this->handleLyrics($context, $query),
            'artist'          => $this->handleArtist($context, $query),
            'history'         => $this->handleHistory($context),
            'wishlist_add'    => $this->handleWishlistAdd($context, $query),
            'wishlist_list'   => $this->handleWishlistList($context),
            'wishlist_remove' => $this->handleWishlistRemove($context, $query),
            default           => $this->handleNaturalLanguage($context, $body),
        };

        return $result;
    }

    // ── Command parser ────────────────────────────────────────────────────────

    private function parseCommand(string $body): ?array
    {
        if (!$body) {
            return null;
        }

        $response = $this->claude->chat(
            "Message: \"{$body}\"",
            'claude-haiku-4-5-20251001',
            $this->buildParserPrompt()
        );

        return $this->parseJson($response);
    }

    private function buildParserPrompt(): string
    {
        return <<<'PROMPT'
Tu es un parseur de commandes musicales. Analyse le message et determine l'action musicale demandee.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication:
{"action": "...", "query": "..."}

ACTIONS POSSIBLES:
- "search"          = rechercher une chanson, un album ou un titre precis. Query = terme de recherche
- "artist"          = chercher des infos sur un artiste (bio, genres, popularite). Query = nom de l'artiste
- "recommend"       = recommandations basees sur une humeur, un genre, un style. Query = humeur/genre
- "playlist"        = chercher ou creer une playlist. Query = nom/theme de la playlist
- "top"             = top charts, classements, titres populaires. Query = code pays ISO ("FR","US","GB","DE","JP","BR","ES") ou "GLOBAL" (defaut: "FR")
- "lyrics"          = chercher les paroles d'une chanson. Query = nom de la chanson (+ artiste si mentionne)
- "history"         = voir l'historique recent des recherches/ecoutes. Query = "" (vide)
- "wishlist_add"    = ajouter une chanson a la wishlist/favoris. Query = nom de la chanson (+ artiste si mentionne)
- "wishlist_list"   = voir sa wishlist/favoris. Query = "" (vide)
- "wishlist_remove" = supprimer un element de la wishlist. Query = numero de l'element (string)

EXEMPLES:
- "cherche du Daft Punk" → {"action": "search", "query": "Daft Punk"}
- "trouve la chanson Shape of You" → {"action": "search", "query": "Shape of You Ed Sheeran"}
- "info sur Eminem" → {"action": "artist", "query": "Eminem"}
- "qui est Queen" → {"action": "artist", "query": "Queen"}
- "parle moi de Daft Punk" → {"action": "artist", "query": "Daft Punk"}
- "mets de la musique triste" → {"action": "recommend", "query": "triste"}
- "je veux du chill" → {"action": "recommend", "query": "chill"}
- "recommande moi du jazz" → {"action": "recommend", "query": "jazz"}
- "musique pour se concentrer" → {"action": "recommend", "query": "concentration"}
- "playlist pour courir" → {"action": "playlist", "query": "running workout"}
- "playlist de fete" → {"action": "playlist", "query": "fete party"}
- "top charts France" → {"action": "top", "query": "FR"}
- "top musique" → {"action": "top", "query": "FR"}
- "top UK" → {"action": "top", "query": "GB"}
- "qu'est-ce qui est populaire en ce moment" → {"action": "top", "query": "FR"}
- "paroles de Bohemian Rhapsody" → {"action": "lyrics", "query": "Bohemian Rhapsody Queen"}
- "mes ecoutes recentes" → {"action": "history", "query": ""}
- "historique musique" → {"action": "history", "query": ""}
- "ajoute a ma wishlist Shape of You" → {"action": "wishlist_add", "query": "Shape of You Ed Sheeran"}
- "ajoute en favori Bohemian Rhapsody" → {"action": "wishlist_add", "query": "Bohemian Rhapsody Queen"}
- "ma wishlist" → {"action": "wishlist_list", "query": ""}
- "mes favoris musique" → {"action": "wishlist_list", "query": ""}
- "supprime favori 2" → {"action": "wishlist_remove", "query": "2"}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    private function handleSearch(AgentContext $context, string $query): AgentResult
    {
        try {
            $data = $this->spotify->searchTrack($query, 'track', 5);
        } catch (\Exception $e) {
            Log::error('[MusicAgent] Spotify search error: ' . $e->getMessage());
            $data = null;
        }

        if (!$data || empty($data['tracks']['items'])) {
            $reply = "Aucun resultat trouve pour \"{$query}\". Essaie avec un autre terme !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_search', 'query' => $query]);
        }

        $tracks = $data['tracks']['items'];
        $reply = MusicFormatter::formatTrackList($tracks, "Resultats pour \"{$query}\"");
        $reply .= "\n\nDis \"ajoute a ma wishlist [nom]\" pour sauvegarder un titre !";

        $firstTrack = $tracks[0];
        $this->trackHistory(
            $context,
            $firstTrack['name'] ?? $query,
            $firstTrack['artists'][0]['name'] ?? '',
            'search',
            $firstTrack['external_urls']['spotify'] ?? null
        );

        $this->sendText($context->from, $reply);
        $this->log($context, 'Music search', ['query' => $query, 'results' => count($tracks)]);

        return AgentResult::reply($reply, ['action' => 'music_search', 'query' => $query]);
    }

    private function handleRecommend(AgentContext $context, string $query): AgentResult
    {
        $genres = $this->spotify->moodToGenres($query);
        $seedGenres = implode(',', array_slice($genres, 0, 5));

        try {
            $data = $this->spotify->getRecommendations([
                'seed_genres' => $seedGenres,
                'limit' => 5,
            ]);
        } catch (\Exception $e) {
            Log::error('[MusicAgent] Spotify recommend error: ' . $e->getMessage());
            $data = null;
        }

        if (!$data || empty($data['tracks'])) {
            $reply = "Pas de recommandations trouvees pour \"{$query}\". Essaie un autre genre ou humeur !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_recommend', 'query' => $query]);
        }

        $tracks = $data['tracks'];
        $reply = MusicFormatter::formatTrackList($tracks, "Recommandations pour \"{$query}\"");
        $reply .= "\n_(Genres: " . implode(', ', $genres) . ")_";
        $reply .= "\n\nDis \"ajoute a ma wishlist [nom]\" pour sauvegarder un titre !";

        // Track history for the first recommendation
        if (!empty($tracks)) {
            $first = $tracks[0];
            $this->trackHistory(
                $context,
                $first['name'] ?? $query,
                $first['artists'][0]['name'] ?? '',
                'recommend',
                $first['external_urls']['spotify'] ?? null
            );
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Music recommend', ['query' => $query, 'genres' => $genres]);

        return AgentResult::reply($reply, ['action' => 'music_recommend', 'query' => $query]);
    }

    private function handlePlaylist(AgentContext $context, string $query): AgentResult
    {
        try {
            $data = $this->spotify->searchPlaylist($query, 5);
        } catch (\Exception $e) {
            Log::error('[MusicAgent] Spotify playlist error: ' . $e->getMessage());
            $data = null;
        }

        if (!$data || empty($data['playlists']['items'])) {
            $reply = "Aucune playlist trouvee pour \"{$query}\". Essaie un autre theme !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_playlist', 'query' => $query]);
        }

        $playlists = array_filter($data['playlists']['items']); // remove nulls
        $reply = "*Playlists pour \"{$query}\"* :\n\n";

        foreach (array_values($playlists) as $i => $playlist) {
            $reply .= MusicFormatter::formatPlaylist($playlist, $i + 1);
            $reply .= "\n\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Playlist search', ['query' => $query, 'results' => count($playlists)]);

        return AgentResult::reply($reply, ['action' => 'music_playlist', 'query' => $query]);
    }

    private function handleTopCharts(AgentContext $context, string $query): AgentResult
    {
        $country = strtoupper(trim($query)) ?: 'FR';

        // Validate / normalise country code
        $supported = ['FR', 'US', 'GLOBAL', 'GB', 'DE', 'ES', 'IT', 'JP', 'BR', 'CA', 'AU'];
        if (!in_array($country, $supported, true)) {
            $country = 'FR';
        }

        try {
            $data = $this->spotify->getTopCharts($country, 10);
        } catch (\Exception $e) {
            Log::error('[MusicAgent] Spotify top charts error: ' . $e->getMessage());
            $data = null;
        }

        if (!$data || empty($data['items'])) {
            $reply = "Impossible de recuperer le top charts pour {$country}. Reessaie plus tard !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_top']);
        }

        $countryLabel = match ($country) {
            'FR'     => 'France',
            'US'     => 'USA',
            'GLOBAL' => 'Monde',
            'GB'     => 'Royaume-Uni',
            'DE'     => 'Allemagne',
            'ES'     => 'Espagne',
            'IT'     => 'Italie',
            'JP'     => 'Japon',
            'BR'     => 'Bresil',
            'CA'     => 'Canada',
            'AU'     => 'Australie',
            default  => $country,
        };

        $reply = "*Top Charts {$countryLabel}* :\n\n";
        $rank = 0;

        foreach ($data['items'] as $item) {
            $track = $item['track'] ?? null;
            if (!$track) continue;

            $rank++;
            $name = $track['name'];
            $artists = implode(', ', array_map(fn($a) => $a['name'], $track['artists']));
            $url = $track['external_urls']['spotify'] ?? '';
            $popularity = $track['popularity'] ?? null;

            $medal = match ($rank) {
                1 => '🥇',
                2 => '🥈',
                3 => '🥉',
                default => "{$rank}.",
            };

            $reply .= "{$medal} *{$name}*\n";
            $reply .= "   {$artists}";
            if ($popularity !== null) {
                $reply .= " — popularite: {$popularity}/100";
            }
            if ($url) {
                $reply .= "\n   {$url}";
            }
            $reply .= "\n\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Top charts', ['country' => $country]);

        return AgentResult::reply($reply, ['action' => 'music_top', 'country' => $country]);
    }

    private function handleLyrics(AgentContext $context, string $query): AgentResult
    {
        try {
            $data = $this->spotify->searchTrack($query, 'track', 1);
        } catch (\Exception $e) {
            Log::error('[MusicAgent] Spotify lyrics search error: ' . $e->getMessage());
            $data = null;
        }

        if (!$data || empty($data['tracks']['items'])) {
            $reply = "Je n'ai pas trouve \"{$query}\" sur Spotify. Verifie le nom de la chanson et reessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_lyrics', 'query' => $query]);
        }

        $track = $data['tracks']['items'][0];
        $name = $track['name'];
        $artists = implode(', ', array_map(fn($a) => $a['name'], $track['artists']));
        $album = $track['album']['name'] ?? '';
        $url = $track['external_urls']['spotify'] ?? '';

        $geniusQuery = urlencode("{$name} {$artists}");
        $geniusUrl = "https://genius.com/search?q={$geniusQuery}";
        $azlyricsQuery = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $artists . $name));
        $azlyricsUrl = "https://www.azlyrics.com/lyrics/{$azlyricsQuery}.html";

        $reply = "*{$name}* — {$artists}";
        if ($album) {
            $reply .= "\n   Album: {$album}";
        }
        if ($url) {
            $reply .= "\n   Spotify: {$url}";
        }

        $reply .= "\n\n📝 *Paroles disponibles ici :*\n";
        $reply .= "• Genius: {$geniusUrl}\n";
        $reply .= "• AZLyrics: {$azlyricsUrl}";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Lyrics request', ['query' => $query, 'found' => $name]);

        return AgentResult::reply($reply, ['action' => 'music_lyrics', 'query' => $query]);
    }

    /**
     * NEW: Fetch artist info (genres, popularity, top tracks from search).
     */
    private function handleArtist(AgentContext $context, string $query): AgentResult
    {
        if (!$query) {
            $reply = "Quel artiste veux-tu rechercher ? Ex: \"info sur Daft Punk\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_artist_empty']);
        }

        try {
            $artistData = $this->spotify->searchArtist($query, 1);
        } catch (\Exception $e) {
            Log::error('[MusicAgent] Spotify artist search error: ' . $e->getMessage());
            $artistData = null;
        }

        if (!$artistData || empty($artistData['artists']['items'])) {
            $reply = "Artiste \"{$query}\" introuvable sur Spotify. Verifie l'orthographe !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_artist_not_found', 'query' => $query]);
        }

        $artist = $artistData['artists']['items'][0];
        $name = $artist['name'];
        $genres = $artist['genres'] ?? [];
        $popularity = $artist['popularity'] ?? null;
        $followers = $artist['followers']['total'] ?? null;
        $url = $artist['external_urls']['spotify'] ?? '';

        $reply = "*{$name}*\n\n";

        if (!empty($genres)) {
            $reply .= "Genres: " . implode(', ', array_slice($genres, 0, 4)) . "\n";
        }
        if ($popularity !== null) {
            $bars = str_repeat('▓', intdiv($popularity, 10)) . str_repeat('░', 10 - intdiv($popularity, 10));
            $reply .= "Popularite: {$bars} {$popularity}/100\n";
        }
        if ($followers !== null) {
            $reply .= "Followers: " . number_format($followers, 0, ',', ' ') . "\n";
        }
        if ($url) {
            $reply .= "Spotify: {$url}\n";
        }

        // Also show top tracks for this artist
        try {
            $trackData = $this->spotify->searchTrack($name, 'track', 3);
            if (!empty($trackData['tracks']['items'])) {
                $reply .= "\n*Titres populaires :*\n";
                foreach ($trackData['tracks']['items'] as $i => $track) {
                    $tName = $track['name'];
                    $tArtists = implode(', ', array_map(fn($a) => $a['name'], $track['artists']));
                    $tUrl = $track['external_urls']['spotify'] ?? '';
                    $reply .= ($i + 1) . ". *{$tName}*";
                    if ($tUrl) {
                        $reply .= "\n   {$tUrl}";
                    }
                    $reply .= "\n";
                }
            }
        } catch (\Exception $e) {
            // Non-critical — ignore
        }

        $this->trackHistory($context, $name, $name, 'artist', $url ?: null);

        $this->sendText($context->from, $reply);
        $this->log($context, 'Artist info', ['query' => $query, 'found' => $name]);

        return AgentResult::reply($reply, ['action' => 'music_artist', 'query' => $query]);
    }

    /**
     * NEW: Show recent music listen history.
     */
    private function handleHistory(AgentContext $context): AgentResult
    {
        $items = MusicListenHistory::where('agent_id', $context->agent->id)
            ->where('user_phone', $context->from)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($items->isEmpty()) {
            $reply = "Ton historique musical est vide.\nCommence par chercher de la musique !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_history_empty']);
        }

        $reply = "*Ton historique musical* ({$items->count()} derniers) :\n\n";

        $actionLabel = [
            'search'    => 'Recherche',
            'recommend' => 'Recommandation',
            'artist'    => 'Artiste',
        ];

        foreach ($items as $i => $item) {
            $label = $actionLabel[$item->action] ?? ucfirst($item->action);
            $reply .= ($i + 1) . ". *{$item->song_name}*";
            if ($item->artist && $item->artist !== $item->song_name) {
                $reply .= " — {$item->artist}";
            }
            $reply .= "\n   _{$label}_";
            if ($item->spotify_url) {
                $reply .= "\n   {$item->spotify_url}";
            }
            $reply .= "\n\n";
        }

        $reply .= "Dis \"cherche [nom]\" pour retrouver un titre !";

        $this->sendText($context->from, $reply);
        $this->log($context, 'History viewed', ['count' => $items->count()]);

        return AgentResult::reply($reply, ['action' => 'music_history']);
    }

    // ── Wishlist ──────────────────────────────────────────────────────────────

    private function handleWishlistAdd(AgentContext $context, string $query): AgentResult
    {
        if (!$query) {
            $reply = "Quel titre veux-tu ajouter a ta wishlist ? Ex: \"ajoute a ma wishlist Shape of You\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_wishlist_add_empty']);
        }

        try {
            $data = $this->spotify->searchTrack($query, 'track', 1);
            $track = $data['tracks']['items'][0] ?? null;
        } catch (\Exception $e) {
            Log::error('[MusicAgent] Spotify wishlist search error: ' . $e->getMessage());
            $track = null;
        }

        if ($track) {
            $songName   = $track['name'];
            $artist     = implode(', ', array_map(fn($a) => $a['name'], $track['artists']));
            $album      = $track['album']['name'] ?? null;
            $spotifyUrl = $track['external_urls']['spotify'] ?? null;
            $durationMs = $track['duration_ms'] ?? null;
            $spotifyId  = $track['id'] ?? null;
        } else {
            $songName   = $query;
            $artist     = 'Inconnu';
            $album      = null;
            $spotifyUrl = null;
            $durationMs = null;
            $spotifyId  = null;
        }

        // Check for duplicates
        $exists = MusicWishlist::where('agent_id', $context->agent->id)
            ->where('user_phone', $context->from)
            ->where('song_name', $songName)
            ->where('artist', $artist)
            ->exists();

        if ($exists) {
            $reply = "*{$songName}* de {$artist} est deja dans ta wishlist !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_wishlist_duplicate']);
        }

        MusicWishlist::create([
            'agent_id'   => $context->agent->id,
            'user_phone' => $context->from,
            'song_name'  => $songName,
            'artist'     => $artist,
            'album'      => $album,
            'spotify_url'=> $spotifyUrl,
            'duration_ms'=> $durationMs,
            'spotify_id' => $spotifyId,
        ]);

        $reply = "Ajoute a ta wishlist !\n\n";
        $reply .= MusicFormatter::formatTrack([
            'name'        => $songName,
            'artist'      => $artist,
            'album'       => $album,
            'duration_ms' => $durationMs ?? 0,
            'spotify_url' => $spotifyUrl,
        ]);
        $reply .= "\n\nDis \"ma wishlist\" pour voir tes favoris !";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Wishlist add', ['song' => $songName, 'artist' => $artist]);

        return AgentResult::reply($reply, ['action' => 'music_wishlist_add', 'song' => $songName]);
    }

    private function handleWishlistList(AgentContext $context): AgentResult
    {
        $items = MusicWishlist::where('agent_id', $context->agent->id)
            ->where('user_phone', $context->from)
            ->orderByDesc('created_at')
            ->get();

        if ($items->isEmpty()) {
            $reply = "Ta wishlist est vide !\nDis \"ajoute a ma wishlist [nom]\" pour sauvegarder un titre.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_wishlist_empty']);
        }

        $reply = "*Ta Wishlist Musicale* ({$items->count()} titres) :\n\n";

        foreach ($items as $i => $item) {
            $reply .= MusicFormatter::formatWishlistItem($item, $i + 1);
            $reply .= "\n\n";
        }

        $reply .= "Dis \"supprime favori [numero]\" pour retirer un titre.";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Wishlist viewed', ['count' => $items->count()]);

        return AgentResult::reply($reply, ['action' => 'music_wishlist_list']);
    }

    private function handleWishlistRemove(AgentContext $context, string $query): AgentResult
    {
        $index = (int) $query;
        if ($index < 1) {
            $reply = "Donne le numero de l'element a supprimer. Dis \"ma wishlist\" pour voir la liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_wishlist_remove_invalid']);
        }

        $items = MusicWishlist::where('agent_id', $context->agent->id)
            ->where('user_phone', $context->from)
            ->orderByDesc('created_at')
            ->get();

        $item = $items->values()[$index - 1] ?? null;

        if (!$item) {
            $count = $items->count();
            $reply = "Element #{$index} introuvable dans ta wishlist ({$count} titres).";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_wishlist_remove_not_found']);
        }

        $songName = $item->song_name;
        $artist = $item->artist;
        $item->delete();

        $reply = "*{$songName}* de {$artist} retire de ta wishlist.";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Wishlist remove', ['song' => $songName]);

        return AgentResult::reply($reply, ['action' => 'music_wishlist_remove']);
    }

    // ── Natural language fallback ─────────────────────────────────────────────

    /**
     * Handle requests that didn't match a specific command.
     * Uses Claude to give a helpful contextual response.
     */
    private function handleNaturalLanguage(AgentContext $context, string $body): AgentResult
    {
        $systemPrompt = <<<'SYSTEM'
Tu es un assistant musical expert connecte a Spotify. Tu peux aider avec:
- Recherche de chansons et artistes
- Recommandations par humeur ou genre
- Playlists thematiques
- Top charts par pays
- Wishlist personnelle

Reponds de facon concise et conviviale en francais (style WhatsApp). Si la demande est floue, propose 2-3 options concretes.
SYSTEM;

        $aiReply = $this->claude->chat($body, 'claude-haiku-4-5-20251001', $systemPrompt);

        $reply = $aiReply ?? $this->buildHelpMessage();

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'music_natural_language']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function trackHistory(AgentContext $context, string $songName, string $artist, string $action, ?string $spotifyUrl = null): void
    {
        try {
            MusicListenHistory::create([
                'agent_id'    => $context->agent->id,
                'user_phone'  => $context->from,
                'song_name'   => $songName,
                'artist'      => $artist,
                'action'      => $action,
                'spotify_url' => $spotifyUrl,
            ]);
        } catch (\Exception $e) {
            Log::warning('[MusicAgent] Failed to track music history: ' . $e->getMessage());
        }
    }

    private function buildHelpMessage(): string
    {
        return "*Assistant Musical*\n\n"
            . "Voici ce que je peux faire :\n\n"
            . "*Rechercher* — \"cherche Shape of You\"\n"
            . "*Artiste* — \"info sur Daft Punk\"\n"
            . "*Recommander* — \"recommande du chill\" ou \"musique pour se concentrer\"\n"
            . "*Playlists* — \"playlist pour courir\"\n"
            . "*Top Charts* — \"top charts France\" ou \"top UK\"\n"
            . "*Paroles* — \"paroles de Bohemian Rhapsody\"\n"
            . "*Historique* — \"mes ecoutes recentes\"\n"
            . "*Wishlist* — \"ajoute a ma wishlist [nom]\" ou \"ma wishlist\"\n\n"
            . "Dis-moi ce que tu veux ecouter !";
    }

    private function parseJson(?string $response): ?array
    {
        if (!$response) return null;

        $clean = trim($response);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $parsed = json_decode($clean, true);

        if (!$parsed || empty($parsed['action'])) {
            return null;
        }

        return $parsed;
    }
}
