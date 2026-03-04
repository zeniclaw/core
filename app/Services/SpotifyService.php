<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpotifyService
{
    private ?string $clientId;
    private ?string $clientSecret;
    private string $baseUrl = 'https://api.spotify.com/v1';

    public function __construct()
    {
        $this->clientId = config('services.spotify.client_id');
        $this->clientSecret = config('services.spotify.client_secret');
    }

    /**
     * Get a valid access token using Client Credentials flow.
     */
    private function getAccessToken(): ?string
    {
        return Cache::remember('spotify_access_token', 3500, function () {
            if (!$this->clientId || !$this->clientSecret) {
                Log::warning('[spotify] Missing client_id or client_secret');
                return null;
            }

            $response = Http::asForm()->post('https://accounts.spotify.com/api/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            if (!$response->successful()) {
                Log::error('[spotify] Token request failed', ['status' => $response->status()]);
                return null;
            }

            return $response->json('access_token');
        });
    }

    /**
     * Make an authenticated GET request to the Spotify API.
     */
    private function apiGet(string $endpoint, array $query = []): ?array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($token)
                ->get("{$this->baseUrl}{$endpoint}", $query);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('[spotify] API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::error('[spotify] API exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Search for tracks, artists, or playlists.
     */
    public function searchTrack(string $query, string $type = 'track', int $limit = 5): ?array
    {
        return $this->apiGet('/search', [
            'q' => $query,
            'type' => $type,
            'limit' => $limit,
            'market' => 'FR',
        ]);
    }

    /**
     * Get recommendations based on seed artists, genres, or tracks.
     */
    public function getRecommendations(array $params): ?array
    {
        $defaults = [
            'limit' => 5,
            'market' => 'FR',
        ];

        return $this->apiGet('/recommendations', array_merge($defaults, $params));
    }

    /**
     * Get top charts by fetching a well-known Spotify editorial playlist.
     */
    public function getTopCharts(string $country = 'FR', int $limit = 10): ?array
    {
        // Spotify "Top 50" editorial playlists by country
        $playlistIds = [
            'FR' => '37i9dQZEVXbIPWwFssbupI',  // Top 50 France
            'US' => '37i9dQZEVXbLRQDuF5jeBp',  // Top 50 USA
            'GLOBAL' => '37i9dQZEVXbMDoHDwVN2tF', // Top 50 Global
        ];

        $playlistId = $playlistIds[strtoupper($country)] ?? $playlistIds['GLOBAL'];

        $data = $this->apiGet("/playlists/{$playlistId}/tracks", [
            'limit' => $limit,
            'fields' => 'items(track(name,artists,album(name),external_urls,popularity,duration_ms))',
        ]);

        return $data;
    }

    /**
     * Search for a playlist by name.
     */
    public function searchPlaylist(string $query, int $limit = 5): ?array
    {
        return $this->searchTrack($query, 'playlist', $limit);
    }

    /**
     * Get artist info by searching.
     */
    public function searchArtist(string $query, int $limit = 5): ?array
    {
        return $this->searchTrack($query, 'artist', $limit);
    }

    /**
     * Get available genre seeds for recommendations.
     */
    public function getAvailableGenres(): ?array
    {
        return $this->apiGet('/recommendations/available-genre-seeds');
    }

    /**
     * Map a mood/ambiance to Spotify seed genres.
     */
    public function moodToGenres(string $mood): array
    {
        $moodMap = [
            'happy' => ['pop', 'dance', 'happy'],
            'joyeux' => ['pop', 'dance', 'happy'],
            'sad' => ['sad', 'acoustic', 'piano'],
            'triste' => ['sad', 'acoustic', 'piano'],
            'energetic' => ['edm', 'dance', 'work-out'],
            'energie' => ['edm', 'dance', 'work-out'],
            'chill' => ['chill', 'ambient', 'lo-fi'],
            'relax' => ['chill', 'ambient', 'acoustic'],
            'detente' => ['chill', 'ambient', 'acoustic'],
            'focus' => ['study', 'ambient', 'classical'],
            'concentration' => ['study', 'ambient', 'classical'],
            'workout' => ['work-out', 'edm', 'hip-hop'],
            'sport' => ['work-out', 'edm', 'hip-hop'],
            'romantic' => ['romance', 'r-n-b', 'soul'],
            'romantique' => ['romance', 'r-n-b', 'soul'],
            'party' => ['party', 'dance', 'edm'],
            'fete' => ['party', 'dance', 'edm'],
            'sleep' => ['sleep', 'ambient', 'piano'],
            'dormir' => ['sleep', 'ambient', 'piano'],
            'rock' => ['rock', 'alt-rock', 'hard-rock'],
            'jazz' => ['jazz', 'blues'],
            'classical' => ['classical', 'piano'],
            'classique' => ['classical', 'piano'],
            'hip-hop' => ['hip-hop', 'rap'],
            'rap' => ['hip-hop', 'rap'],
            'electro' => ['electronic', 'edm', 'house'],
            'metal' => ['metal', 'hard-rock'],
            'country' => ['country', 'folk'],
            'folk' => ['folk', 'acoustic', 'singer-songwriter'],
            'latin' => ['latin', 'reggaeton', 'salsa'],
            'reggae' => ['reggae', 'ska'],
            'rnb' => ['r-n-b', 'soul'],
            'soul' => ['soul', 'r-n-b'],
            'funk' => ['funk', 'disco'],
            'blues' => ['blues', 'jazz'],
            'pop' => ['pop', 'synth-pop'],
            'indie' => ['indie', 'indie-pop', 'alt-rock'],
        ];

        $key = mb_strtolower(trim($mood));

        return $moodMap[$key] ?? ['pop'];
    }
}
