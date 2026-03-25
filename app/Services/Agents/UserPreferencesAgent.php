<?php

namespace App\Services\Agents;

use App\Models\UserPreference;
use App\Services\AgentContext;
use App\Services\PreferencesManager;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

class UserPreferencesAgent extends BaseAgent
{
    private const VALID_LANGUAGES = ['fr', 'en', 'es', 'de', 'it', 'pt', 'ar', 'zh', 'ja', 'ko', 'ru', 'nl'];
    private const VALID_UNIT_SYSTEMS = ['metric', 'imperial'];
    private const VALID_DATE_FORMATS = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y', 'd-m-Y'];
    private const VALID_STYLES = ['friendly', 'formal', 'concise', 'detailed', 'casual'];

    private const LANGUAGE_LABELS = [
        'fr' => 'Français',  'en' => 'English',   'es' => 'Español',
        'de' => 'Deutsch',   'it' => 'Italiano',  'pt' => 'Português',
        'ar' => 'العربية',   'zh' => '中文',       'ja' => '日本語',
        'ko' => '한국어',     'ru' => 'Русский',   'nl' => 'Nederlands',
    ];

    private const STYLE_LABELS = [
        'friendly'  => 'Amical',
        'formal'    => 'Formel',
        'concise'   => 'Concis',
        'detailed'  => 'Détaillé',
        'casual'    => 'Décontracté',
    ];

    // Shortcuts for UTC offsets that are not valid PHP timezones
    private const TIMEZONE_ALIASES = [
        'UTC+0'     => 'UTC',
        'UTC+1'     => 'Europe/Paris',
        'UTC+2'     => 'Europe/Helsinki',
        'UTC+3'     => 'Europe/Moscow',
        'UTC+3:30'  => 'Asia/Tehran',
        'UTC+4'     => 'Asia/Dubai',
        'UTC+4:30'  => 'Asia/Kabul',
        'UTC+5'     => 'Asia/Karachi',
        'UTC+5:30'  => 'Asia/Kolkata',
        'UTC+5:45'  => 'Asia/Kathmandu',
        'UTC+6'     => 'Asia/Dhaka',
        'UTC+6:30'  => 'Asia/Rangoon',
        'UTC+7'     => 'Asia/Bangkok',
        'UTC+8'     => 'Asia/Shanghai',
        'UTC+9'     => 'Asia/Tokyo',
        'UTC+9:30'  => 'Australia/Adelaide',
        'UTC+10'    => 'Australia/Sydney',
        'UTC+10:30' => 'Australia/Lord_Howe',
        'UTC+11'    => 'Pacific/Noumea',
        'UTC+12'    => 'Pacific/Auckland',
        'UTC+13'    => 'Pacific/Apia',
        'UTC+14'    => 'Pacific/Kiritimati',
        'UTC-1'     => 'Atlantic/Azores',
        'UTC-2'     => 'America/Noronha',
        'UTC-3'     => 'America/Sao_Paulo',
        'UTC-3:30'  => 'America/St_Johns',
        'UTC-4'     => 'America/Halifax',
        'UTC-5'     => 'America/New_York',
        'UTC-6'     => 'America/Chicago',
        'UTC-7'     => 'America/Denver',
        'UTC-8'     => 'America/Los_Angeles',
        'UTC-9'     => 'America/Anchorage',
        'UTC-10'    => 'Pacific/Honolulu',
        'UTC-11'    => 'Pacific/Midway',
        'UTC-12'    => 'Etc/GMT+12',
    ];

    // Common city → timezone mapping for natural language input
    private const CITY_TIMEZONE_MAP = [
        // Europe
        'paris'          => 'Europe/Paris',
        'london'         => 'Europe/London',
        'berlin'         => 'Europe/Berlin',
        'madrid'         => 'Europe/Madrid',
        'rome'           => 'Europe/Rome',
        'amsterdam'      => 'Europe/Amsterdam',
        'moscow'         => 'Europe/Moscow',
        'istanbul'       => 'Europe/Istanbul',
        'warsaw'         => 'Europe/Warsaw',
        'stockholm'      => 'Europe/Stockholm',
        'zurich'         => 'Europe/Zurich',
        'lisbon'         => 'Europe/Lisbon',
        'athens'         => 'Europe/Athens',
        'helsinki'       => 'Europe/Helsinki',
        'oslo'           => 'Europe/Oslo',
        'copenhagen'     => 'Europe/Copenhagen',
        'brussels'       => 'Europe/Brussels',
        'bruxelles'      => 'Europe/Brussels',
        'vienna'         => 'Europe/Vienna',
        'vienne'         => 'Europe/Vienna',
        'prague'         => 'Europe/Prague',
        'budapest'       => 'Europe/Budapest',
        'bucharest'      => 'Europe/Bucharest',
        'kyiv'           => 'Europe/Kyiv',
        'kiev'           => 'Europe/Kyiv',
        // Africa
        'casablanca'     => 'Africa/Casablanca',
        'cairo'          => 'Africa/Cairo',
        'le caire'       => 'Africa/Cairo',
        'nairobi'        => 'Africa/Nairobi',
        'lagos'          => 'Africa/Lagos',
        'abidjan'        => 'Africa/Abidjan',
        'tunis'          => 'Africa/Tunis',
        'alger'          => 'Africa/Algiers',
        'algiers'        => 'Africa/Algiers',
        'dakar'          => 'Africa/Dakar',
        'kinshasa'       => 'Africa/Kinshasa',
        'accra'          => 'Africa/Accra',
        'addis ababa'    => 'Africa/Addis_Ababa',
        'johannesburg'   => 'Africa/Johannesburg',
        // Middle East
        'dubai'          => 'Asia/Dubai',
        'riyadh'         => 'Asia/Riyadh',
        'tehran'         => 'Asia/Tehran',
        'bagdad'         => 'Asia/Baghdad',
        'baghdad'        => 'Asia/Baghdad',
        'amman'          => 'Asia/Amman',
        'beyrouth'       => 'Asia/Beirut',
        'beirut'         => 'Asia/Beirut',
        // Asia
        'kolkata'        => 'Asia/Kolkata',
        'mumbai'         => 'Asia/Kolkata',
        'new delhi'      => 'Asia/Kolkata',
        'delhi'          => 'Asia/Kolkata',
        'karachi'        => 'Asia/Karachi',
        'dhaka'          => 'Asia/Dhaka',
        'bangkok'        => 'Asia/Bangkok',
        'hanoi'          => 'Asia/Bangkok',
        'ho chi minh'    => 'Asia/Ho_Chi_Minh',
        'singapore'      => 'Asia/Singapore',
        'kuala lumpur'   => 'Asia/Kuala_Lumpur',
        'hong kong'      => 'Asia/Hong_Kong',
        'beijing'        => 'Asia/Shanghai',
        'shanghai'       => 'Asia/Shanghai',
        'taipei'         => 'Asia/Taipei',
        'manila'         => 'Asia/Manila',
        'jakarta'        => 'Asia/Jakarta',
        'tokyo'          => 'Asia/Tokyo',
        'seoul'          => 'Asia/Seoul',
        'kathmandu'      => 'Asia/Kathmandu',
        'yangon'         => 'Asia/Rangoon',
        'rangoon'        => 'Asia/Rangoon',
        'colombo'        => 'Asia/Colombo',
        'tashkent'       => 'Asia/Tashkent',
        'almaty'         => 'Asia/Almaty',
        'baku'           => 'Asia/Baku',
        'tbilisi'        => 'Asia/Tbilisi',
        'yerevan'        => 'Asia/Yerevan',
        // Oceania
        'sydney'         => 'Australia/Sydney',
        'melbourne'      => 'Australia/Melbourne',
        'brisbane'       => 'Australia/Brisbane',
        'perth'          => 'Australia/Perth',
        'adelaide'       => 'Australia/Adelaide',
        'auckland'       => 'Pacific/Auckland',
        // Americas
        'new york'       => 'America/New_York',
        'chicago'        => 'America/Chicago',
        'los angeles'    => 'America/Los_Angeles',
        'denver'         => 'America/Denver',
        'toronto'        => 'America/Toronto',
        'montreal'       => 'America/Montreal',
        'vancouver'      => 'America/Vancouver',
        'sao paulo'      => 'America/Sao_Paulo',
        'buenos aires'   => 'America/Argentina/Buenos_Aires',
        'santiago'       => 'America/Santiago',
        'lima'           => 'America/Lima',
        'bogota'         => 'America/Bogota',
        'mexico'         => 'America/Mexico_City',
        'mexico city'    => 'America/Mexico_City',
        'havana'         => 'America/Havana',
        'caracas'        => 'America/Caracas',
        'montevideo'     => 'America/Montevideo',
        'miami'          => 'America/New_York',
        'boston'         => 'America/New_York',
        'washington'     => 'America/New_York',
        'houston'        => 'America/Chicago',
        'dallas'         => 'America/Chicago',
        'seattle'        => 'America/Los_Angeles',
        'san francisco'  => 'America/Los_Angeles',
        'las vegas'      => 'America/Los_Angeles',
        'phoenix'        => 'America/Phoenix',
        'salt lake city' => 'America/Denver',
        'anchorage'      => 'America/Anchorage',
        'honolulu'       => 'Pacific/Honolulu',
    ];

    // Default cities shown in worldclock when no cities specified
    private const WORLDCLOCK_DEFAULT_CITIES = [
        'New York'      => 'America/New_York',
        'London'        => 'Europe/London',
        'Paris'         => 'Europe/Paris',
        'Dubai'         => 'Asia/Dubai',
        'Singapore'     => 'Asia/Singapore',
        'Tokyo'         => 'Asia/Tokyo',
        'Sydney'        => 'Australia/Sydney',
        'Los Angeles'   => 'America/Los_Angeles',
    ];

    // Day names indexed by ISO day of week (0=Sun..6=Sat)
    private const DAY_NAMES = [
        'fr' => ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'],
        'en' => ['Sunday',   'Monday',   'Tuesday',  'Wednesday', 'Thursday',  'Friday',    'Saturday'],
        'es' => ['Domingo',  'Lunes',    'Martes',   'Miércoles', 'Jueves',    'Viernes',   'Sábado'],
        'de' => ['Sonntag',  'Montag',   'Dienstag', 'Mittwoch',  'Donnerstag','Freitag',   'Samstag'],
        'pt' => ['Domingo',  'Segunda',  'Terça',    'Quarta',    'Quinta',    'Sexta',     'Sábado'],
        'it' => ['Domenica', 'Lunedì',   'Martedì',  'Mercoledì', 'Giovedì',   'Venerdì',   'Sabato'],
        'nl' => ['Zondag',   'Maandag',  'Dinsdag',  'Woensdag',  'Donderdag', 'Vrijdag',   'Zaterdag'],
    ];

    private const DAY_NAMES_SHORT = [
        'fr' => ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'],
        'en' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
        'es' => ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'],
        'de' => ['So',  'Mo',  'Di',  'Mi',  'Do',  'Fr',  'Sa'],
        'pt' => ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'],
        'it' => ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'],
        'nl' => ['Zo',  'Ma',  'Di',  'Wo',  'Do',  'Vr',  'Za'],
    ];

    private const MONTH_NAMES = [
        'fr' => ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'],
        'en' => ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'es' => ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
        'de' => ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
        'pt' => ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
        'it' => ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'],
        'nl' => ['', 'Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni', 'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December'],
    ];

    public function name(): string
    {
        return 'user_preferences';
    }

