<?php

namespace App\Services\Agents;

use App\Models\MusicWishlist;
use App\Models\MusicListenHistory;
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
        return 'Agent musical connecte a Spotify. Recherche de chansons, recommandations par humeur/genre, playlists thematiques, top charts, paroles, et gestion d\'une wishlist musicale personnelle.';
    }

    public function keywords(): array
    {
        return [
            'musique', 'music', 'chanson', 'song', 'morceau', 'track',
            'cherche musique', 'search music', 'trouve chanson', 'find song',
            'artiste', 'artist', 'chanteur', 'chanteuse', 'groupe', 'band',
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
            'rap', 'rock', 'jazz', 'electro', 'pop', 'classique', 'rnb', 'reggae', 'metal',
            'daft punk', 'eminem', 'queen',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
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
            'search' => $this->handleSearch($context, $query),
            'recommend' => $this->handleRecommend($context, $query),
            'playlist' => $this->handlePlaylist($context, $query),
            'top' => $this->handleTopCharts($context, $query),
            'lyrics' => $this->handleLyrics($context, $query),
            'wishlist_add' => $this->handleWishlistAdd($context, $query),
            'wishlist_list' => $this->handleWishlistList($context),
            'wishlist_remove' => $this->handleWishlistRemove($context, $query),
            default => $this->handleNaturalLanguage($context, $body),
        };

        return $result;
    }

    /**
     * Parse the user message to detect music commands.
     * Uses Claude Haiku for natural language understanding.
     */
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
- "search" = rechercher une chanson, un artiste, un album. Query = terme de recherche
- "recommend" = recommandations basees sur une humeur, un genre, un style. Query = humeur/genre
- "playlist" = chercher ou creer une playlist. Query = nom/theme de la playlist
- "top" = top charts, classements, titres populaires. Query = pays ou "global" (defaut: "FR")
- "lyrics" = chercher les paroles d'une chanson. Query = nom de la chanson (+ artiste si mentionne)
- "wishlist_add" = ajouter une chanson a la wishlist/favoris. Query = nom de la chanson (+ artiste si mentionne)
- "wishlist_list" = voir sa wishlist/favoris. Query = "" (vide)
- "wishlist_remove" = supprimer un element de la wishlist. Query = numero de l'element (string)

