<?php

namespace App\Services\Agents;

use App\Models\UserPreference;
use App\Services\AgentContext;
use App\Services\PreferencesManager;
use Illuminate\Support\Facades\Log;

class UserPreferencesAgent extends BaseAgent
{
    private const VALID_LANGUAGES = ['fr', 'en', 'es', 'de', 'it', 'pt', 'ar', 'zh', 'ja', 'ko', 'ru', 'nl'];
    private const VALID_UNIT_SYSTEMS = ['metric', 'imperial'];
    private const VALID_DATE_FORMATS = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y', 'd-m-Y'];
    private const VALID_STYLES = ['friendly', 'formal', 'concise', 'detailed', 'casual'];

    private const LANGUAGE_LABELS = [
        'fr' => 'Francais', 'en' => 'English', 'es' => 'Espanol',
        'de' => 'Deutsch', 'it' => 'Italiano', 'pt' => 'Portugues',
        'ar' => 'Arabic', 'zh' => 'Chinese', 'ja' => 'Japanese',
        'ko' => 'Korean', 'ru' => 'Russian', 'nl' => 'Dutch',
    ];

    public function name(): string
    {
        return 'user_preferences';
    }

    public function description(): string
    {
        return 'Agent de gestion des preferences utilisateur. Permet de configurer la langue, le fuseau horaire, le format de date, le systeme d\'unites (metrique/imperial), le style de communication, les notifications, le telephone et l\'email. Affiche le profil complet et permet de modifier chaque parametre individuellement.';
    }

    public function keywords(): array
    {
        return [
            'preferences', 'preference', 'profil', 'profile',
            'set language', 'changer langue', 'langue', 'language',
            'set timezone', 'fuseau horaire', 'timezone',
            'set date_format', 'format date', 'date format',
            'set unit_system', 'systeme unites', 'unit system', 'metric', 'imperial',
            'communication style', 'style communication', 'style',
            'notifications', 'notification', 'activer notifications', 'desactiver notifications',
            'mon profil', 'my profile', 'show preferences', 'voir preferences',
            'mes preferences', 'my preferences', 'mes reglages', 'settings',
            'configurer', 'configure', 'parametres', 'reglages',
            'mon email', 'my email', 'mon telephone', 'my phone',
            'set email', 'set phone', 'changer email', 'changer telephone',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'user_preferences';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $userId = $context->from;

        $this->log($context, "Processing preferences command", ['body' => mb_substr($body, 0, 100)]);

        // Use Claude to parse the user intent
        $prefs = PreferencesManager::getPreferences($userId);

        $systemPrompt = $this->buildSystemPrompt($prefs);
        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"",
            $this->resolveModel($context),
            $systemPrompt
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            return AgentResult::reply($this->formatShowPreferences($prefs));
        }

        return match ($parsed['action']) {
            'show' => AgentResult::reply($this->formatShowPreferences($prefs), ['action' => 'show_preferences']),
            'set' => $this->handleSet($context, $userId, $parsed, $prefs),
            'help' => AgentResult::reply($this->formatHelp(), ['action' => 'preferences_help']),
            default => AgentResult::reply($this->formatShowPreferences($prefs)),
        };
    }

    private function buildSystemPrompt(array $currentPrefs): string
    {
        $prefsJson = json_encode($currentPrefs, JSON_UNESCAPED_UNICODE);
        $validLanguages = implode(', ', self::VALID_LANGUAGES);
        $validUnits = implode(', ', self::VALID_UNIT_SYSTEMS);
        $validDateFormats = implode(', ', self::VALID_DATE_FORMATS);
        $validStyles = implode(', ', self::VALID_STYLES);

        return <<<PROMPT
Tu es un agent de gestion des preferences utilisateur.

PREFERENCES ACTUELLES:
{$prefsJson}

CLES VALIDES ET VALEURS ACCEPTEES:
- language: {$validLanguages}
- timezone: tout fuseau horaire valide (ex: Europe/Paris, America/New_York, UTC, UTC+2, Asia/Tokyo)
- date_format: {$validDateFormats}
- unit_system: {$validUnits}
- communication_style: {$validStyles}
- notification_enabled: true / false
- phone: numero de telephone
- email: adresse email

Analyse le message et reponds UNIQUEMENT en JSON:

Pour afficher les prefs:
{"action": "show"}

Pour modifier une pref:
{"action": "set", "key": "language", "value": "en"}

Pour aide:
{"action": "help"}

REGLES:
- "set language en" → {"action": "set", "key": "language", "value": "en"}
- "mets en francais" → {"action": "set", "key": "language", "value": "fr"}
- "fuseau horaire UTC+2" → {"action": "set", "key": "timezone", "value": "UTC+2"}
- "timezone Europe/Paris" → {"action": "set", "key": "timezone", "value": "Europe/Paris"}
- "format americain" → {"action": "set", "key": "date_format", "value": "m/d/Y"}
- "metrique" → {"action": "set", "key": "unit_system", "value": "metric"}
- "imperial" → {"action": "set", "key": "unit_system", "value": "imperial"}
- "style formel" → {"action": "set", "key": "communication_style", "value": "formal"}
- "desactiver notifications" → {"action": "set", "key": "notification_enabled", "value": false}
- "mon profil" / "show preferences" / "mes preferences" → {"action": "show"}
- Si le message est ambigu ou demande de l'aide → {"action": "help"}

Reponds UNIQUEMENT avec le JSON, rien d'autre.
PROMPT;
    }

