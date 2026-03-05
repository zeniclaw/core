<?php

return [
    // Transcription provider: 'whisper' (OpenAI) or 'deepgram'
    'provider' => env('VOICE_PROVIDER', 'whisper'),

    // Supported languages for transcription
    'supported_languages' => ['fr', 'en', 'es', 'de', 'it', 'pt', 'ar'],

    // Default language hint for transcription
    'default_language' => env('VOICE_DEFAULT_LANGUAGE', 'fr'),

    // Minimum confidence score (0.0 - 1.0) to accept a transcription
    'min_confidence' => (float) env('VOICE_MIN_CONFIDENCE', 0.8),

    // Enable voice responses (text-to-speech) back to the user
    'enable_voice_responses' => (bool) env('VOICE_ENABLE_RESPONSES', false),
];