EXEMPLES:
- "cherche du Daft Punk" → {"action": "search", "query": "Daft Punk"}
- "mets de la musique triste" → {"action": "recommend", "query": "triste"}
- "je veux du chill" → {"action": "recommend", "query": "chill"}
- "playlist pour courir" → {"action": "playlist", "query": "running workout"}
- "top charts France" → {"action": "top", "query": "FR"}
- "top musique" → {"action": "top", "query": "FR"}
- "paroles de Bohemian Rhapsody" → {"action": "lyrics", "query": "Bohemian Rhapsody Queen"}
- "recommande moi du jazz" → {"action": "recommend", "query": "jazz"}
- "qu'est-ce qui est populaire en ce moment" → {"action": "top", "query": "FR"}
- "trouve la chanson Shape of You" → {"action": "search", "query": "Shape of You Ed Sheeran"}
- "musique pour se concentrer" → {"action": "recommend", "query": "concentration"}
- "playlist de fete" → {"action": "playlist", "query": "fete party"}
- "ajoute a ma wishlist Shape of You" → {"action": "wishlist_add", "query": "Shape of You Ed Sheeran"}
- "ajoute en favori Bohemian Rhapsody" → {"action": "wishlist_add", "query": "Bohemian Rhapsody Queen"}
- "ma wishlist" → {"action": "wishlist_list", "query": ""}
- "mes favoris musique" → {"action": "wishlist_list", "query": ""}
- "ma musique" → {"action": "wishlist_list", "query": ""}
- "supprime favori 2" → {"action": "wishlist_remove", "query": "2"}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function handleSearch(AgentContext $context, string $query): AgentResult
    {
        $data = $this->spotify->searchTrack($query, 'track', 5);

        if (!$data || empty($data['tracks']['items'])) {
            $reply = "Aucun resultat trouve pour \"{$query}\". Essaie avec un autre terme !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_search', 'query' => $query]);
        }

        $tracks = $data['tracks']['items'];
        $reply = MusicFormatter::formatTrackList($tracks, "Resultats pour \"{$query}\"");
        $reply .= "\n\nDis \"ajoute a ma wishlist [nom]\" pour sauvegarder un titre !";

        $this->trackHistory($context, $tracks[0]['name'] ?? $query, $tracks[0]['artists'][0]['name'] ?? '', 'search', $tracks[0]['external_urls']['spotify'] ?? null);

        $this->sendText($context->from, $reply);
        $this->log($context, 'Music search', ['query' => $query, 'results' => count($tracks)]);

        return AgentResult::reply($reply, ['action' => 'music_search', 'query' => $query]);
    }

    private function handleRecommend(AgentContext $context, string $query): AgentResult
    {
        $genres = $this->spotify->moodToGenres($query);
        $seedGenres = implode(',', array_slice($genres, 0, 5));

        $data = $this->spotify->getRecommendations([
            'seed_genres' => $seedGenres,
            'limit' => 5,
        ]);

        if (!$data || empty($data['tracks'])) {
            $reply = "🎵 Pas de recommandations trouvees pour \"{$query}\". Essaie un autre genre ou humeur !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_recommend', 'query' => $query]);
        }

        $tracks = $data['tracks'];
        $reply = "🎵 *Recommandations pour \"{$query}\"* :\n";
        $reply .= "_(Genres: " . implode(', ', $genres) . ")_\n\n";

        foreach ($tracks as $i => $track) {
            $num = $i + 1;
            $name = $track['name'];
            $artists = implode(', ', array_map(fn($a) => $a['name'], $track['artists']));
            $url = $track['external_urls']['spotify'] ?? '';

            $reply .= "{$num}. 🎤 *{$name}*\n";
            $reply .= "   🎸 {$artists}";
            if ($url) {
                $reply .= "\n   🔗 {$url}";
            }
            $reply .= "\n\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Music recommend', ['query' => $query, 'genres' => $genres]);

        return AgentResult::reply($reply, ['action' => 'music_recommend', 'query' => $query]);
    }

    private function handlePlaylist(AgentContext $context, string $query): AgentResult
    {
        $data = $this->spotify->searchPlaylist($query, 5);

        if (!$data || empty($data['playlists']['items'])) {
            $reply = "🎵 Aucune playlist trouvee pour \"{$query}\". Essaie un autre theme !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_playlist', 'query' => $query]);
        }

        $playlists = $data['playlists']['items'];
        $reply = "🎵 *Playlists pour \"{$query}\"* :\n\n";

        foreach ($playlists as $i => $playlist) {
            $num = $i + 1;
            $name = $playlist['name'];
            $owner = $playlist['owner']['display_name'] ?? 'Spotify';
            $totalTracks = $playlist['tracks']['total'] ?? 0;
            $url = $playlist['external_urls']['spotify'] ?? '';

            $reply .= "{$num}. 🎵 *{$name}*\n";
            $reply .= "   👤 par {$owner} — {$totalTracks} titres";
            if ($url) {
                $reply .= "\n   🔗 {$url}";
            }
            $reply .= "\n\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Playlist search', ['query' => $query, 'results' => count($playlists)]);

        return AgentResult::reply($reply, ['action' => 'music_playlist', 'query' => $query]);
    }

    private function handleTopCharts(AgentContext $context, string $query): AgentResult
    {
        $country = strtoupper(trim($query)) ?: 'FR';
        $data = $this->spotify->getTopCharts($country, 10);

        if (!$data || empty($data['items'])) {
            $reply = "🎵 Impossible de recuperer le top charts. Reessaie plus tard !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_top']);
        }

        $countryLabel = match ($country) {
            'FR' => 'France',
            'US' => 'USA',
            'GLOBAL' => 'Monde',
            default => $country,
        };

        $reply = "🏆 *Top Charts {$countryLabel}* :\n\n";

        foreach ($data['items'] as $i => $item) {
            $track = $item['track'] ?? null;
            if (!$track) continue;

            $num = $i + 1;
            $name = $track['name'];
            $artists = implode(', ', array_map(fn($a) => $a['name'], $track['artists']));
            $url = $track['external_urls']['spotify'] ?? '';

            $medal = match ($num) {
                1 => '🥇',
                2 => '🥈',
                3 => '🥉',
                default => "{$num}.",
            };

            $reply .= "{$medal} *{$name}*\n";
            $reply .= "   🎤 {$artists}";
            if ($url) {
                $reply .= "\n   🔗 {$url}";
            }
            $reply .= "\n\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Top charts', ['country' => $country]);

        return AgentResult::reply($reply, ['action' => 'music_top', 'country' => $country]);
    }

    private function handleLyrics(AgentContext $context, string $query): AgentResult
    {
        // Search for the track first to confirm it exists
        $data = $this->spotify->searchTrack($query, 'track', 1);
        $trackInfo = '';

        if ($data && !empty($data['tracks']['items'])) {
            $track = $data['tracks']['items'][0];
            $name = $track['name'];
            $artists = implode(', ', array_map(fn($a) => $a['name'], $track['artists']));
            $url = $track['external_urls']['spotify'] ?? '';
            $trackInfo = "🎵 *{$name}* — {$artists}";
            if ($url) {
                $trackInfo .= "\n🔗 {$url}";
            }
        }

        // Use Claude to provide lyrics context (we can't fetch full lyrics due to copyright)
        $reply = $trackInfo
            ? "{$trackInfo}\n\n📝 Je ne peux pas afficher les paroles completes (droits d'auteur), "
              . "mais tu peux les trouver sur des sites comme Genius ou AZLyrics !"
            : "🎵 Je n'ai pas trouve \"{$query}\". Verifie le nom de la chanson et reessaie !";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Lyrics request', ['query' => $query]);

        return AgentResult::reply($reply, ['action' => 'music_lyrics', 'query' => $query]);
    }

    private function handleWishlistAdd(AgentContext $context, string $query): AgentResult
    {
        if (!$query) {
            $reply = "Quel titre veux-tu ajouter a ta wishlist ? Ex: \"ajoute a ma wishlist Shape of You\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_wishlist_add_empty']);
        }

        // Search for the track on Spotify to get full info
        $data = $this->spotify->searchTrack($query, 'track', 1);
        $track = $data['tracks']['items'][0] ?? null;

        if ($track) {
            $songName = $track['name'];
            $artist = implode(', ', array_map(fn($a) => $a['name'], $track['artists']));
            $album = $track['album']['name'] ?? null;
            $spotifyUrl = $track['external_urls']['spotify'] ?? null;
            $durationMs = $track['duration_ms'] ?? null;
            $spotifyId = $track['id'] ?? null;
        } else {
            $songName = $query;
            $artist = 'Inconnu';
            $album = null;
            $spotifyUrl = null;
            $durationMs = null;
            $spotifyId = null;
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
            'agent_id' => $context->agent->id,
            'user_phone' => $context->from,
            'song_name' => $songName,
            'artist' => $artist,
            'album' => $album,
            'spotify_url' => $spotifyUrl,
            'duration_ms' => $durationMs,
            'spotify_id' => $spotifyId,
        ]);

        $reply = "Ajoute a ta wishlist !\n\n";
        $reply .= MusicFormatter::formatTrack([
            'name' => $songName,
            'artist' => $artist,
            'album' => $album,
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

        $reply = "*Ta Wishlist Musicale* ({$items->count()} titres)\n\n";

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
            $reply = "Element #{$index} introuvable dans ta wishlist.";
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

    private function trackHistory(AgentContext $context, string $songName, string $artist, string $action, ?string $spotifyUrl = null): void
    {
        try {
            MusicListenHistory::create([
                'agent_id' => $context->agent->id,
                'user_phone' => $context->from,
                'song_name' => $songName,
                'artist' => $artist,
                'action' => $action,
                'spotify_url' => $spotifyUrl,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to track music history: ' . $e->getMessage());
        }
    }

    /**
     * Handle natural language music requests that don't fit a specific command.
     */
    private function handleNaturalLanguage(AgentContext $context, string $body): AgentResult
    {
        $reply = $this->buildHelpMessage();
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'music_help']);
    }

    private function buildHelpMessage(): string
    {
        return "*Assistant Musical*\n\n"
            . "Voici ce que je peux faire :\n\n"
            . "*Rechercher* — \"cherche Shape of You\"\n"
            . "*Recommander* — \"recommande du chill\" ou \"musique pour se concentrer\"\n"
            . "*Playlists* — \"playlist pour courir\"\n"
            . "*Top Charts* — \"top charts France\"\n"
            . "*Paroles* — \"paroles de Bohemian Rhapsody\"\n"
            . "*Wishlist* — \"ajoute a ma wishlist [nom]\" ou \"ma wishlist\"\n\n"
            . "Dis-moi ce que tu veux ecouter !";
    }

    private function formatDuration(int $ms): string
    {
        $seconds = intdiv($ms, 1000);
        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $secs);
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
