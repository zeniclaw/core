<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
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

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function handleSearch(AgentContext $context, string $query): AgentResult
    {
        $data = $this->spotify->searchTrack($query, 'track', 5);

        if (!$data || empty($data['tracks']['items'])) {
            $reply = "🎵 Aucun resultat trouve pour \"{$query}\". Essaie avec un autre terme !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'music_search', 'query' => $query]);
        }

        $tracks = $data['tracks']['items'];
        $reply = "🎵 *Resultats pour \"{$query}\"* :\n\n";

        foreach ($tracks as $i => $track) {
            $num = $i + 1;
            $name = $track['name'];
            $artists = implode(', ', array_map(fn($a) => $a['name'], $track['artists']));
            $album = $track['album']['name'] ?? '';
            $url = $track['external_urls']['spotify'] ?? '';
            $duration = $this->formatDuration($track['duration_ms'] ?? 0);

            $reply .= "{$num}. 🎤 *{$name}*\n";
            $reply .= "   🎸 {$artists}\n";
            if ($album) {
                $reply .= "   💿 {$album}\n";
            }
            $reply .= "   ⏱ {$duration}";
            if ($url) {
                $reply .= " | 🔗 {$url}";
            }
            $reply .= "\n\n";
        }

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
        return "🎵 *Assistant Musical* 🎵\n\n"
            . "Voici ce que je peux faire :\n\n"
            . "🔍 *Rechercher* — \"cherche Shape of You\"\n"
            . "🎧 *Recommander* — \"recommande du chill\" ou \"musique pour se concentrer\"\n"
            . "📋 *Playlists* — \"playlist pour courir\"\n"
            . "🏆 *Top Charts* — \"top charts France\"\n"
            . "📝 *Paroles* — \"paroles de Bohemian Rhapsody\"\n\n"
            . "Dis-moi ce que tu veux ecouter ! 🎶";
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