    private function handleSet(AgentContext $context, string $userId, array $parsed, array $currentPrefs): AgentResult
    {
        $key = $parsed['key'] ?? null;
        $value = $parsed['value'] ?? null;

        if (!$key || $value === null) {
            return AgentResult::reply("Je n'ai pas compris quelle preference modifier. Tape *show preferences* pour voir tes parametres actuels.");
        }

        if (!in_array($key, UserPreference::$validKeys)) {
            $validKeys = implode(', ', UserPreference::$validKeys);
            return AgentResult::reply("Cle invalide *{$key}*. Cles valides : {$validKeys}");
        }

        // Validate values
        $validationError = $this->validateValue($key, $value);
        if ($validationError) {
            return AgentResult::reply($validationError);
        }

        // Cast boolean for notification_enabled
        if ($key === 'notification_enabled') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $oldValue = $currentPrefs[$key] ?? 'non defini';
        $success = PreferencesManager::setPreference($userId, $key, $value);

        if (!$success) {
            return AgentResult::reply("Erreur lors de la mise a jour de *{$key}*. Reessaie.");
        }

        $this->log($context, "Preference updated: {$key}", [
            'key' => $key,
            'old_value' => $oldValue,
            'new_value' => $value,
        ]);

        $displayValue = $this->formatValue($key, $value);
        $displayOld = $this->formatValue($key, $oldValue);

        $reply = "Preference mise a jour !\n\n"
            . "*{$this->formatKeyLabel($key)}*: {$displayOld} → {$displayValue}";

        return AgentResult::reply($reply, ['action' => 'set_preference', 'key' => $key, 'value' => $value]);
    }

    private function validateValue(string $key, mixed $value): ?string
    {
        return match ($key) {
            'language' => !in_array($value, self::VALID_LANGUAGES)
                ? "Langue invalide. Langues supportees : " . implode(', ', array_map(fn ($l) => "*{$l}* (" . (self::LANGUAGE_LABELS[$l] ?? $l) . ")", self::VALID_LANGUAGES))
                : null,
            'unit_system' => !in_array($value, self::VALID_UNIT_SYSTEMS)
                ? "Systeme d'unites invalide. Valeurs acceptees : *metric*, *imperial*"
                : null,
            'date_format' => !in_array($value, self::VALID_DATE_FORMATS)
                ? "Format de date invalide. Formats acceptes : " . implode(', ', array_map(fn ($f) => "*{$f}*", self::VALID_DATE_FORMATS))
                : null,
            'communication_style' => !in_array($value, self::VALID_STYLES)
                ? "Style invalide. Styles acceptes : " . implode(', ', array_map(fn ($s) => "*{$s}*", self::VALID_STYLES))
                : null,
            'email' => ($value && !filter_var($value, FILTER_VALIDATE_EMAIL))
                ? "Adresse email invalide."
                : null,
            default => null,
        };
    }

    private function formatShowPreferences(array $prefs): string
    {
        $langLabel = self::LANGUAGE_LABELS[$prefs['language']] ?? $prefs['language'];
        $notif = $prefs['notification_enabled'] ? 'Activees' : 'Desactivees';

        $lines = [
            "MON PROFIL\n",
            "Langue : *{$langLabel}* ({$prefs['language']})",
            "Fuseau horaire : *{$prefs['timezone']}*",
            "Format date : *{$prefs['date_format']}*",
            "Unites : *{$prefs['unit_system']}*",
            "Style : *{$prefs['communication_style']}*",
            "Notifications : *{$notif}*",
            "Telephone : " . ($prefs['phone'] ? "*{$prefs['phone']}*" : '_non defini_'),
            "Email : " . ($prefs['email'] ? "*{$prefs['email']}*" : '_non defini_'),
            "\nExemples de commandes :",
            "- _set language en_",
            "- _set timezone America/New_York_",
            "- _set unit_system imperial_",
            "- _set communication_style formal_",
        ];

        return implode("\n", $lines);
    }

    private function formatHelp(): string
    {
        $lines = [
            "AIDE PREFERENCES\n",
            "Commandes disponibles :",
            "",
            "*Voir mon profil :*",
            "- _show preferences_ / _mon profil_ / _mes preferences_",
            "",
            "*Changer la langue :*",
            "- _set language en_ / _mets en francais_",
            "- Langues : " . implode(', ', self::VALID_LANGUAGES),
            "",
            "*Changer le fuseau horaire :*",
            "- _set timezone Europe/Paris_",
            "- _fuseau horaire UTC+2_",
            "",
            "*Changer le format de date :*",
            "- _set date_format m/d/Y_ (americain)",
            "- Formats : " . implode(', ', self::VALID_DATE_FORMATS),
            "",
            "*Systeme d'unites :*",
            "- _set unit_system metric_ / _imperial_",
            "",
            "*Style de communication :*",
            "- _set communication_style formal_",
            "- Styles : " . implode(', ', self::VALID_STYLES),
            "",
            "*Notifications :*",
            "- _activer notifications_ / _desactiver notifications_",
            "",
            "*Contact :*",
            "- _set email john@example.com_",
            "- _set phone +33612345678_",
        ];

        return implode("\n", $lines);
    }

    private function formatKeyLabel(string $key): string
    {
        return match ($key) {
            'language' => 'Langue',
            'timezone' => 'Fuseau horaire',
            'date_format' => 'Format date',
            'unit_system' => 'Unites',
            'communication_style' => 'Style',
            'notification_enabled' => 'Notifications',
            'phone' => 'Telephone',
            'email' => 'Email',
            default => $key,
        };
    }

    private function formatValue(string $key, mixed $value): string
    {
        if ($value === null || $value === 'non defini') {
            return '_non defini_';
        }

        return match ($key) {
            'language' => (self::LANGUAGE_LABELS[$value] ?? $value) . " ({$value})",
            'notification_enabled' => $value ? 'Activees' : 'Desactivees',
            default => (string) $value,
        };
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

        return json_decode($clean, true);
    }
}
