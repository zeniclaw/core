<?php

namespace App\Services\Agents;

use App\Models\UserPreference;
use App\Services\AgentContext;
use App\Services\PreferencesManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UserPreferencesAgent extends BaseAgent
{
    private const VALID_LANGUAGES = ['fr', 'en', 'es', 'de', 'it', 'pt', 'ar', 'zh', 'ja', 'ko', 'ru', 'nl', 'tr', 'pl', 'sv', 'da', 'fi', 'no', 'cs', 'hu', 'ro', 'uk', 'hi', 'th', 'vi', 'id'];
    private const VALID_UNIT_SYSTEMS = ['metric', 'imperial'];
    private const VALID_DATE_FORMATS = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y', 'd-m-Y'];
    private const VALID_STYLES = ['friendly', 'formal', 'concise', 'detailed', 'casual'];
    private const VALID_THEMES = ['light', 'dark', 'auto'];
    private const CHANGE_HISTORY_LIMIT = 20;
    private const CHANGE_HISTORY_TTL = 2592000; // 30 days

    private const LANGUAGE_LABELS = [
        'fr' => 'Francais', 'en' => 'English', 'es' => 'Espanol',
        'de' => 'Deutsch', 'it' => 'Italiano', 'pt' => 'Portugues',
        'ar' => 'Arabic', 'zh' => 'Chinese', 'ja' => 'Japanese',
        'ko' => 'Korean', 'ru' => 'Russian', 'nl' => 'Dutch',
        'tr' => 'Turkish', 'pl' => 'Polish', 'sv' => 'Swedish',
        'da' => 'Danish', 'fi' => 'Finnish', 'no' => 'Norwegian',
        'cs' => 'Czech', 'hu' => 'Hungarian', 'ro' => 'Romanian',
        'uk' => 'Ukrainian', 'hi' => 'Hindi', 'th' => 'Thai',
        'vi' => 'Vietnamese', 'id' => 'Indonesian',
    ];

    private const KEY_LABELS = [
        'language' => 'Langue',
        'timezone' => 'Fuseau horaire',
        'date_format' => 'Format date',
        'unit_system' => 'Unites',
        'communication_style' => 'Style',
        'theme' => 'Theme',
        'notification_enabled' => 'Notifications',
        'phone' => 'Telephone',
        'email' => 'Email',
    ];

    private const KEY_LABELS_EN = [
        'language' => 'Language',
        'timezone' => 'Timezone',
        'date_format' => 'Date format',
        'unit_system' => 'Units',
        'communication_style' => 'Style',
        'theme' => 'Theme',
        'notification_enabled' => 'Notifications',
        'phone' => 'Phone',
        'email' => 'Email',
    ];

    private const COMMON_TIMEZONES = [
        'Europe/Paris', 'Europe/London', 'Europe/Berlin', 'Europe/Madrid',
        'Europe/Rome', 'Europe/Brussels', 'Europe/Amsterdam', 'Europe/Zurich',
        'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'America/Toronto', 'America/Sao_Paulo', 'America/Mexico_City',
        'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Dubai', 'Asia/Kolkata',
        'Asia/Singapore', 'Asia/Seoul', 'Asia/Bangkok',
        'Africa/Casablanca', 'Africa/Cairo', 'Africa/Lagos',
        'Australia/Sydney', 'Pacific/Auckland',
        'UTC', 'GMT',
    ];

    public function name(): string
    {
        return 'user_preferences';
    }

    public function description(): string
    {
        return 'Agent de gestion des preferences utilisateur. Configure langue, fuseau horaire, format de date, systeme d\'unites, style de communication, notifications, telephone, email. Affiche le profil complet, modifie chaque parametre, reset, export/import des preferences. Supporte les modifications multiples, l\'historique des changements et la validation avancee des fuseaux horaires.';
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
            // v1.1.0 — reset & export
            'reset preferences', 'reinitialiser preferences', 'effacer preferences',
            'exporter preferences', 'export preferences', 'backup preferences',
            // v1.1.0 — multi-set
            'changer plusieurs', 'modifier plusieurs', 'set multiple',
            // v1.1.0 — quick shortcuts
            'passe en anglais', 'switch to english', 'switch to french', 'passe en francais',
            'mode formel', 'mode decontracte', 'mode concis', 'mode detaille',
            // v1.1.0 — theme
            'theme', 'mode sombre', 'mode clair', 'dark mode', 'light mode',
            // v1.2.0 — import & history
            'import preferences', 'importer preferences', 'restaurer preferences',
            'historique preferences', 'preference history', 'changes history',
            'derniers changements', 'recent changes',
            // v1.2.0 — timezone helpers
            'quelle heure', 'what time', 'heure actuelle', 'current time',
            'fuseaux disponibles', 'available timezones', 'liste fuseaux',
        ];
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function intents(): array
    {
        return [
            [
                'key' => 'show',
                'description' => 'Afficher le profil et les preferences actuelles',
                'examples' => ['mon profil', 'show preferences', 'mes preferences', 'mes reglages'],
            ],
            [
                'key' => 'set_single',
                'description' => 'Modifier une seule preference (langue, timezone, style, etc.)',
                'examples' => ['set language en', 'mets en francais', 'timezone UTC+2', 'style formel'],
            ],
            [
                'key' => 'set_multiple',
                'description' => 'Modifier plusieurs preferences en une seule commande',
                'examples' => ['langue en, timezone UTC, style concis', 'mets anglais et formel'],
            ],
            [
                'key' => 'reset',
                'description' => 'Reinitialiser toutes les preferences aux valeurs par defaut',
                'examples' => ['reset preferences', 'reinitialiser mes preferences', 'valeurs par defaut'],
            ],
            [
                'key' => 'export',
                'description' => 'Exporter les preferences en format texte/JSON',
                'examples' => ['exporter mes preferences', 'export preferences', 'backup mes reglages'],
            ],
            [
                'key' => 'help',
                'description' => 'Afficher l\'aide et les commandes disponibles',
                'examples' => ['aide preferences', 'help settings', 'comment configurer'],
            ],
            [
                'key' => 'import',
                'description' => 'Importer des preferences depuis un JSON',
                'examples' => ['import preferences {"language":"en"}', 'restaurer preferences'],
            ],
            [
                'key' => 'history',
                'description' => 'Afficher l\'historique des changements de preferences',
                'examples' => ['historique preferences', 'derniers changements', 'preference history'],
            ],
            [
                'key' => 'current_time',
                'description' => 'Afficher l\'heure actuelle dans le fuseau configure',
                'examples' => ['quelle heure', 'heure actuelle', 'current time'],
            ],
            [
                'key' => 'list_timezones',
                'description' => 'Lister les fuseaux horaires courants',
                'examples' => ['fuseaux disponibles', 'available timezones', 'liste fuseaux'],
            ],
        ];
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'user_preferences';
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        if (($pendingContext['type'] ?? '') !== 'reset_confirmation') {
            return null;
        }

        $body = mb_strtolower(trim($context->body ?? ''));
        $this->clearPendingContext($context);

        if (preg_match('/\b(oui|yes|ok|confirmer?|confirme|go)\b/iu', $body)) {
            return $this->executeReset($context);
        }

        $reply = "↩️ *Reset annule.* Tes preferences sont conservees.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'reset_cancelled']);
    }

    public function handle(AgentContext $context): AgentResult
    {
        try {
            return $this->dispatch($context);
        } catch (\Throwable $e) {
            Log::error('UserPreferencesAgent handle exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $reply = "Erreur inattendue lors du traitement de tes preferences. Reessaie dans un instant.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
    }

    private function dispatch(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $userId = $context->from;
        $lower = mb_strtolower($body);

        $this->log($context, "Processing preferences command", ['body' => mb_substr($body, 0, 100)]);

        $prefs = PreferencesManager::getPreferences($userId);

        // Quick regex shortcuts before intent classification
        // Reset
        if (preg_match('/\b(reset|reinitialiser|r[eé]initialiser)\s+(preferences?|profil|reglages|settings)\b/iu', $lower)) {
            return $this->handleReset($context);
        }

        // Export
        if (preg_match('/\b(export|exporter|backup)\s+(preferences?|profil|reglages|settings)\b/iu', $lower)) {
            return $this->handleExport($context, $prefs);
        }

        // Import
        if (preg_match('/\b(import|importer|restaurer)\s+(preferences?|profil|reglages|settings)\b/iu', $lower)) {
            return $this->handleImport($context, $body, $prefs);
        }

        // History
        if (preg_match('/\b(historique|history|derniers?\s+changements?|recent\s+changes?)\b/iu', $lower)) {
            return $this->handleHistory($context);
        }

        // Current time
        if (preg_match('/\b(quelle\s+heure|what\s+time|heure\s+actuelle|current\s+time)\b/iu', $lower)) {
            return $this->handleCurrentTime($context, $prefs);
        }

        // List timezones
        if (preg_match('/\b(fuseaux?\s+disponibles?|available\s+timezones?|liste\s+fuseaux?)\b/iu', $lower)) {
            return $this->handleListTimezones($context);
        }

        // Quick language switches
        if (preg_match('/\b(passe|switch)\s+(en|to)\s+(anglais|english|francais|french|espagnol|spanish)\b/iu', $lower, $m)) {
            $langMap = ['anglais' => 'en', 'english' => 'en', 'francais' => 'fr', 'french' => 'fr', 'espagnol' => 'es', 'spanish' => 'es'];
            $lang = $langMap[mb_strtolower($m[3])] ?? null;
            if ($lang) {
                return $this->applySet($context, $userId, 'language', $lang, $prefs);
            }
        }

        // Quick theme switches
        if (preg_match('/\b(mode\s+sombre|dark\s*mode|theme\s+dark)\b/iu', $lower)) {
            return $this->applySet($context, $userId, 'theme', 'dark', $prefs);
        }
        if (preg_match('/\b(mode\s+clair|light\s*mode|theme\s+light)\b/iu', $lower)) {
            return $this->applySet($context, $userId, 'theme', 'light', $prefs);
        }
        if (preg_match('/\b(theme\s+auto)\b/iu', $lower)) {
            return $this->applySet($context, $userId, 'theme', 'auto', $prefs);
        }

        // Quick style mode switches
        if (preg_match('/\bmode\s+(formel|decontracte|concis|detaille|friendly|formal|concise|detailed|casual)\b/iu', $lower, $m)) {
            $styleMap = ['formel' => 'formal', 'decontracte' => 'casual', 'concis' => 'concise', 'detaille' => 'detailed', 'friendly' => 'friendly', 'formal' => 'formal', 'concise' => 'concise', 'detailed' => 'detailed', 'casual' => 'casual'];
            $style = $styleMap[mb_strtolower($m[1])] ?? null;
            if ($style) {
                return $this->applySet($context, $userId, 'communication_style', $style, $prefs);
            }
        }

        // Help
        if (preg_match('/\b(aide|help|commandes|comment)\b/iu', $lower)) {
            return AgentResult::reply($this->formatHelp(), ['action' => 'preferences_help']);
        }

        // Show preferences (explicit)
        if (preg_match('/\b(mon\s+profil|my\s+profile|show\s+preferences?|voir\s+preferences?|mes\s+preferences?|mes\s+reglages|my\s+preferences?|my\s+settings)\b/iu', $lower)) {
            return AgentResult::reply($this->formatShowPreferences($prefs), ['action' => 'show_preferences']);
        }

        // Use Claude to parse the user intent for set commands
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
            'set' => $this->handleSetFromParsed($context, $userId, $parsed, $prefs),
            'set_multiple' => $this->handleSetMultiple($context, $userId, $parsed, $prefs),
            'help' => AgentResult::reply($this->formatHelp(), ['action' => 'preferences_help']),
            'reset' => $this->handleReset($context),
            'export' => $this->handleExport($context, $prefs),
            'import' => $this->handleImport($context, $body, $prefs),
            'history' => $this->handleHistory($context),
            'current_time' => $this->handleCurrentTime($context, $prefs),
            'list_timezones' => $this->handleListTimezones($context),
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
        $validThemes = implode(', ', self::VALID_THEMES);

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
- theme: {$validThemes}
- notification_enabled: true / false
- phone: numero de telephone
- email: adresse email

Analyse le message et reponds UNIQUEMENT en JSON:

Pour afficher les prefs:
{"action": "show"}

Pour modifier une pref:
{"action": "set", "key": "language", "value": "en"}

Pour modifier PLUSIEURS prefs en une seule commande:
{"action": "set_multiple", "changes": [{"key": "language", "value": "en"}, {"key": "communication_style", "value": "formal"}]}

Pour reinitialiser:
{"action": "reset"}

Pour exporter:
{"action": "export"}

Pour importer (JSON dans le message):
{"action": "import"}

Pour l'historique des changements:
{"action": "history"}

Pour l'heure actuelle:
{"action": "current_time"}

Pour lister les fuseaux horaires:
{"action": "list_timezones"}

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
- "mode sombre" / "dark mode" → {"action": "set", "key": "theme", "value": "dark"}
- "mode clair" / "light mode" → {"action": "set", "key": "theme", "value": "light"}
- "theme auto" → {"action": "set", "key": "theme", "value": "auto"}
- "mon profil" / "show preferences" / "mes preferences" → {"action": "show"}
- "langue en et style formel" → {"action": "set_multiple", "changes": [{"key": "language", "value": "en"}, {"key": "communication_style", "value": "formal"}]}
- "importer mes preferences" ou message contenant du JSON → {"action": "import"}
- "historique" / "derniers changements" → {"action": "history"}
- "quelle heure" / "heure actuelle" → {"action": "current_time"}
- "fuseaux disponibles" / "liste fuseaux" → {"action": "list_timezones"}
- Si le message est ambigu ou demande de l'aide → {"action": "help"}

Reponds UNIQUEMENT avec le JSON, rien d'autre.
PROMPT;
    }

    private function handleSetFromParsed(AgentContext $context, string $userId, array $parsed, array $currentPrefs): AgentResult
    {
        $key = $parsed['key'] ?? null;
        $value = $parsed['value'] ?? null;

        if (!$key || $value === null) {
            return AgentResult::reply("Je n'ai pas compris quelle preference modifier. Tape *show preferences* pour voir tes parametres actuels.");
        }

        return $this->applySet($context, $userId, $key, $value, $currentPrefs);
    }

    private function handleSetMultiple(AgentContext $context, string $userId, array $parsed, array $currentPrefs): AgentResult
    {
        $changes = $parsed['changes'] ?? [];
        if (empty($changes)) {
            return AgentResult::reply("Aucune modification detectee. Tape *help preferences* pour l'aide.");
        }

        $results = [];
        $errors = [];
        $updatedPrefs = $currentPrefs;

        foreach ($changes as $change) {
            $key = $change['key'] ?? null;
            $value = $change['value'] ?? null;

            if (!$key || $value === null) {
                continue;
            }

            if (!in_array($key, UserPreference::$validKeys)) {
                $errors[] = "Cle invalide : *{$key}*";
                continue;
            }

            $validationError = $this->validateValue($key, $value);
            if ($validationError) {
                $errors[] = $validationError;
                continue;
            }

            if ($key === 'notification_enabled') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            $oldValue = $updatedPrefs[$key] ?? 'non defini';
            $success = PreferencesManager::setPreference($userId, $key, $value);

            if ($success) {
                $updatedPrefs[$key] = $value;
                $this->recordChange($userId, $key, $oldValue, $value);
                $displayValue = $this->formatValue($key, $value);
                $displayOld = $this->formatValue($key, $oldValue);
                $label = $this->getKeyLabel($key, $currentPrefs['language'] ?? 'fr');
                $results[] = "*{$label}*: {$displayOld} → {$displayValue}";

                $this->log($context, "Preference updated: {$key}", [
                    'key' => $key,
                    'old_value' => $oldValue,
                    'new_value' => $value,
                ]);
            } else {
                $errors[] = "Erreur lors de la mise a jour de *{$key}*";
            }
        }

        $lines = [];
        if (!empty($results)) {
            $count = count($results);
            $lines[] = "✅ *{$count} preference(s) mise(s) a jour :*\n";
            $lines = array_merge($lines, array_map(fn($r) => "• {$r}", $results));
        }
        if (!empty($errors)) {
            $lines[] = "\n⚠️ *Erreurs :*";
            $lines = array_merge($lines, array_map(fn($e) => "• {$e}", $errors));
        }

        if (empty($results) && empty($errors)) {
            return AgentResult::reply("Aucune modification effectuee.");
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'set_multiple', 'count' => count($results)]);
    }

    private function applySet(AgentContext $context, string $userId, string $key, mixed $value, array $currentPrefs): AgentResult
    {
        if (!in_array($key, UserPreference::$validKeys)) {
            $validKeys = implode(', ', UserPreference::$validKeys);
            return AgentResult::reply("Cle invalide *{$key}*. Cles valides : {$validKeys}");
        }

        $validationError = $this->validateValue($key, $value);
        if ($validationError) {
            return AgentResult::reply($validationError);
        }

        if ($key === 'notification_enabled') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $oldValue = $currentPrefs[$key] ?? 'non defini';

        // Skip if value unchanged
        if ((string) $oldValue === (string) $value) {
            $displayValue = $this->formatValue($key, $value);
            $label = self::KEY_LABELS[$key] ?? $key;
            $reply = "ℹ️ *{$label}* est deja configure sur {$displayValue}.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'set_preference_unchanged', 'key' => $key]);
        }

        $success = PreferencesManager::setPreference($userId, $key, $value);

        if (!$success) {
            return AgentResult::reply("Erreur lors de la mise a jour de *{$key}*. Reessaie.");
        }

        $this->log($context, "Preference updated: {$key}", [
            'key' => $key,
            'old_value' => $oldValue,
            'new_value' => $value,
        ]);

        $this->recordChange($userId, $key, $oldValue, $value);

        $displayValue = $this->formatValue($key, $value);
        $displayOld = $this->formatValue($key, $oldValue);
        $label = $this->getKeyLabel($key, $currentPrefs['language'] ?? 'fr');

        $reply = "✅ Preference mise a jour !\n\n"
            . "*{$label}*: {$displayOld} → {$displayValue}";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'set_preference', 'key' => $key, 'value' => $value]);
    }

    private function handleReset(AgentContext $context): AgentResult
    {
        $this->setPendingContext($context, 'reset_confirmation', [], 2);

        $reply = "⚠️ *Reinitialiser les preferences ?*\n\n"
            . "Cela va remettre TOUTES tes preferences aux valeurs par defaut :\n"
            . "• Langue → Francais\n"
            . "• Fuseau horaire → Europe/Paris\n"
            . "• Format date → d/m/Y\n"
            . "• Unites → metric\n"
            . "• Style → friendly\n"
            . "• Theme → auto\n"
            . "• Notifications → Activees\n\n"
            . "Reponds *oui* pour confirmer ou *non* pour annuler.";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'reset_confirmation_pending']);
    }

    private function executeReset(AgentContext $context): AgentResult
    {
        $userId = $context->from;
        $defaults = [
            'language' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'unit_system' => 'metric',
            'communication_style' => 'friendly',
            'theme' => 'auto',
            'notification_enabled' => true,
        ];

        foreach ($defaults as $key => $value) {
            PreferencesManager::setPreference($userId, $key, $value);
        }

        $this->log($context, "Preferences reset to defaults");

        $reply = "✅ *Preferences reinitialisees !*\n\nToutes tes preferences sont revenues aux valeurs par defaut.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'reset_completed']);
    }

    private function handleExport(AgentContext $context, array $prefs): AgentResult
    {
        $langLabel = self::LANGUAGE_LABELS[$prefs['language']] ?? $prefs['language'];
        $notif = ($prefs['notification_enabled'] ?? false) ? 'true' : 'false';

        $jsonExport = json_encode($prefs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $lines = [
            "📋 *Export de tes preferences*\n",
            "```json",
            $jsonExport,
            "```",
            "\nTu peux copier ce JSON pour le sauvegarder.",
        ];

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'export_preferences']);
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
            'theme' => !in_array($value, self::VALID_THEMES)
                ? "Theme invalide. Valeurs acceptees : " . implode(', ', array_map(fn ($t) => "*{$t}*", self::VALID_THEMES))
                : null,
            'timezone' => !$this->isValidTimezone($value)
                ? "Fuseau horaire invalide : *{$value}*.\nExemples valides : Europe/Paris, America/New_York, UTC, Asia/Tokyo.\nTape *fuseaux disponibles* pour voir la liste."
                : null,
            'email' => ($value && !filter_var($value, FILTER_VALIDATE_EMAIL))
                ? "Adresse email invalide."
                : null,
            'phone' => ($value && !preg_match('/^\+?[0-9\s\-().]{7,20}$/', $value))
                ? "Numero de telephone invalide. Utilise le format international (ex: +33612345678)."
                : null,
            default => null,
        };
    }

    private function isValidTimezone(string $tz): bool
    {
        // Accept UTC offset formats
        if (preg_match('/^UTC([+-]\d{1,2}(:\d{2})?)?$/i', $tz)) {
            return true;
        }
        if (preg_match('/^GMT([+-]\d{1,2}(:\d{2})?)?$/i', $tz)) {
            return true;
        }

        try {
            new \DateTimeZone($tz);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function formatShowPreferences(array $prefs): string
    {
        $langLabel = self::LANGUAGE_LABELS[$prefs['language']] ?? $prefs['language'];
        $notif = ($prefs['notification_enabled'] ?? false) ? 'Activees' : 'Desactivees';

        $lines = [
            "👤 *MON PROFIL*\n",
            "🌐 Langue : *{$langLabel}* ({$prefs['language']})",
            "🕐 Fuseau horaire : *{$prefs['timezone']}*",
            "📅 Format date : *{$prefs['date_format']}*",
            "📏 Unites : *{$prefs['unit_system']}*",
            "💬 Style : *{$prefs['communication_style']}*",
            "🎨 Theme : *" . ($prefs['theme'] ?? 'auto') . "*",
            "🔔 Notifications : *{$notif}*",
            "📱 Telephone : " . ($prefs['phone'] ? "*{$prefs['phone']}*" : '_non defini_'),
            "📧 Email : " . ($prefs['email'] ? "*{$prefs['email']}*" : '_non defini_'),
            "\n─────────────────",
            "💡 *Commandes rapides :*",
            "• _set language en_ — changer la langue",
            "• _set timezone UTC+2_ — fuseau horaire",
            "• _mode formel_ — style formel",
            "• _passe en anglais_ — raccourci langue",
            "• _reset preferences_ — tout reinitialiser",
            "• _export preferences_ — exporter en JSON",
            "• _historique preferences_ — voir les changements",
            "• _quelle heure_ — heure dans ton fuseau",
        ];

        return implode("\n", $lines);
    }

    private function formatHelp(): string
    {
        $lines = [
            "⚙️ *AIDE PREFERENCES*\n",
            "*Voir mon profil :*",
            "• _show preferences_ / _mon profil_ / _mes preferences_",
            "",
            "*Changer la langue :*",
            "• _set language en_ / _mets en francais_",
            "• _passe en anglais_ / _switch to french_",
            "• Langues : " . implode(', ', array_slice(self::VALID_LANGUAGES, 0, 12)) . "...",
            "",
            "*Fuseau horaire :*",
            "• _set timezone Europe/Paris_",
            "• _fuseau horaire UTC+2_",
            "",
            "*Format de date :*",
            "• _set date_format m/d/Y_ (americain)",
            "• Formats : " . implode(', ', self::VALID_DATE_FORMATS),
            "",
            "*Systeme d'unites :*",
            "• _set unit_system metric_ / _imperial_",
            "",
            "*Style de communication :*",
            "• _mode formel_ / _mode concis_ / _mode decontracte_",
            "• Styles : " . implode(', ', self::VALID_STYLES),
            "",
            "*Notifications :*",
            "• _activer notifications_ / _desactiver notifications_",
            "",
            "*Contact :*",
            "• _set email john@example.com_",
            "• _set phone +33612345678_",
            "",
            "*Theme :*",
            "• _mode sombre_ / _dark mode_ / _mode clair_ / _light mode_",
            "• Themes : " . implode(', ', self::VALID_THEMES),
            "",
            "*Modifier plusieurs d'un coup :*",
            "• _langue en et style formel_",
            "• _set language en, timezone UTC, style concise_",
            "",
            "*Reset, Export & Import :*",
            "• _reset preferences_ — remet tout par defaut",
            "• _export preferences_ — exporte en JSON",
            "• _import preferences {\"language\":\"en\"}_ — importer",
            "",
            "*Historique & Fuseau :*",
            "• _historique preferences_ — derniers changements",
            "• _quelle heure_ — heure dans ton fuseau",
            "• _fuseaux disponibles_ — liste des fuseaux",
        ];

        return implode("\n", $lines);
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

    private function handleImport(AgentContext $context, string $body, array $currentPrefs): AgentResult
    {
        $userId = $context->from;

        // Try to extract JSON from the message
        $json = null;
        if (preg_match('/\{[^}]+\}/s', $body, $m)) {
            $json = json_decode($m[0], true);
        }

        if (!$json || !is_array($json)) {
            $reply = "📥 *Import de preferences*\n\n"
                . "Pour importer, envoie un JSON avec tes preferences. Exemple :\n\n"
                . "```\nimport preferences {\"language\":\"en\",\"timezone\":\"UTC\",\"communication_style\":\"formal\"}\n```\n\n"
                . "Tu peux obtenir ton JSON actuel avec _export preferences_.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'import_help']);
        }

        $imported = [];
        $errors = [];

        foreach ($json as $key => $value) {
            if (!in_array($key, UserPreference::$validKeys)) {
                $errors[] = "Cle ignoree : *{$key}*";
                continue;
            }

            $validationError = $this->validateValue($key, $value);
            if ($validationError) {
                $errors[] = $validationError;
                continue;
            }

            if ($key === 'notification_enabled') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            $oldValue = $currentPrefs[$key] ?? 'non defini';
            $success = PreferencesManager::setPreference($userId, $key, $value);

            if ($success) {
                $this->recordChange($userId, $key, $oldValue, $value);
                $label = $this->getKeyLabel($key, $currentPrefs['language'] ?? 'fr');
                $imported[] = "*{$label}*: {$this->formatValue($key, $value)}";
            } else {
                $errors[] = "Erreur : *{$key}*";
            }
        }

        $lines = [];
        if (!empty($imported)) {
            $count = count($imported);
            $lines[] = "📥 *{$count} preference(s) importee(s) :*\n";
            $lines = array_merge($lines, array_map(fn($r) => "• {$r}", $imported));
        }
        if (!empty($errors)) {
            $lines[] = "\n⚠️ *Erreurs :*";
            $lines = array_merge($lines, array_map(fn($e) => "• {$e}", $errors));
        }

        if (empty($imported) && empty($errors)) {
            $reply = "Aucune preference valide trouvee dans le JSON.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'import_preferences', 'count' => count($imported)]);
    }

    private function handleHistory(AgentContext $context): AgentResult
    {
        $userId = $context->from;
        $history = $this->getChangeHistory($userId);

        if (empty($history)) {
            $reply = "📜 *Historique des preferences*\n\nAucun changement enregistre.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'history_empty']);
        }

        $prefs = PreferencesManager::getPreferences($userId);
        $lang = $prefs['language'] ?? 'fr';

        $lines = ["📜 *Historique des preferences*\n"];

        foreach (array_slice($history, 0, 10) as $entry) {
            $label = $this->getKeyLabel($entry['key'], $lang);
            $date = date('d/m H:i', $entry['timestamp']);
            $oldDisplay = $this->formatValue($entry['key'], $entry['old']);
            $newDisplay = $this->formatValue($entry['key'], $entry['new']);
            $lines[] = "• [{$date}] *{$label}*: {$oldDisplay} → {$newDisplay}";
        }

        $total = count($history);
        if ($total > 10) {
            $lines[] = "\n_... et " . ($total - 10) . " autre(s) changement(s)_";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'show_history', 'total' => $total]);
    }

    private function handleCurrentTime(AgentContext $context, array $prefs): AgentResult
    {
        $tz = $prefs['timezone'] ?? 'Europe/Paris';
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';

        try {
            $timezone = new \DateTimeZone($tz);
            $now = new \DateTime('now', $timezone);
            $timeStr = $now->format('H:i:s');
            $dateStr = $now->format($dateFormat);
            $offset = $now->format('P');

            $reply = "🕐 *Heure actuelle*\n\n"
                . "📍 Fuseau : *{$tz}* (UTC{$offset})\n"
                . "📅 Date : *{$dateStr}*\n"
                . "⏰ Heure : *{$timeStr}*";
        } catch (\Exception) {
            $reply = "Impossible de determiner l'heure pour le fuseau *{$tz}*. Verifie ton reglage avec _set timezone_.";
        }

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'current_time']);
    }

    private function handleListTimezones(AgentContext $context): AgentResult
    {
        $grouped = [
            '🇪🇺 Europe' => [],
            '🌎 Ameriques' => [],
            '🌏 Asie' => [],
            '🌍 Afrique' => [],
            '🌊 Oceanie' => [],
            '🌐 Autres' => [],
        ];

        foreach (self::COMMON_TIMEZONES as $tz) {
            try {
                $now = new \DateTime('now', new \DateTimeZone($tz));
                $offset = $now->format('P');
                $entry = "*{$tz}* (UTC{$offset})";
            } catch (\Exception) {
                $entry = "*{$tz}*";
            }

            if (str_starts_with($tz, 'Europe/')) {
                $grouped['🇪🇺 Europe'][] = $entry;
            } elseif (str_starts_with($tz, 'America/')) {
                $grouped['🌎 Ameriques'][] = $entry;
            } elseif (str_starts_with($tz, 'Asia/')) {
                $grouped['🌏 Asie'][] = $entry;
            } elseif (str_starts_with($tz, 'Africa/')) {
                $grouped['🌍 Afrique'][] = $entry;
            } elseif (str_starts_with($tz, 'Australia/') || str_starts_with($tz, 'Pacific/')) {
                $grouped['🌊 Oceanie'][] = $entry;
            } else {
                $grouped['🌐 Autres'][] = $entry;
            }
        }

        $lines = ["🌍 *Fuseaux horaires disponibles*\n"];

        foreach ($grouped as $region => $tzList) {
            if (empty($tzList)) continue;
            $lines[] = "\n*{$region}*";
            foreach ($tzList as $entry) {
                $lines[] = "• {$entry}";
            }
        }

        $lines[] = "\n💡 _Utilise_ set timezone Europe/Paris _pour changer._";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'list_timezones']);
    }

    private function recordChange(string $userId, string $key, mixed $oldValue, mixed $newValue): void
    {
        try {
            $cacheKey = "user:{$userId}:prefs_history";
            $history = Cache::store('redis')->get($cacheKey, []);

            array_unshift($history, [
                'key' => $key,
                'old' => $oldValue,
                'new' => $newValue,
                'timestamp' => time(),
            ]);

            // Keep only recent entries
            $history = array_slice($history, 0, self::CHANGE_HISTORY_LIMIT);

            Cache::store('redis')->put($cacheKey, $history, self::CHANGE_HISTORY_TTL);
        } catch (\Exception $e) {
            Log::warning('Failed to record preference change history', ['error' => $e->getMessage()]);
        }
    }

    private function getChangeHistory(string $userId): array
    {
        try {
            return Cache::store('redis')->get("user:{$userId}:prefs_history", []);
        } catch (\Exception) {
            return [];
        }
    }

    private function getKeyLabel(string $key, string $lang = 'fr'): string
    {
        if ($lang === 'en') {
            return self::KEY_LABELS_EN[$key] ?? $key;
        }
        return self::KEY_LABELS[$key] ?? $key;
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
