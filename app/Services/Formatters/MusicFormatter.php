<?php

namespace App\Services\Formatters;

class MusicFormatter
{
    /**
     * Format a single track for WhatsApp display.
     */
    public static function formatTrack(array $track, int $index = 0): string
    {
        $name = $track['name'] ?? 'Unknown';
        $artists = isset($track['artists'])
            ? implode(', ', array_map(fn($a) => $a['name'], $track['artists']))
            : ($track['artist'] ?? 'Unknown');
        $album = $track['album']['name'] ?? ($track['album'] ?? '');
        $durationMs = $track['duration_ms'] ?? 0;
        $spotifyUrl = $track['external_urls']['spotify'] ?? ($track['spotify_url'] ?? '');
        $duration = self::formatDuration($durationMs);

        $prefix = $index > 0 ? "{$index}. " : '';
        $line = "{$prefix}*{$name}*\n";
        $line .= "   Artiste: {$artists}\n";

        if ($album) {
            $line .= "   Album: {$album}\n";
        }

        $line .= "   Duree: {$duration}";

        if ($spotifyUrl) {
            $line .= "\n   Spotify: {$spotifyUrl}";
            $youtubeSearch = urlencode("{$name} {$artists}");
            $line .= "\n   YouTube: https://music.youtube.com/search?q={$youtubeSearch}";
        }

        return $line;
    }

    /**
     * Format a list of tracks.
     */
    public static function formatTrackList(array $tracks, string $title = ''): string
    {
        $lines = [];

        if ($title) {
            $lines[] = "*{$title}*\n";
        }

        foreach ($tracks as $i => $track) {
            $lines[] = self::formatTrack($track, $i + 1);
        }

        return implode("\n\n", $lines);
    }

    /**
     * Format a playlist result.
     */
    public static function formatPlaylist(array $playlist, int $index = 0): string
    {
        $name = $playlist['name'] ?? 'Unknown';
        $owner = $playlist['owner']['display_name'] ?? 'Spotify';
        $totalTracks = $playlist['tracks']['total'] ?? 0;
        $url = $playlist['external_urls']['spotify'] ?? '';

        $prefix = $index > 0 ? "{$index}. " : '';
        $line = "{$prefix}*{$name}*\n";
        $line .= "   Par: {$owner} — {$totalTracks} titres";

        if ($url) {
            $line .= "\n   Spotify: {$url}";
        }

        return $line;
    }

    /**
     * Format a wishlist item for display.
     */
    public static function formatWishlistItem(object $item, int $index): string
    {
        $line = "{$index}. *{$item->song_name}*\n";
        $line .= "   Artiste: {$item->artist}";

        if ($item->album) {
            $line .= "\n   Album: {$item->album}";
        }

        if ($item->duration_ms) {
            $line .= "\n   Duree: " . self::formatDuration($item->duration_ms);
        }

        if ($item->spotify_url) {
            $line .= "\n   Spotify: {$item->spotify_url}";
        }

        return $line;
    }

    /**
     * Format duration from milliseconds to M:SS.
     */
    public static function formatDuration(int $ms): string
    {
        if ($ms <= 0) return '0:00';

        $seconds = intdiv($ms, 1000);
        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