    public function description(): string
    {
        return 'Agent de gestion des préférences utilisateur. Permet de configurer la langue, le fuseau horaire, le format de date, le système d\'unités (métrique/impérial), le style de communication, les notifications, le téléphone et l\'email. Affiche le profil complet, l\'heure locale (avec numéro de semaine), les personnalisations actives, modifie un ou plusieurs paramètres à la fois, compare des fuseaux horaires, affiche une horloge mondiale multi-villes, vérifie les heures ouvrables d\'une ville, planifie des réunions entre fuseaux (meeting planner), recherche des fuseaux par région/pays, exporte/importe les préférences, réinitialise aux valeurs par défaut, affiche un compte à rebours jusqu\'à une date cible, donne les informations sur l\'heure d\'été/hiver (DST), convertit une heure spécifique d\'un fuseau à un autre (convert_time), affiche le calendrier de la semaine courante (calendar_week), affiche le calendrier mensuel (calendar_month), affiche les heures de lever/coucher du soleil (sun_times), calcule le temps restant avant une heure cible (time_until), affiche la progression de l\'année en cours avec jour de l\'année et semaine ISO (year_progress), affiche un aperçu rapide d\'une ville combinant heure, heures ouvrables et DST en un message (quick_brief), audite les préférences personnalisées vs valeurs par défaut (preferences_audit), convertit des timestamps Unix en dates et inversement (unix_timestamp), convertit une heure vers plusieurs fuseaux horaires simultanément (multi_convert), affiche une carte jour/nuit mondiale indiquant quelles villes dorment ou sont éveillées (day_night_map), calcule les prochaines occurrences d\'un événement récurrent (repeat_event), affiche les prochains jours fériés par pays ou internationaux (holiday_info), et convertit un numéro de semaine ISO en dates lundi-dimanche (week_to_dates).';
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
            'reset preferences', 'reinitialiser', 'valeurs par defaut', 'default',
            'quelle heure', 'heure actuelle', 'heure locale', 'current time',
            'what time', 'heure', 'heure maintenant',
            'personnalise', 'mes changements', 'differences', 'diff preferences',
            'quelles differences', 'ce que jai change', 'modifications',
            'compare timezone', 'comparer heure', 'heure a', 'heure en',
            'quelle heure a', 'quelle heure en', 'decalage horaire',
            'exporter', 'export preferences', 'backup preferences',
            'sauvegarder preferences', 'copier preferences',
            'importer', 'import preferences', 'restaurer preferences',
            'restore preferences', 'coller preferences',
            'preview date', 'apercu date', 'formats date', 'voir formats',
            'choisir format', 'quel format', 'exemple date',
            'worldclock', 'world clock', 'horloge mondiale', 'fuseaux mondiaux',
            'heure dans le monde', 'heure partout', 'toutes les heures',
            'heures mondiales', 'clock', 'multi timezone',
            'heures ouvrables', 'horaires bureau', 'business hours',
            'est-ce ouvert', 'ouvert maintenant', 'travaille maintenant',
            'au bureau', 'heure bureau', 'working hours', 'open now',
            'meeting planner', 'planifier reunion', 'planifier réunion',
            'creneaux communs', 'créneaux communs', 'trouver un horaire',
            'bonne heure pour reunion', 'reunion entre fuseaux',
            'timezone search', 'recherche fuseau', 'rechercher fuseau',
            'fuseaux europe', 'fuseaux amerique', 'fuseaux asie',
            'liste fuseaux', 'quels fuseaux', 'trouver fuseau',
            'countdown', 'compte a rebours', 'compte rebours', 'combien de jours',
            'jours restants', 'dans combien de temps', 'jours avant', 'jours jusqu',
            'heure ete', 'heure hiver', 'heure d ete', 'dst', 'changement heure',
            'heure d\'ete', 'heure d\'hiver', 'bascule horaire', 'bst', 'cet',
            'est ce l heure d ete', 'est-ce l\'heure d\'ete', 'heure ete activee',
            'convertir heure', 'convert time', 'si c est', 'quelle heure sera',
            'convertis', 'conversion heure', 'heure equivalente', 'heure correspondante',
            'semaine', 'calendrier semaine', 'calendar week', 'cette semaine',
            'semaine courante', 'jours de la semaine', 'planning semaine',
            'semaine prochaine', 'semaine passee', 'semaine precedente', 'semaine derniere',
            'prochaine semaine', 'calendrier prochain', 'prochain calendrier',
            'multi meeting', 'multi-meeting', 'reunion multi', 'réunion multi',
            'plusieurs fuseaux', 'multiple timezones', 'multi fuseau',
            'planifier reunion plusieurs', 'creneaux multiples', 'créneaux multiples',
            'plusieurs villes', 'fuseaux multiples', 'meeting plusieurs',
            'calendrier mois', 'calendrier mensuel', 'ce mois', 'mois courant',
            'mois prochain', 'mois precedent', 'mois dernier', 'calendar month',
            'mensuel', 'vue mensuelle', 'planning mensuel', 'calendrier complet',
            'lever soleil', 'coucher soleil', 'lever du soleil', 'coucher du soleil',
            'sunrise', 'sunset', 'soleil aujourd hui', 'heure lever',
            'quand se leve le soleil', 'quand se couche le soleil',
            'duree du jour', 'journee', 'aube', 'crepuscule',
            // age calculator
            'quel age', 'mon age', 'calculer age', 'calcule mon age', 'age anniversaire',
            'date naissance', 'ne le', 'née le', 'anniversaire calcul', 'combien ai je',
            'age pour', 'combien ans', 'birthday', 'age calculator',
            // working days
            'jours ouvres', 'jours ouvrables', 'jours ouvrés', 'jours de travail',
            'jours travail', 'nombre de jours ouvres', 'jours ouvrables entre',
            'working days', 'business days', 'jours sem', 'semaines de travail',
            // same offset
            'meme fuseau', 'même fuseau', 'meme heure que moi', 'même heure que moi',
            'qui partage mon fuseau', 'villes dans mon fuseau', 'offset identique',
            'fuseau partage', 'fuseau partagé', 'same timezone', 'same offset',
            'fuseaux identiques', 'qui est avec moi', 'offset similaire',
            'villes meme fuseau', 'qui a la meme heure', 'meme decalage',
            // next open
            'quand ouvre', 'prochaine ouverture', 'prochaines heures ouvrables de',
            'dans combien de temps ouvre', 'prochain horaire bureau', 'next open',
            'quand rouvre', 'quand puis-je appeler', 'prochaine fenetre ouvree',
            'ouverture prochaine', 'quand seront les bureaux ouverts',
            // time_until
            'dans combien de temps avant', 'time until', 'temps restant avant',
            'combien de temps avant', 'dans combien pour', 'avant quelle heure',
            'combien de temps jusqu', 'temps restant jusqu', 'avant 18h', 'avant 14h',
            'dans combien avant', 'temps avant reunion', 'temps avant midi',
            // year_progress
            'progression annee', 'progression de l annee', 'avancement annee',
            'jour de l annee', 'jours restants cette annee', 'jours restants annee',
            'semaine iso', 'numero du jour', 'numéro du jour', 'quel jour de l annee',
            'trimestre', 'year progress', 'progression 2026', 'avancement 2026',
            'combien de jours dans l annee', 'combien restant annee', 'bilan annee',
            // date_add
            'dans combien de jours date', 'quelle date dans', 'date dans n jours',
            'date calcul', 'ajouter jours', 'soustraire jours', 'date il y a',
            'difference de jours', 'ecart entre dates', 'ecart de jours',
            'combien de jours entre', 'difference entre dates', 'dans 30 jours',
            'dans 7 jours', 'dans 14 jours', 'dans 45 jours', 'dans 60 jours',
            'dans 100 jours', 'dans une semaine date', 'dans 2 semaines date',
            'dans 6 semaines', 'dans 3 mois date', 'dans 6 mois date',
            'date dans semaines', 'date dans mois', 'calcul date',
            // date_info
            'infos sur le', 'infos date', 'quel jour est le', 'date info',
            'quel jour tombe', 'c est quoi comme jour', 'semaine et trimestre',
            'details date', 'details sur la date', 'info date', 'informations date',
            // quick_brief
            'apercu', 'aperçu', 'brief', 'quick brief', 'resume ville', 'résumé ville',
            'apercu rapide', 'aperçu rapide', 'overview', 'infos ville',
            'brief tokyo', 'brief new york', 'brief dubai', 'brief london',
            // preferences_audit
            'audit preferences', 'audit préférences', 'bilan preferences', 'bilan préférences',
            'etat preferences', 'état préférences', 'stats preferences', 'stats préférences',
            'taux personnalisation', 'combien de preferences', 'combien de préférences',
            // unix_timestamp
            'timestamp', 'unix timestamp', 'unix', 'epoch', 'timestamp unix',
            'convertir timestamp', 'timestamp actuel', 'quel timestamp', 'mon timestamp',
            'timestamp maintenant', 'epoch now', 'from timestamp', 'to timestamp',
            // multi_convert
            'multi convert', 'convertir plusieurs', 'heure dans plusieurs villes',
            'si c est a', 'quelle heure dans', 'conversion multiple',
            'multi fuseaux', 'multi-fuseaux', 'convertir vers plusieurs',
            // batch_brief — v1.17.0
            'batch brief', 'brief plusieurs villes', 'apercu plusieurs',
            'overview multiple', 'resume plusieurs villes', 'briefs',
            'apercu villes', 'brief villes', 'multi brief',
            // schedule_check — v1.17.0
            'schedule check', 'verifier horaire', 'est-ce que ca marche',
            'horaire compatible', 'compatible timezone', 'check meeting time',
            'est-ce un bon horaire', 'bonne heure pour', 'check horaire',
            'verifier heure reunion', 'ca passe pour', 'horaire ok',
            // day_night_map — v1.18.0
            'day night', 'jour nuit', 'carte jour nuit', 'map jour nuit',
            'qui dort', 'qui est reveille', 'qui est réveillé', 'nuit ou jour',
            'jour ou nuit', 'planete', 'planète', 'monde entier',
            'day night map', 'carte mondiale', 'statut mondial',
            // repeat_event — v1.18.0
            'repeat event', 'evenement recurrent', 'événement récurrent',
            'toutes les semaines', 'tous les mois', 'tous les jours',
            'prochaines occurrences', 'recurrence', 'récurrence',
            'repeter', 'répéter', 'chaque semaine', 'chaque mois',
            'every week', 'every month', 'every day', 'recurring',
            // holiday_info — v1.19.0
            'jour ferie', 'jour férié', 'jours feries', 'jours fériés',
            'fete nationale', 'fête nationale', 'holiday', 'holidays',
            'prochain ferie', 'prochain férié', 'public holiday',
            'bank holiday', 'vacances', 'jour off', 'conge', 'congé',
            // week_to_dates — v1.19.0
            'semaine numero', 'semaine numéro', 'week number',
            'dates de la semaine', 'dates semaine', 'quelle semaine',
            'semaine iso', 'week to dates', 'week dates',
        ];
    }

    public function version(): string
    {
        return '1.19.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'user_preferences';
    }

    public function handle(AgentContext $context): AgentResult
    {
        try {
            return $this->handleInner($context);
        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            Log::error('UserPreferencesAgent handle() exception', [
                'error'   => $errMsg,
                'trace'   => mb_substr($e->getTraceAsString(), 0, 500),
                'from'    => $context->from,
                'body'    => mb_substr($context->body ?? '', 0, 100),
            ]);
            $this->log($context, 'EXCEPTION: ' . $errMsg, ['class' => get_class($e)], 'error');

            $isDbError      = $e instanceof \Illuminate\Database\QueryException;
            $isRateLimit    = str_contains($errMsg, 'rate_limit') || str_contains($errMsg, '429');
            $isTimeout      = str_contains($errMsg, 'timed out') || str_contains($errMsg, 'timeout');
            $isOverloaded   = str_contains($errMsg, 'overloaded') || str_contains($errMsg, '529') || str_contains($errMsg, '503');
            $isConnection   = str_contains($errMsg, 'cURL') || str_contains($errMsg, 'Connection refused') || str_contains($errMsg, 'Could not resolve');

            $reply = match (true) {
                $isDbError    => "⚠️ Erreur temporaire de base de données. Réessaie dans quelques instants.",
                $isRateLimit  => "⚠️ Trop de requêtes en ce moment. Réessaie dans 30 secondes.",
                $isTimeout    => "⚠️ Le service a mis trop de temps à répondre. Réessaie dans quelques instants.",
                $isOverloaded => "⚠️ Le service est temporairement surchargé. Réessaie dans 1-2 minutes.",
                $isConnection => "⚠️ Problème de connexion au service. Vérifie ta connexion et réessaie.",
                default       => "⚠️ Une erreur inattendue s'est produite. Réessaie ou tape *aide preferences* pour voir les commandes disponibles.",
            };

            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'error', 'error_type' => get_class($e)]);
        }
    }

    private function handleInner(AgentContext $context): AgentResult
    {
        $body   = trim($context->body ?? '');
        $userId = $context->from;

        $this->log($context, "Processing preferences command", ['body' => mb_substr($body, 0, 100)]);

        // Handle empty or very short messages
        if (mb_strlen($body) < 2) {
            $prefs = PreferencesManager::getPreferences($userId);
            return AgentResult::reply($this->formatShowPreferences($prefs), ['action' => 'show_preferences', 'reason' => 'empty_body']);
        }

        $prefs = PreferencesManager::getPreferences($userId);

        // Pre-detect export blocks pasted by the user (bypass LLM for reliability)
        if ($this->looksLikeExportBlock($body)) {
            return $this->handleImport($context, $userId, ['data' => $body]);
        }

        $systemPrompt = $this->buildSystemPrompt($prefs);
        $response     = $this->claude->chat(
            "Message utilisateur: \"{$body}\"",
            $this->resolveModel($context),
            $systemPrompt
        );

        if ($response === null) {
            Log::warning('UserPreferencesAgent: LLM returned null response', [
                'body' => mb_substr($body, 0, 200),
            ]);
            return AgentResult::reply(
                "⚠️ Je n'ai pas pu analyser ta demande. Réessaie ou tape *aide preferences* pour voir les commandes.\n\n"
                . "_Exemples : mon profil, quelle heure, timezone Europe/Paris_"
            );
        }

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            Log::warning('UserPreferencesAgent: failed to parse LLM response', [
                'body'     => mb_substr($body, 0, 200),
                'response' => mb_substr($response, 0, 300),
            ]);
            return AgentResult::reply($this->formatShowPreferences($prefs));
        }

        return match ($parsed['action']) {
            'show'             => AgentResult::reply($this->formatShowPreferences($prefs), ['action' => 'show_preferences']),
            'set'              => $this->handleSet($context, $userId, $parsed, $prefs),
            'set_multiple'     => $this->handleSetMultiple($context, $userId, $parsed, $prefs),
            'reset'            => $this->handleReset($context, $userId, $parsed),
            'help'             => AgentResult::reply($this->formatHelp(), ['action' => 'preferences_help']),
            'current_time'     => $this->handleCurrentTime($prefs),
            'show_diff'        => $this->handleShowDiff($prefs),
            'compare_timezone' => $this->handleCompareTimezone($parsed, $prefs),
            'export'           => $this->handleExport($prefs),
            'import'           => $this->handleImport($context, $userId, $parsed),
            'preview_date'     => $this->handlePreviewDate($prefs),
            'worldclock'       => $this->handleWorldClock($parsed, $prefs),
            'business_hours'   => $this->handleBusinessHours($parsed, $prefs),
            'meeting_planner'  => $this->handleMeetingPlanner($parsed, $prefs),
            'timezone_search'  => $this->handleTimezoneSearch($parsed),
            'countdown'        => $this->handleCountdown($parsed, $prefs),
            'dst_info'         => $this->handleDstInfo($parsed, $prefs),
            'convert_time'     => $this->handleConvertTime($parsed, $prefs),
            'calendar_week'    => $this->handleCalendarWeek($parsed, $prefs),
            'multi_meeting'    => $this->handleMultiMeeting($parsed, $prefs),
            'calendar_month'   => $this->handleCalendarMonth($parsed, $prefs),
            'sun_times'        => $this->handleSunTimes($parsed, $prefs),
            'age'              => $this->handleAge($parsed, $prefs),
            'working_days'     => $this->handleWorkingDays($parsed, $prefs),
            'same_offset'      => $this->handleSameOffset($parsed, $prefs),
            'next_open'        => $this->handleNextOpen($parsed, $prefs),
            'time_until'       => $this->handleTimeUntil($parsed, $prefs),
            'year_progress'    => $this->handleYearProgress($prefs),
            'date_add'         => $this->handleDateAdd($parsed, $prefs),
            'date_info'        => $this->handleDateInfo($parsed, $prefs),
            'quick_brief'      => $this->handleQuickBrief($parsed, $prefs),
            'preferences_audit'=> $this->handlePreferencesAudit($prefs),
            'unix_timestamp'   => $this->handleUnixTimestamp($parsed, $prefs),
            'multi_convert'    => $this->handleMultiConvert($parsed, $prefs),
            'jet_lag'          => $this->handleJetLag($parsed, $prefs),
            'time_bridge'      => $this->handleTimeBridge($parsed, $prefs),
            'batch_brief'      => $this->handleBatchBrief($parsed, $prefs),
            'schedule_check'   => $this->handleScheduleCheck($parsed, $prefs),
            'day_night_map'    => $this->handleDayNightMap($parsed, $prefs),
            'repeat_event'     => $this->handleRepeatEvent($parsed, $prefs),
            'holiday_info'     => $this->handleHolidayInfo($parsed, $prefs),
            'week_to_dates'    => $this->handleWeekToDates($parsed, $prefs),
            default            => AgentResult::reply($this->formatShowPreferences($prefs)),
        };
    }

    // -------------------------------------------------------------------------
    // System prompt
    // -------------------------------------------------------------------------

    private function buildSystemPrompt(array $currentPrefs): string
    {
        $prefsJson      = json_encode($currentPrefs, JSON_UNESCAPED_UNICODE);
        $validLanguages = implode(', ', self::VALID_LANGUAGES);
        $validUnits     = implode(', ', self::VALID_UNIT_SYSTEMS);
        $validFormats   = implode(', ', self::VALID_DATE_FORMATS);
        $validStyles    = implode(', ', self::VALID_STYLES);
        $nowUtc         = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        return <<<PROMPT
Tu es un agent de gestion des préférences utilisateur pour WhatsApp.
Date/heure UTC actuelle : {$nowUtc}

PRÉFÉRENCES ACTUELLES DE L'UTILISATEUR:
{$prefsJson}

CLÉS VALIDES ET VALEURS ACCEPTÉES:
- language: {$validLanguages}
- timezone: tout fuseau horaire valide IANA (ex: Europe/Paris, America/New_York, UTC, Asia/Tokyo) ou offset UTC (ex: UTC+2, UTC-5)
- date_format: {$validFormats}
- unit_system: {$validUnits}
- communication_style: {$validStyles}
- notification_enabled: true / false
- phone: numéro de téléphone international (ex: +33612345678)
- email: adresse email valide

Analyse le message et réponds UNIQUEMENT en JSON valide. Choisis UNE des formes suivantes:

Afficher le profil complet:
{"action": "show"}

Modifier UNE préférence:
{"action": "set", "key": "language", "value": "en"}

Modifier PLUSIEURS préférences en même temps:
{"action": "set_multiple", "changes": [{"key": "language", "value": "en"}, {"key": "timezone", "value": "America/New_York"}]}

Réinitialiser UNE préférence à sa valeur par défaut:
{"action": "reset", "key": "language"}

Réinitialiser TOUTES les préférences:
{"action": "reset", "key": "all"}

Afficher l'aide:
{"action": "help"}

Afficher l'heure locale actuelle dans le fuseau de l'utilisateur:
{"action": "current_time"}

Comparer l'heure de l'utilisateur avec une autre ville ou fuseau horaire:
{"action": "compare_timezone", "target": "Tokyo"}
{"action": "compare_timezone", "target": "America/New_York"}

Afficher l'horloge mondiale (8 grandes villes par défaut):
{"action": "worldclock"}

Afficher l'horloge mondiale avec des villes spécifiques:
{"action": "worldclock", "cities": ["Tokyo", "London", "New York"]}

Vérifier si c'est les heures ouvrables (9h-18h) dans une ville:
{"action": "business_hours", "target": "Tokyo"}

Planifier une réunion — trouver les créneaux communs entre le fuseau de l'utilisateur et une autre ville:
{"action": "meeting_planner", "target": "Tokyo"}
{"action": "meeting_planner", "target": "America/New_York"}

Rechercher des fuseaux horaires par région ou pays:
{"action": "timezone_search", "query": "Europe"}
{"action": "timezone_search", "query": "America/New"}

Exporter les préférences (résumé copier-coller):
{"action": "export"}

Afficher uniquement les préférences personnalisées (différentes des valeurs par défaut):
{"action": "show_diff"}

Afficher la date du jour dans tous les formats disponibles pour choisir:
{"action": "preview_date"}

Importer/restaurer des préférences depuis un bloc texte:
{"action": "import", "data": "language: en\ntimezone: America/New_York\n..."}

Compte à rebours jusqu'à une date cible:
{"action": "countdown", "target_date": "2026-12-25", "label": "Noël"}
{"action": "countdown", "target_date": "2027-01-01", "label": "Nouvel An"}

Informations sur l'heure d'été/hiver (DST) dans le fuseau de l'utilisateur ou une ville:
{"action": "dst_info"}
{"action": "dst_info", "target": "Tokyo"}
{"action": "dst_info", "target": "America/New_York"}

Convertir une heure spécifique d'un fuseau à un autre:
{"action": "convert_time", "time": "14:30", "from": "Tokyo", "to": "Paris"}
{"action": "convert_time", "time": "9h", "from": "America/New_York", "to": "Europe/Paris"}
{"action": "convert_time", "time": "2pm", "from": "London", "to": ""}
Si "from" est vide ou absent, utilise le fuseau de l'utilisateur. Idem pour "to".
Le champ "time" doit être extrait tel que mentionné (14:30, 9h, 2pm, 14h30, etc.).

Afficher le calendrier de la semaine courante (week_offset optionnel: 0=cette semaine, 1=suivante, -1=précédente):
{"action": "calendar_week"}
{"action": "calendar_week", "week_offset": 1}
{"action": "calendar_week", "week_offset": -1}
{"action": "calendar_week", "week_offset": 2}

Planifier une réunion entre PLUSIEURS fuseaux horaires (3 villes ou plus):
{"action": "multi_meeting", "cities": ["Tokyo", "London", "New York"]}
{"action": "multi_meeting", "cities": ["Paris", "Dubai", "Singapore", "Sydney"]}
Si seulement 2 villes mentionnées, préférer meeting_planner.

EXEMPLES DE CORRESPONDANCES:
- "set language en" → {"action": "set", "key": "language", "value": "en"}
- "mets en français" → {"action": "set", "key": "language", "value": "fr"}
- "passe en anglais" → {"action": "set", "key": "language", "value": "en"}
- "fuseau horaire UTC+2" → {"action": "set", "key": "timezone", "value": "UTC+2"}
- "timezone Europe/Paris" → {"action": "set", "key": "timezone", "value": "Europe/Paris"}
- "timezone New York" → {"action": "set", "key": "timezone", "value": "America/New_York"}
- "fuseau horaire Tokyo" → {"action": "set", "key": "timezone", "value": "Asia/Tokyo"}
- "format américain" → {"action": "set", "key": "date_format", "value": "m/d/Y"}
- "format ISO" → {"action": "set", "key": "date_format", "value": "Y-m-d"}
- "métrique" → {"action": "set", "key": "unit_system", "value": "metric"}
- "imperial" → {"action": "set", "key": "unit_system", "value": "imperial"}
- "style formel" → {"action": "set", "key": "communication_style", "value": "formal"}
- "style concis" → {"action": "set", "key": "communication_style", "value": "concise"}
- "désactiver notifications" → {"action": "set", "key": "notification_enabled", "value": false}
- "activer les notifs" → {"action": "set", "key": "notification_enabled", "value": true}
- "mon email est foo@bar.com" → {"action": "set", "key": "email", "value": "foo@bar.com"}
- "mon numéro +33612345678" → {"action": "set", "key": "phone", "value": "+33612345678"}
- "langue anglais et timezone New York" → {"action": "set_multiple", "changes": [{"key": "language", "value": "en"}, {"key": "timezone", "value": "America/New_York"}]}
- "tout en anglais et format américain" → {"action": "set_multiple", "changes": [{"key": "language", "value": "en"}, {"key": "date_format", "value": "m/d/Y"}]}
- "reset language" / "langue par défaut" → {"action": "reset", "key": "language"}
- "réinitialiser tout" / "reset all" → {"action": "reset", "key": "all"}
- "mon profil" / "show preferences" / "mes préférences" → {"action": "show"}
- "quelle heure est-il" / "heure actuelle" / "heure locale" / "current time" → {"action": "current_time"}
- "quelle heure est-il à Tokyo" / "heure à Londres" / "heure en New York" → {"action": "compare_timezone", "target": "Tokyo"}
- "compare timezone London" / "décalage horaire Dubai" → {"action": "compare_timezone", "target": "London"}
- "horloge mondiale" / "world clock" / "heure dans toutes les villes" → {"action": "worldclock"}
- "heure à Paris, Tokyo et New York" / "worldclock Tokyo London" → {"action": "worldclock", "cities": ["Paris", "Tokyo", "New York"]}
- "est-ce les heures ouvrables à Tokyo" / "au bureau à Dubai" / "open now in London" → {"action": "business_hours", "target": "Tokyo"}
- "planifier une réunion avec Tokyo" / "trouver un horaire commun avec New York" / "meeting planner London" → {"action": "meeting_planner", "target": "Tokyo"}
- "créneaux communs avec Paris et moi" / "bonne heure pour appeler Dubai" → {"action": "meeting_planner", "target": "Dubai"}
- "recherche fuseau Europe" / "fuseaux Amérique" / "timezone search Asia" / "liste fuseaux France" → {"action": "timezone_search", "query": "Europe"}
- "quels fuseaux en Amérique" / "fuseaux disponibles Asie" → {"action": "timezone_search", "query": "America"}
- "exporter mes préférences" / "backup settings" / "copier mes paramètres" → {"action": "export"}
- "qu'est-ce que j'ai personnalisé" / "mes différences" / "diff preferences" / "mes modifications" → {"action": "show_diff"}
- "aperçu des formats de date" / "montre-moi les formats" / "quel format de date choisir" / "exemple de date" → {"action": "preview_date"}
- "importer mes préférences" / "restaurer mes paramètres" / "import settings" → {"action": "import", "data": ""}
- "dans combien de temps est Noël" / "combien de jours jusqu'au 25 décembre" → {"action": "countdown", "target_date": "2026-12-25", "label": "Noël"}
- "compte à rebours jusqu'au 14 juillet 2026" / "jours restants avant 2026-07-14" → {"action": "countdown", "target_date": "2026-07-14", "label": "14 juillet"}
- "countdown 2026-06-01" → {"action": "countdown", "target_date": "2026-06-01", "label": ""}
- IMPORTANT: Pour countdown, target_date doit toujours être au format AAAA-MM-JJ. Si l'utilisateur dit "Noël", utilise l'année courante ou suivante selon si la date est déjà passée.
- "est-ce l'heure d'été" / "heure d'été ou d'hiver" / "DST actif" → {"action": "dst_info"}
- "heure d'été à Tokyo" / "DST New York" / "changement heure Londres" → {"action": "dst_info", "target": "London"}
- "quand change l'heure en France" / "prochain changement d'heure Paris" → {"action": "dst_info", "target": "Europe/Paris"}
- "convertir 14h30 de Tokyo à Paris" / "si c'est 9h à New York quelle heure est-il à Paris" → {"action": "convert_time", "time": "14:30", "from": "Tokyo", "to": "Paris"}
- "si c'est 15h à Dubai quelle heure chez moi" → {"action": "convert_time", "time": "15:00", "from": "Dubai", "to": ""}
- "convertis 2pm de Londres en heure Paris" → {"action": "convert_time", "time": "2pm", "from": "London", "to": "Paris"}
- "quelle heure est-il ici si c'est 8h à Sydney" → {"action": "convert_time", "time": "08:00", "from": "Sydney", "to": ""}
- "calendrier de la semaine" / "ma semaine" / "cette semaine" / "planning de la semaine" / "calendar week" → {"action": "calendar_week"}
- "semaine prochaine" / "calendrier semaine prochaine" / "la semaine d'après" → {"action": "calendar_week", "week_offset": 1}
- "semaine passée" / "semaine précédente" / "semaine dernière" / "calendrier semaine dernière" → {"action": "calendar_week", "week_offset": -1}
- "dans 2 semaines" / "calendrier dans 2 semaines" → {"action": "calendar_week", "week_offset": 2}
- "réunion multi Paris Tokyo New York" / "planifier réunion entre 3 villes" / "créneaux communs Paris, Tokyo et New York" → {"action": "multi_meeting", "cities": ["Paris", "Tokyo", "New York"]}
- "multi-meeting London Dubai Sydney" / "planifier avec Londres, Dubai et Sydney" → {"action": "multi_meeting", "cities": ["London", "Dubai", "Sydney"]}
- "fuseaux multiples Paris, New York, Tokyo, Sydney" → {"action": "multi_meeting", "cities": ["Paris", "New York", "Tokyo", "Sydney"]}

Afficher le calendrier mensuel (month_offset optionnel: 0=ce mois, 1=suivant, -1=précédent):
{"action": "calendar_month"}
{"action": "calendar_month", "month_offset": 1}
{"action": "calendar_month", "month_offset": -1}

Afficher les heures de lever/coucher du soleil:
{"action": "sun_times"}
{"action": "sun_times", "target": "Tokyo"}
{"action": "sun_times", "target": "London"}

- "calendrier du mois" / "ce mois" / "planning mensuel" / "vue mensuelle" → {"action": "calendar_month"}
- "mois prochain" / "calendrier mois prochain" → {"action": "calendar_month", "month_offset": 1}
- "mois précédent" / "mois dernier" → {"action": "calendar_month", "month_offset": -1}
- "dans 2 mois" / "calendrier dans 3 mois" → {"action": "calendar_month", "month_offset": 2}
- "lever du soleil" / "coucher du soleil" / "soleil aujourd'hui" / "heure lever soleil" → {"action": "sun_times"}
- "lever du soleil à Tokyo" / "coucher soleil New York" / "quand se lève le soleil à Dubai" → {"action": "sun_times", "target": "Tokyo"}
- "durée du jour à Paris" / "aube et crépuscule Londres" → {"action": "sun_times", "target": "Paris"}

Calculer l'âge exact à partir d'une date de naissance (et jours avant le prochain anniversaire):
{"action": "age", "birthdate": "1990-05-15"}

- "quel âge ai-je si je suis né le 1990-05-15" / "calcule mon âge, né le 1985-12-25" → {"action": "age", "birthdate": "1990-05-15"}
- "mon anniversaire est le 1992-07-04" / "age pour quelqu'un né le 2000-01-01" → {"action": "age", "birthdate": "1992-07-04"}
- "j'ai quoi comme âge, né le 15 mars 1988" → {"action": "age", "birthdate": "1988-03-15"}
- IMPORTANT: birthdate doit toujours être au format AAAA-MM-JJ.

Compter les jours ouvrés (lundi–vendredi) entre deux dates:
{"action": "working_days", "from_date": "2026-04-01", "to_date": "2026-04-30"}

- "combien de jours ouvrés en avril 2026" → {"action": "working_days", "from_date": "2026-04-01", "to_date": "2026-04-30"}
- "jours de travail du 2026-03-15 au 2026-03-31" → {"action": "working_days", "from_date": "2026-03-15", "to_date": "2026-03-31"}
- "nombre de jours ouvrables entre 2026-05-01 et 2026-05-31" → {"action": "working_days", "from_date": "2026-05-01", "to_date": "2026-05-31"}
- "combien de jours ouvrés cette semaine" → utilise les dates lun-ven de la semaine courante
- IMPORTANT: from_date et to_date doivent être au format AAAA-MM-JJ. Si l'utilisateur dit "ce mois", calcule le premier et dernier jour du mois courant.

Afficher les villes qui partagent le même fuseau horaire (même offset UTC) que l'utilisateur ou une ville cible:
{"action": "same_offset"}
{"action": "same_offset", "target": "Tokyo"}
{"action": "same_offset", "target": "UTC+2"}

- "quelles villes sont sur le même fuseau que moi" / "même heure que moi" / "qui partage mon fuseau" / "villes dans mon fuseau" → {"action": "same_offset"}
- "même fuseau que Tokyo" / "villes avec New York" / "fuseaux identiques à Dubai" / "qui est à UTC+2 avec moi" → {"action": "same_offset", "target": "Tokyo"}
- "quelles villes ont le même décalage horaire" / "offset identique" / "fuseau partagé" → {"action": "same_offset"}

Savoir quand les prochaines heures ouvrables (9h–18h lun–ven) commencent dans une ville:
{"action": "next_open", "target": "Tokyo"}
{"action": "next_open", "target": "New York"}

- "quand ouvre Tokyo" / "prochaines heures ouvrables New York" / "dans combien de temps ouvre Dubai" → {"action": "next_open", "target": "Tokyo"}
- "à quelle heure ouvrent les bureaux à Londres" / "prochain horaire bureau Los Angeles" → {"action": "next_open", "target": "London"}
- "quand puis-je appeler quelqu'un à Sydney" / "quand rouvre le bureau Tokyo" → {"action": "next_open", "target": "Sydney"}
- "prochaine fenêtre ouvrée à Singapour" / "ouverture prochaine bureau Dubai" → {"action": "next_open", "target": "Singapore"}

Calculer le temps restant avant une heure cible aujourd'hui (ou demain si déjà passée), dans le fuseau de l'utilisateur ou d'une ville:
{"action": "time_until", "time": "18:00"}
{"action": "time_until", "time": "14h30", "target": "Tokyo"}
{"action": "time_until", "time": "9am", "target": "New York"}
Si "target" est absent, utilise le fuseau de l'utilisateur. Le champ "time" doit être extrait tel que mentionné (14h30, 18h, 9am, etc.).

- "dans combien de temps avant 18h" / "combien de minutes avant 18h00" / "temps restant avant 18h" → {"action": "time_until", "time": "18:00"}
- "dans combien de temps avant 14h30 à Tokyo" / "time until 2pm New York" → {"action": "time_until", "time": "14:30", "target": "Tokyo"}
- "combien de temps avant midi" / "dans combien avant 12h" → {"action": "time_until", "time": "12:00"}
- "dans combien de temps avant 9h à Londres" / "temps avant ouverture 9h Dubai" → {"action": "time_until", "time": "09:00", "target": "London"}
- IMPORTANT: time_until calcule jusqu'à la prochaine occurrence de l'heure (aujourd'hui si non passée, demain sinon).

Afficher la progression de l'année en cours (jour de l'année, semaine ISO, trimestre, jours restants, % complété):
{"action": "year_progress"}

- "progression de l'année" / "avancement de l'année" / "combien de jours dans l'année" → {"action": "year_progress"}
- "quel jour de l'année sommes-nous" / "numéro du jour" / "jour de l'année" → {"action": "year_progress"}
- "semaine ISO" / "semaine numéro" / "trimestre actuel" / "quel trimestre" → {"action": "year_progress"}
- "combien de jours restants cette année" / "jours restants en 2026" / "bilan de l'année" → {"action": "year_progress"}
- "progression 2026" / "year progress" / "avancement annuel" → {"action": "year_progress"}

Calculer une date future ou passée (ajouter/soustraire des jours/semaines/mois), ou calculer l'écart entre deux dates:
{"action": "date_add", "base_date": "today", "days": 30, "label": ""}
{"action": "date_add", "base_date": "today", "days": -7, "label": ""}
{"action": "date_add", "base_date": "2026-03-20", "weeks": 6, "label": ""}
{"action": "date_add", "base_date": "today", "months": 3, "label": "dans 3 mois"}
{"action": "date_add", "from_date": "2026-01-01", "to_date": "2026-06-30"}
{"action": "date_add", "from_date": "today", "to_date": "2026-12-31"}
Si l'utilisateur demande un décalage depuis aujourd'hui, utilise base_date: "today". Si une date de base spécifique est mentionnée, utilise-la au format AAAA-MM-JJ. Pour la différence entre deux dates, utilise from_date et to_date (sans days/weeks/months). Le champ "label" est optionnel.

- "quelle date dans 30 jours" / "dans 45 jours on sera quel jour" → {"action": "date_add", "base_date": "today", "days": 30}
- "date il y a 7 jours" / "quel jour était-on il y a une semaine" → {"action": "date_add", "base_date": "today", "days": -7}
- "quelle date dans 6 semaines" → {"action": "date_add", "base_date": "today", "weeks": 6}
- "quelle date dans 3 mois" / "dans 2 mois on sera quand" → {"action": "date_add", "base_date": "today", "months": 3}
- "date dans 100 jours (vacances)" → {"action": "date_add", "base_date": "today", "days": 100, "label": "vacances"}
- "différence entre 2026-01-01 et 2026-06-30" / "écart entre ces deux dates" → {"action": "date_add", "from_date": "2026-01-01", "to_date": "2026-06-30"}
- "combien de jours entre le 15 mars et le 30 juin 2026" → {"action": "date_add", "from_date": "2026-03-15", "to_date": "2026-06-30"}
- "combien de jours d'ici la fin de l'année" → {"action": "date_add", "from_date": "today", "to_date": "2026-12-31"}
- IMPORTANT: days/weeks/months peuvent être négatifs pour aller dans le passé.

Afficher les informations complètes sur une date spécifique (jour, semaine ISO, trimestre, position dans l'année, distance depuis aujourd'hui):
{"action": "date_info", "date": "2026-07-14"}
{"action": "date_info", "date": "2026-12-25"}
{"action": "date_info", "date": "today"}

- "infos sur le 14 juillet 2026" / "quel jour est le 14 juillet 2026" / "infos date 2026-07-14" → {"action": "date_info", "date": "2026-07-14"}
- "c'est quoi comme jour le 2026-09-01" / "quel jour tombe le 1er septembre 2026" → {"action": "date_info", "date": "2026-09-01"}
- "infos sur aujourd'hui" / "détails sur la date d'aujourd'hui" → {"action": "date_info", "date": "today"}
- "quel jour est le 25 décembre 2026" / "c'est quoi le 25/12/2026" → {"action": "date_info", "date": "2026-12-25"}
- "date info 2026-06-21" / "semaine et trimestre du 2026-06-21" → {"action": "date_info", "date": "2026-06-21"}
- IMPORTANT: date doit toujours être au format AAAA-MM-JJ ou "today".

Aperçu rapide d'une ville (heure + heures ouvrables + DST en un seul message):
{"action": "quick_brief", "target": "Tokyo"}
{"action": "quick_brief", "target": "New York"}

- "aperçu Tokyo" / "brief New York" / "résumé Dubai" / "quick brief London" → {"action": "quick_brief", "target": "Tokyo"}
- "aperçu rapide Singapore" / "infos ville Sydney" / "overview Paris" → {"action": "quick_brief", "target": "Singapore"}
- "brief de ma ville" / "résumé de mon fuseau" → {"action": "quick_brief", "target": ""}

Audit des préférences (résumé de ce qui est personnalisé vs valeurs par défaut):
{"action": "preferences_audit"}

- "audit préférences" / "audit de mes préférences" / "bilan préférences" → {"action": "preferences_audit"}
- "qu'est-ce qui est personnalisé" / "combien de préférences" / "état des préférences" → {"action": "preferences_audit"}
- "mes stats préférences" / "taux de personnalisation" → {"action": "preferences_audit"}

Convertir un timestamp Unix en date ou l'inverse:
{"action": "unix_timestamp", "value": "1711234567", "mode": "from_unix"}
{"action": "unix_timestamp", "value": "now", "mode": "to_unix"}
{"action": "unix_timestamp", "value": "2026-07-14 15:00", "mode": "to_unix"}
Si "value" est un nombre de 9-11 chiffres, c'est un timestamp à convertir en date (from_unix). Sinon c'est une date à convertir en timestamp (to_unix). "mode": "auto" détecte automatiquement.

- "timestamp 1711234567" / "convertir timestamp 1711234567" → {"action": "unix_timestamp", "value": "1711234567", "mode": "auto"}
- "timestamp maintenant" / "unix timestamp now" / "timestamp actuel" → {"action": "unix_timestamp", "value": "now", "mode": "to_unix"}
- "timestamp du 2026-07-14" / "unix 2026-07-14 15:00" → {"action": "unix_timestamp", "value": "2026-07-14 15:00", "mode": "to_unix"}
- "quel timestamp" / "mon timestamp" / "epoch now" → {"action": "unix_timestamp", "value": "now", "mode": "to_unix"}

Convertir une heure vers PLUSIEURS fuseaux horaires en même temps (tableau multi-fuseaux):
{"action": "multi_convert", "time": "15:00", "from": "Paris", "cities": ["Tokyo", "New York", "Dubai"]}
{"action": "multi_convert", "time": "9am", "from": "London", "cities": ["Paris", "Sydney", "Singapore"]}
Si "from" est vide ou absent, utilise le fuseau de l'utilisateur. "time" est l'heure source. "cities" contient la liste des villes cibles.

- "si c'est 15h à Paris quelle heure à Tokyo, New York et Dubai" → {"action": "multi_convert", "time": "15:00", "from": "Paris", "cities": ["Tokyo", "New York", "Dubai"]}
- "convertir 9h du matin vers Tokyo, Sydney et Singapore" → {"action": "multi_convert", "time": "9:00", "from": "", "cities": ["Tokyo", "Sydney", "Singapore"]}
- "heure dans plusieurs villes si 14h30 à Londres" → {"action": "multi_convert", "time": "14:30", "from": "London", "cities": []}
Note: si l'utilisateur ne spécifie qu'une seule ville cible, utiliser convert_time. multi_convert est pour 2+ villes cibles.

Calculer le jet lag et l'heure d'arrivée pour un vol entre deux villes:
{"action": "jet_lag", "from": "Paris", "to": "Tokyo", "duration": "12h"}
{"action": "jet_lag", "from": "New York", "to": "London", "duration": "7h30"}
- "jet lag Paris Tokyo 12h" / "vol Paris vers Tokyo durée 12h" → {"action": "jet_lag", "from": "Paris", "to": "Tokyo", "duration": "12h"}
- "décalage horaire vol New York Londres 7h30" → {"action": "jet_lag", "from": "New York", "to": "London", "duration": "7h30"}
- "arrivée vol Dubai Singapore 7h" → {"action": "jet_lag", "from": "Dubai", "to": "Singapore", "duration": "7h"}
- IMPORTANT: duration accepte: 12h, 7h30, 12:30, 7.5h, 90min. "from" et "to" sont des noms de villes.

Afficher un pont horaire visuel (timeline heure par heure) entre le fuseau de l'utilisateur et une ville:
{"action": "time_bridge", "target": "Tokyo"}
{"action": "time_bridge", "target": "New York"}
- "pont horaire Tokyo" / "timeline Tokyo" / "bridge New York" → {"action": "time_bridge", "target": "Tokyo"}
- "grille horaire Londres" / "tableau horaire Dubai" → {"action": "time_bridge", "target": "London"}
- "visualiser heures Tokyo" / "overlap horaire avec New York" → {"action": "time_bridge", "target": "Tokyo"}

Aperçu rapide de PLUSIEURS villes en un seul message (batch brief):
{"action": "batch_brief", "cities": ["Tokyo", "New York", "Dubai"]}
{"action": "batch_brief", "cities": ["London", "Singapore", "Sydney"]}

- "brief Tokyo, New York et Dubai" / "aperçu plusieurs villes" → {"action": "batch_brief", "cities": ["Tokyo", "New York", "Dubai"]}
- "overview London, Singapore, Sydney" / "résumé de plusieurs villes" → {"action": "batch_brief", "cities": ["London", "Singapore", "Sydney"]}
- "briefs Paris et Tokyo" / "multi brief Dubai London" → {"action": "batch_brief", "cities": ["Paris", "Tokyo"]}
Note: si UNE seule ville, utiliser quick_brief. batch_brief est pour 2+ villes.

Vérifier si une heure donnée est compatible avec les heures ouvrables de plusieurs villes:
{"action": "schedule_check", "time": "15:00", "cities": ["Tokyo", "New York"]}
{"action": "schedule_check", "time": "10h", "cities": ["London", "Dubai", "Singapore"]}
Si "time" est absent, utilise l'heure actuelle. Vérifie si l'heure est dans les heures ouvrables (9h-18h lun-ven) de chaque ville.

- "est-ce que 15h marche pour Tokyo et New York" / "15h c'est ok pour Tokyo et NYC" → {"action": "schedule_check", "time": "15:00", "cities": ["Tokyo", "New York"]}
- "check horaire 10h London Dubai Singapore" / "est-ce un bon horaire 10h pour Londres et Dubai" → {"action": "schedule_check", "time": "10:00", "cities": ["London", "Dubai", "Singapore"]}
- "vérifier si 14h30 passe pour Paris, Tokyo et Sydney" → {"action": "schedule_check", "time": "14:30", "cities": ["Paris", "Tokyo", "Sydney"]}
- "horaire compatible 9am pour New York et London" → {"action": "schedule_check", "time": "09:00", "cities": ["New York", "London"]}

Afficher la carte jour/nuit mondiale (quelles grandes villes sont en jour, nuit, aube ou crépuscule):
{"action": "day_night_map"}
{"action": "day_night_map", "cities": ["Tokyo", "London", "Dubai", "Sydney"]}

- "carte jour nuit" / "qui dort" / "qui est réveillé" / "jour ou nuit dans le monde" → {"action": "day_night_map"}
- "day night map" / "statut mondial" / "jour nuit Tokyo London Dubai" → {"action": "day_night_map", "cities": ["Tokyo", "London", "Dubai"]}
- "carte mondiale" / "planète" / "monde entier" → {"action": "day_night_map"}

Calculer les prochaines occurrences d'un événement récurrent:
{"action": "repeat_event", "start_date": "2026-04-01", "interval": "2 weeks", "count": 5, "label": "Réunion bi-hebdo"}
{"action": "repeat_event", "start_date": "2026-04-15", "interval": "1 month", "count": 6, "label": "Paye"}
{"action": "repeat_event", "start_date": "today", "interval": "3 days", "count": 5, "label": ""}
Valeurs possibles pour interval: "N days", "N weeks", "N months". count est le nombre d'occurrences à afficher (max 12, défaut 5).

- "toutes les 2 semaines à partir du 2026-04-01" → {"action": "repeat_event", "start_date": "2026-04-01", "interval": "2 weeks", "count": 5, "label": ""}
- "prochaines payes tous les mois à partir du 15 avril" → {"action": "repeat_event", "start_date": "2026-04-15", "interval": "1 month", "count": 6, "label": "Paye"}
- "événement tous les 3 jours" → {"action": "repeat_event", "start_date": "today", "interval": "3 days", "count": 5, "label": ""}
- "réunion chaque semaine à partir du lundi prochain, 8 prochaines" → {"action": "repeat_event", "start_date": "2026-03-30", "interval": "1 week", "count": 8, "label": "Réunion"}
- IMPORTANT: start_date au format AAAA-MM-JJ ou "today". interval au format "N days/weeks/months".

Afficher les prochains jours fériés (par pays ou international):
{"action": "holiday_info"}
{"action": "holiday_info", "country": "france"}
{"action": "holiday_info", "country": "us", "count": 5}

- "prochains jours fériés" / "jours fériés" / "holidays" → {"action": "holiday_info"}
- "jours fériés en France" / "fêtes nationales France" → {"action": "holiday_info", "country": "france"}
- "jours fériés US" / "public holidays USA" / "bank holidays UK" → {"action": "holiday_info", "country": "us"}
- "5 prochains jours fériés Allemagne" → {"action": "holiday_info", "country": "de", "count": 5}
- IMPORTANT: country accepte: fr/france, us/usa, uk, de/germany, es/spain, it/italy, ou vide pour international.

Convertir un numéro de semaine ISO en dates (lundi à dimanche):
{"action": "week_to_dates", "week": 15}
{"action": "week_to_dates", "week": 15, "year": 2026}

- "semaine 15" / "dates de la semaine 15" / "week 15" → {"action": "week_to_dates", "week": 15}
- "semaine 20 2026" / "dates semaine 20 en 2026" → {"action": "week_to_dates", "week": 20, "year": 2026}
- "quelles dates sont en semaine 1 2027" → {"action": "week_to_dates", "week": 1, "year": 2027}
- IMPORTANT: week est le numéro de semaine ISO (1-53). year est optionnel (défaut: année courante).

- Si le message est ambigu ou demande de l'aide → {"action": "help"}

RÈGLES STRICTES:
- Réponds UNIQUEMENT avec le JSON, rien d'autre. Pas de texte avant ou après.
- N'encapsule PAS le JSON dans des backticks ou blocs de code.
- Assure-toi que le JSON est syntaxiquement valide (guillemets doubles, pas de virgule traînante).
PROMPT;
    }

    // -------------------------------------------------------------------------
    // Action handlers
    // -------------------------------------------------------------------------

    private function handleSet(AgentContext $context, string $userId, array $parsed, array $currentPrefs): AgentResult
    {
        $key   = $parsed['key'] ?? null;
        $value = $parsed['value'] ?? null;

        if (!$key || $value === null) {
            return AgentResult::reply("Je n'ai pas compris quelle préférence modifier. Tape *show preferences* pour voir tes paramètres actuels.");
        }

        if (!in_array($key, UserPreference::$validKeys)) {
            $validKeys = implode(', ', UserPreference::$validKeys);
            return AgentResult::reply("Clé invalide *{$key}*. Clés valides : {$validKeys}");
        }

        $validationError = $this->validateValue($key, $value);
        if ($validationError) {
            return AgentResult::reply($validationError);
        }

        $value    = $this->normalizeValue($key, $value);
        $oldValue = $currentPrefs[$key] ?? null;
        $success  = PreferencesManager::setPreference($userId, $key, $value);

        if (!$success) {
            return AgentResult::reply("Erreur lors de la mise à jour de *{$key}*. Réessaie dans quelques instants.");
        }

        $this->log($context, "Preference updated: {$key}", [
            'key'       => $key,
            'old_value' => $oldValue,
            'new_value' => $value,
        ]);

        $displayValue = $this->formatValue($key, $value);
        $displayOld   = $this->formatValue($key, $oldValue);

        $reply = "✅ Préférence mise à jour !\n\n"
            . "*{$this->formatKeyLabel($key)}* : {$displayOld} → {$displayValue}";

        // For timezone changes, immediately show local time in new timezone
        if ($key === 'timezone') {
            try {
                $tz      = new DateTimeZone($value);
                $now     = new DateTimeImmutable('now', $tz);
                $lang    = $currentPrefs['language'] ?? 'fr';
                $dayName = $this->getDayName((int) $now->format('w'), $lang);
                $reply  .= "\n\n🕐 Il est actuellement *{$now->format('H:i')}* ({$dayName}) dans ce fuseau.";
            } catch (\Exception) {
                // Silently ignore
            }
        }

        return AgentResult::reply($reply, ['action' => 'set_preference', 'key' => $key, 'value' => $value]);
    }

    private function handleSetMultiple(AgentContext $context, string $userId, array $parsed, array $currentPrefs): AgentResult
    {
        $changes = $parsed['changes'] ?? [];

        if (empty($changes) || !is_array($changes)) {
            return AgentResult::reply("Aucun changement à effectuer. Précise les préférences à modifier.");
        }

        // Validate ALL changes first before applying any
        $validChanges = [];
        $errors       = [];

        foreach ($changes as $change) {
            $key   = $change['key'] ?? null;
            $value = $change['value'] ?? null;

            if (!$key || $value === null) {
                $errors[] = "⚠️ Changement incomplet (clé ou valeur manquante)";
                continue;
            }

            if (!in_array($key, UserPreference::$validKeys)) {
                $errors[] = "⚠️ Clé invalide : *{$key}*";
                continue;
            }

            $validationError = $this->validateValue($key, $value);
            if ($validationError) {
                $errors[] = "⚠️ {$validationError}";
                continue;
            }

            $validChanges[] = [
                'key'      => $key,
                'value'    => $this->normalizeValue($key, $value),
                'oldValue' => $currentPrefs[$key] ?? null,
            ];
        }

        if (empty($validChanges)) {
            return AgentResult::reply(
                "Aucune préférence valide à mettre à jour.\n\n" . implode("\n", $errors)
            );
        }

        // Apply all validated changes
        $lines       = ["✅ *Préférences mises à jour !*\n"];
        $updated     = 0;
        $applyErrors = [];

        foreach ($validChanges as $change) {
            $key      = $change['key'];
            $value    = $change['value'];
            $oldValue = $change['oldValue'];
            $success  = PreferencesManager::setPreference($userId, $key, $value);

            if ($success) {
                $lines[] = "• *{$this->formatKeyLabel($key)}* : {$this->formatValue($key, $oldValue)} → {$this->formatValue($key, $value)}";
                $updated++;

                $this->log($context, "Preference updated (multi): {$key}", [
                    'key'       => $key,
                    'old_value' => $oldValue,
                    'new_value' => $value,
                ]);
            } else {
                $applyErrors[] = "⚠️ Erreur pour *{$key}*";
            }
        }

        if ($updated === 0) {
            return AgentResult::reply("Aucune préférence mise à jour.\n\n" . implode("\n", $applyErrors));
        }

        $allErrors = array_merge($errors, $applyErrors);
        if (!empty($allErrors)) {
            $lines[] = "\n" . implode("\n", $allErrors);
        }

        return AgentResult::reply(implode("\n", $lines), ['action' => 'set_multiple_preferences', 'count' => $updated]);
    }

    private function handleReset(AgentContext $context, string $userId, array $parsed): AgentResult
    {
        $key = $parsed['key'] ?? null;

        if (!$key) {
            return AgentResult::reply("Précise quelle préférence réinitialiser, ou tape *reset all* pour tout réinitialiser.");
        }

        if ($key === 'all') {
            $defaults = UserPreference::$defaults;
            $errors   = [];

            foreach ($defaults as $k => $defaultValue) {
                if (!PreferencesManager::setPreference($userId, $k, $defaultValue)) {
                    $errors[] = $k;
                }
            }

            $this->log($context, "All preferences reset to defaults");

            if (!empty($errors)) {
                return AgentResult::reply(
                    "⚠️ Réinitialisation partielle. Erreurs sur : " . implode(', ', $errors) . "\n"
                    . "Les autres préférences ont bien été réinitialisées."
                );
            }

            $freshPrefs = PreferencesManager::getPreferences($userId);
            return AgentResult::reply(
                "🔄 Toutes les préférences ont été réinitialisées aux valeurs par défaut.\n\n"
                    . $this->formatShowPreferences($freshPrefs),
                ['action' => 'reset_all_preferences']
            );
        }

        if (!in_array($key, UserPreference::$validKeys)) {
            $validKeys = implode(', ', UserPreference::$validKeys);
            return AgentResult::reply("Clé invalide *{$key}*. Clés valides : {$validKeys}");
        }

        $defaultValue = UserPreference::$defaults[$key] ?? null;
        $success      = PreferencesManager::setPreference($userId, $key, $defaultValue);

        if (!$success) {
            return AgentResult::reply("Erreur lors de la réinitialisation de *{$key}*. Réessaie dans quelques instants.");
        }

        $this->log($context, "Preference reset: {$key}", ['default' => $defaultValue]);

        $displayDefault = $this->formatValue($key, $defaultValue);
        $reply = "🔄 Préférence réinitialisée !\n\n"
            . "*{$this->formatKeyLabel($key)}* → {$displayDefault} _(valeur par défaut)_";

        return AgentResult::reply($reply, ['action' => 'reset_preference', 'key' => $key]);
    }

    private function handleCurrentTime(array $prefs): AgentResult
    {
        $tzName = $prefs['timezone'] ?? 'UTC';
        $lang   = $prefs['language'] ?? 'fr';

        try {
            $tz         = new DateTimeZone($tzName);
            $now        = new DateTimeImmutable('now', $tz);
            $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
            $dateStr    = $now->format($dateFormat);
            $timeStr    = $now->format('H:i:s');
            $dayName    = $this->getDayName((int) $now->format('w'), $lang);
            $offsetStr  = $now->format('P');
            $weekNum    = $now->format('W'); // ISO 8601 week number

            // Time-of-day visual indicator
            $hour    = (int) $now->format('G');
            $dayIcon = match (true) {
                $hour >= 6  && $hour < 9  => '🌅',
                $hour >= 9  && $hour < 18 => '☀️',
                $hour >= 18 && $hour < 21 => '🌆',
                default                   => '🌙',
            };

            $reply = "🕐 *HEURE LOCALE*\n"
                . "────────────────\n"
                . "📍 Fuseau : *{$tzName}* (UTC{$offsetStr})\n"
                . "📅 Date : *{$dayName} {$dateStr}* _(semaine {$weekNum})_\n"
                . "{$dayIcon} Heure : *{$timeStr}*\n"
                . "────────────────\n"
                . "_Pour changer ton fuseau : timezone Europe/Paris_\n"
                . "_Voir d'autres villes : horloge mondiale_";

        } catch (\Exception $e) {
            $reply = "⚠️ Impossible d'afficher l'heure pour le fuseau *{$tzName}*.\n"
                . "Configure un fuseau valide : _timezone Europe/Paris_";
        }

        return AgentResult::reply($reply, ['action' => 'current_time', 'timezone' => $tzName]);
    }

    private function handleCompareTimezone(array $parsed, array $prefs): AgentResult
    {
        $target = trim($parsed['target'] ?? '');
        $lang   = $prefs['language'] ?? 'fr';

        if ($target === '') {
            return AgentResult::reply(
                "Précise la ville ou le fuseau à comparer.\n"
                . "_Ex : heure à Tokyo, compare timezone London, décalage New York_"
            );
        }

        $targetTz = $this->resolveTimezoneString($target);
        if (!$targetTz) {
            $suggestion = $this->suggestTimezone($target);
            $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
            return AgentResult::reply(
                "Fuseau horaire inconnu : *{$target}*.\n"
                . "Exemples valides : _Tokyo_, _London_, _America/New_York_, _UTC+5_{$extra}"
            );
        }

        $userTzName = $prefs['timezone'] ?? 'UTC';

        try {
            $utcNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $userNow   = $utcNow->setTimezone(new DateTimeZone($userTzName));
            $targetNow = $utcNow->setTimezone(new DateTimeZone($targetTz));

            $userTimeStr   = $userNow->format('H:i');
            $targetTimeStr = $targetNow->format('H:i');
            $userOffset    = $userNow->format('P');
            $targetOffset  = $targetNow->format('P');

            $diffSecs = $targetNow->getOffset() - $userNow->getOffset();
            $absH     = intdiv(abs($diffSecs), 3600);
            $absM     = (abs($diffSecs) % 3600) / 60;
            $sign     = $diffSecs > 0 ? '+' : '-';

            if ($diffSecs === 0) {
                $diffStr = 'même fuseau horaire';
            } elseif ($absM > 0) {
                $diffStr = "{$sign}{$absH}h{$absM}min";
            } else {
                $diffStr = "{$sign}{$absH}h";
            }

            $userDay   = $this->getDayName((int) $userNow->format('w'), $lang, short: true);
            $targetDay = $this->getDayName((int) $targetNow->format('w'), $lang, short: true);
            $dayDiff   = ($userNow->format('Y-m-d') !== $targetNow->format('Y-m-d'))
                ? " _({$targetDay})_"
                : '';

            // Business hours indicator for target city
            $targetHour = (int) $targetNow->format('G');
            $isBusinessHours = ($targetHour >= 9 && $targetHour < 18);
            $businessIcon = $isBusinessHours ? '🟢' : '🔴';
            $businessLabel = $isBusinessHours ? 'heures ouvrables' : 'hors bureaux';

            $reply = "🌍 *COMPARAISON DE FUSEAUX*\n"
                . "────────────────\n"
                . "📍 *Ici* ({$userTzName})\n"
                . "   ⏰ *{$userTimeStr}* _{$userDay}_ (UTC{$userOffset})\n"
                . "📍 *{$target}* ({$targetTz})\n"
                . "   ⏰ *{$targetTimeStr}*{$dayDiff} (UTC{$targetOffset})\n"
                . "   {$businessIcon} _{$businessLabel}_\n"
                . "────────────────\n"
                . "⏱ Décalage : *{$diffStr}*\n"
                . "────────────────\n"
                . "_Pour adopter ce fuseau : timezone {$targetTz}_";

        } catch (\Exception $e) {
            Log::warning("UserPreferencesAgent: compare_timezone error", [
                'target'   => $target,
                'targetTz' => $targetTz,
                'error'    => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Impossible de calculer le décalage horaire. Vérifie les fuseaux configurés.\n"
                . "_Ton fuseau actuel : {$userTzName}_"
            );
        }

        return AgentResult::reply($reply, ['action' => 'compare_timezone', 'target' => $targetTz]);
    }

    private function handleExport(array $prefs): AgentResult
    {
        $notifStr   = $prefs['notification_enabled'] ? 'true' : 'false';
        $exportedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i') . ' UTC';

        $lines = [
            "📋 *EXPORT DE MES PRÉFÉRENCES*",
            "────────────────",
            "_(Exporté le {$exportedAt})_",
            "_(Colle ce bloc pour le restaurer)_",
            "",
            "language: {$prefs['language']}",
            "timezone: {$prefs['timezone']}",
            "date_format: {$prefs['date_format']}",
            "unit_system: {$prefs['unit_system']}",
            "communication_style: {$prefs['communication_style']}",
            "notification_enabled: {$notifStr}",
            "phone: " . ($prefs['phone'] ?? ''),
            "email: " . ($prefs['email'] ?? ''),
            "────────────────",
            "💡 _Pour restaurer : colle ce bloc dans un message._",
            "💡 _Ou modifie manuellement : timezone Europe/Paris_",
        ];

        return AgentResult::reply(implode("\n", $lines), ['action' => 'export_preferences']);
    }

    private function handleImport(AgentContext $context, string $userId, array $parsed): AgentResult
    {
        $raw = trim($parsed['data'] ?? '');

        if (empty($raw)) {
            return AgentResult::reply(
                "⚠️ Aucune donnée à importer.\n\n"
                . "_Colle le bloc exporté de tes préférences pour les restaurer._\n"
                . "_Ex: exporter mes préférences, puis colle le bloc reçu._"
            );
        }

        $toApply      = [];
        $skipped      = [];
        $currentPrefs = PreferencesManager::getPreferences($userId);

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            // Skip decoration lines and empty lines
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '_')
                || str_starts_with($line, '─') || str_starts_with($line, '•')
                || str_starts_with($line, '📋') || str_starts_with($line, '💡')) {
                continue;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$rawKey, $rawVal] = array_pad(explode(':', $line, 2), 2, '');
            $key = trim($rawKey);
            $val = trim($rawVal);

            if (!in_array($key, UserPreference::$validKeys)) {
                continue; // Unknown key — skip silently
            }

            // Skip empty/placeholder values
            if ($val === '' || $val === '(non défini)') {
                continue;
            }

            $validationError = $this->validateValue($key, $val);
            if ($validationError) {
                $skipped[] = "⚠️ *{$key}* ignoré (valeur invalide : {$val})";
                continue;
            }

            $toApply[$key] = $this->normalizeValue($key, $val);
        }

        if (empty($toApply)) {
            $msg = empty($skipped)
                ? "Aucune préférence valide trouvée dans les données.\n_Vérifie que tu as bien collé un bloc d'export ZeniClaw._"
                : "Données invalides :\n" . implode("\n", $skipped);
            return AgentResult::reply("⚠️ Import échoué. {$msg}");
        }

        $applied = [];
        $failed  = [];

        foreach ($toApply as $key => $value) {
            $oldDisplay = $this->formatValue($key, $currentPrefs[$key] ?? null);
            $newDisplay = $this->formatValue($key, $value);

            if (PreferencesManager::setPreference($userId, $key, $value)) {
                $applied[] = "• *{$this->formatKeyLabel($key)}* : {$oldDisplay} → {$newDisplay}";
            } else {
                $failed[] = $key;
            }
        }

        $this->log($context, "Preferences imported", [
            'count' => count($applied),
            'keys'  => array_keys($toApply),
        ]);

        if (empty($applied)) {
            return AgentResult::reply(
                "⚠️ Import échoué. Erreurs sur : " . implode(', ', $failed)
            );
        }

        $lines = ["📥 *IMPORT RÉUSSI* (" . count($applied) . " préférence(s) restaurée(s))\n────────────────"];
        $lines = array_merge($lines, $applied);

        if (!empty($failed)) {
            $lines[] = "\n⚠️ Erreurs : " . implode(', ', $failed);
        }
        if (!empty($skipped)) {
            $lines[] = "\n" . implode("\n", $skipped);
        }

        $lines[] = "────────────────";
        $lines[] = "_Tape *mon profil* pour voir toutes tes préférences._";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'import_preferences', 'count' => count($applied)]);
    }

    private function handlePreviewDate(array $prefs): AgentResult
    {
        $tzName = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($tzName);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $formatLabels = [
            'd/m/Y' => 'Européen  JJ/MM/AAAA',
            'm/d/Y' => 'Américain MM/JJ/AAAA',
            'Y-m-d' => 'ISO 8601  AAAA-MM-JJ',
            'd.m.Y' => 'Allemand  JJ.MM.AAAA',
            'd-m-Y' => 'Neutre    JJ-MM-AAAA',
        ];

        $current = $prefs['date_format'] ?? 'd/m/Y';

        $lines = ["📅 *APERÇU DES FORMATS DE DATE*", "_(basé sur la date actuelle dans ton fuseau)_", "────────────────"];

        foreach (self::VALID_DATE_FORMATS as $fmt) {
            $marker  = $fmt === $current ? ' ✅' : '';
            $label   = $formatLabels[$fmt] ?? $fmt;
            $example = $now->format($fmt);
            $lines[] = "• *{$example}*  _{$label}_{$marker}";
        }

        $lines[] = "────────────────";
        $lines[] = "🕐 *Heure actuelle :* *{$now->format('H:i')}* (UTC{$now->format('P')})";
        $lines[] = "────────────────";
        $lines[] = "_Pour choisir un format :_";
        $lines[] = "• _format d/m/Y_  ou  _format américain_  ou  _format ISO_";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'preview_date_formats', 'current' => $current]);
    }

    /**
     * NEW: World clock — show current time in multiple cities simultaneously.
     */
    private function handleWorldClock(array $parsed, array $prefs): AgentResult
    {
        $lang         = $prefs['language'] ?? 'fr';
        $userTzName   = $prefs['timezone'] ?? 'UTC';
        $requestedCities = $parsed['cities'] ?? [];

        // Build the city→timezone map to display
        if (!empty($requestedCities) && is_array($requestedCities)) {
            $cityMap = [];
            $unknown = [];

            foreach ($requestedCities as $cityInput) {
                $cityInput = trim((string) $cityInput);
                $tz = $this->resolveTimezoneString($cityInput);
                if ($tz) {
                    $cityMap[$cityInput] = $tz;
                } else {
                    $unknown[] = $cityInput;
                }
            }

            if (empty($cityMap)) {
                return AgentResult::reply(
                    "⚠️ Aucune ville reconnue parmi : " . implode(', ', $requestedCities) . ".\n"
                    . "_Essaie : Paris, Tokyo, New York, London, Dubai, Sydney…_"
                );
            }
        } else {
            $cityMap = self::WORLDCLOCK_DEFAULT_CITIES;
        }

        try {
            $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $lines  = ["🌍 *HORLOGE MONDIALE*", "────────────────"];

            foreach ($cityMap as $cityLabel => $tz) {
                try {
                    $cityNow  = $utcNow->setTimezone(new DateTimeZone($tz));
                    $timeStr  = $cityNow->format('H:i');
                    $day      = $this->getDayName((int) $cityNow->format('w'), $lang, short: true);
                    $offset   = $cityNow->format('P');
                    $hour     = (int) $cityNow->format('G');

                    // Visual time-of-day indicator
                    $icon = match (true) {
                        $hour >= 6  && $hour < 9  => '🌅',
                        $hour >= 9  && $hour < 18 => '☀️',
                        $hour >= 18 && $hour < 21 => '🌆',
                        default                   => '🌙',
                    };

                    // Mark user's own timezone
                    $isMine = ($tz === $userTzName) ? ' _(vous)_' : '';

                    $lines[] = "{$icon} *{$cityLabel}* — *{$timeStr}* _{$day}_ (UTC{$offset}){$isMine}";
                } catch (\Exception) {
                    $lines[] = "⚠️ *{$cityLabel}* — fuseau invalide";
                }
            }

            // Warn about unresolved cities if any
            if (!empty($unknown ?? [])) {
                $lines[] = "\n⚠️ Non reconnu : " . implode(', ', $unknown ?? []);
            }

            $lines[] = "────────────────";
            $lines[] = "_Personnalise : horloge Tokyo, Paris, Dubai_";

        } catch (\Exception $e) {
            Log::warning("UserPreferencesAgent: worldclock error", ['error' => $e->getMessage()]);
            return AgentResult::reply("⚠️ Impossible d'afficher l'horloge mondiale. Réessaie dans quelques instants.");
        }

        return AgentResult::reply(implode("\n", $lines), ['action' => 'worldclock', 'city_count' => count($cityMap)]);
    }

    /**
     * NEW: Business hours check — is it working hours (9h-18h) in a given city?
     */
    private function handleBusinessHours(array $parsed, array $prefs): AgentResult
    {
        $target = trim($parsed['target'] ?? '');
        $lang   = $prefs['language'] ?? 'fr';

        if ($target === '') {
            return AgentResult::reply(
                "Précise la ville à vérifier.\n"
                . "_Ex : heures ouvrables Tokyo, au bureau à Dubai, open now in London_"
            );
        }

        $targetTz = $this->resolveTimezoneString($target);
        if (!$targetTz) {
            $suggestion = $this->suggestTimezone($target);
            $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
            return AgentResult::reply(
                "Ville inconnue : *{$target}*.\n"
                . "Exemples : _Tokyo_, _London_, _New York_, _Dubai_{$extra}"
            );
        }

        try {
            $utcNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $targetNow = $utcNow->setTimezone(new DateTimeZone($targetTz));
            $userTzName = $prefs['timezone'] ?? 'UTC';
            $userNow   = $utcNow->setTimezone(new DateTimeZone($userTzName));

            $hour        = (int) $targetNow->format('G');
            $minute      = (int) $targetNow->format('i');
            $dayOfWeek   = (int) $targetNow->format('N'); // 1=Mon … 7=Sun
            $timeStr     = $targetNow->format('H:i');
            $offsetStr   = $targetNow->format('P');
            $dayName     = $this->getDayName((int) $targetNow->format('w'), $lang);

            $isWeekend       = $dayOfWeek >= 6;
            $isWorkingHours  = ($hour >= 9 && $hour < 18) && !$isWeekend;
            $isEarlyMorning  = $hour >= 7 && $hour < 9;
            $isLateEvening   = $hour >= 18 && $hour < 21;

            if ($isWeekend) {
                $status = '🔴 *Week-end* — bureaux fermés';
                $detail = "C'est le {$dayName} à {$target}.";
                // ISO: 6=Samedi, 7=Dimanche
                $hint = $dayOfWeek === 6
                    ? "Demain c'est dimanche — les bureaux réouvrent lundi matin à 9h00."
                    : "Les bureaux réouvrent demain lundi à 9h00.";
            } elseif ($isWorkingHours) {
                $remaining = 17 * 60 + 59 - ($hour * 60 + $minute);
                $remH      = intdiv($remaining, 60);
                $remM      = $remaining % 60;
                $remStr    = $remH > 0 ? "{$remH}h{$remM}min" : "{$remM}min";
                $status    = '🟢 *Heures ouvrables* — bureaux ouverts';
                $detail    = "Il est {$timeStr} à {$target} — il reste *{$remStr}* avant la fermeture (18h).";
                $hint      = "C'est le bon moment pour contacter quelqu'un là-bas.";
            } elseif ($isEarlyMorning) {
                $minsUntil = (9 * 60) - ($hour * 60 + $minute);
                $status    = '🟡 *Tôt le matin* — bureaux pas encore ouverts';
                $detail    = "Il est {$timeStr} à {$target} — les bureaux ouvrent dans *{$minsUntil}min* (9h00).";
                $hint      = "Encore un peu de patience !";
            } elseif ($isLateEvening) {
                $status = '🟠 *Fin de journée* — bureaux fermés';
                $detail = "Il est {$timeStr} à {$target} — les bureaux ont fermé à 18h00.";
                $hint   = "Réessaie demain matin dès 9h00.";
            } else {
                $status = '🔴 *Nuit* — bureaux fermés';
                $detail = "Il est {$timeStr} à {$target} — c'est la nuit là-bas.";
                $hint   = "Attends demain matin (9h00 heure locale).";
            }

            // Diff from user's current time
            $diffSecs  = $targetNow->getOffset() - $userNow->getOffset();
            $absH      = intdiv(abs($diffSecs), 3600);
            $absM      = (abs($diffSecs) % 3600) / 60;
            $sign      = $diffSecs >= 0 ? '+' : '-';
            $diffLabel = $diffSecs === 0
                ? 'même heure que toi'
                : ($absM > 0 ? "{$sign}{$absH}h{$absM}min par rapport à toi" : "{$sign}{$absH}h par rapport à toi");

            $reply = "🏢 *HEURES OUVRABLES — {$target}*\n"
                . "────────────────\n"
                . "📍 *{$target}* ({$targetTz}) — UTC{$offsetStr}\n"
                . "📅 {$dayName} *{$timeStr}* _{$diffLabel}_\n"
                . "────────────────\n"
                . "{$status}\n"
                . "{$detail}\n"
                . "💡 _{$hint}_\n"
                . "────────────────\n"
                . "_Horaires standards : 9h00–18h00 lun–ven_";

        } catch (\Exception $e) {
            Log::warning("UserPreferencesAgent: business_hours error", [
                'target'   => $target,
                'targetTz' => $targetTz,
                'error'    => $e->getMessage(),
            ]);
            return AgentResult::reply("⚠️ Impossible de vérifier les horaires pour *{$target}*. Réessaie dans quelques instants.");
        }

        return AgentResult::reply($reply, ['action' => 'business_hours', 'target' => $targetTz, 'is_open' => $isWorkingHours ?? false]);
    }

    /**
     * NEW: Meeting planner — find overlapping business hours between user's timezone and a target.
     */
    private function handleMeetingPlanner(array $parsed, array $prefs): AgentResult
    {
        $target = trim($parsed['target'] ?? '');
        $lang   = $prefs['language'] ?? 'fr';

        if ($target === '') {
            return AgentResult::reply(
                "Précise la ville ou le fuseau pour planifier la réunion.\n"
                . "_Ex : planifier réunion Tokyo, meeting planner New York_"
            );
        }

        $targetTz = $this->resolveTimezoneString($target);
        if (!$targetTz) {
            $suggestion = $this->suggestTimezone($target);
            $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
            return AgentResult::reply(
                "Fuseau horaire inconnu : *{$target}*.\n"
                . "Exemples valides : _Tokyo_, _London_, _America/New_York_{$extra}"
            );
        }

        $userTzName = $prefs['timezone'] ?? 'UTC';

        // Same timezone — no need to compare
        if ($userTzName === $targetTz) {
            try {
                $utcNow  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $userNow = $utcNow->setTimezone(new DateTimeZone($userTzName));
            } catch (\Exception) {
                $userNow = new \DateTimeImmutable('now');
            }
            $reply = "📅 *PLANIFICATEUR DE RÉUNION*\n"
                . "────────────────\n"
                . "📍 *Ici* et *{$target}* sont dans le même fuseau (*{$userTzName}*).\n"
                . "────────────────\n"
                . "🟢 Tous les créneaux 9h–18h vous conviennent mutuellement.\n"
                . "────────────────\n"
                . "_Il est actuellement *{$userNow->format('H:i')}* dans ce fuseau._";
            return AgentResult::reply($reply, ['action' => 'meeting_planner', 'target' => $targetTz]);
        }

        try {
            $utcNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $userNow   = $utcNow->setTimezone(new DateTimeZone($userTzName));
            $targetNow = $utcNow->setTimezone(new DateTimeZone($targetTz));

            // Business hours: 9h–18h local time.
            // Use offsets in minutes to correctly handle half-hour timezones (India UTC+5:30, etc.)
            $userOffsetMin   = (int) round($userNow->getOffset() / 60);
            $targetOffsetMin = (int) round($targetNow->getOffset() / 60);

            // Express business hours in UTC minutes
            $userStartUtcMin   = 9 * 60  - $userOffsetMin;
            $userEndUtcMin     = 18 * 60 - $userOffsetMin;
            $targetStartUtcMin = 9 * 60  - $targetOffsetMin;
            $targetEndUtcMin   = 18 * 60 - $targetOffsetMin;

            $overlapStartUtcMin = max($userStartUtcMin, $targetStartUtcMin);
            $overlapEndUtcMin   = min($userEndUtcMin, $targetEndUtcMin);

            $userDay   = $this->getDayName((int) $userNow->format('w'), $lang, short: true);
            $targetDay = $this->getDayName((int) $targetNow->format('w'), $lang, short: true);

            $lines = [
                "📅 *PLANIFICATEUR DE RÉUNION*",
                "────────────────",
                "📍 *Ici* ({$userTzName}) — {$userNow->format('H:i')} {$userDay} (UTC{$userNow->format('P')})",
                "📍 *{$target}* ({$targetTz}) — {$targetNow->format('H:i')} {$targetDay} (UTC{$targetNow->format('P')})",
                "────────────────",
            ];

            if ($overlapEndUtcMin <= $overlapStartUtcMin) {
                // No overlap — suggest the best compromise midpoint
                $midUtcMin = (int) (($userStartUtcMin + $targetEndUtcMin) / 2);
                $midUserMin   = (($midUtcMin + $userOffsetMin) % 1440 + 1440) % 1440;
                $midTargetMin = (($midUtcMin + $targetOffsetMin) % 1440 + 1440) % 1440;

                $lines[] = "🔴 *Aucun créneau commun* en heures ouvrables (9h–18h).";
                $lines[] = "";
                $lines[] = "💡 *Meilleur compromis :*";
                $lines[] = sprintf(
                    "   • Ici : *%02d:%02d*  →  %s : *%02d:%02d*",
                    intdiv($midUserMin, 60), $midUserMin % 60,
                    $target,
                    intdiv($midTargetMin, 60), $midTargetMin % 60
                );
            } else {
                $durationMin = $overlapEndUtcMin - $overlapStartUtcMin;
                $durationH   = intdiv($durationMin, 60);
                $durationM   = $durationMin % 60;
                $durationStr = $durationM > 0 ? "{$durationH}h{$durationM}min" : "{$durationH}h";
                $lines[]     = "🟢 *Créneaux communs* — fenêtre de *{$durationStr}*";
                $lines[]     = "";

                // Show one slot per hour in the overlap window
                $stepUtcMin = $overlapStartUtcMin;
                while ($stepUtcMin < $overlapEndUtcMin) {
                    $hereMin  = (($stepUtcMin + $userOffsetMin) % 1440 + 1440) % 1440;
                    $thereMin = (($stepUtcMin + $targetOffsetMin) % 1440 + 1440) % 1440;
                    $lines[]  = sprintf(
                        "   • *%02d:%02d* ici  →  *%02d:%02d* à %s",
                        intdiv($hereMin, 60), $hereMin % 60,
                        intdiv($thereMin, 60), $thereMin % 60,
                        $target
                    );
                    $stepUtcMin += 60;
                }
            }

            $lines[] = "────────────────";
            $lines[] = "_Horaires standards : 9h00–18h00 lun–ven_";
            $lines[] = "_Pour adopter ce fuseau : timezone {$targetTz}_";

        } catch (\Exception $e) {
            Log::warning("UserPreferencesAgent: meeting_planner error", [
                'target'   => $target,
                'targetTz' => $targetTz,
                'error'    => $e->getMessage(),
            ]);
            return AgentResult::reply("⚠️ Impossible de calculer les créneaux. Vérifie les fuseaux configurés.");
        }

        return AgentResult::reply(implode("\n", $lines), ['action' => 'meeting_planner', 'target' => $targetTz]);
    }

    /**
     * NEW: Multi-meeting planner — find overlapping business hours for 3+ cities simultaneously.
     */
    private function handleMultiMeeting(array $parsed, array $prefs): AgentResult
    {
        $cities     = $parsed['cities'] ?? [];
        $lang       = $prefs['language'] ?? 'fr';
        $userTzName = $prefs['timezone'] ?? 'UTC';

        if (empty($cities) || !is_array($cities)) {
            return AgentResult::reply(
                "Précise les villes pour la réunion multi-fuseau.\n"
                . "_Ex : réunion multi Paris Tokyo New York, multi-meeting London Dubai Sydney_"
            );
        }

        // Resolve timezones for all requested cities
        $cityMap = [];
        $unknown = [];

        foreach ($cities as $city) {
            $city = trim((string) $city);
            if ($city === '') {
                continue;
            }
            $tz = $this->resolveTimezoneString($city);
            if ($tz) {
                $cityMap[$city] = $tz;
            } else {
                $unknown[] = $city;
            }
        }

        if (empty($cityMap)) {
            return AgentResult::reply(
                "⚠️ Aucune ville reconnue. Essaie : _Paris_, _Tokyo_, _New York_, _London_…"
            );
        }

        try {
            $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            // Always include user's own timezone as "Ici"
            $allCities = array_merge(['Ici' => $userTzName], $cityMap);

            // Deduplicate: keep first label per unique timezone
            $seen    = [];
            $deduped = [];
            foreach ($allCities as $label => $tz) {
                if (!in_array($tz, $seen, true)) {
                    $seen[]          = $tz;
                    $deduped[$label] = $tz;
                }
            }
            $allCities = $deduped;

            // Compute per-city current time info and find overlap window
            $cityInfos          = [];
            $overlapStartUtcMin = PHP_INT_MIN;
            $overlapEndUtcMin   = PHP_INT_MAX;

            foreach ($allCities as $label => $tz) {
                $cityNow   = $utcNow->setTimezone(new DateTimeZone($tz));
                $offsetMin = (int) round($cityNow->getOffset() / 60);
                $startUtc  = 9 * 60 - $offsetMin;   // 9h local in UTC minutes
                $endUtc    = 18 * 60 - $offsetMin;  // 18h local in UTC minutes

                $overlapStartUtcMin = max($overlapStartUtcMin, $startUtc);
                $overlapEndUtcMin   = min($overlapEndUtcMin, $endUtc);

                $cityInfos[$label] = [
                    'tz'        => $tz,
                    'offsetMin' => $offsetMin,
                    'timeStr'   => $cityNow->format('H:i'),
                    'offset'    => $cityNow->format('P'),
                    'day'       => $this->getDayName((int) $cityNow->format('w'), $lang, short: true),
                ];
            }

            $cityCount = count($allCities);
            $lines = [
                "📅 *PLANIFICATEUR MULTI-FUSEAU* ({$cityCount} fuseaux)",
                "────────────────",
            ];

            foreach ($cityInfos as $label => $info) {
                $lines[] = "📍 *{$label}* ({$info['tz']}) — {$info['timeStr']} {$info['day']} (UTC{$info['offset']})";
            }

            $lines[] = "────────────────";

            if ($overlapEndUtcMin <= $overlapStartUtcMin) {
                // No overlap — show 9h opening time for each city expressed in all others
                $lines[] = "🔴 *Aucun créneau commun* en heures ouvrables (9h–18h).";
                $lines[] = "";
                $lines[] = "💡 *Ouverture des bureaux (9h local) dans chaque fuseau :*";

                foreach ($cityInfos as $label => $info) {
                    // 9h local → UTC: utcMin = 9*60 - offsetMin
                    $open9UtcMin = 9 * 60 - $info['offsetMin'];
                    $slotParts   = [];
                    foreach ($cityInfos as $lbl2 => $info2) {
                        $localMin    = (($open9UtcMin + $info2['offsetMin']) % 1440 + 1440) % 1440;
                        $slotParts[] = sprintf("*%02d:%02d* à %s", intdiv($localMin, 60), $localMin % 60, $lbl2);
                    }
                    $lines[] = "   • 9h à *{$label}* → " . implode(' / ', $slotParts);
                }

                $lines[] = "";
                $lines[] = "💡 _Envisage des horaires décalés ou des réunions tournantes._";
            } else {
                $durationMin = $overlapEndUtcMin - $overlapStartUtcMin;
                $durationH   = intdiv($durationMin, 60);
                $durationM   = $durationMin % 60;
                $durationStr = $durationM > 0 ? "{$durationH}h{$durationM}min" : "{$durationH}h";
                $lines[]     = "🟢 *Créneaux communs* — fenêtre de *{$durationStr}*";
                $lines[]     = "";

                $stepUtcMin = $overlapStartUtcMin;
                while ($stepUtcMin < $overlapEndUtcMin) {
                    $slotParts = [];
                    foreach ($cityInfos as $label => $info) {
                        $localMin    = (($stepUtcMin + $info['offsetMin']) % 1440 + 1440) % 1440;
                        $slotParts[] = sprintf("*%02d:%02d* (%s)", intdiv($localMin, 60), $localMin % 60, $label);
                    }
                    $lines[]    = "   • " . implode(' / ', $slotParts);
                    $stepUtcMin += 60;
                }
            }

            if (!empty($unknown)) {
                $lines[] = "\n⚠️ Non reconnu : " . implode(', ', $unknown);
            }

            $lines[] = "────────────────";
            $lines[] = "_Horaires standards : 9h00–18h00 lun–ven_";
            $lines[] = "_Pour deux villes : meeting planner Tokyo_";

        } catch (\Exception $e) {
            Log::warning("UserPreferencesAgent: multi_meeting error", ['error' => $e->getMessage()]);
            return AgentResult::reply("⚠️ Impossible de calculer les créneaux communs. Vérifie les villes saisies.");
        }

        return AgentResult::reply(
            implode("\n", $lines),
            ['action' => 'multi_meeting', 'city_count' => count($allCities ?? [])]
        );
    }

    /**
     * NEW: Countdown — how many days/hours until a target date.
     */
    private function handleCountdown(array $parsed, array $prefs): AgentResult
    {
        $targetDateStr = trim($parsed['target_date'] ?? '');
        $label         = trim($parsed['label'] ?? '');
        $lang          = $prefs['language'] ?? 'fr';
        $tzName        = $prefs['timezone'] ?? 'UTC';
        $dateFormat    = $prefs['date_format'] ?? 'd/m/Y';

        if ($targetDateStr === '') {
            return AgentResult::reply(
                "Précise la date cible du compte à rebours.\n"
                . "_Ex : countdown 2026-12-25, dans combien de temps est Noël, jours restants avant le 1er janvier_"
            );
        }

        try {
            $tz         = new DateTimeZone($tzName);
            $now        = new DateTimeImmutable('now', $tz);
            $targetDate = new DateTimeImmutable($targetDateStr . ' 00:00:00', $tz);
        } catch (\Exception) {
            return AgentResult::reply(
                "⚠️ Date invalide : *{$targetDateStr}*.\n"
                . "_Utilise le format AAAA-MM-JJ, ex : 2026-12-25_"
            );
        }

        // If date already passed this year and no year specified, check if we should roll to next year
        $nowMidnight    = new DateTimeImmutable($now->format('Y-m-d') . ' 00:00:00', new DateTimeZone($tzName));
        $diffFull       = $nowMidnight->diff($targetDate);
        $totalDays      = (int) $diffFull->days;
        $isPast         = $targetDate < $nowMidnight;

        $targetFormatted = $targetDate->format($dateFormat);
        $dayName         = $this->getDayName((int) $targetDate->format('w'), $lang);
        $titleStr        = $label !== '' ? " — *{$label}*" : '';

        if ($isPast) {
            $daysAgo = $totalDays;
            $reply = "📅 *COMPTE À REBOURS*{$titleStr}\n"
                . "────────────────\n"
                . "📌 Date : *{$dayName} {$targetFormatted}*\n"
                . "────────────────\n"
                . "⏮ Cette date est *passée il y a {$daysAgo} jour(s)*.\n"
                . "────────────────\n"
                . "_Pour une date future : countdown 2027-01-01_";

            return AgentResult::reply($reply, ['action' => 'countdown', 'days' => -$daysAgo, 'label' => $label]);
        }

        if ($totalDays === 0) {
            $reply = "📅 *COMPTE À REBOURS*{$titleStr}\n"
                . "────────────────\n"
                . "📌 Date : *{$dayName} {$targetFormatted}*\n"
                . "────────────────\n"
                . "🎉 *C'est aujourd'hui !*\n"
                . "────────────────";

            return AgentResult::reply($reply, ['action' => 'countdown', 'days' => 0, 'label' => $label]);
        }

        // Decompose in weeks and days
        $weeks      = intdiv($totalDays, 7);
        $remDays    = $totalDays % 7;
        $breakdown  = '';
        if ($weeks > 0 && $remDays > 0) {
            $breakdown = " _({$weeks} sem. + {$remDays} j)_";
        } elseif ($weeks > 0) {
            $breakdown = " _({$weeks} semaine(s))_";
        }

        // Time-of-year context
        $months  = (int) $diffFull->m + ((int) $diffFull->y * 12);
        $approx  = '';
        if ($months >= 24) {
            $years  = round($totalDays / 365.25, 1);
            $approx = " ≈ {$years} ans";
        } elseif ($months >= 2) {
            $approx = " ≈ {$months} mois";
        }

        // Progress bar toward year end
        $yearStart    = new DateTimeImmutable($now->format('Y') . '-01-01 00:00:00', new DateTimeZone($tzName));
        $yearEnd      = new DateTimeImmutable($now->format('Y') . '-12-31 23:59:59', new DateTimeZone($tzName));
        $yearDays     = (int) $yearStart->diff($yearEnd)->days + 1;
        $dayOfYear    = (int) $now->format('z') + 1;
        $yearProgress = min(100, (int) round(($dayOfYear / $yearDays) * 10));
        $progressBar  = str_repeat('▓', $yearProgress) . str_repeat('░', 10 - $yearProgress);

        $reply = "📅 *COMPTE À REBOURS*{$titleStr}\n"
            . "────────────────\n"
            . "📌 Date cible : *{$dayName} {$targetFormatted}*\n"
            . "📍 Depuis ton fuseau : *{$tzName}*\n"
            . "────────────────\n"
            . "⏳ Il reste *{$totalDays} jour(s)*{$breakdown}{$approx}\n"
            . "────────────────\n"
            . "📆 Progression de l'année [{$progressBar}]\n"
            . "────────────────\n"
            . "_Pour un autre compte à rebours : countdown 2027-01-01_";

        return AgentResult::reply($reply, ['action' => 'countdown', 'days' => $totalDays, 'label' => $label]);
    }

    /**
     * NEW: DST info — heure d'été/hiver, prochain changement, status.
     */
    private function handleDstInfo(array $parsed, array $prefs): AgentResult
    {
        $target  = trim($parsed['target'] ?? '');
        $lang    = $prefs['language'] ?? 'fr';

        // Resolve target timezone (defaults to user's timezone)
        if ($target === '') {
            $tzName = $prefs['timezone'] ?? 'UTC';
        } else {
            $resolved = $this->resolveTimezoneString($target);
            if (!$resolved) {
                $suggestion = $this->suggestTimezone($target);
                $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
                return AgentResult::reply(
                    "Ville ou fuseau inconnu : *{$target}*.\n"
                    . "Exemples : _Paris_, _London_, _America/New_York_{$extra}"
                );
            }
            $tzName = $resolved;
        }

        $isDst = false; // Initialize before try block to prevent undefined variable

        try {
            $tz  = new DateTimeZone($tzName);
            $now = new DateTimeImmutable('now', $tz);

            $offsetNow = $now->getOffset(); // seconds
            $offsetH   = intdiv(abs($offsetNow), 3600);
            $offsetM   = (abs($offsetNow) % 3600) / 60;
            $offsetSign = $offsetNow >= 0 ? '+' : '-';
            $offsetStr  = $offsetM > 0
                ? sprintf('%s%02d:%02d', $offsetSign, $offsetH, $offsetM)
                : sprintf('%s%02d:00', $offsetSign, $offsetH);

            // Check DST transitions for current year (±1 year window)
            $yearStart = (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('U') - 365 * 86400;
            $yearEnd   = $yearStart + 2 * 365 * 86400;
            $transitions = $tz->getTransitions($yearStart, $yearEnd);

            // Find current DST state
            $currentTransition = null;
            $nextTransition    = null;
            $prevTransition    = null;
            $nowTs             = (int) $now->format('U');

            foreach ($transitions as $i => $t) {
                if ($t['ts'] <= $nowTs) {
                    $currentTransition = $t;
                    $prevTransition    = $transitions[$i - 1] ?? null;
                } elseif ($nextTransition === null) {
                    $nextTransition = $t;
                }
            }

            $isDst    = $currentTransition['isdst'] ?? false;
            $tzAbbr   = $currentTransition['abbr'] ?? $now->format('T');
            $cityLabel = $target !== '' ? $target : $tzName;

            $dstIcon   = $isDst ? '☀️' : '❄️';
            $dstStatus = $isDst ? '*Heure d\'été* (DST actif)' : '*Heure d\'hiver* (DST inactif)';

            $lines = [
                "🕐 *HEURE D'ÉTÉ / HIVER*",
                "────────────────",
                "📍 Fuseau : *{$tzName}* ({$tzAbbr})",
                "🕐 Décalage actuel : *UTC{$offsetStr}*",
                "────────────────",
                "{$dstIcon} {$dstStatus}",
            ];

            // Next DST transition
            if ($nextTransition !== null) {
                $nextDt     = new DateTimeImmutable('@' . $nextTransition['ts']);
                $nextDtLocal = $nextDt->setTimezone($tz);
                $nextDate   = $nextDtLocal->format($prefs['date_format'] ?? 'd/m/Y');
                $nextDay    = $this->getDayName((int) $nextDtLocal->format('w'), $lang);
                $nextAbbr   = $nextTransition['abbr'];
                $nextIsDst  = $nextTransition['isdst'];
                $nextLabel  = $nextIsDst ? "passage à l'heure d'été ({$nextAbbr})" : "passage à l'heure d'hiver ({$nextAbbr})";

                // Days until transition
                $daysUntil  = (int) ceil(($nextTransition['ts'] - $nowTs) / 86400);

                $lines[] = "";
                $lines[] = "🔄 *Prochain changement :*";
                $lines[] = "   📅 *{$nextDay} {$nextDate}* — {$nextLabel}";
                $lines[] = "   _(dans {$daysUntil} jour(s))_";
            } else {
                $lines[] = "";
                $lines[] = "ℹ️ _Aucun changement d'heure prévu dans ce fuseau._";
            }

            // No DST zones info
            if (empty($transitions) || count($transitions) <= 1) {
                $lines[] = "";
                $lines[] = "ℹ️ _Ce fuseau n'observe pas l'heure d'été (pas de DST)._";
            }

            $lines[] = "────────────────";
            $lines[] = "_Pour voir l'heure d'été d'une autre ville : DST Tokyo_";

        } catch (\Exception $e) {
            Log::warning("UserPreferencesAgent: dst_info error", [
                'target' => $target,
                'tzName' => $tzName ?? 'unknown',
                'error'  => $e->getMessage(),
            ]);
            $errLabel = $target !== '' ? $target : 'ton fuseau';
            return AgentResult::reply("⚠️ Impossible d'obtenir les informations DST pour *{$errLabel}*. Réessaie dans quelques instants.");
        }

        return AgentResult::reply(implode("\n", $lines), ['action' => 'dst_info', 'timezone' => $tzName, 'is_dst' => $isDst]);
    }

    /**
     * Timezone search — list IANA timezone identifiers matching a query.
     */
    private function handleTimezoneSearch(array $parsed): AgentResult
    {
        $query = mb_strtolower(trim($parsed['query'] ?? ''));

        if ($query === '') {
            $regions = ['Africa', 'America', 'Antarctica', 'Asia', 'Atlantic', 'Australia', 'Europe', 'Indian', 'Pacific', 'UTC'];
            return AgentResult::reply(
                "Précise une région ou un pays à rechercher.\n\n"
                . "_Régions disponibles : " . implode(', ', $regions) . "_\n"
                . "_Ex : fuseaux Europe, timezones America, recherche Asia_"
            );
        }

        $allIds  = DateTimeZone::listIdentifiers();
        $matches = array_values(array_filter($allIds, fn($id) => str_contains(mb_strtolower($id), $query)));
        $total   = count($matches);

        if ($total === 0) {
            $regions = ['Africa', 'America', 'Asia', 'Australia', 'Europe', 'Pacific', 'UTC'];
            return AgentResult::reply(
                "Aucun fuseau trouvé pour *{$query}*.\n\n"
                . "_Régions disponibles : " . implode(', ', $regions) . "_\n"
                . "_Ex : fuseaux Europe, fuseaux America/New_"
            );
        }

        // Limit display to 25 items
        $shown = array_slice($matches, 0, 25);
        $extra = $total > 25 ? " _(25 affichés sur {$total})_" : '';

        $lines   = ["🔍 *FUSEAUX HORAIRES* — _{$query}_{$extra}", "────────────────"];
        foreach ($shown as $tz) {
            $lines[] = "• {$tz}";
        }

        if ($total > 25) {
            $lines[] = "_…et " . ($total - 25) . " autres. Affine ta recherche._";
        }

        $lines[] = "────────────────";
        $lines[] = "_Pour utiliser un fuseau : timezone Europe/Paris_";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'timezone_search', 'query' => $query, 'count' => $total]);
    }

    /**
     * NEW: Convert a specific time from one timezone to another.
     */
    private function handleConvertTime(array $parsed, array $prefs): AgentResult
    {
        $timeStr   = trim($parsed['time'] ?? '');
        $fromInput = trim($parsed['from'] ?? '');
        $toInput   = trim($parsed['to']   ?? '');
        $lang      = $prefs['language'] ?? 'fr';
        $dateFmt   = $prefs['date_format'] ?? 'd/m/Y';

        if ($timeStr === '') {
            return AgentResult::reply(
                "Précise l'heure à convertir.\n"
                . "_Ex : convertir 14h30 de Tokyo à Paris, si c'est 9h à New York quelle heure est-il à Dubai_"
            );
        }

        $normalizedTime = $this->parseTimeString($timeStr);
        if ($normalizedTime === null) {
            return AgentResult::reply(
                "Heure invalide : *{$timeStr}*.\n"
                . "_Formats acceptés : 14:30 / 14h30 / 9h / 2pm / 2:30pm_"
            );
        }

        // Resolve "from" timezone (default = user's timezone)
        if ($fromInput === '') {
            $fromTz    = $prefs['timezone'] ?? 'UTC';
            $fromLabel = $fromTz;
        } else {
            $fromTz = $this->resolveTimezoneString($fromInput);
            if (!$fromTz) {
                $suggestion = $this->suggestTimezone($fromInput);
                $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
                return AgentResult::reply(
                    "Fuseau source inconnu : *{$fromInput}*.\n"
                    . "_Exemples : Tokyo, Europe/Paris, UTC+2_{$extra}"
                );
            }
            $fromLabel = $fromInput;
        }

        // Resolve "to" timezone (default = user's timezone)
        if ($toInput === '') {
            $toTz    = $prefs['timezone'] ?? 'UTC';
            $toLabel = $toTz;
        } else {
            $toTz = $this->resolveTimezoneString($toInput);
            if (!$toTz) {
                $suggestion = $this->suggestTimezone($toInput);
                $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
                return AgentResult::reply(
                    "Fuseau cible inconnu : *{$toInput}*.\n"
                    . "_Exemples : Paris, America/New_York, UTC-5_{$extra}"
                );
            }
            $toLabel = $toInput;
        }

        try {
            $utcNow  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $fromNow = $utcNow->setTimezone(new DateTimeZone($fromTz));
            $todayStr = $fromNow->format('Y-m-d');

            // Build the source datetime with today's date (important for DST accuracy)
            $fromDt = new DateTimeImmutable("{$todayStr} {$normalizedTime}:00", new DateTimeZone($fromTz));
            $toDt   = $fromDt->setTimezone(new DateTimeZone($toTz));

            $fromTimeStr = $fromDt->format('H:i');
            $toTimeStr   = $toDt->format('H:i');
            $fromOffset  = $fromDt->format('P');
            $toOffset    = $toDt->format('P');
            $fromDay     = $this->getDayName((int) $fromDt->format('w'), $lang, short: true);
            $toDay       = $this->getDayName((int) $toDt->format('w'), $lang, short: true);

            // Day change indicator
            $dayDiff = '';
            if ($fromDt->format('Y-m-d') !== $toDt->format('Y-m-d')) {
                $dayDiff = $toDt->format('Y-m-d') > $fromDt->format('Y-m-d')
                    ? " _(+1 jour, {$toDay})_"
                    : " _(-1 jour, {$toDay})_";
            }

            // Offset difference
            $diffSecs = $toDt->getOffset() - $fromDt->getOffset();
            $sign     = $diffSecs >= 0 ? '+' : '-';
            $absH     = intdiv(abs($diffSecs), 3600);
            $absM     = (abs($diffSecs) % 3600) / 60;
            $diffStr  = $diffSecs === 0
                ? 'même fuseau'
                : ($absM > 0 ? "{$sign}{$absH}h{$absM}min" : "{$sign}{$absH}h");

            $dateLabel = $fromDt->format($dateFmt);

            $reply = "🔄 *CONVERSION D'HEURE*\n"
                . "────────────────\n"
                . "⏰ *{$fromTimeStr}* à *{$fromLabel}* (UTC{$fromOffset})\n"
                . "   ↕ décalage *{$diffStr}*\n"
                . "⏰ *{$toTimeStr}*{$dayDiff} à *{$toLabel}* (UTC{$toOffset})\n"
                . "────────────────\n"
                . "📅 _Basé sur le {$dateLabel}_\n"
                . "────────────────\n"
                . "_Pour planifier une réunion : meeting planner {$toLabel}_\n"
                . "_Pour comparer les fuseaux : heure à {$toLabel}_";

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: convert_time error', [
                'from'  => $fromTz,
                'to'    => $toTz,
                'time'  => $normalizedTime,
                'error' => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Impossible de convertir l'heure. Vérifie les fuseaux saisis.\n"
                . "_Ex : convertir 14h30 de Tokyo à Paris_"
            );
        }

        return AgentResult::reply($reply, ['action' => 'convert_time', 'from' => $fromTz, 'to' => $toTz, 'time' => $normalizedTime]);
    }

    /**
     * Calendar week — show the 7 days of the current/next/previous week with today highlighted.
     * Supports week_offset: 0 = this week, 1 = next week, -1 = last week, etc.
     */
    private function handleCalendarWeek(array $parsed, array $prefs): AgentResult
    {
        $tzName     = $prefs['timezone'] ?? 'UTC';
        $lang       = $prefs['language'] ?? 'fr';
        $dateFmt    = $prefs['date_format'] ?? 'd/m/Y';
        $weekOffset = (int) ($parsed['week_offset'] ?? 0);

        try {
            $tz    = new DateTimeZone($tzName);
            $today = new DateTimeImmutable('now', $tz);

            // Determine the reference day based on week_offset
            $referenceDay = $weekOffset !== 0
                ? $today->modify(($weekOffset > 0 ? '+' : '') . ($weekOffset * 7) . ' days')
                : $today;

            $weekNum = $referenceDay->format('W');
            $year    = $referenceDay->format('Y');

            // ISO: N = 1 (Mon) … 7 (Sun) — start week on Monday
            $dayOfWeekIso = (int) $referenceDay->format('N');
            $monday       = $referenceDay->modify('-' . ($dayOfWeekIso - 1) . ' days');

            // Week label
            $weekLabel = match (true) {
                $weekOffset === 0  => "SEMAINE {$weekNum}",
                $weekOffset === 1  => "SEMAINE {$weekNum} _(prochaine)_",
                $weekOffset === -1 => "SEMAINE {$weekNum} _(précédente)_",
                $weekOffset > 1   => "SEMAINE {$weekNum} _(dans {$weekOffset} sem.)_",
                default            => "SEMAINE {$weekNum} _(il y a " . abs($weekOffset) . " sem.)_",
            };

            $lines = [
                "📅 *{$weekLabel}* — {$year}",
                "────────────────",
            ];

            for ($i = 0; $i < 7; $i++) {
                $day       = $monday->modify("+{$i} days");
                $isToday   = $day->format('Y-m-d') === $today->format('Y-m-d');
                $isoDay    = (int) $day->format('N');
                $isWeekend = $isoDay >= 6;
                $dayName   = $this->getDayName((int) $day->format('w'), $lang);
                $dateStr   = $day->format($dateFmt);
                $icon      = $isWeekend ? '🏖️' : '💼';

                if ($isToday) {
                    $timeStr = $today->format('H:i');
                    $lines[] = "{$icon} *{$dayName} {$dateStr}* ◀ _{$timeStr}_ _(aujourd'hui)_";
                } else {
                    $lines[] = "{$icon} {$dayName} {$dateStr}";
                }
            }

            $lines[] = "────────────────";
            $lines[] = "📍 _{$tzName}_ (UTC{$today->format('P')})";

            if ($weekOffset !== 0) {
                $lines[] = "_Revenir à cette semaine : cette semaine_";
            } else {
                $lines[] = "_Navigation : semaine prochaine / semaine précédente_";
            }

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: calendar_week error', ['error' => $e->getMessage()]);
            return AgentResult::reply("⚠️ Impossible d'afficher le calendrier. Vérifie ton fuseau horaire.");
        }

        return AgentResult::reply(implode("\n", $lines), ['action' => 'calendar_week', 'week' => $weekNum ?? '?', 'offset' => $weekOffset]);
    }

    /**
     * NEW: Monthly calendar — display the days of a given month, grouped by ISO week.
     */
    private function handleCalendarMonth(array $parsed, array $prefs): AgentResult
    {
        $tzName      = $prefs['timezone'] ?? 'UTC';
        $lang        = $prefs['language'] ?? 'fr';
        $dateFmt     = $prefs['date_format'] ?? 'd/m/Y';
        $monthOffset = (int) ($parsed['month_offset'] ?? 0);

        try {
            $tz    = new DateTimeZone($tzName);
            $today = new DateTimeImmutable('now', $tz);

            // Use 'first day of' to avoid month overflow (e.g. Jan 31 + 1 month → Feb 1, not Mar 2)
            $reference = $monthOffset !== 0
                ? $today->modify('first day of ' . ($monthOffset > 0 ? "+{$monthOffset}" : "{$monthOffset}") . ' month')
                : $today;

            $year        = (int) $reference->format('Y');
            $month       = (int) $reference->format('n');
            $monthName   = $this->getMonthName($month, $lang);
            $daysInMonth = (int) (new DateTimeImmutable("last day of {$year}-{$month}", $tz))->format('j');

            $monthLabel = match (true) {
                $monthOffset === 0  => strtoupper($monthName) . " {$year}",
                $monthOffset === 1  => strtoupper($monthName) . " {$year} _(mois prochain)_",
                $monthOffset === -1 => strtoupper($monthName) . " {$year} _(mois précédent)_",
                $monthOffset > 1    => strtoupper($monthName) . " {$year} _(dans {$monthOffset} mois)_",
                default             => strtoupper($monthName) . " {$year} _(il y a " . abs($monthOffset) . " mois)_",
            };

            $lines = [
                "📅 *{$monthLabel}* — {$daysInMonth} jours",
                "────────────────",
            ];

            // Build weeks indexed by ISO week number
            $weeks = [];

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dayDt   = new DateTimeImmutable("{$year}-{$month}-{$d}", $tz);
                $isoDay  = (int) $dayDt->format('N'); // 1=Mon .. 7=Sun
                $weekNum = (int) $dayDt->format('W');
                $isToday   = $dayDt->format('Y-m-d') === $today->format('Y-m-d');
                $isWeekend = $isoDay >= 6;

                if (!isset($weeks[$weekNum])) {
                    $weeks[$weekNum] = [];
                }

                if ($isToday) {
                    $weeks[$weekNum][] = "*{$d}*";
                } elseif ($isWeekend) {
                    $weeks[$weekNum][] = "_{$d}_";
                } else {
                    $weeks[$weekNum][] = (string) $d;
                }
            }

            foreach ($weeks as $weekNum => $days) {
                $lines[] = "S{$weekNum} │ " . implode(' · ', $days);
            }

            $lines[] = "────────────────";
            $lines[] = "📍 _{$tzName}_ · _*gras*=aujourd'hui · italique=week-end_";

            if ($monthOffset !== 0) {
                $lines[] = "_Revenir au mois courant : calendrier du mois_";
            } else {
                $lines[] = "_Navigation : mois prochain / mois précédent_";
            }

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: calendar_month error', ['error' => $e->getMessage()]);
            return AgentResult::reply("⚠️ Impossible d'afficher le calendrier mensuel. Vérifie ton fuseau horaire.");
        }

        return AgentResult::reply(
            implode("\n", $lines),
            ['action' => 'calendar_month', 'month' => $month ?? '?', 'offset' => $monthOffset]
        );
    }

    /**
     * NEW: Sun times — sunrise, sunset and solar noon for the user's timezone or a given city.
     */
    private function handleSunTimes(array $parsed, array $prefs): AgentResult
    {
        $target  = trim($parsed['target'] ?? '');
        $lang    = $prefs['language'] ?? 'fr';
        $dateFmt = $prefs['date_format'] ?? 'd/m/Y';

        if ($target === '') {
            $tzName    = $prefs['timezone'] ?? 'UTC';
            $cityLabel = $tzName;
        } else {
            $resolved = $this->resolveTimezoneString($target);
            if (!$resolved) {
                $suggestion = $this->suggestTimezone($target);
                $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
                return AgentResult::reply(
                    "Ville ou fuseau inconnu : *{$target}*.\n"
                    . "Exemples : _Paris_, _London_, _Tokyo_{$extra}"
                );
            }
            $tzName    = $resolved;
            $cityLabel = $target;
        }

        try {
            $tz       = new DateTimeZone($tzName);
            $location = $tz->getLocation();
            $lat      = $location['latitude'] ?? null;
            $lon      = $location['longitude'] ?? null;

            if ($lat === null || $lon === null || ($lat == 0.0 && $lon == 0.0 && !str_contains($tzName, 'Africa'))) {
                return AgentResult::reply(
                    "⚠️ Coordonnées non disponibles pour *{$tzName}*.\n"
                    . "_Essaie avec un fuseau géographique, ex : Europe/Paris, Asia/Tokyo._"
                );
            }

            $today   = new DateTimeImmutable('now', $tz);
            $noonTs  = (int) $today->setTime(12, 0, 0)->format('U');
            $sunInfo = date_sun_info($noonTs, (float) $lat, (float) $lon);

            $dateStr = $today->format($dateFmt);
            $dayName = $this->getDayName((int) $today->format('w'), $lang);

            $lines = [
                "🌅 *LEVER / COUCHER DU SOLEIL*",
                "────────────────",
                "📍 *{$cityLabel}* ({$tzName})",
                "📅 {$dayName} {$dateStr}",
                "────────────────",
            ];

            $sunrise = $sunInfo['sunrise'] ?? false;
            $sunset  = $sunInfo['sunset']  ?? false;
            $transit = $sunInfo['transit'] ?? false;

            if (!is_int($sunrise) || !is_int($sunset)) {
                if (is_int($transit)) {
                    $transitDt = (new DateTimeImmutable('@' . $transit))->setTimezone($tz);
                    $lines[] = "☀️ *Jour polaire* — le soleil reste visible toute la journée.";
                    $lines[] = "🌞 Midi solaire : *" . $transitDt->format('H:i') . "*";
                } else {
                    $lines[] = "🌙 *Nuit polaire* — le soleil ne se lève pas aujourd'hui.";
                }
            } else {
                $sunriseDt = (new DateTimeImmutable('@' . $sunrise))->setTimezone($tz);
                $sunsetDt  = (new DateTimeImmutable('@' . $sunset))->setTimezone($tz);

                $durationSecs = $sunset - $sunrise;
                $durH         = intdiv($durationSecs, 3600);
                $durM         = ($durationSecs % 3600) / 60;
                $durStr       = sprintf('%dh%02dmin', $durH, $durM);

                $lines[] = "🌄 Lever : *" . $sunriseDt->format('H:i') . "*";

                if (is_int($transit)) {
                    $transitDt = (new DateTimeImmutable('@' . $transit))->setTimezone($tz);
                    $lines[] = "🌞 Midi solaire : *" . $transitDt->format('H:i') . "*";
                }

                $lines[] = "🌇 Coucher : *" . $sunsetDt->format('H:i') . "*";
                $lines[] = "────────────────";
                $lines[] = "⏱ Durée du jour : *{$durStr}*";

                // Compare with yesterday to show if days are getting longer/shorter
                $yesterdayTs   = $noonTs - 86400;
                $yesterdaySun  = date_sun_info($yesterdayTs, (float) $lat, (float) $lon);
                $ySunrise      = $yesterdaySun['sunrise'] ?? false;
                $ySunset       = $yesterdaySun['sunset']  ?? false;

                if (is_int($ySunrise) && is_int($ySunset)) {
                    $yDuration = $ySunset - $ySunrise;
                    $diff      = $durationSecs - $yDuration;
                    if (abs($diff) >= 60) {
                        $sign    = $diff > 0 ? '+' : '';
                        $diffMin = (int) round($diff / 60);
                        $trend   = $diff > 0 ? '📈 journées qui s\'allongent' : '📉 journées qui raccourcissent';
                        $lines[] = "_{$sign}{$diffMin}min vs hier — {$trend}_";
                    }
                }
            }

            // Civil twilight (dawn/dusk)
            $dawnTs = $sunInfo['civil_twilight_begin'] ?? false;
            $duskTs = $sunInfo['civil_twilight_end']   ?? false;

            if (is_int($dawnTs) && is_int($duskTs)) {
                $dawnDt = (new DateTimeImmutable('@' . $dawnTs))->setTimezone($tz);
                $duskDt = (new DateTimeImmutable('@' . $duskTs))->setTimezone($tz);
                $lines[] = "────────────────";
                $lines[] = "🌆 Crépuscule civil : *" . $dawnDt->format('H:i') . "* → *" . $duskDt->format('H:i') . "*";
            }

            $lines[] = "────────────────";
            $target !== ''
                ? $lines[] = "_Pour ton fuseau : lever du soleil_"
                : $lines[] = "_Pour une autre ville : lever du soleil Tokyo_";

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: sun_times error', [
                'target' => $target,
                'tzName' => $tzName ?? 'unknown',
                'error'  => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Impossible de calculer les heures de lever/coucher du soleil.\n"
                . "_Essaie avec un fuseau géographique, ex : lever du soleil Paris_"
            );
        }

        return AgentResult::reply(
            implode("\n", $lines),
            ['action' => 'sun_times', 'timezone' => $tzName]
        );
    }

    /**
     * NEW: Age calculator — compute exact age from a birthdate and days until next birthday.
     */
    private function handleAge(array $parsed, array $prefs): AgentResult
    {
        $birthdateStr = trim($parsed['birthdate'] ?? '');
        $lang         = $prefs['language'] ?? 'fr';
        $tzName       = $prefs['timezone'] ?? 'UTC';
        $dateFmt      = $prefs['date_format'] ?? 'd/m/Y';

        if ($birthdateStr === '') {
            return AgentResult::reply(
                "Précise la date de naissance au format AAAA-MM-JJ.\n"
                . "_Ex : quel âge ai-je si je suis né le 1990-05-15_\n"
                . "_Ex : calcule mon âge, née le 1985-12-25_"
            );
        }

        try {
            $tz        = new DateTimeZone($tzName);
            $today     = new DateTimeImmutable('now', $tz);
            $birthDate = new DateTimeImmutable($birthdateStr . ' 00:00:00', $tz);
        } catch (\Exception) {
            return AgentResult::reply(
                "⚠️ Date de naissance invalide : *{$birthdateStr}*.\n"
                . "_Utilise le format AAAA-MM-JJ, ex : 1990-05-15_"
            );
        }

        if ($birthDate > $today) {
            return AgentResult::reply(
                "⚠️ La date *{$birthdateStr}* est dans le futur.\n"
                . "_Vérifie la date saisie._"
            );
        }

        $diff   = $today->diff($birthDate);
        $years  = (int) $diff->y;
        $months = (int) $diff->m;
        $days   = (int) $diff->d;

        // Compute next birthday
        $todayMidnight = new DateTimeImmutable($today->format('Y-m-d') . ' 00:00:00', $tz);

        // Handle Feb 29 birthdays: use Mar 1 in non-leap years
        $bdayMonthDay = $birthDate->format('m-d');
        if ($bdayMonthDay === '02-29') {
            $thisYear  = (int) $today->format('Y');
            $bdayThisYear = checkdate(2, 29, $thisYear) ? "{$thisYear}-02-29" : "{$thisYear}-03-01";
            $nextYear     = $thisYear + 1;
            $bdayNextYear = checkdate(2, 29, $nextYear) ? "{$nextYear}-02-29" : "{$nextYear}-03-01";
        } else {
            $bdayThisYear = $today->format('Y') . "-{$bdayMonthDay}";
            $bdayNextYear = ((int) $today->format('Y') + 1) . "-{$bdayMonthDay}";
        }

        try {
            $thisYearBirthday = new DateTimeImmutable($bdayThisYear . ' 00:00:00', $tz);
        } catch (\Exception) {
            $thisYearBirthday = new DateTimeImmutable($bdayNextYear . ' 00:00:00', $tz);
        }

        $nextBirthday = ($thisYearBirthday <= $todayMidnight)
            ? new DateTimeImmutable($bdayNextYear . ' 00:00:00', $tz)
            : $thisYearBirthday;

        $daysUntilBirthday  = (int) $todayMidnight->diff($nextBirthday)->days;
        $birthdayFormatted  = $nextBirthday->format($dateFmt);
        $birthdayDay        = $this->getDayName((int) $nextBirthday->format('w'), $lang);
        $birthdateFormatted = $birthDate->format($dateFmt);
        $totalDays          = (int) $todayMidnight->diff(
            new DateTimeImmutable($birthDate->format('Y-m-d') . ' 00:00:00', $tz)
        )->days;

        if ($daysUntilBirthday === 0) {
            $birthdayMsg = "🎉 *Joyeux anniversaire aujourd'hui !*";
        } elseif ($daysUntilBirthday <= 7) {
            $birthdayMsg = "🎂 Anniversaire *très bientôt* — dans *{$daysUntilBirthday} jour(s)* ({$birthdayDay} {$birthdayFormatted})";
        } else {
            $birthdayMsg = "📅 Prochain anniversaire : *{$birthdayDay} {$birthdayFormatted}* _(dans {$daysUntilBirthday} jours)_";
        }

        $ageStr = "{$years} an(s)";
        if ($months > 0 || $days > 0) {
            $ageStr .= ", {$months} mois";
            if ($days > 0) {
                $ageStr .= " et {$days} jour(s)";
            }
        }

        $totalDaysFormatted = number_format($totalDays, 0, ',', "\u{202F}");

        $reply = "🎂 *CALCULATEUR D'ÂGE*\n"
            . "────────────────\n"
            . "📅 Né(e) le : *{$birthdateFormatted}*\n"
            . "📅 Aujourd'hui : *{$today->format($dateFmt)}*\n"
            . "────────────────\n"
            . "🎯 Âge exact : *{$ageStr}*\n"
            . "📆 Jours vécus : *{$totalDaysFormatted} jours*\n"
            . "────────────────\n"
            . "{$birthdayMsg}\n"
            . "────────────────\n"
            . "_Pour un compte à rebours vers une date : countdown {$nextBirthday->format('Y-m-d')}_";

        return AgentResult::reply($reply, [
            'action'               => 'age',
            'years'                => $years,
            'days_until_birthday'  => $daysUntilBirthday,
        ]);
    }

    /**
     * NEW: Working days counter — count business days (Mon–Fri) between two dates, inclusive.
     */
    private function handleWorkingDays(array $parsed, array $prefs): AgentResult
    {
        $fromStr = trim($parsed['from_date'] ?? '');
        $toStr   = trim($parsed['to_date']   ?? '');
        $lang    = $prefs['language'] ?? 'fr';
        $tzName  = $prefs['timezone'] ?? 'UTC';
        $dateFmt = $prefs['date_format'] ?? 'd/m/Y';

        if ($fromStr === '' || $toStr === '') {
            return AgentResult::reply(
                "Précise les deux dates au format AAAA-MM-JJ.\n"
                . "_Ex : jours ouvrés du 2026-04-01 au 2026-04-30_\n"
                . "_Ex : combien de jours de travail entre 2026-03-15 et 2026-03-31_"
            );
        }

        try {
            $tz       = new DateTimeZone($tzName);
            $fromDate = new DateTimeImmutable($fromStr . ' 00:00:00', $tz);
            $toDate   = new DateTimeImmutable($toStr   . ' 00:00:00', $tz);
        } catch (\Exception) {
            return AgentResult::reply(
                "⚠️ Date(s) invalide(s). Utilise le format AAAA-MM-JJ.\n"
                . "_Ex : 2026-03-01 et 2026-03-31_"
            );
        }

        // Ensure chronological order
        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
            [$fromStr,  $toStr ] = [$toStr,  $fromStr ];
        }

        // Sanity check: avoid iterating over huge date ranges
        $rangeDays = (int) $fromDate->diff($toDate)->days + 1;
        if ($rangeDays > 3660) {
            return AgentResult::reply(
                "⚠️ La plage dépasse 10 ans — réduis l'intervalle.\n"
                . "_Ex : jours ouvrés du 2026-01-01 au 2026-12-31_"
            );
        }

        $workingDays = 0;
        $weekendDays = 0;
        $current     = $fromDate;

        while ($current <= $toDate) {
            $dow = (int) $current->format('N'); // 1=Mon…7=Sun
            if ($dow <= 5) {
                $workingDays++;
            } else {
                $weekendDays++;
            }
            $current = $current->modify('+1 day');
        }

        $fromFormatted = $fromDate->format($dateFmt);
        $toFormatted   = $toDate->format($dateFmt);
        $fromDay       = $this->getDayName((int) $fromDate->format('w'), $lang, short: true);
        $toDay         = $this->getDayName((int) $toDate->format('w'), $lang, short: true);

        // Ratio bar
        $pct         = $rangeDays > 0 ? min(10, (int) round(($workingDays / $rangeDays) * 10)) : 0;
        $progressBar = str_repeat('▓', $pct) . str_repeat('░', 10 - $pct);
        $pctLabel    = $rangeDays > 0 ? round($workingDays / $rangeDays * 100) . '%' : '0%';

        // Approximate full weeks + isolated days
        $fullWeeks = intdiv($workingDays, 5);
        $remDays   = $workingDays % 5;
        $breakdown = '';
        if ($fullWeeks > 0 && $remDays > 0) {
            $breakdown = " _({$fullWeeks} sem. complète(s) + {$remDays} j)_";
        } elseif ($fullWeeks > 0) {
            $breakdown = " _({$fullWeeks} semaine(s) complète(s))_";
        }

        $reply = "📊 *JOURS OUVRÉS*\n"
            . "────────────────\n"
            . "📅 Du *{$fromDay} {$fromFormatted}*\n"
            . "📅 Au *{$toDay} {$toFormatted}*\n"
            . "────────────────\n"
            . "📆 Total jours : *{$rangeDays}*\n"
            . "💼 Jours ouvrés (lun–ven) : *{$workingDays}*{$breakdown}\n"
            . "🏖️ Week-ends : *{$weekendDays}*\n"
            . "────────────────\n"
            . "📈 Ratio ouvrés [{$progressBar}] {$pctLabel}\n"
            . "────────────────\n"
            . "⚠️ _Jours fériés non inclus (variables selon le pays)._\n"
            . "_Pour un compte à rebours : countdown {$toStr}_";

        return AgentResult::reply($reply, [
            'action'  => 'working_days',
            'total'   => $rangeDays,
            'working' => $workingDays,
            'weekend' => $weekendDays,
        ]);
    }

    /**
     * NEW: Same offset — show cities sharing the same UTC offset as the user or a given city/timezone.
     */
    private function handleSameOffset(array $parsed, array $prefs): AgentResult
    {
        $target     = trim($parsed['target'] ?? '');
        $lang       = $prefs['language'] ?? 'fr';
        $userTzName = $prefs['timezone'] ?? 'UTC';

        if ($target !== '') {
            $tzName = $this->resolveTimezoneString($target);
            if (!$tzName) {
                $suggestion = $this->suggestTimezone($target);
                $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
                return AgentResult::reply(
                    "Fuseau ou ville inconnu(e) : *{$target}*.\n"
                    . "Exemples : _Paris_, _Tokyo_, _UTC+2_{$extra}"
                );
            }
            $refLabel = $target;
        } else {
            $tzName   = $userTzName;
            $refLabel = 'ton fuseau';
        }

        try {
            $utcNow  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $refNow  = $utcNow->setTimezone(new DateTimeZone($tzName));
            $offsetS = $refNow->getOffset(); // seconds

            $sign      = $offsetS >= 0 ? '+' : '-';
            $absH      = intdiv(abs($offsetS), 3600);
            $absM      = (abs($offsetS) % 3600) / 60;
            $offsetStr = 'UTC' . $sign . sprintf('%02d', $absH) . ($absM > 0 ? ':' . sprintf('%02d', (int) $absM) : '');

            // Find well-known cities at the same UTC offset (deduplicated by IANA id)
            $matchingCities = [];
            $seenTz         = [];

            foreach (self::CITY_TIMEZONE_MAP as $cityName => $cityTz) {
                if (in_array($cityTz, $seenTz, true)) {
                    continue;
                }
                try {
                    $cityNow = $utcNow->setTimezone(new DateTimeZone($cityTz));
                    if ($cityNow->getOffset() === $offsetS) {
                        $matchingCities[] = ucwords($cityName) . " ({$cityTz})";
                        $seenTz[]         = $cityTz;
                    }
                } catch (\Exception) {
                    // skip
                }
            }

            // Exclude the reference timezone itself from the list
            $matchingCities = array_filter(
                $matchingCities,
                fn($c) => !str_contains($c, "({$tzName})")
            );

            $timeStr = $refNow->format('H:i');
            $dayName = $this->getDayName((int) $refNow->format('w'), $lang, short: true);

            $lines = [
                "🌍 *FUSEAUX IDENTIQUES*",
                "────────────────",
                "📍 Référence : *{$tzName}* ({$offsetStr})",
                "🕐 Heure actuelle : *{$timeStr}* {$dayName}",
                "────────────────",
            ];

            if (empty($matchingCities)) {
                $lines[] = "ℹ️ _Aucune ville connue ne partage exactement cet offset ({$offsetStr})._";
            } else {
                $count   = count($matchingCities);
                $lines[] = "🏙️ *{$count} ville(s) au même fuseau ({$offsetStr}) :*";
                foreach ($matchingCities as $city) {
                    $lines[] = "• {$city}";
                }
            }

            $lines[] = "────────────────";
            $lines[] = "_Pour comparer : heure à Tokyo_";
            $lines[] = "_Pour chercher d'autres fuseaux : fuseaux Europe_";

        } catch (\Exception $e) {
            Log::warning("UserPreferencesAgent: same_offset error", [
                'target' => $target,
                'tzName' => $tzName ?? 'unknown',
                'error'  => $e->getMessage(),
            ]);
            return AgentResult::reply("⚠️ Impossible de calculer les fuseaux identiques. Réessaie dans quelques instants.");
        }

        return AgentResult::reply(
            implode("\n", $lines),
            ['action' => 'same_offset', 'timezone' => $tzName, 'offset' => $offsetStr ?? 'UTC+00']
        );
    }

    /**
     * NEW: Next open — when will business hours (9h–18h Mon–Fri) next start in a given city.
     */
    private function handleNextOpen(array $parsed, array $prefs): AgentResult
    {
        $target  = trim($parsed['target'] ?? '');
        $lang    = $prefs['language'] ?? 'fr';
        $dateFmt = $prefs['date_format'] ?? 'd/m/Y';

        if ($target === '') {
            return AgentResult::reply(
                "Précise la ville ou le fuseau.\n"
                . "_Ex : quand ouvre Tokyo, prochaines heures ouvrables New York_"
            );
        }

        $targetTz = $this->resolveTimezoneString($target);
        if (!$targetTz) {
            $suggestion = $this->suggestTimezone($target);
            $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
            return AgentResult::reply(
                "Ville inconnue : *{$target}*.\n"
                . "Exemples : _Tokyo_, _London_, _New York_, _Dubai_{$extra}"
            );
        }

        $userTzName = $prefs['timezone'] ?? 'UTC';

        try {
            $utcNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $targetNow = $utcNow->setTimezone(new DateTimeZone($targetTz));
            $userTz    = new DateTimeZone($userTzName);

            $hour      = (int) $targetNow->format('G');
            $minute    = (int) $targetNow->format('i');
            $dayOfWeek = (int) $targetNow->format('N'); // 1=Mon … 7=Sun
            $timeStr   = $targetNow->format('H:i');
            $dayName   = $this->getDayName((int) $targetNow->format('w'), $lang);
            $offsetStr = $targetNow->format('P');

            $lines = [
                "📅 *PROCHAINE OUVERTURE — {$target}*",
                "────────────────",
                "📍 *{$target}* ({$targetTz}) — UTC{$offsetStr}",
                "🕐 Maintenant : *{$timeStr}* {$dayName}",
                "────────────────",
            ];

            $isWeekend      = $dayOfWeek >= 6;
            $isWorkingHours = ($hour >= 9 && $hour < 18) && !$isWeekend;

            if ($isWorkingHours) {
                $remaining = 18 * 60 - ($hour * 60 + $minute);
                $remH      = intdiv($remaining, 60);
                $remM      = $remaining % 60;
                $remStr    = $remH > 0 ? "{$remH}h{$remM}min" : "{$remM}min";
                $lines[]   = "🟢 *Bureaux ouverts maintenant !*";
                $lines[]   = "⏱ Fermeture dans *{$remStr}* (à 18h00)";
                $lines[]   = "_Pour plus de détails : heures ouvrables {$target}_";
            } else {
                // Calculate days to add until next Monday–Friday
                if ($isWeekend) {
                    $daysToAdd = $dayOfWeek === 6 ? 2 : 1; // Sat → Mon, Sun → Mon
                } elseif ($hour >= 18) {
                    $daysToAdd = $dayOfWeek === 5 ? 3 : 1; // Fri after 18h → Mon, else tomorrow
                } else {
                    $daysToAdd = 0; // Opens later today
                }

                if ($daysToAdd > 0) {
                    $nextOpen       = $targetNow->modify("+{$daysToAdd} days")->setTime(9, 0, 0);
                    $nextOpenUser   = $nextOpen->setTimezone($userTz);
                    $nextDayName    = $this->getDayName((int) $nextOpen->format('w'), $lang);
                    $nextDateStr    = $nextOpen->format($dateFmt);
                    $nextUserDay    = $this->getDayName((int) $nextOpenUser->format('w'), $lang, short: true);

                    $diffSecs = (int) $nextOpen->format('U') - (int) $utcNow->format('U');
                    $diffH    = intdiv($diffSecs, 3600);
                    $diffM    = ($diffSecs % 3600) / 60;
                    $diffStr  = $diffH > 0 ? "{$diffH}h{$diffM}min" : "{$diffM}min";

                    $lines[] = "🔴 *Bureaux fermés*";
                    $lines[] = "";
                    $lines[] = "📅 *Prochaine ouverture :*";
                    $lines[] = "   📍 *{$target}* : *{$nextDayName} {$nextDateStr}* à *09:00*";
                    $lines[] = "   🏠 *Chez toi* ({$userTzName}) : *{$nextOpenUser->format('H:i')}* {$nextUserDay}";
                    $lines[] = "   ⏱ Dans : *{$diffStr}*";
                } else {
                    // Before 9h today (weekday)
                    $minsUntil  = 9 * 60 - ($hour * 60 + $minute);
                    $openH      = intdiv($minsUntil, 60);
                    $openM      = $minsUntil % 60;
                    $openStr    = $openH > 0 ? "{$openH}h{$openM}min" : "{$openM}min";
                    $nextOpen   = $targetNow->setTime(9, 0, 0);
                    $nextOpenUser = $nextOpen->setTimezone($userTz);
                    $nextUserDay  = $this->getDayName((int) $nextOpenUser->format('w'), $lang, short: true);

                    $lines[] = "🟡 *Bureaux pas encore ouverts aujourd'hui*";
                    $lines[] = "";
                    $lines[] = "📅 *Ouverture ce matin :*";
                    $lines[] = "   📍 *{$target}* : *09:00* (dans *{$openStr}*)";
                    $lines[] = "   🏠 *Chez toi* ({$userTzName}) : *{$nextOpenUser->format('H:i')}* {$nextUserDay}";
                }

                $lines[] = "";
                $lines[] = "_Horaires standards : 9h00–18h00 lun–ven_";
                $lines[] = "_Pour vérifier maintenant : heures ouvrables {$target}_";
            }

            $lines[] = "────────────────";
            $lines[] = "_Pour planifier une réunion : meeting planner {$target}_";

        } catch (\Exception $e) {
            Log::warning("UserPreferencesAgent: next_open error", [
                'target'   => $target,
                'targetTz' => $targetTz,
                'error'    => $e->getMessage(),
            ]);
            return AgentResult::reply("⚠️ Impossible de calculer la prochaine ouverture. Réessaie dans quelques instants.");
        }

        return AgentResult::reply(
            implode("\n", $lines),
            ['action' => 'next_open', 'target' => $targetTz, 'is_open' => $isWorkingHours ?? false]
        );
    }

    /**
     * NEW: Time until — how many hours/minutes until a specific time today (or tomorrow if already passed).
     */
    private function handleTimeUntil(array $parsed, array $prefs): AgentResult
    {
        $timeStr = trim($parsed['time'] ?? '');
        $target  = trim($parsed['target'] ?? '');
        $lang    = $prefs['language'] ?? 'fr';

        if ($timeStr === '') {
            return AgentResult::reply(
                "Précise l'heure cible.\n"
                . "_Ex : dans combien de temps avant 18h, combien de minutes avant 14h30, time until 9pm Tokyo_"
            );
        }

        $normalizedTime = $this->parseTimeString($timeStr);
        if ($normalizedTime === null) {
            return AgentResult::reply(
                "Heure invalide : *{$timeStr}*.\n"
                . "_Formats acceptés : 14:30 / 14h30 / 9h / 2pm / 2:30pm_"
            );
        }

        // Resolve timezone
        if ($target !== '') {
            $tzName = $this->resolveTimezoneString($target);
            if (!$tzName) {
                $suggestion = $this->suggestTimezone($target);
                $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
                return AgentResult::reply(
                    "Ville ou fuseau inconnu : *{$target}*.\n"
                    . "Exemples : _Tokyo_, _Europe/Paris_, _UTC+2_{$extra}"
                );
            }
            $cityLabel = $target;
        } else {
            $tzName    = $prefs['timezone'] ?? 'UTC';
            $cityLabel = $tzName;
        }

        try {
            $tz  = new DateTimeZone($tzName);
            $now = new DateTimeImmutable('now', $tz);

            [$targetHour, $targetMin] = array_map('intval', explode(':', $normalizedTime));
            $targetToday  = $now->setTime($targetHour, $targetMin, 0);
            $isTomorrow   = $targetToday <= $now;
            $targetActual = $isTomorrow ? $targetToday->modify('+1 day') : $targetToday;

            $diffSecs = (int) $targetActual->format('U') - (int) $now->format('U');
            $diffH    = intdiv($diffSecs, 3600);
            $diffM    = (int) (($diffSecs % 3600) / 60);
            $diffStr  = $diffH > 0 ? "{$diffH}h{$diffM}min" : "{$diffM}min";

            $nowStr  = $now->format('H:i');
            $offset  = $now->format('P');
            $dayName = $this->getDayName((int) $now->format('w'), $lang, short: true);

            if ($isTomorrow) {
                $tomorrowDay = $this->getDayName((int) $targetActual->format('w'), $lang, short: true);
                $whenLabel   = "demain ({$tomorrowDay}) à *{$targetActual->format('H:i')}*";
            } else {
                $whenLabel = "aujourd'hui à *{$targetToday->format('H:i')}*";
            }

            $reply = "⏳ *TEMPS RESTANT*\n"
                . "────────────────\n"
                . "📍 *{$cityLabel}* (UTC{$offset})\n"
                . "🕐 Il est maintenant *{$nowStr}* {$dayName}\n"
                . "────────────────\n"
                . "🎯 Cible : *{$normalizedTime}* — {$whenLabel}\n"
                . "⏱ Dans : *{$diffStr}*\n"
                . "────────────────\n"
                . "_Pour convertir cette heure : convertir {$normalizedTime} de {$cityLabel} à ..._\n"
                . "_Pour un compte à rebours par date : countdown AAAA-MM-JJ_";

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: time_until error', [
                'time'   => $normalizedTime,
                'target' => $target,
                'error'  => $e->getMessage(),
            ]);
            return AgentResult::reply("⚠️ Impossible de calculer le temps restant. Vérifie les paramètres saisis.");
        }

        return AgentResult::reply($reply, ['action' => 'time_until', 'time' => $normalizedTime, 'timezone' => $tzName]);
    }

    /**
     * NEW: Year progress — day of year, ISO week number, quarter, days remaining, completion %.
     */
    private function handleYearProgress(array $prefs): AgentResult
    {
        $tzName  = $prefs['timezone'] ?? 'UTC';
        $lang    = $prefs['language'] ?? 'fr';
        $dateFmt = $prefs['date_format'] ?? 'd/m/Y';

        try {
            $tz  = new DateTimeZone($tzName);
            $now = new DateTimeImmutable('now', $tz);

            $year      = (int) $now->format('Y');
            $dayOfYear = (int) $now->format('z') + 1; // 1-based
            $weekNum   = (int) $now->format('W');
            $isLeap    = (bool) $now->format('L');
            $yearDays  = $isLeap ? 366 : 365;
            $remaining = $yearDays - $dayOfYear;
            $pct       = (int) round(($dayOfYear / $yearDays) * 100);

            // Progress bar (20 segments)
            $filled      = (int) round($pct / 5);
            $progressBar = str_repeat('▓', $filled) . str_repeat('░', 20 - $filled);

            // Quarter
            $month   = (int) $now->format('n');
            $quarter = (int) ceil($month / 3);

            // Quarter progress (weeks into current quarter)
            $quarterStartMonth = ($quarter - 1) * 3 + 1;
            $quarterStart      = new DateTimeImmutable("{$year}-{$quarterStartMonth}-01", $tz);
            $quarterEnd        = $quarterStart->modify('+3 months')->modify('-1 day');
            $quarterDays       = (int) $quarterStart->diff($quarterEnd)->days + 1;
            $quarterElapsed    = (int) $quarterStart->diff($now)->days + 1;
            $quarterPct        = min(100, (int) round(($quarterElapsed / $quarterDays) * 100));

            $dayName = $this->getDayName((int) $now->format('w'), $lang);
            $dateStr = $now->format($dateFmt);

            $reply = "📊 *PROGRESSION DE L'ANNÉE {$year}*\n"
                . "────────────────\n"
                . "📅 Aujourd'hui : *{$dayName} {$dateStr}*\n"
                . "📍 Fuseau : *{$tzName}* (UTC{$now->format('P')})\n"
                . "────────────────\n"
                . "📆 Jour de l'année : *{$dayOfYear}* / {$yearDays}" . ($isLeap ? ' _(bissextile)_' : '') . "\n"
                . "📅 Semaine ISO : *S{$weekNum}*\n"
                . "🗓 Trimestre : *T{$quarter}* ({$quarterPct}% du trimestre écoulé)\n"
                . "────────────────\n"
                . "⏳ Jours restants dans l'année : *{$remaining}*\n"
                . "✅ Année complétée : *{$pct}%*\n"
                . "📈 [{$progressBar}]\n"
                . "────────────────\n"
                . "_Pour un compte à rebours vers fin d'année : countdown {$year}-12-31_\n"
                . "_Pour le calendrier du mois : calendrier du mois_";

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: year_progress error', ['error' => $e->getMessage()]);
            return AgentResult::reply("⚠️ Impossible d'afficher la progression de l'année. Vérifie ton fuseau horaire.");
        }

        return AgentResult::reply($reply, ['action' => 'year_progress', 'timezone' => $tzName]);
    }

    /**
     * NEW: Date arithmetic — add/subtract days/weeks/months from a date,
     * or calculate the difference (in days) between two dates.
     */
    private function handleDateAdd(array $parsed, array $prefs): AgentResult
    {
        $baseDateStr = trim($parsed['base_date'] ?? 'today');
        $fromDateStr = trim($parsed['from_date'] ?? '');
        $toDateStr   = trim($parsed['to_date']   ?? '');
        $days        = $parsed['days']   ?? null;
        $weeks       = $parsed['weeks']  ?? null;
        $months      = $parsed['months'] ?? null;
        $label       = trim($parsed['label'] ?? '');
        $lang        = $prefs['language'] ?? 'fr';
        $tzName      = $prefs['timezone'] ?? 'UTC';
        $dateFmt     = $prefs['date_format'] ?? 'd/m/Y';

        // ── Mode A: difference between two dates ─────────────────────────────
        if ($fromDateStr !== '' && $toDateStr !== '') {
            try {
                $tz   = new DateTimeZone($tzName);
                $from = new DateTimeImmutable(
                    ($fromDateStr === 'today' ? 'now' : $fromDateStr . ' 00:00:00'), $tz
                );
                $to   = new DateTimeImmutable(
                    ($toDateStr === 'today' ? 'now' : $toDateStr . ' 00:00:00'), $tz
                );
            } catch (\Exception) {
                return AgentResult::reply(
                    "⚠️ Date(s) invalide(s). Utilise le format AAAA-MM-JJ.\n"
                    . "_Ex : différence entre 2026-01-01 et 2026-06-30_"
                );
            }

            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }

            $diff       = $from->diff($to);
            $totalDays  = (int) $diff->days;
            $years      = (int) $diff->y;
            $remMonths  = (int) $diff->m;
            $remDays    = (int) $diff->d;
            $fullWeeks  = intdiv($totalDays, 7);
            $leftDays   = $totalDays % 7;

            $fromFmt  = $from->format($dateFmt);
            $toFmt    = $to->format($dateFmt);
            $fromDay  = $this->getDayName((int) $from->format('w'), $lang, short: true);
            $toDay    = $this->getDayName((int) $to->format('w'), $lang, short: true);

            $breakdown = '';
            if ($years > 0) {
                $breakdown = "\n📆 Décomposé : *{$years} an(s)*, {$remMonths} mois, {$remDays} j";
            } elseif ($remMonths > 0) {
                $breakdown = "\n📆 Décomposé : *{$remMonths} mois*, {$remDays} jour(s)";
            }

            $weekBreakdown = $totalDays >= 7 ? " _({$fullWeeks} sem. + {$leftDays} j)_" : '';

            $reply = "📅 *DIFFÉRENCE ENTRE DATES*\n"
                . "────────────────\n"
                . "📍 Du *{$fromDay} {$fromFmt}*\n"
                . "📍 Au *{$toDay} {$toFmt}*\n"
                . "────────────────\n"
                . "⏱ Écart : *{$totalDays} jour(s)*{$weekBreakdown}{$breakdown}\n"
                . "────────────────\n"
                . "_Pour les jours ouvrés : jours ouvrés du {$from->format('Y-m-d')} au {$to->format('Y-m-d')}_";

            return AgentResult::reply($reply, ['action' => 'date_add', 'mode' => 'diff', 'days' => $totalDays]);
        }

        // ── Mode B: add/subtract days/weeks/months from a base date ───────────
        $deltaD = (int) ($days   ?? 0);
        $deltaW = (int) ($weeks  ?? 0);
        $deltaM = (int) ($months ?? 0);

        if ($deltaD === 0 && $deltaW === 0 && $deltaM === 0) {
            return AgentResult::reply(
                "Précise le nombre de jours à ajouter ou soustraire, ou deux dates pour calculer l'écart.\n"
                . "_Ex : quelle date dans 30 jours_\n"
                . "_Ex : date il y a 7 jours_\n"
                . "_Ex : différence entre 2026-01-01 et 2026-06-30_"
            );
        }

        try {
            $tz   = new DateTimeZone($tzName);
            $base = new DateTimeImmutable(
                ($baseDateStr === 'today' ? 'now' : $baseDateStr . ' 00:00:00'), $tz
            );
        } catch (\Exception) {
            return AgentResult::reply(
                "⚠️ Date de base invalide : *{$baseDateStr}*.\n"
                . "_Utilise le format AAAA-MM-JJ ou 'today'._"
            );
        }

        try {
            $result = $base;
            if ($deltaM !== 0) {
                $result = $result->modify(($deltaM > 0 ? "+{$deltaM}" : "{$deltaM}") . ' month');
            }
            $totalDeltaDays = $deltaD + ($deltaW * 7);
            if ($totalDeltaDays !== 0) {
                $result = $result->modify(($totalDeltaDays > 0 ? "+{$totalDeltaDays}" : "{$totalDeltaDays}") . ' day');
            }
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: date_add error', ['error' => $e->getMessage()]);
            return AgentResult::reply("⚠️ Impossible de calculer la date. Vérifie les paramètres saisis.");
        }

        $today     = new DateTimeImmutable('now', new DateTimeZone($tzName));
        $baseFmt   = $base->format($dateFmt);
        $resultFmt = $result->format($dateFmt);
        $baseDay   = $this->getDayName((int) $base->format('w'), $lang);
        $resultDay = $this->getDayName((int) $result->format('w'), $lang);
        $isToday   = $base->format('Y-m-d') === $today->format('Y-m-d');
        $baseLbl   = $isToday ? "aujourd'hui ({$baseFmt})" : "*{$baseDay} {$baseFmt}*";
        $titleLabel = $label !== '' ? " — *{$label}*" : '';

        // Human-readable delta
        $parts = [];
        if ($deltaM !== 0) {
            $parts[] = ($deltaM > 0 ? '+' : '') . "{$deltaM} mois";
        }
        if ($deltaW !== 0) {
            $parts[] = ($deltaW > 0 ? '+' : '') . "{$deltaW} sem.";
        }
        if ($deltaD !== 0) {
            $parts[] = ($deltaD > 0 ? '+' : '') . "{$deltaD} j";
        }
        $deltaStr = implode(', ', $parts);

        $isoDay    = (int) $result->format('N');
        $isWeekend = $isoDay >= 6;
        $weekendNote = $isWeekend ? "\n⚠️ _C'est un week-end._" : '';
        $weekNum   = $result->format('W');

        $reply = "📅 *CALCUL DE DATE*{$titleLabel}\n"
            . "────────────────\n"
            . "📍 Base : {$baseLbl}\n"
            . "➕ Décalage : *{$deltaStr}*\n"
            . "────────────────\n"
            . "🎯 Résultat : *{$resultDay} {$resultFmt}* _(sem. {$weekNum})_{$weekendNote}\n"
            . "────────────────\n"
            . "_Compte à rebours : countdown {$result->format('Y-m-d')}_\n"
            . "_Infos sur cette date : date info {$result->format('Y-m-d')}_";

        return AgentResult::reply($reply, [
            'action' => 'date_add',
            'mode'   => 'add',
            'result' => $result->format('Y-m-d'),
            'delta'  => $deltaStr,
        ]);
    }

    /**
     * NEW: Date info — complete information about a specific date
     * (day of week, ISO week, quarter, day of year, distance from today, weekend check).
     */
    private function handleDateInfo(array $parsed, array $prefs): AgentResult
    {
        $dateStr = trim($parsed['date'] ?? '');
        $lang    = $prefs['language'] ?? 'fr';
        $tzName  = $prefs['timezone'] ?? 'UTC';
        $dateFmt = $prefs['date_format'] ?? 'd/m/Y';

        try {
            $tz    = new DateTimeZone($tzName);
            $today = new DateTimeImmutable('now', $tz);

            if ($dateStr === '' || $dateStr === 'today') {
                $date = $today;
            } else {
                $date = new DateTimeImmutable($dateStr . ' 00:00:00', $tz);
            }
        } catch (\Exception) {
            return AgentResult::reply(
                "⚠️ Date invalide : *{$dateStr}*.\n"
                . "_Utilise le format AAAA-MM-JJ, ex : 2026-07-14_"
            );
        }

        $year      = (int) $date->format('Y');
        $month     = (int) $date->format('n');
        $dayOfYear = (int) $date->format('z') + 1;
        $weekNum   = (int) $date->format('W');
        $isoDay    = (int) $date->format('N'); // 1=Mon .. 7=Sun
        $quarter   = (int) ceil($month / 3);
        $isLeap    = (bool) $date->format('L');
        $yearDays  = $isLeap ? 366 : 365;
        $isWeekend = $isoDay >= 6;

        $dateFmted = $date->format($dateFmt);
        $dayName   = $this->getDayName((int) $date->format('w'), $lang);
        $monthName = $this->getMonthName($month, $lang);

        // Distance from today
        $todayMidnight = new DateTimeImmutable($today->format('Y-m-d') . ' 00:00:00', $tz);
        $dateMidnight  = new DateTimeImmutable($date->format('Y-m-d')  . ' 00:00:00', $tz);
        $diffDays      = (int) $todayMidnight->diff($dateMidnight)->days;
        $isPast        = $dateMidnight < $todayMidnight;
        $isToday       = $dateMidnight->format('Y-m-d') === $todayMidnight->format('Y-m-d');

        if ($isToday) {
            $relativeLabel = "_(c'est aujourd'hui)_";
        } elseif ($isPast) {
            $relativeLabel = "_(il y a {$diffDays} jour(s))_";
        } else {
            $relativeLabel = "_(dans {$diffDays} jour(s))_";
        }

        // Days remaining in month and year
        $daysInMonth  = (int) (new DateTimeImmutable("last day of {$year}-{$month}", $tz))->format('j');
        $dayOfMonth   = (int) $date->format('j');
        $daysLeftMonth = $daysInMonth - $dayOfMonth;
        $daysLeftYear  = $yearDays - $dayOfYear;

        $weekendIcon  = $isWeekend ? '🏖️ Week-end' : '💼 Jour ouvrable';
        $leapTag      = $isLeap ? ' _(bissextile)_' : '';

        $reply = "📅 *INFOS SUR LA DATE*\n"
            . "────────────────\n"
            . "📌 *{$dayName} {$dateFmted}* {$relativeLabel}\n"
            . "📍 {$monthName} {$year} · {$weekendIcon}\n"
            . "────────────────\n"
            . "📆 Jour de l'année : *{$dayOfYear}* / {$yearDays}{$leapTag}\n"
            . "📅 Semaine ISO : *S{$weekNum}*\n"
            . "🗓 Trimestre : *T{$quarter}*\n"
            . "────────────────\n"
            . "📍 Jours restants dans le mois : *{$daysLeftMonth}*\n"
            . "📍 Jours restants dans l'année : *{$daysLeftYear}*\n"
            . "────────────────\n"
            . "_Compte à rebours : countdown {$date->format('Y-m-d')}_\n"
            . "_Calendrier du mois : calendrier {$monthName}_";

        return AgentResult::reply($reply, [
            'action'      => 'date_info',
            'date'        => $date->format('Y-m-d'),
            'day_of_year' => $dayOfYear,
            'week'        => $weekNum,
            'quarter'     => $quarter,
        ]);
    }

    // -------------------------------------------------------------------------
    // Quick brief — combined city overview (time + business hours + DST)
    // -------------------------------------------------------------------------

    private function handleQuickBrief(array $parsed, array $prefs): AgentResult
    {
        $target    = trim($parsed['target'] ?? '');
        $userTz    = $prefs['timezone'] ?? 'UTC';
        $lang      = $prefs['language'] ?? 'fr';

        if ($target === '') {
            $target = $userTz;
        }

        $targetTz = $this->resolveTimezoneString($target) ?? $target;

        try {
            $tz        = new DateTimeZone($targetTz);
            $userTzObj = new DateTimeZone($userTz);
            $utcNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $targetNow = $utcNow->setTimezone($tz);
            $userNow   = $utcNow->setTimezone($userTzObj);

            $targetOffset = $tz->getOffset($utcNow);
            $userOffset   = $userTzObj->getOffset($utcNow);
            $diffSeconds  = $targetOffset - $userOffset;
            $diffHours    = $diffSeconds / 3600;
            $diffSign     = $diffHours >= 0 ? '+' : '';
            $diffLabel    = $diffHours == (int) $diffHours
                ? $diffSign . (int) $diffHours . 'h'
                : $diffSign . number_format($diffHours, 1, '.', '') . 'h';

            $cityLabel = ucwords($target);
            $dayName   = $this->getDayName((int) $targetNow->format('w'), $lang);
            $hour      = (int) $targetNow->format('G');
            $isoDay    = (int) $targetNow->format('N');

            // Business hours check (9-18 Mon-Fri)
            $isWorkDay = $isoDay <= 5;
            $isOpen    = $isWorkDay && $hour >= 9 && $hour < 18;
            if ($isOpen) {
                $businessIcon   = '🟢';
                $businessStatus = 'Bureaux ouverts (9h–18h)';
                $closesIn       = 18 - $hour;
                $businessExtra  = "Ferme dans ~{$closesIn}h";
            } elseif ($isWorkDay && $hour >= 18) {
                $businessIcon   = '🔴';
                $businessStatus = 'Bureaux fermés';
                $businessExtra  = 'Réouverture demain à 9h';
            } elseif ($isWorkDay && $hour < 9) {
                $businessIcon   = '🟡';
                $businessStatus = 'Bureaux pas encore ouverts';
                $opensIn        = 9 - $hour;
                $businessExtra  = "Ouvre dans ~{$opensIn}h";
            } else {
                $businessIcon   = '🔴';
                $businessStatus = 'Week-end';
                $daysToMon      = $isoDay === 6 ? 2 : 1;
                $businessExtra  = "Réouverture lundi à 9h (dans {$daysToMon}j)";
            }

            // DST check
            $isDst = (bool) $targetNow->format('I');
            $dstLabel = $isDst ? '☀️ Heure d\'été active' : '❄️ Heure d\'hiver active';

            // Find next DST transition
            $transitions = $tz->getTransitions(
                $targetNow->getTimestamp(),
                $targetNow->getTimestamp() + 365 * 86400
            );
            $nextTransition = null;
            foreach ($transitions as $i => $tr) {
                if ($i === 0) continue;
                if ($tr['isdst'] !== $isDst) {
                    $nextTransition = $tr;
                    break;
                }
            }

            $dstExtra = '';
            if ($nextTransition) {
                $trDate   = (new DateTimeImmutable('@' . $nextTransition['ts']))->setTimezone($tz);
                $trDay    = $this->getDayName((int) $trDate->format('w'), $lang, short: true);
                $dstExtra = "\n   Prochain changement : *{$trDate->format('d/m/Y')}* ({$trDay})";
            } else {
                $dstExtra = "\n   _Pas de changement d\'heure prévu_";
            }

            $lines = [
                "🌍 *APERÇU RAPIDE — {$cityLabel}*",
                "────────────────",
                "🕐 *{$targetNow->format('H:i')}* ({$dayName}) — UTC{$targetNow->format('P')}",
                "⏱ Décalage avec toi : *{$diffLabel}*",
                "────────────────",
                "{$businessIcon} {$businessStatus}",
                "   {$businessExtra}",
                "────────────────",
                "{$dstLabel}{$dstExtra}",
                "────────────────",
                "_Pour plus de détails :_",
                "• _heure à {$cityLabel}_ — comparaison détaillée",
                "• _heures ouvrables {$cityLabel}_ — planning complet",
                "• _DST {$cityLabel}_ — infos heure d'été",
            ];

            return AgentResult::reply(implode("\n", $lines), [
                'action'    => 'quick_brief',
                'target'    => $target,
                'timezone'  => $targetTz,
                'is_open'   => $isOpen,
                'is_dst'    => $isDst,
            ]);

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: quick_brief error', [
                'target' => $target,
                'error'  => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Impossible d'afficher l'aperçu pour *{$target}*.\n"
                . "_Vérifie le nom de la ville ou du fuseau horaire._\n"
                . "_Exemples : aperçu Tokyo, brief New York, résumé Dubai_"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Preferences audit — customization summary
    // -------------------------------------------------------------------------

    private function handlePreferencesAudit(array $prefs): AgentResult
    {
        $defaults = UserPreference::$defaults;

        $customized = [];
        $atDefault  = [];

        foreach ($defaults as $key => $defaultValue) {
            $currentValue = $prefs[$key] ?? $defaultValue;
            $isCustom     = (string) $currentValue !== (string) $defaultValue;

            if ($isCustom) {
                $customized[$key] = [
                    'current' => $currentValue,
                    'default' => $defaultValue,
                ];
            } else {
                $atDefault[$key] = $defaultValue;
            }
        }

        $totalKeys   = count($defaults);
        $customCount = count($customized);
        $pct         = $totalKeys > 0 ? round(($customCount / $totalKeys) * 100) : 0;

        $lines = [
            "📊 *AUDIT DES PRÉFÉRENCES*",
            "────────────────",
            "🎯 Personnalisation : *{$customCount}* / {$totalKeys} ({$pct}%)",
            "",
        ];

        if (!empty($customized)) {
            $lines[] = "✏️ *Préférences personnalisées :*";
            foreach ($customized as $key => $info) {
                $label   = $this->formatKeyLabel($key);
                $current = $this->formatValue($key, $info['current']);
                $default = $this->formatValue($key, $info['default']);
                $lines[] = "• *{$label}* : {$current} _(défaut: {$default})_";
            }
            $lines[] = "";
        }

        if (!empty($atDefault)) {
            $lines[] = "📋 *Préférences par défaut :*";
            foreach ($atDefault as $key => $value) {
                $label   = $this->formatKeyLabel($key);
                $display = $this->formatValue($key, $value);
                $lines[] = "• {$label} : {$display}";
            }
            $lines[] = "";
        }

        $lines[] = "────────────────";

        if ($customCount === 0) {
            $lines[] = "💡 _Tu n'as rien personnalisé. Commence par :_";
            $lines[] = "• _set language en_ / _timezone Europe/Paris_";
        } elseif ($customCount < $totalKeys) {
            $remaining = $totalKeys - $customCount;
            $lines[] = "💡 _Il reste {$remaining} préférence(s) à personnaliser._";
            $lines[] = "• _mon profil_ — voir tous les paramètres";
        } else {
            $lines[] = "🎉 _Toutes tes préférences sont personnalisées !_";
        }

        $lines[] = "• _reset all_ — tout réinitialiser";

        return AgentResult::reply(implode("\n", $lines), [
            'action'          => 'preferences_audit',
            'customized'      => $customCount,
            'total'           => $totalKeys,
            'percentage'      => $pct,
        ]);
    }

    // -------------------------------------------------------------------------
    // Unix timestamp conversion
    // -------------------------------------------------------------------------

    private function handleUnixTimestamp(array $parsed, array $prefs): AgentResult
    {
        $userTz   = $prefs['timezone'] ?? 'UTC';
        $lang     = $prefs['language'] ?? 'fr';
        $dateFmt  = $prefs['date_format'] ?? 'd/m/Y';
        $input    = trim($parsed['value'] ?? '');
        $mode     = $parsed['mode'] ?? 'auto'; // 'to_unix', 'from_unix', 'auto'

        try {
            $tz = new DateTimeZone($userTz);

            // Auto-detect mode: if input looks like a Unix timestamp (all digits, 9-11 chars)
            if ($mode === 'auto') {
                if (preg_match('/^-?\d{9,11}$/', $input)) {
                    $mode = 'from_unix';
                } elseif (strtolower($input) === 'now' || $input === '') {
                    $mode = 'to_unix';
                    $input = 'now';
                } else {
                    $mode = 'to_unix';
                }
            }

            if ($mode === 'from_unix') {
                $timestamp = (int) $input;
                $dt        = (new DateTimeImmutable("@{$timestamp}"))->setTimezone($tz);
                $utcDt     = new DateTimeImmutable("@{$timestamp}", new DateTimeZone('UTC'));
                $dayName   = $this->getDayName((int) $dt->format('w'), $lang);

                $lines = [
                    "🔢 *CONVERSION TIMESTAMP*",
                    "────────────────",
                    "📥 Timestamp : *{$timestamp}*",
                    "",
                    "📅 Date ({$userTz}) :",
                    "   *{$dt->format($dateFmt)}* — {$dayName} à *{$dt->format('H:i:s')}*",
                    "",
                    "🌍 Date (UTC) :",
                    "   *{$utcDt->format($dateFmt)}* — {$utcDt->format('H:i:s')}",
                    "────────────────",
                    "📊 ISO 8601 : `{$dt->format('c')}`",
                    "🗓 Semaine ISO : W{$dt->format('W')} — Jour {$dt->format('z')}/365",
                ];

                return AgentResult::reply(implode("\n", $lines), [
                    'action'    => 'unix_timestamp',
                    'mode'      => 'from_unix',
                    'timestamp' => $timestamp,
                ]);
            }

            // to_unix: convert a date/time to Unix timestamp
            if (strtolower($input) === 'now' || $input === '') {
                $dt = new DateTimeImmutable('now', $tz);
            } else {
                $dt = new DateTimeImmutable($input, $tz);
            }

            $timestamp = $dt->getTimestamp();
            $dayName   = $this->getDayName((int) $dt->format('w'), $lang);

            $lines = [
                "🔢 *CONVERSION TIMESTAMP*",
                "────────────────",
                "📅 Date : *{$dt->format($dateFmt)}* — {$dayName} à *{$dt->format('H:i:s')}*",
                "   _{$userTz}_",
                "",
                "📤 Timestamp Unix : *{$timestamp}*",
                "",
                "────────────────",
                "📊 ISO 8601 : `{$dt->format('c')}`",
                "💡 _Colle un timestamp pour le convertir en date._",
            ];

            return AgentResult::reply(implode("\n", $lines), [
                'action'    => 'unix_timestamp',
                'mode'      => 'to_unix',
                'timestamp' => $timestamp,
            ]);

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: unix_timestamp error', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Impossible de convertir *{$input}*.\n\n"
                . "Exemples valides :\n"
                . "• _timestamp 1711234567_ — timestamp → date\n"
                . "• _timestamp now_ — date actuelle → timestamp\n"
                . "• _timestamp 2026-07-14 15:00_ — date → timestamp"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Multi-convert: show a time across multiple timezones
    // -------------------------------------------------------------------------

    private function handleMultiConvert(array $parsed, array $prefs): AgentResult
    {
        $userTz  = $prefs['timezone'] ?? 'UTC';
        $lang    = $prefs['language'] ?? 'fr';
        $dateFmt = $prefs['date_format'] ?? 'd/m/Y';
        $time    = trim($parsed['time'] ?? '');
        $from    = trim($parsed['from'] ?? '');
        $cities  = $parsed['cities'] ?? [];

        if (empty($cities) || !is_array($cities)) {
            return AgentResult::reply(
                "⚠️ Indique au moins 2 villes pour la conversion multiple.\n\n"
                . "Exemples :\n"
                . "• _si c'est 15h à Paris, quelle heure à Tokyo, New York, Dubai_\n"
                . "• _multi convert 9am London → Tokyo, Sydney, New York_"
            );
        }

        // Resolve source timezone
        $fromTz = $userTz;
        if ($from !== '') {
            $resolved = $this->resolveTimezoneString($from);
            if ($resolved) {
                $fromTz = $resolved;
            }
        }

        try {
            $sourceTz = new DateTimeZone($fromTz);

            // Parse the time
            $parsedTime = $this->parseTimeString($time);
            if ($parsedTime && preg_match('/^(\d{2}):(\d{2})$/', $parsedTime, $m)) {
                $now = new DateTimeImmutable('now', $sourceTz);
                $dt  = $now->setTime((int) $m[1], (int) $m[2], 0);
            } else {
                $dt = new DateTimeImmutable('now', $sourceTz);
            }

            $fromLabel = $from !== '' ? ucwords($from) : $userTz;
            $dayName   = $this->getDayName((int) $dt->format('w'), $lang, short: true);

            $lines = [
                "🔄 *CONVERSION MULTI-FUSEAUX*",
                "────────────────",
                "📍 Référence : *{$dt->format('H:i')}* ({$dayName}) — {$fromLabel}",
                "",
            ];

            $maxCityLen = 0;
            $results    = [];

            foreach ($cities as $city) {
                $cityName = trim($city);
                if ($cityName === '') continue;

                $cityTz = $this->resolveTimezoneString($cityName);
                if (!$cityTz) {
                    $results[] = ['city' => $cityName, 'error' => true];
                    continue;
                }

                $targetTz  = new DateTimeZone($cityTz);
                $targetDt  = $dt->setTimezone($targetTz);

                $targetOffset = $targetTz->getOffset($dt);
                $sourceOffset = $sourceTz->getOffset($dt);
                $diffHours    = ($targetOffset - $sourceOffset) / 3600;
                $diffSign     = $diffHours >= 0 ? '+' : '';
                $diffLabel    = $diffHours == (int) $diffHours
                    ? $diffSign . (int) $diffHours . 'h'
                    : $diffSign . number_format($diffHours, 1, '.', '') . 'h';

                $targetDay = $this->getDayName((int) $targetDt->format('w'), $lang, short: true);

                // Same day or different?
                $dayIndicator = '';
                $dayDiff = (int) $targetDt->format('j') - (int) $dt->format('j');
                if ($dayDiff === 1 || ($dayDiff < -25)) {
                    $dayIndicator = ' _(+1j)_';
                } elseif ($dayDiff === -1 || ($dayDiff > 25)) {
                    $dayIndicator = ' _(-1j)_';
                }

                // Business hours indicator
                $hour   = (int) $targetDt->format('G');
                $isoDay = (int) $targetDt->format('N');
                $isOpen = $isoDay <= 5 && $hour >= 9 && $hour < 18;
                $icon   = $isOpen ? '🟢' : '🔴';

                $results[] = [
                    'city'    => ucwords($cityName),
                    'time'    => $targetDt->format('H:i'),
                    'day'     => $targetDay,
                    'diff'    => $diffLabel,
                    'dayInd'  => $dayIndicator,
                    'icon'    => $icon,
                    'error'   => false,
                ];

                $len = mb_strlen(ucwords($cityName));
                if ($len > $maxCityLen) $maxCityLen = $len;
            }

            foreach ($results as $r) {
                if ($r['error']) {
                    $lines[] = "❓ {$r['city']} — _fuseau non trouvé_";
                    continue;
                }
                $lines[] = "{$r['icon']} *{$r['city']}* — *{$r['time']}* ({$r['day']}) {$r['diff']}{$r['dayInd']}";
            }

            $lines[] = "";
            $lines[] = "────────────────";
            $lines[] = "🟢 = heures ouvrables (9h–18h)";
            $lines[] = "_Exemples : horloge mondiale, heure à Tokyo_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'   => 'multi_convert',
                'from'     => $fromLabel,
                'time'     => $dt->format('H:i'),
                'cities'   => count($results),
            ]);

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: multi_convert error', [
                'time'   => $time,
                'from'   => $from,
                'cities' => $cities,
                'error'  => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Erreur lors de la conversion multi-fuseaux.\n\n"
                . "_Vérifie les noms de villes et réessaie._\n"
                . "Exemple : _si c'est 15h à Paris, quelle heure à Tokyo, New York, Dubai_"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Jet lag calculator — flight arrival time & jet lag estimation
    // -------------------------------------------------------------------------

    private function handleJetLag(array $parsed, array $prefs): AgentResult
    {
        $from     = trim($parsed['from'] ?? '');
        $to       = trim($parsed['to'] ?? '');
        $duration = trim($parsed['duration'] ?? '');
        $lang     = $prefs['language'] ?? 'fr';

        if ($from === '' || $to === '') {
            return AgentResult::reply(
                "⚠️ Précise la ville de départ et d'arrivée.\n"
                . "_Ex : jet lag Paris → Tokyo durée 12h_\n"
                . "_Ex : vol de New York à Londres 7h_"
            );
        }

        $fromTz = $this->resolveTimezoneString($from);
        $toTz   = $this->resolveTimezoneString($to);

        if (!$fromTz) {
            $suggestion = $this->suggestTimezone($from);
            $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
            return AgentResult::reply("⚠️ Ville de départ inconnue : *{$from}*.{$extra}");
        }
        if (!$toTz) {
            $suggestion = $this->suggestTimezone($to);
            $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
            return AgentResult::reply("⚠️ Ville d'arrivée inconnue : *{$to}*.{$extra}");
        }

        $flightMinutes = $this->parseFlightDuration($duration);
        if ($flightMinutes === null || $flightMinutes <= 0 || $flightMinutes > 1440) {
            return AgentResult::reply(
                "⚠️ Durée de vol invalide : *{$duration}*.\n"
                . "_Formats acceptés : 12h, 7h30, 12:30, 7.5h_\n"
                . "_Ex : jet lag Paris → Tokyo durée 12h_"
            );
        }

        try {
            $fromTzObj = new DateTimeZone($fromTz);
            $toTzObj   = new DateTimeZone($toTz);
            $utcNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $departureNow = $utcNow->setTimezone($fromTzObj);
            $arrivalUtc   = $utcNow->modify("+{$flightMinutes} minutes");
            $arrivalTime  = $arrivalUtc->setTimezone($toTzObj);

            $fromOffset    = $fromTzObj->getOffset($utcNow);
            $toOffset      = $toTzObj->getOffset($utcNow);
            $jetLagSeconds = abs($toOffset - $fromOffset);
            $jetLagHours   = $jetLagSeconds / 3600;

            $flightH     = intdiv($flightMinutes, 60);
            $flightM     = $flightMinutes % 60;
            $durationStr = $flightM > 0 ? "{$flightH}h{$flightM}min" : "{$flightH}h";

            $severity = match (true) {
                $jetLagHours <= 2  => '🟢 Léger (adaptation rapide)',
                $jetLagHours <= 5  => '🟡 Modéré (1-2 jours d\'adaptation)',
                $jetLagHours <= 8  => '🟠 Important (2-4 jours d\'adaptation)',
                default            => '🔴 Sévère (4-7 jours d\'adaptation)',
            };

            $direction = ($toOffset - $fromOffset) > 0 ? 'Est ➡️ (avance)' : 'Ouest ⬅️ (recul)';
            if ($toOffset === $fromOffset) {
                $direction = 'Même fuseau (pas de jet lag)';
            }

            $fromDay    = $this->getDayName((int) $departureNow->format('w'), $lang, short: true);
            $arrivalDay = $this->getDayName((int) $arrivalTime->format('w'), $lang, short: true);

            $jetLagStr = $jetLagHours == (int) $jetLagHours
                ? (int) $jetLagHours . 'h'
                : number_format($jetLagHours, 1, '.', '') . 'h';

            $lines = [
                "✈️ *CALCULATEUR DE JET LAG*",
                "────────────────",
                "🛫 Départ : *{$from}* ({$fromTz})",
                "   🕐 Heure locale : *{$departureNow->format('H:i')}* {$fromDay}",
                "🛬 Arrivée : *{$to}* ({$toTz})",
                "   🕐 Heure locale à l'arrivée : *{$arrivalTime->format('H:i')}* {$arrivalDay}",
                "⏱ Durée du vol : *{$durationStr}*",
                "────────────────",
                "🌍 Décalage horaire : *{$jetLagStr}* — {$direction}",
                "{$severity}",
                "────────────────",
            ];

            if ($jetLagHours > 2) {
                $lines[] = "💡 *Conseils :*";
                if ($jetLagHours > 5) {
                    $lines[] = "• Commence à ajuster ton rythme 2-3 jours avant";
                }
                $lines[] = "• Expose-toi à la lumière naturelle à destination";
                $lines[] = "• Hydrate-toi bien pendant le vol";
                $lines[] = "• Évite la caféine 6h avant le coucher local";
            }

            return AgentResult::reply(implode("\n", $lines), [
                'action'         => 'jet_lag',
                'from'           => $fromTz,
                'to'             => $toTz,
                'flight_minutes' => $flightMinutes,
                'jet_lag_hours'  => $jetLagHours,
            ]);

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: jet_lag error', [
                'from' => $from, 'to' => $to, 'error' => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Impossible de calculer le jet lag.\n"
                . "_Vérifie les villes et la durée du vol._\n"
                . "_Ex : jet lag Paris → Tokyo durée 12h_"
            );
        }
    }

    private function parseFlightDuration(string $duration): ?int
    {
        $duration = trim(mb_strtolower($duration));

        if (preg_match('/^(\d{1,2})h(\d{1,2})(?:m(?:in)?)?$/', $duration, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (preg_match('/^(\d{1,2})h$/', $duration, $m)) {
            return (int) $m[1] * 60;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $duration, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (preg_match('/^(\d{1,2}(?:\.\d+)?)h?$/', $duration, $m)) {
            return (int) round((float) $m[1] * 60);
        }
        if (preg_match('/^(\d{1,4})m(?:in)?$/', $duration, $m)) {
            return (int) $m[1];
        }

        return null;
    }



    // -------------------------------------------------------------------------
    // Time bridge — visual hour-by-hour timeline between two timezones
    // -------------------------------------------------------------------------

    private function handleTimeBridge(array $parsed, array $prefs): AgentResult
    {
        $target = trim($parsed['target'] ?? '');
        $lang   = $prefs['language'] ?? 'fr';
        $userTz = $prefs['timezone'] ?? 'UTC';

        if ($target === '') {
            return AgentResult::reply(
                "⚠️ Précise une ville pour le pont horaire.\n"
                . "_Ex : pont horaire Tokyo, timeline New York, bridge Londres_"
            );
        }

        $targetTz = $this->resolveTimezoneString($target);
        if (!$targetTz) {
            $suggestion = $this->suggestTimezone($target);
            $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
            return AgentResult::reply("⚠️ Fuseau inconnu : *{$target}*.{$extra}");
        }

        try {
            $userTzObj   = new DateTimeZone($userTz);
            $targetTzObj = new DateTimeZone($targetTz);
            $utcNow      = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            // Start from midnight today in user's timezone
            $userToday = $utcNow->setTimezone($userTzObj)->setTime(0, 0, 0);

            $lines = [
                "🌉 *PONT HORAIRE*",
                "────────────────",
                "📍 Ici : *{$userTz}*",
                "📍 Là-bas : *{$target}* ({$targetTz})",
                "",
                "⏰ *Timeline (heures ouvrables en gras) :*",
                "",
            ];

            // Show hours from 6:00 to 23:00 user time
            for ($h = 6; $h <= 23; $h += 1) {
                $userDt   = $userToday->setTime($h, 0, 0);
                $targetDt = $userDt->setTimezone($targetTzObj);
                $targetH  = (int) $targetDt->format('G');
                $targetM  = $targetDt->format('i');

                // Business hours highlight (9-18)
                $userBiz   = ($h >= 9 && $h < 18);
                $targetBiz = ($targetH >= 9 && $targetH < 18);

                $userStr   = sprintf('%02d:00', $h);
                $targetStr = sprintf('%02d:%s', $targetH, $targetM);

                // Visual indicators
                $overlap = ($userBiz && $targetBiz) ? '🟢' : (($userBiz || $targetBiz) ? '🟡' : '⚪');

                $userLabel   = $userBiz ? "*{$userStr}*" : $userStr;
                $targetLabel = $targetBiz ? "*{$targetStr}*" : $targetStr;

                $lines[] = "{$overlap} {$userLabel} → {$targetLabel}";
            }

            $lines[] = "";
            $lines[] = "────────────────";
            $lines[] = "🟢 = les deux en heures ouvrables";
            $lines[] = "🟡 = un seul en heures ouvrables";
            $lines[] = "⚪ = aucun en heures ouvrables";
            $lines[] = "────────────────";
            $lines[] = "_Voir aussi : planifier réunion {$target}_";

            return AgentResult::reply(implode("\n", $lines), [
                'action' => 'time_bridge',
                'target' => $targetTz,
            ]);

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: time_bridge error', [
                'target' => $target,
                'error'  => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Impossible d'afficher le pont horaire.\n"
                . "_Vérifie le fuseau horaire._\n"
                . "_Ex : pont horaire Tokyo, bridge New York_"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Batch brief — multi-city quick overview (v1.17.0)
    // -------------------------------------------------------------------------

    private function handleBatchBrief(array $parsed, array $prefs): AgentResult
    {
        $cities = $parsed['cities'] ?? [];
        $lang   = $prefs['language'] ?? 'fr';
        $userTz = $prefs['timezone'] ?? 'UTC';

        if (!is_array($cities) || count($cities) < 2) {
            return AgentResult::reply(
                "⚠️ Précise au moins 2 villes pour un aperçu groupé.\n"
                . "_Ex : brief Tokyo, New York et Dubai_\n"
                . "_Pour une seule ville, utilise : aperçu Tokyo_"
            );
        }

        if (count($cities) > 8) {
            $cities = array_slice($cities, 0, 8);
        }

        try {
            $utcNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $userTzObj = new DateTimeZone($userTz);
            $userNow   = $utcNow->setTimezone($userTzObj);
            $userDay   = $this->getDayName((int) $userNow->format('w'), $lang, short: true);

            $lines = [
                "🌍 *APERÇU MULTI-VILLES*",
                "────────────────",
                "📍 Toi : *{$userTz}* — *{$userNow->format('H:i')}* {$userDay}",
                "────────────────",
            ];

            $resolvedCount = 0;
            foreach ($cities as $city) {
                $cityName = trim((string) $city);
                if ($cityName === '') {
                    continue;
                }

                $tz = $this->resolveTimezoneString($cityName);
                if (!$tz) {
                    $lines[] = "❌ *{$cityName}* — _fuseau inconnu_";
                    continue;
                }

                $tzObj   = new DateTimeZone($tz);
                $cityNow = $utcNow->setTimezone($tzObj);
                $cityH   = (int) $cityNow->format('G');
                $cityW   = (int) $cityNow->format('w');
                $dayName = $this->getDayName($cityW, $lang, short: true);

                $isWeekday    = ($cityW >= 1 && $cityW <= 5);
                $isBusinessH  = $isWeekday && $cityH >= 9 && $cityH < 18;
                $statusIcon   = $isBusinessH ? '🟢' : ($isWeekday && ($cityH >= 7 && $cityH < 21) ? '🟡' : '🔴');
                $statusLabel  = $isBusinessH ? 'Ouvert' : (!$isWeekday ? 'Week-end' : ($cityH < 9 ? 'Pas encore ouvert' : 'Fermé'));

                // DST info
                $isDst   = (bool) $cityNow->format('I');
                $dstTag  = $isDst ? '☀️' : '❄️';

                $offsetH   = $tzObj->getOffset($utcNow) / 3600;
                $offsetStr = $offsetH >= 0 ? "UTC+{$offsetH}" : "UTC{$offsetH}";
                if (floor($offsetH) !== (float) $offsetH) {
                    $offsetStr = sprintf('UTC%+.1f', $offsetH);
                }

                $lines[] = "";
                $lines[] = "📍 *{$cityName}* ({$tz})";
                $lines[] = "   🕐 *{$cityNow->format('H:i')}* {$dayName} — {$offsetStr} {$dstTag}";
                $lines[] = "   {$statusIcon} {$statusLabel}";
                $resolvedCount++;
            }

            $lines[] = "";
            $lines[] = "────────────────";
            $lines[] = "_💡 Pour plus de détails : aperçu <ville>_";

            return AgentResult::reply(implode("\n", $lines), [
                'action' => 'batch_brief',
                'cities_count' => $resolvedCount,
            ]);

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: batch_brief error', [
                'cities' => $cities,
                'error'  => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Erreur lors de l'aperçu multi-villes.\n"
                . "_Vérifie les noms de villes et réessaie._\n"
                . "_Ex : brief Tokyo, New York et Dubai_"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Schedule check — verify if a time works across cities (v1.17.0)
    // -------------------------------------------------------------------------

    private function handleScheduleCheck(array $parsed, array $prefs): AgentResult
    {
        $timeStr = trim($parsed['time'] ?? '');
        $cities  = $parsed['cities'] ?? [];
        $lang    = $prefs['language'] ?? 'fr';
        $userTz  = $prefs['timezone'] ?? 'UTC';

        if (!is_array($cities) || count($cities) < 1) {
            return AgentResult::reply(
                "⚠️ Précise au moins une ville à vérifier.\n"
                . "_Ex : est-ce que 15h marche pour Tokyo et New York_\n"
                . "_Ex : check horaire 10h London Dubai_"
            );
        }

        if (count($cities) > 10) {
            $cities = array_slice($cities, 0, 10);
        }

        try {
            $userTzObj = new DateTimeZone($userTz);
            $utcNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $userNow   = $utcNow->setTimezone($userTzObj);

            // Parse the target time or use current time
            if ($timeStr === '') {
                $checkTime = $userNow;
                $timeLabel = $userNow->format('H:i') . ' (maintenant)';
            } else {
                $parsedTime = $this->parseTimeString($timeStr);
                if (!$parsedTime) {
                    return AgentResult::reply(
                        "⚠️ Format d'heure invalide : *{$timeStr}*.\n"
                        . "_Formats acceptés : 14h30, 15:00, 2pm, 9h_"
                    );
                }
                [$h, $m] = explode(':', $parsedTime);
                $checkTime = $userNow->setTime((int) $h, (int) $m, 0);
                $timeLabel = $parsedTime;
            }

            $lines = [
                "📋 *VÉRIFICATION D'HORAIRE*",
                "────────────────",
                "🕐 Heure vérifiée : *{$timeLabel}* ({$userTz})",
                "────────────────",
            ];

            $allOk     = true;
            $okCount   = 0;
            $totalCity = 0;

            foreach ($cities as $city) {
                $cityName = trim((string) $city);
                if ($cityName === '') {
                    continue;
                }

                $tz = $this->resolveTimezoneString($cityName);
                if (!$tz) {
                    $lines[] = "❌ *{$cityName}* — _fuseau inconnu_";
                    $allOk = false;
                    continue;
                }

                $tzObj    = new DateTimeZone($tz);
                $cityTime = $checkTime->setTimezone(new DateTimeZone('UTC'))
                    ->setTimezone($userTzObj)
                    ->setTime((int) explode(':', $timeLabel === $userNow->format('H:i') . ' (maintenant)' ? $userNow->format('H:i') : $timeLabel)[0], (int) explode(':', $timeLabel === $userNow->format('H:i') . ' (maintenant)' ? $userNow->format('H:i') : $timeLabel)[1], 0);

                // Recalculate: build a UTC datetime for the user's chosen time, then convert
                $userCheckUtc = $userNow->setTime((int) explode(':', str_replace(' (maintenant)', '', $timeLabel))[0], (int) explode(':', str_replace(' (maintenant)', '', $timeLabel))[1], 0);
                $utcEquiv     = (new DateTimeImmutable($userCheckUtc->format('Y-m-d H:i:s'), $userTzObj))->setTimezone(new DateTimeZone('UTC'));
                $cityDt       = $utcEquiv->setTimezone($tzObj);

                $cityH = (int) $cityDt->format('G');
                $cityW = (int) $cityDt->format('w');
                $dayName = $this->getDayName($cityW, $lang, short: true);

                $isWeekday   = ($cityW >= 1 && $cityW <= 5);
                $isBusiness  = $isWeekday && $cityH >= 9 && $cityH < 18;

                if ($isBusiness) {
                    $icon = '✅';
                    $label = 'Heures ouvrables';
                    $okCount++;
                } elseif (!$isWeekday) {
                    $icon = '⛔';
                    $label = 'Week-end';
                    $allOk = false;
                } elseif ($cityH >= 7 && $cityH < 9) {
                    $icon = '🟡';
                    $label = 'Tôt (avant 9h)';
                    $allOk = false;
                } elseif ($cityH >= 18 && $cityH < 21) {
                    $icon = '🟡';
                    $label = 'Tardif (après 18h)';
                    $allOk = false;
                } else {
                    $icon = '⛔';
                    $label = 'Hors horaires';
                    $allOk = false;
                }

                $lines[] = "{$icon} *{$cityName}* → *{$cityDt->format('H:i')}* {$dayName} — {$label}";
                $totalCity++;
            }

            $lines[] = "────────────────";

            if ($totalCity > 0) {
                if ($allOk && $okCount === $totalCity) {
                    $lines[] = "✅ *Parfait !* Cet horaire convient à toutes les villes.";
                } elseif ($okCount > 0) {
                    $lines[] = "🟡 *Partiel :* {$okCount}/{$totalCity} villes en heures ouvrables.";
                    $lines[] = "_💡 Essaie : planifier réunion multi pour trouver le créneau idéal._";
                } else {
                    $lines[] = "⛔ *Aucune* ville n'est en heures ouvrables à cette heure.";
                    $lines[] = "_💡 Essaie : planifier réunion multi pour trouver le créneau idéal._";
                }
            }

            return AgentResult::reply(implode("\n", $lines), [
                'action'   => 'schedule_check',
                'time'     => $timeLabel,
                'ok_count' => $okCount,
                'total'    => $totalCity,
            ]);

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: schedule_check error', [
                'time'   => $timeStr,
                'cities' => $cities,
                'error'  => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Erreur lors de la vérification d'horaire.\n"
                . "_Vérifie les villes et l'heure._\n"
                . "_Ex : est-ce que 15h marche pour Tokyo et New York_"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Day/Night Map — v1.18.0
    // -------------------------------------------------------------------------

    private function handleDayNightMap(array $parsed, array $prefs): AgentResult
    {
        $userTz = $prefs['timezone'] ?? 'UTC';
        $lang   = $prefs['language'] ?? 'fr';

        $defaultCities = [
            'Los Angeles' => 'America/Los_Angeles',
            'New York'    => 'America/New_York',
            'São Paulo'   => 'America/Sao_Paulo',
            'London'      => 'Europe/London',
            'Paris'       => 'Europe/Paris',
            'Dubai'       => 'Asia/Dubai',
            'Mumbai'      => 'Asia/Kolkata',
            'Bangkok'     => 'Asia/Bangkok',
            'Tokyo'       => 'Asia/Tokyo',
            'Sydney'      => 'Australia/Sydney',
        ];

        $requestedCities = $parsed['cities'] ?? [];
        $cityMap = [];

        if (!empty($requestedCities) && is_array($requestedCities)) {
            foreach ($requestedCities as $city) {
                $tz = $this->resolveTimezoneString(trim($city));
                if ($tz) {
                    $cityMap[ucwords(trim($city))] = $tz;
                }
            }
        }

        if (empty($cityMap)) {
            $cityMap = $defaultCities;
        }

        try {
            $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $userNow = $utcNow->setTimezone(new DateTimeZone($userTz));

            $lines = [
                "🌍 *CARTE JOUR / NUIT*",
                "────────────────",
                "📍 Ton heure : *{$userNow->format('H:i')}* ({$userTz})",
                "",
            ];

            $dayCount = 0;
            $nightCount = 0;
            $twilightCount = 0;

            foreach ($cityMap as $cityName => $tzName) {
                $tz   = new DateTimeZone($tzName);
                $now  = $utcNow->setTimezone($tz);
                $hour = (int) $now->format('G');
                $time = $now->format('H:i');

                // Determine day phase
                if ($hour >= 7 && $hour < 18) {
                    $icon = '☀️';
                    $phase = 'Jour';
                    $dayCount++;
                } elseif ($hour >= 18 && $hour < 21) {
                    $icon = '🌅';
                    $phase = 'Crépuscule';
                    $twilightCount++;
                } elseif ($hour >= 5 && $hour < 7) {
                    $icon = '🌅';
                    $phase = 'Aube';
                    $twilightCount++;
                } else {
                    $icon = '🌙';
                    $phase = 'Nuit';
                    $nightCount++;
                }

                $dayName = $this->getDayName((int) $now->format('w'), $lang, short: true);
                $lines[] = "{$icon} *{$cityName}* — {$time} ({$dayName}) _{$phase}_";
            }

            $total = count($cityMap);
            $lines[] = "";
            $lines[] = "────────────────";
            $lines[] = "☀️ {$dayCount} en jour · 🌅 {$twilightCount} aube/crépuscule · 🌙 {$nightCount} en nuit";
            $lines[] = "_Total : {$total} villes_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'    => 'day_night_map',
                'day_count' => $dayCount,
                'night_count' => $nightCount,
            ]);

        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: day_night_map error', [
                'error' => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "⚠️ Erreur lors de la génération de la carte jour/nuit.\n"
                . "_Réessaie ou précise les villes : carte jour nuit Tokyo, Paris, Dubai_"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Repeat Event — v1.18.0
    // -------------------------------------------------------------------------

    private function handleRepeatEvent(array $parsed, array $prefs): AgentResult
    {
        $startDateStr = trim($parsed['start_date'] ?? 'today');
        $intervalStr  = trim($parsed['interval'] ?? '1 week');
        $count        = (int) ($parsed['count'] ?? 5);
        $label        = trim($parsed['label'] ?? '');
        $lang         = $prefs['language'] ?? 'fr';
        $dateFormat   = $prefs['date_format'] ?? 'd/m/Y';
        $userTz       = $prefs['timezone'] ?? 'UTC';

        $count = max(1, min($count, 12));

        // Parse start date
        try {
            $tz = new DateTimeZone($userTz);
            if (strtolower($startDateStr) === 'today') {
                $startDate = new DateTimeImmutable('now', $tz);
            } else {
                $startDate = new DateTimeImmutable($startDateStr, $tz);
            }
        } catch (\Exception) {
            return AgentResult::reply(
                "⚠️ Date de début invalide : *{$startDateStr}*\n"
                . "_Utilise le format AAAA-MM-JJ ou \"today\"._\n"
                . "_Ex : toutes les 2 semaines à partir du 2026-04-01_"
            );
        }

        // Parse interval
        if (!preg_match('/^(\d+)\s*(day|days|week|weeks|month|months|jour|jours|semaine|semaines|mois)$/i', $intervalStr, $m)) {
            return AgentResult::reply(
                "⚠️ Intervalle non reconnu : *{$intervalStr}*\n"
                . "_Formats acceptés : 2 weeks, 1 month, 3 days, 1 semaine, 2 mois_\n"
                . "_Ex : toutes les 2 semaines à partir du 2026-04-01_"
            );
        }

        $n    = (int) $m[1];
        $unit = strtolower($m[2]);

        // Normalize French units
        $unitMap = [
            'jour' => 'day', 'jours' => 'day',
            'semaine' => 'week', 'semaines' => 'week',
            'mois' => 'month',
            'day' => 'day', 'days' => 'day',
            'week' => 'week', 'weeks' => 'week',
            'month' => 'month', 'months' => 'month',
        ];
        $normalizedUnit = $unitMap[$unit] ?? 'week';

        $intervalSpec = match ($normalizedUnit) {
            'day'   => "P{$n}D",
            'week'  => 'P' . ($n * 7) . 'D',
            'month' => "P{$n}M",
        };

        $unitLabel = match ($normalizedUnit) {
            'day'   => $n === 1 ? 'jour' : "{$n} jours",
            'week'  => $n === 1 ? 'semaine' : "{$n} semaines",
            'month' => $n === 1 ? 'mois' : "{$n} mois",
        };

        $titleLabel = $label !== '' ? " — {$label}" : '';
        $lines = [
            "🔁 *ÉVÉNEMENT RÉCURRENT*{$titleLabel}",
            "────────────────",
            "📅 Début : *{$startDate->format($dateFormat)}*",
            "🔄 Fréquence : toutes les *{$unitLabel}*",
            "📊 Prochaines *{$count}* occurrences :",
            "",
        ];

        $current = $startDate;
        $interval = new \DateInterval($intervalSpec);
        $today = new DateTimeImmutable('now', $tz);

        for ($i = 1; $i <= $count; $i++) {
            $dayName = $this->getDayName((int) $current->format('w'), $lang);
            $dateStr = $current->format($dateFormat);
            $weekNum = $current->format('W');

            // Indicator: past, today, or future
            $diff = (int) $today->diff($current)->format('%r%a');
            if ($diff < 0) {
                $marker = '⬜';
                $extra = '_(passé)_';
            } elseif ($diff === 0) {
                $marker = '🟢';
                $extra = '_(aujourd\'hui)_';
            } else {
                $marker = '🔲';
                $extra = "_(dans {$diff}j)_";
            }

            $lines[] = "{$marker} *{$i}.* {$dayName} {$dateStr} _(S{$weekNum})_ {$extra}";
            $current = $current->add($interval);
        }

        $lines[] = "";
        $lines[] = "────────────────";
        $lines[] = "💡 _Modifie : count, interval, ou start_date pour ajuster._";

        return AgentResult::reply(implode("\n", $lines), [
            'action'     => 'repeat_event',
            'interval'   => $intervalStr,
            'count'      => $count,
            'start_date' => $startDate->format('Y-m-d'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Holiday Info — v1.19.0
    // -------------------------------------------------------------------------

    private function handleHolidayInfo(array $parsed, array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $dateFormat  = $prefs['date_format'] ?? 'd/m/Y';
        $userTz     = $prefs['timezone'] ?? 'UTC';
        $count      = max(1, min((int) ($parsed['count'] ?? 10), 20));
        $country    = strtolower(trim($parsed['country'] ?? 'international'));

        try {
            $tz    = new DateTimeZone($userTz);
            $today = new DateTimeImmutable('now', $tz);
            $year  = (int) $today->format('Y');

            $holidays = array_merge(
                $this->getHolidayList($year, $country),
                $this->getHolidayList($year + 1, $country)
            );

            $upcoming = [];
            foreach ($holidays as $h) {
                $date = new DateTimeImmutable($h['date'], $tz);
                if ($date >= $today->setTime(0, 0)) {
                    $diff       = (int) $today->diff($date)->format('%a');
                    $upcoming[] = array_merge($h, ['date_obj' => $date, 'days_until' => $diff]);
                }
            }

            usort($upcoming, fn($a, $b) => $a['date_obj'] <=> $b['date_obj']);
            $upcoming = array_slice($upcoming, 0, $count);

            if (empty($upcoming)) {
                return AgentResult::reply("⚠️ Aucun jour férié trouvé pour ce pays.\n_Essaie : jours fériés France / US / UK_");
            }

            $countryLabel = match ($country) {
                'fr', 'france'          => '🇫🇷 France',
                'us', 'usa'             => '🇺🇸 États-Unis',
                'uk', 'united kingdom'  => '🇬🇧 Royaume-Uni',
                'de', 'germany'         => '🇩🇪 Allemagne',
                'es', 'spain'           => '🇪🇸 Espagne',
                'it', 'italy'           => '🇮🇹 Italie',
                default                 => '🌍 International',
            };

            $lines = [
                "🎉 *PROCHAINS JOURS FÉRIÉS* — {$countryLabel}",
                "────────────────",
                "",
            ];

            foreach ($upcoming as $h) {
                $dayName = $this->getDayName((int) $h['date_obj']->format('w'), $lang);
                $dateStr = $h['date_obj']->format($dateFormat);
                $daysStr = $h['days_until'] === 0
                    ? '_(aujourd\'hui !)_'
                    : ($h['days_until'] === 1 ? '_(demain)_' : "_(dans {$h['days_until']}j)_");
                $lines[] = "{$h['icon']} *{$h['name']}*";
                $lines[] = "   {$dayName} {$dateStr} {$daysStr}";
                $lines[] = "";
            }

            $lines[] = "────────────────";
            $lines[] = "_Total : {$count} prochains jours fériés_";
            $lines[] = "💡 _Précise un pays : jours fériés France / US / UK / DE / ES / IT_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'  => 'holiday_info',
                'country' => $country,
                'count'   => count($upcoming),
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: holiday_info error', ['error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Erreur lors de la recherche des jours fériés.\n"
                . "_Réessaie ou précise : jours fériés France_"
            );
        }
    }

    private function getHolidayList(int $year, string $country): array
    {
        $holidays = [
            ['date' => "{$year}-01-01", 'name' => 'Nouvel An',             'icon' => '🎆'],
            ['date' => "{$year}-02-14", 'name' => 'Saint-Valentin',        'icon' => '💕'],
            ['date' => "{$year}-05-01", 'name' => 'Fête du Travail',       'icon' => '✊'],
            ['date' => "{$year}-12-25", 'name' => 'Noël',                  'icon' => '🎄'],
            ['date' => "{$year}-12-31", 'name' => 'Réveillon du Nouvel An','icon' => '🎉'],
        ];

        if (in_array($country, ['fr', 'france', 'international'])) {
            $holidays = array_merge($holidays, [
                ['date' => "{$year}-01-06", 'name' => 'Épiphanie',          'icon' => '👑'],
                ['date' => "{$year}-02-02", 'name' => 'Chandeleur',         'icon' => '🕯️'],
                ['date' => "{$year}-05-08", 'name' => 'Victoire 1945',      'icon' => '🎖️'],
                ['date' => "{$year}-06-21", 'name' => 'Fête de la Musique', 'icon' => '🎵'],
                ['date' => "{$year}-07-14", 'name' => 'Fête Nationale',     'icon' => '🇫🇷'],
                ['date' => "{$year}-08-15", 'name' => 'Assomption',         'icon' => '⛪'],
                ['date' => "{$year}-11-01", 'name' => 'Toussaint',          'icon' => '🕯️'],
                ['date' => "{$year}-11-11", 'name' => 'Armistice',          'icon' => '🎖️'],
            ]);
        }

        if (in_array($country, ['us', 'usa'])) {
            $holidays = array_merge($holidays, [
                ['date' => "{$year}-01-20", 'name' => 'Martin Luther King Day', 'icon' => '✊'],
                ['date' => "{$year}-07-04", 'name' => 'Independence Day',       'icon' => '🇺🇸'],
                ['date' => "{$year}-10-31", 'name' => 'Halloween',              'icon' => '🎃'],
                ['date' => "{$year}-11-11", 'name' => 'Veterans Day',           'icon' => '🎖️'],
            ]);
        }

        if (in_array($country, ['uk', 'united kingdom'])) {
            $holidays = array_merge($holidays, [
                ['date' => "{$year}-11-05", 'name' => 'Guy Fawkes Night', 'icon' => '🎆'],
                ['date' => "{$year}-12-26", 'name' => 'Boxing Day',       'icon' => '🎁'],
            ]);
        }

        if (in_array($country, ['de', 'germany'])) {
            $holidays = array_merge($holidays, [
                ['date' => "{$year}-10-03", 'name' => 'Tag der Deutschen Einheit', 'icon' => '🇩🇪'],
                ['date' => "{$year}-10-31", 'name' => 'Reformationstag',           'icon' => '⛪'],
            ]);
        }

        if (in_array($country, ['es', 'spain'])) {
            $holidays = array_merge($holidays, [
                ['date' => "{$year}-01-06", 'name' => 'Día de Reyes',       'icon' => '👑'],
                ['date' => "{$year}-10-12", 'name' => 'Fiesta Nacional',    'icon' => '🇪🇸'],
            ]);
        }

        if (in_array($country, ['it', 'italy'])) {
            $holidays = array_merge($holidays, [
                ['date' => "{$year}-04-25", 'name' => 'Festa della Liberazione', 'icon' => '🇮🇹'],
                ['date' => "{$year}-06-02", 'name' => 'Festa della Repubblica',  'icon' => '🇮🇹'],
            ]);
        }

        // Deduplicate by date
        $seen   = [];
        $unique = [];
        foreach ($holidays as $h) {
            if (!isset($seen[$h['date']])) {
                $seen[$h['date']] = true;
                $unique[]         = $h;
            }
        }

        return $unique;
    }

    // -------------------------------------------------------------------------
    // Week to Dates — v1.19.0
    // -------------------------------------------------------------------------

    private function handleWeekToDates(array $parsed, array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $dateFormat  = $prefs['date_format'] ?? 'd/m/Y';
        $userTz     = $prefs['timezone'] ?? 'UTC';
        $weekNum    = (int) ($parsed['week'] ?? 0);
        $year       = (int) ($parsed['year'] ?? date('Y'));

        if ($weekNum < 1 || $weekNum > 53) {
            return AgentResult::reply(
                "⚠️ Numéro de semaine invalide : *{$weekNum}*\n"
                . "_Les semaines ISO vont de 1 à 53. Ex : semaine 15 2026_"
            );
        }

        try {
            $tz     = new DateTimeZone($userTz);
            $monday = (new DateTimeImmutable())->setISODate($year, $weekNum, 1)->setTimezone($tz);
            $sunday = $monday->modify('+6 days');
            $today  = new DateTimeImmutable('now', $tz);

            $lines = [
                "📆 *SEMAINE {$weekNum} — {$year}*",
                "────────────────",
                "📅 {$monday->format($dateFormat)} → {$sunday->format($dateFormat)}",
                "",
            ];

            for ($d = 0; $d < 7; $d++) {
                $day       = $monday->modify("+{$d} days");
                $dayName   = $this->getDayName((int) $day->format('w'), $lang);
                $dateStr   = $day->format($dateFormat);
                $isToday   = $day->format('Y-m-d') === $today->format('Y-m-d');
                $isWeekend = in_array((int) $day->format('w'), [0, 6]);

                $marker   = $isToday ? '👉' : ($isWeekend ? '🔵' : '⚪');
                $todayTag = $isToday ? ' *(aujourd\'hui)*' : '';
                $lines[]  = "{$marker} {$dayName} {$dateStr}{$todayTag}";
            }

            // Position relative to today
            if ($today < $monday) {
                $diff     = (int) $today->diff($monday)->format('%a');
                $position = "📍 Cette semaine commence dans *{$diff}* jours";
            } elseif ($today > $sunday) {
                $diff     = (int) $sunday->diff($today)->format('%a');
                $position = "📍 Cette semaine est passée il y a *{$diff}* jours";
            } else {
                $dayInWeek = (int) $today->format('N');
                $position  = "📍 Nous sommes au *jour {$dayInWeek}/7* de cette semaine";
            }

            $lines[] = "";
            $lines[] = "────────────────";
            $lines[] = $position;
            $lines[] = "💡 _Ex : semaine 20 2026, semaine 1 2027_";

            return AgentResult::reply(implode("\n", $lines), [
                'action' => 'week_to_dates',
                'week'   => $weekNum,
                'year'   => $year,
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: week_to_dates error', ['error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Erreur lors du calcul de la semaine {$weekNum}.\n"
                . "_Vérifie le format : semaine 15 ou semaine 15 2026_"
            );
        }
    }

    /**
     * Parse a human-written time string into HH:MM (24h).
     * Accepts: 14:30, 14h30, 9h, 9h00, 2pm, 2:30pm, 14h, etc.
     */
    private function parseTimeString(string $time): ?string
    {
        $time = trim($time);

        // HH:MM or H:MM (24h)
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            $h = (int) $m[1]; $mn = (int) $m[2];
            if ($h <= 23 && $mn <= 59) {
                return sprintf('%02d:%02d', $h, $mn);
            }
        }

        // French format: 14h30, 9h, 9h00, 14h
        if (preg_match('/^(\d{1,2})h(\d{0,2})$/i', $time, $m)) {
            $h  = (int) $m[1];
            $mn = $m[2] !== '' ? (int) $m[2] : 0;
            if ($h <= 23 && $mn <= 59) {
                return sprintf('%02d:%02d', $h, $mn);
            }
        }

        // 12h format: 2pm, 2:30pm, 2:30am, 12pm, 12am
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)$/i', $time, $m)) {
            $h        = (int) $m[1];
            $mn       = ($m[2] !== '') ? (int) $m[2] : 0;
            $meridiem = strtolower($m[3]);
            if ($meridiem === 'pm' && $h !== 12) {
                $h += 12;
            } elseif ($meridiem === 'am' && $h === 12) {
                $h = 0;
            }
            if ($h <= 23 && $mn <= 59) {
                return sprintf('%02d:%02d', $h, $mn);
            }
        }

        return null;
    }

    /**
     * Detect if the message looks like a pasted export block (≥3 valid preference key: value lines).
     */
    private function looksLikeExportBlock(string $text): bool
    {
        // Must have multiple lines to be an export block
        $lines = explode("\n", $text);
        if (count($lines) < 3) {
            return false;
        }

        $validKeyCount = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key] = explode(':', $line, 2);
            if (in_array(trim($key), UserPreference::$validKeys)) {
                $validKeyCount++;
            }
        }

        return $validKeyCount >= 3;
    }

    private function handleShowDiff(array $prefs): AgentResult
    {
        $defaults = UserPreference::$defaults;
        $diffs    = [];

        foreach ($defaults as $key => $defaultValue) {
            $current = $prefs[$key] ?? $defaultValue;

            // Normalize boolean comparison
            $defaultNorm = is_bool($defaultValue) ? (int) $defaultValue : $defaultValue;
            $currentNorm = is_bool($current)      ? (int) $current      : $current;

            if ((string) $currentNorm !== (string) $defaultNorm) {
                $diffs[$key] = [
                    'current' => $current,
                    'default' => $defaultValue,
                ];
            }
        }

        if (empty($diffs)) {
            return AgentResult::reply(
                "✨ *Aucune personnalisation*\n\n"
                . "Toutes tes préférences sont aux valeurs par défaut.\n"
                . "_Tape *mon profil* pour voir toutes les préférences._",
                ['action' => 'show_diff', 'count' => 0]
            );
        }

        $lines = ["✏️ *MES PERSONNALISATIONS* (" . count($diffs) . " modifiée(s))\n"
            . "────────────────"];

        foreach ($diffs as $key => $vals) {
            $displayCurrent = $this->formatValue($key, $vals['current']);
            $displayDefault = $this->formatValue($key, $vals['default']);
            $label          = $this->formatKeyLabel($key);
            $lines[]        = "• *{$label}* : {$displayCurrent} _(défaut : {$displayDefault})_";
        }

        $lines[] = "────────────────";
        $lines[] = "_Tape *reset all* pour revenir aux valeurs par défaut._";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'show_diff', 'count' => count($diffs)]);
    }

    // -------------------------------------------------------------------------
    // Validation & normalization
    // -------------------------------------------------------------------------

    private function validateValue(string $key, mixed $value): ?string
    {
        return match ($key) {
            'language' => !in_array($value, self::VALID_LANGUAGES)
                ? "Langue invalide *{$value}*. Langues supportées : "
                    . implode(', ', array_map(fn($l) => "*{$l}* (" . (self::LANGUAGE_LABELS[$l] ?? $l) . ")", self::VALID_LANGUAGES))
                : null,

            'timezone' => $this->validateTimezone($value),

            'unit_system' => !in_array($value, self::VALID_UNIT_SYSTEMS)
                ? "Système d'unités invalide. Valeurs acceptées : *metric*, *imperial*"
                : null,

            'date_format' => !in_array($value, self::VALID_DATE_FORMATS)
                ? "Format de date invalide. Formats acceptés : " . implode(', ', array_map(fn($f) => "*{$f}*", self::VALID_DATE_FORMATS))
                : null,

            'communication_style' => !in_array($value, self::VALID_STYLES)
                ? "Style invalide. Styles acceptés : " . implode(', ', array_map(fn($s) => "*{$s}*", self::VALID_STYLES))
                : null,

            'email' => ($value && !filter_var($value, FILTER_VALIDATE_EMAIL))
                ? "Adresse email invalide. Exemple : _jean@exemple.com_"
                : null,

            'phone' => $this->validatePhone($value),

            default => null,
        };
    }

    private function validateTimezone(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return "Fuseau horaire invalide. Exemples : _Europe/Paris_, _America/New_York_, _UTC_, _UTC+2_";
        }

        $value = trim($value);

        // Accept known aliases (UTC+1, UTC-5, etc.)
        if (isset(self::TIMEZONE_ALIASES[$value])) {
            return null;
        }

        // Accept raw UTC offsets (UTC+2, UTC-5, UTC+5:30, etc.)
        if (preg_match('/^UTC[+-]\d{1,2}(:\d{2})?$/', $value)) {
            return null;
        }

        // Accept city names (case-insensitive lookup)
        $lower = mb_strtolower($value);
        if (isset(self::CITY_TIMEZONE_MAP[$lower])) {
            return null;
        }

        // Validate against PHP's known timezone list
        if (!in_array($value, DateTimeZone::listIdentifiers())) {
            $suggestion = $this->suggestTimezone($value);
            $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
            return "Fuseau horaire invalide *{$value}*. Exemples valides : _Europe/Paris_, _America/New_York_, _Asia/Tokyo_, _UTC_, _UTC+2_{$extra}";
        }

        return null;
    }

    private function suggestTimezone(string $value): ?string
    {
        $lower = mb_strtolower(trim($value));

        foreach (self::CITY_TIMEZONE_MAP as $city => $tz) {
            if (str_contains($lower, $city) || str_contains($city, $lower)) {
                return $tz;
            }
        }

        foreach (DateTimeZone::listIdentifiers() as $id) {
            if (str_contains(mb_strtolower($id), $lower)) {
                return $id;
            }
        }

        return null;
    }

    private function validatePhone(mixed $value): ?string
    {
        if (!$value) {
            return null; // phone can be empty/null
        }

        $str        = (string) $value;
        $normalized = preg_replace('/[\s\-().]+/', '', $str);

        // Detect leading 0 without country code (e.g. 0612345678 for French number)
        if (preg_match('/^0(\d{9})$/', $normalized, $matches)) {
            $localPart = $matches[1];
            return "Numéro invalide. Remplace le 0 initial par l'indicatif pays.\n"
                . "Ex (France) : _+33{$localPart}_\n"
                . "_(Belgique : +32, Suisse : +41, Canada/USA : +1)_";
        }

        if (!preg_match('/^\+?[0-9]{7,15}$/', $normalized)) {
            return "Numéro de téléphone invalide. Utilise le format international : _+33612345678_";
        }

        return null;
    }

    private function normalizeValue(string $key, mixed $value): mixed
    {
        if ($key === 'notification_enabled') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if ($key === 'timezone') {
            $valueStr = trim((string) $value);

            // Resolve known aliases first (UTC+1, UTC-5, etc.)
            if (isset(self::TIMEZONE_ALIASES[$valueStr])) {
                return self::TIMEZONE_ALIASES[$valueStr];
            }

            // Resolve city names
            $lower = mb_strtolower($valueStr);
            if (isset(self::CITY_TIMEZONE_MAP[$lower])) {
                return self::CITY_TIMEZONE_MAP[$lower];
            }

            // Handle raw UTC offsets not in TIMEZONE_ALIASES (e.g. UTC+13, UTC-9:30)
            // Map to Etc/GMT equivalents (Etc/GMT uses inverted sign convention)
            if (preg_match('/^UTC([+-])(\d{1,2})(?::(\d{2}))?$/', $valueStr, $m)) {
                $sign    = $m[1];
                $hours   = (int) $m[2];
                $minutes = isset($m[3]) ? (int) $m[3] : 0;

                // Etc/GMT only supports whole-hour offsets; skip for half-hour offsets
                if ($minutes === 0) {
                    // Etc/GMT uses inverted sign: UTC+5 → Etc/GMT-5
                    $etcSign = $sign === '+' ? '-' : '+';
                    $etcId   = "Etc/GMT{$etcSign}{$hours}";
                    if (in_array($etcId, DateTimeZone::listIdentifiers())) {
                        return $etcId;
                    }
                }

                // For half-hour offsets (e.g. UTC+5:30), try a best-match from TIMEZONE_ALIASES
                $aliasKey = "UTC{$sign}{$hours}" . ($minutes > 0 ? ":{$minutes}" : '');
                if (isset(self::TIMEZONE_ALIASES[$aliasKey])) {
                    return self::TIMEZONE_ALIASES[$aliasKey];
                }
            }
        }

        if ($key === 'phone' && $value) {
            return preg_replace('/[\s\-().]+/', '', (string) $value);
        }

        return $value;
    }

    /**
     * Resolve any timezone string (city name, UTC offset, IANA id) to a valid PHP timezone identifier.
     */
    private function resolveTimezoneString(string $input): ?string
    {
        $input = trim($input);

        // Direct IANA timezone
        if (in_array($input, DateTimeZone::listIdentifiers())) {
            return $input;
        }

        // Known alias (UTC+2, UTC-5, etc.)
        if (isset(self::TIMEZONE_ALIASES[$input])) {
            return self::TIMEZONE_ALIASES[$input];
        }

        // City name (case-insensitive)
        $lower = mb_strtolower($input);
        if (isset(self::CITY_TIMEZONE_MAP[$lower])) {
            return self::CITY_TIMEZONE_MAP[$lower];
        }

        // Partial city name match
        foreach (self::CITY_TIMEZONE_MAP as $city => $tz) {
            if (str_contains($lower, $city) || str_contains($city, $lower)) {
                return $tz;
            }
        }

        // Raw UTC offset → Etc/GMT
        if (preg_match('/^UTC([+-])(\d{1,2})$/', $input, $m)) {
            $etcSign = $m[1] === '+' ? '-' : '+';
            $etcId   = "Etc/GMT{$etcSign}{$m[2]}";
            if (in_array($etcId, DateTimeZone::listIdentifiers())) {
                return $etcId;
            }
        }

        // Partial IANA identifier match
        foreach (DateTimeZone::listIdentifiers() as $id) {
            if (str_contains(mb_strtolower($id), $lower)) {
                return $id;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Day name helpers
    // -------------------------------------------------------------------------

    /**
     * Get localized day name for the given day-of-week index (0=Sun .. 6=Sat).
     */
    private function getDayName(int $dayOfWeek, string $lang = 'fr', bool $short = false): string
    {
        $lang = isset(self::DAY_NAMES[$lang]) ? $lang : 'fr';

        if ($short) {
            $table = self::DAY_NAMES_SHORT[$lang] ?? self::DAY_NAMES_SHORT['fr'];
        } else {
            $table = self::DAY_NAMES[$lang];
        }

        return $table[$dayOfWeek] ?? $table[0];
    }

    /**
     * Get localized month name for the given month number (1=Jan .. 12=Dec).
     */
    private function getMonthName(int $month, string $lang = 'fr'): string
    {
        $lang = isset(self::MONTH_NAMES[$lang]) ? $lang : 'fr';
        return self::MONTH_NAMES[$lang][$month] ?? (string) $month;
    }

    // -------------------------------------------------------------------------
    // Formatters
    // -------------------------------------------------------------------------

    private function formatShowPreferences(array $prefs): string
    {
        $langLabel  = self::LANGUAGE_LABELS[$prefs['language']] ?? $prefs['language'];
        $styleLabel = self::STYLE_LABELS[$prefs['communication_style']] ?? $prefs['communication_style'];
        $notif      = $prefs['notification_enabled'] ? '🔔 Activées' : '🔕 Désactivées';
        $unitIcon   = $prefs['unit_system'] === 'imperial' ? '🇺🇸' : '🌍';
        $lang       = $prefs['language'] ?? 'fr';

        $localTimeStr = '';
        try {
            $tz           = new DateTimeZone($prefs['timezone'] ?? 'UTC');
            $now          = new DateTimeImmutable('now', $tz);
            $dayName      = $this->getDayName((int) $now->format('w'), $lang, short: true);
            $localTimeStr = " _(heure locale : *{$now->format('H:i')}* {$dayName} UTC{$now->format('P')})_";
        } catch (\Exception) {
            // Silently ignore if timezone is invalid
        }

        $lines = [
            "👤 *MON PROFIL*",
            "────────────────",
            "🌐 Langue : *{$langLabel}* ({$prefs['language']})",
            "🕐 Fuseau : *{$prefs['timezone']}*{$localTimeStr}",
            "📅 Format date : *{$prefs['date_format']}* _(ex: " . date($prefs['date_format'] ?? 'd/m/Y') . ")_",
            "📏 Unités : {$unitIcon} *{$prefs['unit_system']}*",
            "💬 Style : *{$styleLabel}*",
            "🔔 Notifications : *{$notif}*",
            "📱 Téléphone : " . ($prefs['phone'] ? "*{$prefs['phone']}*" : '_non défini_'),
            "📧 Email : "     . ($prefs['email'] ? "*{$prefs['email']}*" : '_non défini_'),
            "────────────────",
            "💡 _Exemples de commandes :_",
            "• _set language en_",
            "• _timezone America/New\\_York_",
            "• _style formel_",
            "• _quelle heure est-il_",
            "• _heure à Tokyo_",
            "• _horloge mondiale_",
            "• _heures ouvrables Dubai_",
            "• _planifier réunion Tokyo_",
            "• _réunion multi Paris Tokyo New York_",
            "• _semaine prochaine_",
            "• _fuseaux Europe_",
            "• _mes personnalisations_",
            "• _aperçu formats date_",
            "• _dans combien de temps est Noël_",
            "• _est-ce l'heure d'été_",
            "• _convertir 14h30 de Tokyo à Paris_",
            "• _calendrier de la semaine_",
            "• _calendrier du mois_",
            "• _lever du soleil_",
            "• _quel âge ai-je si né le 1990-05-15_",
            "• _jours ouvrés du 2026-04-01 au 2026-04-30_",
            "• _quelles villes partagent mon fuseau_",
            "• _quand ouvre Tokyo_",
            "• _dans combien de temps avant 18h_",
            "• _progression de l'année_",
            "• _quelle date dans 30 jours_",
            "• _infos sur le 14 juillet 2026_",
            "• _aperçu Tokyo_ — brief rapide (heure+bureau+DST)",
            "• _audit préférences_ — bilan personnalisation",
            "• _timestamp 1711234567_ — convertir timestamp",
            "• _si c'est 15h à Paris, quelle heure à Tokyo, NYC, Dubai_",
            "• _brief Tokyo, New York et Dubai_ — aperçu multi-villes",
            "• _est-ce que 15h marche pour Tokyo et NYC_ — check horaire",
            "• _carte jour nuit_ — qui dort, qui est réveillé",
            "• _toutes les 2 semaines à partir du 1er avril_ — récurrence",
            "• _jours fériés France_ — prochains jours fériés",
            "• _semaine 15 2026_ — dates d'une semaine ISO",
            "• _exporter mes préférences_",
            "• _reset all_",
            "• _aide preferences_",
        ];

        return implode("\n", $lines);
    }

    private function formatHelp(): string
    {
        $lines = [
            "⚙️ *AIDE PRÉFÉRENCES* _(v{$this->version()})_",
            "────────────────",
            "",
            "*📋 Voir mon profil :*",
            "• _show preferences_ / _mon profil_ / _mes préférences_",
            "",
            "*🌐 Changer la langue :*",
            "• _set language en_ / _mets en français_",
            "• Langues : " . implode(', ', self::VALID_LANGUAGES),
            "",
            "*🕐 Changer le fuseau horaire :*",
            "• _timezone Europe/Paris_",
            "• _fuseau horaire New York_ / _timezone Tokyo_",
            "",
            "*📅 Changer le format de date :*",
            "• _format américain_ → m/d/Y",
            "• _format ISO_ → Y-m-d",
            "• Formats : " . implode(', ', self::VALID_DATE_FORMATS),
            "",
            "*📏 Système d'unités :*",
            "• _métrique_ / _imperial_",
            "",
            "*💬 Style de communication :*",
            "• _style formel_ / _style concis_",
            "• Styles : " . implode(', ', self::VALID_STYLES),
            "",
            "*🔔 Notifications :*",
            "• _activer notifications_ / _désactiver notifications_",
            "",
            "*📱 Contact :*",
            "• _mon email jean@exemple.com_",
            "• _mon numéro +33612345678_",
            "",
            "*🕐 Heure locale :*",
            "• _quelle heure est-il_ / _heure actuelle_",
            "",
            "*🌍 Comparer des fuseaux horaires :*",
            "• _heure à Tokyo_ / _heure en New York_",
            "• _compare timezone London_ / _décalage Dubai_",
            "",
            "*🌐 Horloge mondiale :*",
            "• _horloge mondiale_ → 8 grandes villes",
            "• _worldclock Tokyo, Paris, Dubai_ → villes choisies",
            "",
            "*🏢 Heures ouvrables :*",
            "• _heures ouvrables Tokyo_ / _au bureau à Dubai_",
            "• _est-ce ouvert maintenant à New York_",
            "",
            "*📅 Planificateur de réunion (2 villes) :*",
            "• _planifier réunion Tokyo_ / _meeting planner New York_",
            "• _trouver un horaire commun avec Londres_",
            "",
            "*📅 Planificateur multi-fuseau (3+ villes) :*",
            "• _réunion multi Paris Tokyo New York_",
            "• _créneaux communs Londres, Dubai et Sydney_",
            "• _multi-meeting Paris, New York, Tokyo, Singapore_",
            "",
            "*🔍 Recherche de fuseaux :*",
            "• _fuseaux Europe_ / _timezone search America_",
            "• _liste fuseaux Asie_ / _recherche fuseau Paris_",
            "",
            "*✏️ Mes personnalisations :*",
            "• _mes personnalisations_ / _diff preferences_",
            "",
            "*📋 Exporter mes préférences :*",
            "• _exporter mes préférences_ / _backup settings_",
            "",
            "*🔄 Réinitialiser :*",
            "• _reset language_ → langue par défaut",
            "• _reset all_ → tout réinitialiser",
            "",
            "*✏️ Modification multiple :*",
            "• _langue anglais et timezone New York_",
            "• _style formel et désactiver notifs_",
            "",
            "*📅 Aperçu des formats de date :*",
            "• _aperçu formats date_ / _quel format de date_",
            "• _montre-moi les formats de date_",
            "",
            "*📥 Importer / Restaurer des préférences :*",
            "• Colle directement un bloc exporté dans le chat",
            "• Ou : _importer mes préférences_ puis colle le bloc",
            "",
            "*⏳ Compte à rebours :*",
            "• _dans combien de temps est Noël_ / _jours jusqu'au 25 décembre_",
            "• _countdown 2027-01-01_ / _combien de jours avant le 14 juillet_",
            "• _jours restants avant 2026-06-15_",
            "",
            "*☀️ Heure d'été / Hiver — DST :*",
            "• _est-ce l'heure d'été_ / _heure d'été ou d'hiver_",
            "• _DST Paris_ / _changement heure Londres_ / _heure d'été Tokyo_",
            "• _quand change l'heure en France_ / _prochain changement d'heure_",
            "",
            "*🔄 Convertir une heure entre fuseaux :*",
            "• _convertir 14h30 de Tokyo à Paris_",
            "• _si c'est 9h à New York quelle heure est-il chez moi_",
            "• _quelle heure est-il à Londres si c'est 15h à Dubai_",
            "• _convertis 2pm de Londres en heure Paris_",
            "",
            "*📆 Calendrier de la semaine :*",
            "• _calendrier de la semaine_ / _ma semaine_ / _cette semaine_",
            "• _planning de la semaine_ / _calendar week_",
            "• _semaine prochaine_ / _semaine précédente_ / _semaine dernière_",
            "• _dans 2 semaines_ / _calendrier dans 3 semaines_",
            "",
            "*📅 Calendrier mensuel :*",
            "• _calendrier du mois_ / _ce mois_ / _vue mensuelle_",
            "• _mois prochain_ / _mois précédent_ / _mois dernier_",
            "• _calendrier dans 2 mois_",
            "",
            "*🌅 Lever / Coucher du soleil :*",
            "• _lever du soleil_ / _coucher du soleil_ / _soleil aujourd'hui_",
            "• _lever du soleil à Tokyo_ / _coucher soleil New York_",
            "• _durée du jour_ / _aube et crépuscule_",
            "• _quand se lève le soleil à Dubai_",
            "",
            "*🎂 Calculateur d'âge :*",
            "• _quel âge ai-je si je suis né le 1990-05-15_",
            "• _calcule mon âge, née le 1985-12-25_",
            "• _age pour quelqu'un né le 2000-01-01_",
            "• _anniversaire 1992-07-04_",
            "",
            "*💼 Jours ouvrés entre deux dates :*",
            "• _combien de jours ouvrés en avril 2026_",
            "• _jours de travail du 2026-03-15 au 2026-03-31_",
            "• _jours ouvrables entre 2026-05-01 et 2026-05-31_",
            "",
            "*🌐 Villes au même fuseau (same offset) :*",
            "• _quelles villes partagent mon fuseau_",
            "• _même heure que moi_ / _qui est avec moi_",
            "• _même fuseau que Tokyo_ / _fuseaux identiques à Dubai_",
            "",
            "*📅 Prochaine ouverture des bureaux :*",
            "• _quand ouvre Tokyo_ / _prochaines heures ouvrables New York_",
            "• _dans combien de temps ouvre Dubai_ / _prochain horaire bureau Londres_",
            "• _quand puis-je appeler quelqu'un à Sydney_",
            "",
            "*⏳ Temps restant avant une heure cible :*",
            "• _dans combien de temps avant 18h_ / _combien de minutes avant 14h30_",
            "• _time until 9pm_ / _temps restant avant midi_",
            "• _dans combien avant 14h30 à Tokyo_ / _time until 9am New York_",
            "",
            "*📊 Progression de l'année en cours :*",
            "• _progression de l'année_ / _avancement annuel_",
            "• _quel jour de l'année_ / _numéro du jour_ / _semaine ISO_",
            "• _combien de jours restants cette année_ / _quel trimestre_",
            "• _bilan de l'année_ / _year progress_",
            "",
            "*➕ Calcul de date (ajouter/soustraire) :*",
            "• _quelle date dans 30 jours_ / _dans 6 semaines on sera quel jour_",
            "• _date il y a 7 jours_ / _dans 3 mois quelle date_",
            "• _différence entre 2026-01-01 et 2026-06-30_",
            "• _combien de jours entre le 15 mars et le 30 juin 2026_",
            "• _combien de jours d'ici la fin de l'année_",
            "",
            "*🗓 Infos complètes sur une date :*",
            "• _quel jour est le 14 juillet 2026_ / _infos sur le 2026-09-01_",
            "• _c'est quoi comme jour le 25 décembre 2026_",
            "• _semaine et trimestre du 2026-06-21_",
            "• _date info today_ / _infos sur aujourd'hui_",
            "",
            "*🌍 Aperçu rapide d'une ville :*",
            "• _aperçu Tokyo_ / _brief New York_ / _résumé Dubai_",
            "• _quick brief London_ / _overview Singapore_",
            "• Combine : heure locale, heures ouvrables et heure d'été",
            "",
            "*📊 Audit des préférences :*",
            "• _audit préférences_ / _bilan préférences_",
            "• _état des préférences_ / _combien de préférences_",
            "• Résumé : personnalisées vs valeurs par défaut",
            "",
            "*🔢 Timestamp Unix :*",
            "• _timestamp 1711234567_ → convertir en date",
            "• _timestamp now_ / _quel timestamp_ → timestamp actuel",
            "• _timestamp 2026-07-14 15:00_ → date vers timestamp",
            "",
            "*🔄 Conversion multi-fuseaux :*",
            "• _si c'est 15h à Paris, quelle heure à Tokyo, New York, Dubai_",
            "• _convertir 9h vers Tokyo, Sydney et Singapore_",
            "• Affiche une heure dans plusieurs villes simultanément",
            "",
            "*🌍 Aperçu multi-villes (batch brief) :*",
            "• _brief Tokyo, New York et Dubai_ — aperçu groupé",
            "• _aperçu plusieurs villes London Singapore Sydney_",
            "• Combine heure, bureau et DST pour chaque ville",
            "",
            "*📋 Vérification d'horaire (schedule check) :*",
            "• _est-ce que 15h marche pour Tokyo et New York_",
            "• _check horaire 10h London Dubai Singapore_",
            "• Vérifie si un horaire est en heures ouvrables partout",
            "",
            "*🌍 Carte jour/nuit mondiale :*",
            "• _carte jour nuit_ / _qui dort_ / _qui est réveillé_",
            "• _day night map_ / _jour ou nuit dans le monde_",
            "• Affiche le statut jour/nuit de grandes villes mondiales",
            "",
            "*🔁 Événement récurrent :*",
            "• _toutes les 2 semaines à partir du 2026-04-01_",
            "• _prochaines payes tous les mois à partir du 15 avril_",
            "• _réunion chaque semaine, 8 prochaines occurrences_",
            "• Calcule les prochaines dates d'un événement répétitif",
            "",
            "*🎉 Jours fériés :*",
            "• _jours fériés_ / _prochains fériés_ / _holidays_",
            "• _jours fériés France_ / _public holidays US_ / _bank holidays UK_",
            "• _5 prochains jours fériés Allemagne_",
            "• Pays : FR, US, UK, DE, ES, IT ou international",
            "",
            "*📆 Semaine → Dates (ISO) :*",
            "• _semaine 15_ / _dates de la semaine 20_ / _week 15 2026_",
            "• _quelles dates sont en semaine 1 2027_",
            "• Convertit un numéro de semaine en dates lundi–dimanche",
        ];

        return implode("\n", $lines);
    }

    private function formatKeyLabel(string $key): string
    {
        return match ($key) {
            'language'            => 'Langue',
            'timezone'            => 'Fuseau horaire',
            'date_format'         => 'Format date',
            'unit_system'         => 'Unités',
            'communication_style' => 'Style',
            'notification_enabled'=> 'Notifications',
            'phone'               => 'Téléphone',
            'email'               => 'Email',
            default               => $key,
        };
    }

    private function formatValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '_non défini_';
        }

        return match ($key) {
            'language'             => (self::LANGUAGE_LABELS[$value] ?? $value) . " ({$value})",
            'communication_style'  => (self::STYLE_LABELS[$value] ?? $value),
            'notification_enabled' => ($value || $value === true || $value === 1) ? '🔔 Activées' : '🔕 Désactivées',
            'unit_system'          => ($value === 'imperial' ? '🇺🇸 ' : '🌍 ') . $value,
            default                => (string) $value,
        };
    }

    // -------------------------------------------------------------------------
    // JSON parsing
    // -------------------------------------------------------------------------

    private function parseJson(?string $response): ?array
    {
        if (!$response) {
            return null;
        }

        $clean = trim($response);

        // Strip markdown code blocks
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        // Handle array response — take first object
        $trimmed = ltrim($clean);
        if (str_starts_with($trimmed, '[')) {
            $arr = json_decode($trimmed, true);
            if (is_array($arr) && !empty($arr) && is_array($arr[0])) {
                return $arr[0];
            }
        }

        // Extract JSON object from surrounding text
        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        // Strip trailing commas before closing braces (common LLM error)
        $clean = preg_replace('/,\s*([}\]])/', '$1', $clean);

        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : null;
    }
}
