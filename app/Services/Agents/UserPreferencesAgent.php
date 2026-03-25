<?php

namespace App\Services\Agents;

use App\Models\UserPreference;
use App\Services\AgentContext;
use App\Services\PreferencesManager;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
        return 'Agent de gestion des préférences utilisateur. Permet de configurer la langue, le fuseau horaire, le format de date, le système d\'unités (métrique/impérial), le style de communication, les notifications, le téléphone et l\'email. Affiche le profil complet, l\'heure locale (avec numéro de semaine), les personnalisations actives, modifie un ou plusieurs paramètres à la fois, compare des fuseaux horaires, affiche une horloge mondiale multi-villes, vérifie les heures ouvrables d\'une ville, planifie des réunions entre fuseaux (meeting planner), recherche des fuseaux par région/pays, exporte/importe les préférences, réinitialise aux valeurs par défaut, affiche un compte à rebours jusqu\'à une date cible, donne les informations sur l\'heure d\'été/hiver (DST), convertit une heure spécifique d\'un fuseau à un autre (convert_time), affiche le calendrier de la semaine courante (calendar_week), affiche le calendrier mensuel (calendar_month), affiche les heures de lever/coucher du soleil (sun_times), calcule le temps restant avant une heure cible (time_until), affiche la progression de l\'année en cours avec jour de l\'année et semaine ISO (year_progress), affiche un aperçu rapide d\'une ville combinant heure, heures ouvrables et DST en un message (quick_brief), audite les préférences personnalisées vs valeurs par défaut (preferences_audit), convertit des timestamps Unix en dates et inversement (unix_timestamp), convertit une heure vers plusieurs fuseaux horaires simultanément (multi_convert), affiche une carte jour/nuit mondiale indiquant quelles villes dorment ou sont éveillées (day_night_map), calcule les prochaines occurrences d\'un événement récurrent (repeat_event), affiche les prochains jours fériés par pays ou internationaux (holiday_info), convertit un numéro de semaine ISO en dates lundi-dimanche (week_to_dates), calcule la durée écoulée entre deux heures ou dates (elapsed_time), trouve les créneaux de travail profond (focus/deep work) entre le fuseau de l\'utilisateur et une ville cible (focus_window), trouve le Nième jour de la semaine dans un mois donné comme le 3ème vendredi de juin ou le dernier lundi de mai (nth_weekday), affiche un briefing quotidien complet combinant date, progression journée/semaine/année, trimestre et DST (daily_summary), affiche un roster des fuseaux horaires montrant le statut en temps réel de plusieurs villes — au bureau, dort, week-end (timezone_roster), affiche un tableau de bord de productivité combinant progression journée, semaine, mois, trimestre et année avec un score composite (productivity_score), affiche l\'historique des transitions DST (changements d\'heure) pour un fuseau sur l\'année en cours (timezone_history), affiche le statut en temps réel des principaux marchés financiers mondiaux — NYSE, NASDAQ, LSE, Euronext, TSE, HKEX, SSE, ASX — avec heures d\'ouverture/fermeture et temps restant (market_hours), affiche un résumé compact de la semaine en cours combinant jour, progression, jours ouvrés restants et countdown week-end (week_summary), génère une fiche pratique complète pour un fuseau horaire combinant heure actuelle, décalage, DST, overlap bureau et meilleur créneau de réunion (timezone_cheatsheet), affiche la progression entre deux dates pour le suivi de projet avec barre visuelle, jours ouvrés et jalons (project_progress), montre quelle date et heure c\'était il y a N jours/mois/ans à cet instant précis comme une capsule temporelle (time_capsule), suggère le salut approprié pour une ville en fonction de l\'heure locale avec conseil de communication et traduction en langue locale (smart_greeting), affiche un tableau de bord complet combinant heure, progression journée/semaine/mois/année, énergie et prochain événement (status_dashboard), calcule le temps écoulé depuis une date passée avec jalons et détails (time_ago), calcule un score de focus basé sur l\'heure et le jour pour recommander le type de tâche optimal à réaliser maintenant — travail profond, réunions, créatif, admin ou repos — avec suggestions concrètes (focus_score), génère un template de standup/point quotidien prêt à coller avec date, semaine, sprint, jours ouvrés restants et conseil du jour (standup_helper), suit des objectifs personnels avec progression, deadline et rythme quotidien nécessaire (goal_tracker), et trouve le fuseau horaire le plus compatible parmi une liste de villes pour optimiser la collaboration (timezone_buddy).';
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
            // preferences suggestions
            'suggestions preferences', 'ameliorer preferences', 'improve preferences',
            'optimiser preferences', 'analyse preferences', 'preferences tips',
            'conseils preferences', 'suggestions profil', 'profile tips',
            // availability now
            'disponibilite', 'disponibilité', 'availability now', 'qui est disponible',
            'villes disponibles', 'available cities', 'open for calls',
            'qui peut appeler', 'appeler maintenant', 'call now',
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
            // timezone_abbrev — v1.20.0
            'abbreviation', 'abréviation', 'abreviation', 'que veut dire',
            'c est quoi', "c'est quoi", 'signifie', 'PST', 'EST', 'CET',
            'GMT', 'JST', 'IST', 'timezone abbreviation',
            // pomodoro — v1.20.0
            'pomodoro', 'tomate', 'sessions travail', 'focus timer',
            'technique pomodoro', 'minuteur travail', 'planning pomodoro',
            // elapsed_time — v1.21.0
            'duree entre', 'durée entre', 'elapsed time', 'temps ecoule', 'temps écoulé',
            'combien de temps entre', 'duration', 'heures entre',
            'de 9h a 17h', 'de 9h à 17h', 'temps de travail', 'heures travaillees',
            'heures travaillées', 'calculer duree', 'calculer durée',
            // focus_window — v1.21.0
            'focus window', 'fenetre focus', 'fenêtre focus', 'deep work',
            'heures calmes', 'quiet hours', 'creneau focus', 'créneau focus',
            'quand travailler tranquille', 'plage de concentration',
            'focus time', 'heures tranquilles', 'travail profond',
            // time_add — v1.22.0
            'dans combien il sera', 'quelle heure dans', 'heure plus',
            'timer', 'minuteur', 'dans 2h', 'dans 1h', 'dans 3h',
            'dans 30 minutes', 'dans 45 minutes', 'dans 1h30',
            'heure + ', 'ajouter temps', 'add time', 'time add',
            'si je pars dans', 'quand je finis', 'dans combien je finis',
            // meeting_suggest — v1.22.0
            'meilleur horaire', 'meilleur creneau', 'meilleur créneau',
            'suggest meeting', 'meeting suggest', 'creneau ideal', 'créneau idéal',
            'top horaires', 'meilleur moment reunion', 'meilleur moment réunion',
            'best meeting time', 'optimal meeting', 'suggestion reunion',
            'suggestion réunion', 'horaire optimal', 'score reunion',
            // timezone_summary — v1.23.0
            'résumé fuseau', 'resume fuseau', 'timezone summary', 'infos fuseau',
            'mon fuseau', 'profil fuseau', 'timezone info', 'tz info',
            'détails fuseau', 'details fuseau', 'fuseau complet',
            // date_range — v1.23.0
            'plage de dates', 'date range', 'tous les lundis', 'tous les mardis',
            'tous les mercredis', 'tous les jeudis', 'tous les vendredis',
            'every monday', 'every tuesday', 'every friday',
            'dates entre', 'liste de dates', 'jours entre',
            'calendrier entre', 'dates récurrentes', 'dates recurrentes',
            // theme — v1.24.0
            'theme', 'thème', 'mode sombre', 'dark mode', 'mode clair', 'light mode',
            'mode automatique',
            // profile_completeness — v1.24.0
            'completude', 'complétude', 'profile completeness', 'profil complet',
            'score profil', 'taux de remplissage', 'profil incomplet',
            // preference_snapshot — v1.24.0
            'snapshot', 'résumé rapide', 'resume rapide', 'quick summary',
            // favorite_cities — v1.25.0
            'favorite cities', 'villes favorites', 'mes villes', 'villes préférées',
            'villes preferees', 'ajouter ville', 'supprimer ville', 'add city',
            'remove city', 'my cities', 'liste villes', 'villes sauvegardees',
            'villes sauvegardées', 'fav cities', 'favori ville',
            // time_diff — v1.25.0
            'time diff', 'difference horaire', 'différence horaire', 'decalage entre',
            'décalage entre', 'combien d heures entre', 'combien heures entre',
            'ecart horaire', 'écart horaire', 'heures de difference', 'heures de différence',
            'offset entre', 'diff horaire', 'heure diff',
            // v1.26.0 — locale_preset
            'profil français', 'profil francais', 'french profile', 'profil france',
            'us profile', 'profil américain', 'profil americain', 'profil usa',
            'uk profile', 'profil anglais', 'british profile', 'profil britannique',
            'german profile', 'profil allemand', 'deutsches profil',
            'spanish profile', 'profil espagnol', 'profil espagne',
            'italian profile', 'profil italien',
            'japanese profile', 'profil japonais',
            'chinese profile', 'profil chinois',
            'arabic profile', 'profil arabe',
            'portuguese profile', 'profil portugais',
            'profils régionaux', 'profils regionaux', 'locale preset', 'locale presets',
            'profil regional', 'profil régional', 'regional profile',
            // v1.26.0 — workday_progress
            'progression journée', 'progression journee', 'workday progress',
            'ma journée', 'ma journee', 'avancement journée', 'avancement journee',
            'journée de travail', 'journee de travail', 'my workday',
            'fin de journée', 'fin de journee', 'bilan journée', 'bilan journee',
            'temps de travail restant', 'progress travail',
            // v1.27.0 — timezone_overlap
            'overlap', 'overlap horaire', 'chevauchement horaire', 'timezone overlap',
            'heures communes', 'overlap avec', 'chevauchement avec',
            'overlap bureau', 'overlap travail', 'heures partagees',
            'heures partagées', 'overlap Tokyo', 'overlap New York',
            // v1.27.0 — sleep_schedule
            'sleep schedule', 'adaptation horaire', 'planning sommeil',
            'jet lag recovery', 'recuperation jet lag', 'récupération jet lag',
            'planning adaptation', 'adaptation sommeil', 'sommeil voyage',
            'sleep plan', 'horaires sommeil voyage', 'adaptation decalage',
            'adaptation décalage', 'quand dormir', 'schedule sommeil',
            // v1.28.0 — flight_time
            'flight time', 'heure arrivee', 'heure arrivée', 'vol', 'avion',
            'atterrissage', 'arrival time', 'depart vol', 'départ vol',
            'heure atterrissage', 'arrivee vol', 'arrivée vol',
            'flight arrival', 'calcul vol', 'duree vol', 'durée vol',
            'vol de', 'vol paris', 'vol tokyo', 'vol new york',
            // v1.28.0 — deadline_check
            'deadline', 'echeance', 'échéance', 'deadline check',
            'verifier deadline', 'vérifier deadline', 'date limite',
            'rappel deadline', 'deadline reminder', 'check deadline',
            'jours avant deadline', 'jours restants deadline',
            'est-ce un jour ouvre', 'est-ce un jour ouvré',
            // v1.29.0 — month_progress
            'progression mois', 'month progress', 'avancement mois',
            'ce mois ci', 'stats mois', 'bilan mois', 'mois en cours',
            'jours ouvres ce mois', 'jours ouvrés ce mois', 'restant ce mois',
            'jours restants ce mois', 'monthly progress', 'progression mensuelle',
            // v1.29.0 — alarm_time
            'alarm', 'alarme', 'heure coucher', 'heure reveil', 'heure réveil',
            'sleep calculator', 'calculateur sommeil', 'cycle sommeil',
            'cycles de sommeil', 'a quelle heure dormir', 'à quelle heure dormir',
            'quand me coucher', 'quand dormir pour', 'quand me reveiller',
            'quand me réveiller', 'bedtime', 'wakeup time', 'sleep cycles',
            // v1.30.0 — quarter_progress
            'progression trimestre', 'quarter progress', 'avancement trimestre',
            'stats trimestre', 'bilan trimestre', 'trimestre en cours',
            'jours restants trimestre', 'quarterly progress',
            // v1.30.0 — next_weekday
            'prochain lundi', 'prochain mardi', 'prochain mercredi',
            'prochain jeudi', 'prochain vendredi', 'prochain samedi', 'prochain dimanche',
            'next monday', 'next tuesday', 'next wednesday', 'next thursday',
            'next friday', 'next saturday', 'next sunday',
            'prochains lundis', 'prochains vendredis', 'next weekday',
            // v1.32.0 — week_progress
            'progression semaine', 'week progress', 'avancement semaine',
            'stats semaine', 'bilan semaine', 'semaine en cours',
            'jours restants semaine', 'weekly progress', 'progression hebdo',
            'ma semaine avancement', 'ou en est la semaine',
            // v1.32.0 — batch_countdown
            'batch countdown', 'multi countdown', 'plusieurs comptes a rebours',
            'countdown multiple', 'comptes a rebours', 'mes countdowns',
            'multi rebours', 'countdowns', 'tous mes comptes a rebours',
            // v1.33.0 — nth_weekday
            'nieme jour', 'nième jour', 'nth weekday', 'quel jour est le',
            'premier lundi', 'deuxieme mardi', 'deuxième mardi',
            'troisieme vendredi', 'troisième vendredi', 'dernier vendredi',
            'dernier lundi', 'dernier mardi', 'dernier mercredi',
            'dernier jeudi', 'dernier samedi', 'dernier dimanche',
            'last friday', 'last monday', 'first monday', 'second tuesday',
            'third friday', 'fourth thursday', '1er lundi', '2eme mardi',
            '3eme vendredi', '4eme jeudi', 'nieme', 'nième',
            // v1.34.0 — daily_summary
            'daily summary', 'briefing du jour', 'briefing jour', 'résumé du jour',
            'resume du jour', 'daily briefing', 'briefing quotidien', 'mon briefing',
            'ma journée résumé', 'bilan du jour', 'récap du jour', 'recap du jour',
            'résumé quotidien', 'resume quotidien', 'today briefing', 'today summary',
            // v1.34.0 — timezone_roster
            'roster', 'timezone roster', 'roster fuseaux', 'roster équipe',
            'roster equipe', 'team roster', 'statut équipe', 'statut equipe',
            'team status', 'qui travaille', 'qui dort maintenant',
            'statut villes', 'statut mondial', 'roster mondial',
            'roster villes', 'city roster', 'team timezone',
            // v1.35.0 — productivity_score
            'productivity score', 'score productivité', 'score productivite',
            'productivité', 'productivite', 'productivity', 'bilan productivité',
            'bilan productivite', 'mes stats', 'my stats', 'tableau de bord',
            'dashboard', 'performance', 'mes métriques', 'mes metriques',
            // v1.35.0 — timezone_history
            'timezone history', 'historique fuseau', 'historique changement heure',
            'changements horaires', 'dst history', 'historique dst', 'transitions horaires',
            'transitions fuseau', 'past dst changes', 'passé heure été',
            // v1.36.0 — unit_convert
            'convertir unité', 'convertir unite', 'conversion unité', 'conversion unite',
            'unit convert', 'convert unit', 'celsius', 'fahrenheit', 'kelvin',
            'kilometres', 'kilometers', 'miles', 'kilogrammes', 'kilograms',
            'pounds', 'livres', 'litres', 'liters', 'gallons',
            'combien de miles', 'combien de km', 'combien en celsius', 'combien en fahrenheit',
            'temperature', 'température', 'distance', 'poids', 'weight', 'volume',
            'kg en livres', 'livres en kg', 'km en miles', 'miles en km',
            'celsius en fahrenheit', 'fahrenheit en celsius', 'litres en gallons',
            'gallons en litres', 'conversion metrique', 'conversion métrique',
            'conversion imperial', 'conversion impériale', 'metric to imperial',
            'imperial to metric',
            // v1.36.0 — week_planner
            'week planner', 'planning hebdomadaire', 'planner semaine',
            'ma semaine complète', 'ma semaine complete', 'vue semaine',
            'weekly planner', 'semaine vue complète', 'semaine vue complete',
            'plan de la semaine', 'planification semaine', 'mon planning',
            'weekly overview', 'overview semaine', 'semaine détaillée', 'semaine detaillee',
            // v1.37.0 — season_info
            'season info', 'info saison', 'quelle saison', 'saison actuelle',
            'current season', 'saison en cours', 'equinoxe', 'equinox',
            'solstice', 'solstice été', 'solstice hiver', 'printemps',
            'été', 'automne', 'hiver', 'spring', 'summer', 'autumn', 'winter',
            'prochaine saison', 'next season', 'changement de saison',
            'season change', 'début saison', 'estación', 'jahreszeit',
            'saison hémisphère', 'hemisphere season',
            // v1.37.0 — quick_timer
            'quick timer', 'minuteur', 'minuteur rapide', 'timer rapide',
            'timer', 'chrono', 'chronomètre', 'chronometer',
            'si je commence à', 'if i start at', 'start at',
            'durée depuis', 'combien de temps si', 'how long if',
            'début plus durée', 'start plus duration',
            'fin de tâche', 'end of task', 'quand je finis',
            'when do i finish', 'calcul fin', 'heure de fin',
            'temporizador', 'schneller timer',
            // v1.38.0 — market_hours
            'market hours', 'marchés financiers', 'marches financiers', 'financial markets',
            'bourse', 'stock market', 'bourses ouvertes', 'marchés ouverts',
            'wall street', 'nyse', 'nasdaq', 'london stock exchange', 'lse',
            'tokyo stock exchange', 'tse', 'nikkei', 'marché ouvert',
            'est-ce que la bourse est ouverte', 'is the market open',
            'horaires bourse', 'market open', 'market closed',
            'trading hours', 'heures trading', 'mercados financieros',
            'finanzmärkte', 'börse', 'mercati finanziari',
            // v1.38.0 — week_summary
            'week summary', 'résumé semaine', 'resume semaine', 'bilan semaine',
            'recap semaine', 'weekly summary', 'weekly recap',
            'bilan de la semaine', 'récap semaine', 'recap hebdo',
            'où en est ma semaine', 'ou en est ma semaine',
            'week recap', 'how is my week', 'resumen semana',
            'wochenzusammenfassung', 'riepilogo settimana',
            // v1.39.0 — date_diff
            'date diff', 'différence dates', 'difference dates', 'entre deux dates',
            'between two dates', 'days between', 'jours entre', 'combien de jours entre',
            'écart dates', 'ecart dates', 'intervalle dates', 'date interval',
            'diferencia fechas', 'datumsunterschied', 'differenza date',
            'how many days between', 'how many days from',
            'combien de jours du', 'combien de semaines entre',
            'weeks between', 'months between', 'mois entre',
            // v1.39.0 — relative_date
            'il y a combien de jours', 'how long ago', 'days ago',
            'dans combien de jours', 'days until', 'how many days until',
            'jours depuis', 'days since', 'depuis le', 'since',
            'avant le', 'before', 'il y a', 'ago',
            'hace cuántos días', 'vor wie vielen tagen', 'quanti giorni fa',
            // v1.40.0 — timezone_cheatsheet
            'cheatsheet', 'cheat sheet', 'fiche fuseau', 'fiche horaire',
            'résumé horaire', 'resume horaire', 'timezone cheatsheet',
            'fiche pratique', 'quick reference', 'fiche ville',
            'ref fuseau', 'aide-mémoire fuseau', 'aide memoire fuseau',
            'timezone card', 'tz card', 'fiche tz',
            // v1.40.0 — project_progress
            'project progress', 'progression projet', 'avancement projet',
            'suivi projet', 'project tracker', 'suivi date',
            'entre deux dates progression', 'progress between',
            'barre de progression', 'progress bar', 'tracking projet',
            'projet avancement', 'milestone', 'jalon projet',
            // v1.41.0 — time_capsule
            'capsule', 'time capsule', 'capsule temporelle',
            'il y a 1 an', 'il y a un an', 'il y a 5 ans',
            'il y a 6 mois', 'il y a 100 jours',
            'years ago', 'months ago', 'days ago', 'weeks ago',
            'ans en arrière', 'mois en arrière', 'jours en arrière',
            'à cette date', 'at this time', 'hace un año',
            // v1.41.0 — smart_greeting
            'smart greeting', 'salut intelligent', 'greeting',
            'quel salut', 'comment saluer', 'bonjour ou bonsoir',
            'quoi dire', 'salut pour', 'salutation',
            'good morning or evening', 'what greeting',
            'cómo saludar', 'wie begrüßen',
            // v1.42.0 — energy_level
            'energy level', 'niveau énergie', 'niveau energie', 'énergie',
            'circadian', 'rythme circadien', 'productivité optimale',
            'quand travailler', 'best time to work', 'peak hours',
            'heures productives', 'focus time', 'quand être productif',
            'energy now', 'mon énergie', 'mon energie', 'energy advice',
            'conseil productivité', 'conseil productivite',
            'when to focus', 'optimal hours', 'heures optimales',
            // v1.42.0 — international_dialing
            'indicatif', 'dialing code', 'country code', 'code pays',
            'indicatif téléphonique', 'indicatif telephonique',
            'appeler', 'calling code', 'phone code',
            'indicatif international', 'international dialing',
            'comment appeler', 'how to call', 'dial code',
            'code téléphone', 'code telephone', 'préfixe pays',
            'prefixe pays', 'quel indicatif', 'indicatif de',
            // v1.43.0 — preferences_search + locale_details
            'chercher preference', 'chercher préférence', 'search preference', 'find preference',
            'rechercher preference', 'rechercher préférence', 'search settings',
            'locale details', 'détails locale', 'details locale', 'info locale',
            'parametres regionaux', 'paramètres régionaux', 'regional settings',
            // v1.44.0 — status_dashboard + time_ago
            'dashboard complet', 'full dashboard', 'status dashboard', 'mon tableau de bord',
            'vue ensemble', 'overview', 'mon statut', 'my status',
            'il y a combien', 'time ago', 'depuis combien', 'elapsed since',
            'ça fait combien', 'how long ago', 'temps écoulé', 'temps ecoule',
            'depuis quand', 'since when', 'how long since',
            // v1.45.0 — focus_score + standup_helper
            'focus score', 'score de focus', 'score focus', 'que faire maintenant',
            'what should i do', 'quoi travailler', 'optimal task', 'tâche optimale',
            'deep work', 'best task now', 'meilleure tâche', 'productivité maintenant',
            'focus now', 'concentration', 'que travailler', 'what to work on',
            'standup', 'stand up', 'standup helper', 'template standup',
            'daily standup', 'standup du jour', 'mon standup', 'my standup',
            'standup template', 'modèle standup', 'modele standup',
            'scrum daily', 'daily scrum', 'point quotidien', 'point du jour',
            // v1.46.0 — morning_routine + preferences_compare
            'morning routine', 'routine matinale', 'routine du matin', 'ma routine',
            'my routine', 'morning checklist', 'checklist matin', 'morning plan',
            'plan du matin', 'routine quotidienne', 'daily routine', 'start my day',
            'commencer ma journée', 'commencer ma journee', 'bien démarrer',
            'preferences compare', 'comparer preferences', 'comparer préférences',
            'compare settings', 'compare my preferences', 'compare mes préférences',
            'vs regional', 'vs locale', 'comparaison locale', 'locale comparison',
            'how do my settings compare', 'mes réglages vs', 'benchmark preferences',
            // v1.48.0 — weekly_review + habit_tracker
            'weekly review', 'bilan semaine', 'recap semaine', 'résumé hebdomadaire',
            'revue de la semaine', 'bilan hebdo', 'review my week', 'how was my week',
            'weekly recap', 'semaine en revue', 'bilan de la semaine',
            'habit tracker', 'mes habitudes', 'suivi habitudes', 'daily habits',
            'habit check', 'check-in habitudes', 'bilan habitudes', 'routine tracker',
            'mes objectifs du jour', 'daily goals', 'streak',
            // v1.49.0 — goal_tracker + timezone_buddy
            'goal tracker', 'mes objectifs', 'my goals', 'suivi objectifs', 'track goals',
            'objectifs', 'goals', 'bilan objectifs', 'goal progress', 'progression objectifs',
            'timezone buddy', 'tz buddy', 'fuseau ami', 'partenaire fuseau',
            'closest timezone', 'fuseau le plus proche', 'meilleur fuseau',
            // v1.52.0 — break_reminder + time_budget
            'break reminder', 'pause', 'rappel pause', 'when to take a break',
            'quand faire une pause', 'break time', 'stretch break', 'pause café',
            'pause cafe', 'temps de pause', 'descanso', 'pausenzeit',
            'time budget', 'budget temps', 'heures restantes', 'remaining hours',
            'hours left', 'working hours left', 'budget horaire', 'temps de travail restant',
            'horas restantes', 'verbleibende stunden', 'ore rimanenti',

            // v1.50.0 — duration_calc + smart_schedule
            'duration calc', 'calcul durée', 'calcul duree', 'additionner heures',
            'somme heures', 'total heures', 'heures total', 'timesheet',
            'feuille de temps', 'addition heures', 'cumul heures',
            'durée totale', 'duree totale', 'sum hours', 'add hours',
            'add durations', 'total duration', 'somme durées', 'somme durees',
            'smart schedule', 'meilleur moment', 'quand faire', 'best time for',
            'optimal time', 'heure idéale', 'heure ideale', 'quand planifier',
            'schedule suggestion', 'suggestion horaire', 'meilleur créneau pour',
            'meilleur creneau pour', 'when to exercise', 'quand faire du sport',
            'quand créer', 'quand creer', 'quand réunion', 'quand reunion',

            // v1.53.0 — additional break_reminder + time_budget keywords
            'need a break', 'prochaine pause', 'take a break',
            'pause santé', 'pause sante', 'pause travail',
            'budget journée', 'budget journee', 'temps restant',
            'remaining time', 'combien de temps reste', 'how much time left',
            'day budget', 'budget semaine', 'weekly budget', 'time remaining today',

            // v1.54.0 — time_report + city_compare keywords
            'time report', 'rapport quotidien', 'daily report', 'rapport temps',
            'daily time report', 'résumé du jour', 'resume du jour', 'bilan journée',
            'bilan journee', 'état du monde', 'etat du monde', 'world status',
            'comparer villes', 'compare cities', 'city compare', 'city comparison',
            'paris vs', 'vs tokyo', 'vs london', 'vs new york',
            'comparer avec', 'compare with', 'ville vs ville',

            // v1.55.0 — birthday_countdown + quick_setup keywords
            'countdown anniversaire', 'birthday countdown', 'prochain anniversaire',
            'mon anniversaire', 'my birthday', 'next birthday', 'cumpleaños',
            'geburtstag', 'compleanno', 'aniversário',
            'quick setup', 'setup rapide', 'configuration rapide', 'configurer profil',
            'setup', 'config rapide', 'quick start', 'démarrage rapide', 'demarrage rapide',
            'assistant configuration', 'guide configuration', 'wizard',

            // v1.56.0 — work_life_balance + timezone_quiz keywords
            'work-life balance', 'work life balance', 'équilibre vie-travail', 'equilibre vie-travail',
            'balance travail', 'balance score', 'equilibrio trabajo', 'gleichgewicht',
            'suis-je en surcharge', 'am i overworking', 'surcharge travail',
            'quiz timezone', 'quiz fuseau', 'timezone quiz', 'timezone game',
            'quiz fuseaux', 'quiz horaires', 'question fuseau', 'devinette horaire',
            'quiz géo', 'quiz geo', 'quiz tz', 'teste mes connaissances',

            // v1.59.0 — timezone_roulette + meeting_cost keywords
            'timezone roulette', 'roulette fuseau', 'ville aléatoire', 'ville aleatoire',
            'random city', 'random timezone', 'découvrir une ville', 'decouvrir une ville',
            'surprise fuseau', 'explore timezone', 'discover city',
            'meeting cost', 'coût réunion', 'cout reunion', 'timezone tax',
            'taxe fuseau', 'inconvénience réunion', 'inconvenience reunion',
            'meeting inconvenience', 'coût horaire réunion', 'cout horaire reunion',

            // v1.60.0 — currency_info + water_reminder keywords
            'currency info', 'devise', 'monnaie', 'currency', 'quelle devise',
            'quelle monnaie', 'money', 'change', 'taux de change', 'exchange rate',
            'devise locale', 'local currency', 'argent', 'money info',
            'water reminder', 'rappel eau', 'hydratation', 'hydration',
            'boire de l\'eau', 'drink water', 'rappel hydratation',
            'eau', 'water tracker', 'suivi eau', 'hydration tracker',
            'combien boire', 'how much water', 'objectif eau', 'water goal',

            // v1.61.0 — meeting_countdown + daily_planner keywords
            'meeting countdown', 'countdown réunion', 'countdown reunion',
            'réunion à', 'reunion a', 'meeting at', 'rdv à', 'rdv a',
            'dans combien ma réunion', 'dans combien ma reunion',
            'countdown meeting', 'avant ma réunion', 'avant ma reunion',
            'daily planner', 'planning journée', 'planning journee',
            'plan du jour', 'plan journalier', 'time blocking',
            'blocs horaires', 'organiser ma journée', 'organiser ma journee',
            'plan my day', 'my daily plan',
            // v1.62.0 — Timezone matrix
            'timezone matrix', 'matrice fuseaux', 'matrice fuseau',
            'time matrix', 'grille horaire', 'grille fuseaux',
            'timezone grid', 'planning grid',
        ];
    }

    public function version(): string
    {
        return '1.62.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'user_preferences';
    }

    public function handle(AgentContext $context): AgentResult
    {
        set_time_limit(120);
        $startMs = hrtime(true);

        try {
            $result = $this->handleInner($context);
            $durationMs = (int) ((hrtime(true) - $startMs) / 1_000_000);
            $this->log($context, "handle() completed in {$durationMs}ms", [
                'duration_ms' => $durationMs,
                'action'      => $result->metadata['action'] ?? 'unknown',
            ]);
            return $result;
        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            Log::error('UserPreferencesAgent handle() exception', [
                'error'   => $errMsg,
                'trace'   => mb_substr($e->getTraceAsString(), 0, 1500),
                'from'    => $context->from,
                'body'    => mb_substr($context->body ?? '', 0, 100),
            ]);
            $this->log($context, 'EXCEPTION: ' . $errMsg, ['class' => get_class($e)], 'error');

            $isDbError      = $e instanceof \Illuminate\Database\QueryException;
            $isRateLimit    = str_contains($errMsg, 'rate_limit') || str_contains($errMsg, '429');
            $isTimeout      = str_contains($errMsg, 'timed out') || str_contains($errMsg, 'timeout');
            $isOverloaded   = str_contains($errMsg, 'overloaded') || str_contains($errMsg, '529') || str_contains($errMsg, '503');
            $isConnection   = str_contains($errMsg, 'cURL') || str_contains($errMsg, 'Connection refused') || str_contains($errMsg, 'Could not resolve');
            $isAuth         = str_contains($errMsg, '401') || str_contains($errMsg, '403') || str_contains($errMsg, 'authentication') || str_contains($errMsg, 'Unauthorized');
            $isMemory       = $e instanceof \Error && str_contains($errMsg, 'memory');
            $isTypeError    = $e instanceof \TypeError;
            $isJsonError    = $e instanceof \JsonException || str_contains($errMsg, 'JSON') || str_contains($errMsg, 'json_decode');

            // i18n-aware error messages based on user language preference
            $lang = 'fr';
            try {
                $prefs = PreferencesManager::getPreferences($context->from);
                $lang  = $prefs['language'] ?? 'fr';
            } catch (\Throwable) {
                // Fallback to French if preferences can't be loaded
            }

            $reply = match (true) {
                $isDbError    => $this->i18nError($lang, 'db'),
                $isRateLimit  => $this->i18nError($lang, 'rate_limit'),
                $isTimeout    => $this->i18nError($lang, 'timeout'),
                $isOverloaded => $this->i18nError($lang, 'overloaded'),
                $isConnection => $this->i18nError($lang, 'connection'),
                $isAuth       => $this->i18nError($lang, 'auth'),
                $isMemory     => $this->i18nError($lang, 'memory'),
                $isTypeError  => $this->i18nError($lang, 'input_error'),
                $isJsonError  => $this->i18nError($lang, 'json_error'),
                default       => $this->i18nError($lang, 'default'),
            };

            // v1.49.0 — Track consecutive errors per user (5-min window) with smart cooldown
            $errorCacheKey = "user_prefs:error_count:{$context->from}";
            $errorCount = (int) Cache::get($errorCacheKey, 0) + 1;
            Cache::put($errorCacheKey, $errorCount, now()->addMinutes(5));

            if ($errorCount >= 5) {
                // Heavy rate — suggest waiting longer
                $escalation = match ($lang) {
                    'en' => "\n\n⏸️ _Too many errors in a row ({$errorCount}). Please wait 2-3 minutes before trying again. The service may be temporarily overloaded._",
                    'es' => "\n\n⏸️ _Demasiados errores seguidos ({$errorCount}). Espera 2-3 minutos antes de intentar de nuevo._",
                    'de' => "\n\n⏸️ _Zu viele Fehler hintereinander ({$errorCount}). Bitte warte 2-3 Minuten, bevor du es erneut versuchst._",
                    'it' => "\n\n⏸️ _Troppi errori consecutivi ({$errorCount}). Attendi 2-3 minuti prima di riprovare._",
                    'pt' => "\n\n⏸️ _Muitos erros seguidos ({$errorCount}). Aguarde 2-3 minutos antes de tentar novamente._",
                    'ar' => "\n\n⏸️ _أخطاء متتالية كثيرة ({$errorCount}). يرجى الانتظار 2-3 دقائق قبل المحاولة مرة أخرى._",
                    'zh' => "\n\n⏸️ _连续错误过多 ({$errorCount})。请等待 2-3 分钟后再试。_",
                    'ja' => "\n\n⏸️ _連続エラーが多すぎます ({$errorCount})。2-3分お待ちの上、再度お試しください。_",
                    'ko' => "\n\n⏸️ _연속 오류가 너무 많습니다 ({$errorCount}). 2-3분 후에 다시 시도해 주세요._",
                    'ru' => "\n\n⏸️ _Слишком много ошибок подряд ({$errorCount}). Подождите 2-3 минуты перед повторной попыткой._",
                    'nl' => "\n\n⏸️ _Te veel fouten achter elkaar ({$errorCount}). Wacht 2-3 minuten voordat je het opnieuw probeert._",
                    default => "\n\n⏸️ _Trop d'erreurs consécutives ({$errorCount}). Patiente 2-3 minutes avant de réessayer. Le service est peut-être temporairement surchargé._",
                };
                $reply .= $escalation;
            } elseif ($errorCount >= 3) {
                $escalation = match ($lang) {
                    'en' => "\n\n_Multiple errors detected. Try a simpler command like *my profile* or *help preferences*, or wait a few minutes._",
                    'es' => "\n\n_Múltiples errores detectados. Prueba un comando simple como *mi perfil* o *ayuda preferencias*, o espera unos minutos._",
                    'de' => "\n\n_Mehrere Fehler erkannt. Versuche einen einfachen Befehl wie *mein Profil* oder *Hilfe Einstellungen*, oder warte einige Minuten._",
                    'it' => "\n\n_Più errori rilevati. Prova un comando semplice come *mio profilo* o *aiuto preferenze*, oppure attendi qualche minuto._",
                    'pt' => "\n\n_Múltiplos erros detectados. Tente um comando simples como *meu perfil* ou *ajuda preferências*, ou aguarde alguns minutos._",
                    'ar' => "\n\n_تم اكتشاف أخطاء متعددة. جرب أمرًا بسيطًا مثل *ملفي الشخصي* أو *مساعدة التفضيلات*، أو انتظر بضع دقائق._",
                    'zh' => "\n\n_检测到多次错误。请尝试简单命令如 *我的资料* 或 *帮助 偏好设置*，或等待几分钟。_",
                    'ja' => "\n\n_複数のエラーが検出されました。*my profile* や *help preferences* のような簡単なコマンドを試すか、数分お待ちください。_",
                    'ko' => "\n\n_여러 오류가 감지되었습니다. *my profile* 또는 *help preferences* 같은 간단한 명령을 시도하거나 몇 분 기다려 주세요._",
                    'ru' => "\n\n_Обнаружено несколько ошибок. Попробуйте простую команду вроде *my profile* или *help preferences*, или подождите несколько минут._",
                    'nl' => "\n\n_Meerdere fouten gedetecteerd. Probeer een eenvoudig commando zoals *mijn profiel* of *help preferences*, of wacht een paar minuten._",
                    default => "\n\n_Plusieurs erreurs détectées. Essaie une commande simple comme *mon profil* ou *aide preferences*, ou patiente quelques minutes._",
                };
                $reply .= $escalation;
            }

            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'error', 'error_type' => get_class($e), 'error_count' => $errorCount]);
        }
    }

    private function handleInner(AgentContext $context): AgentResult
    {
        $body   = trim($context->body ?? '');
        $userId = $context->from;

        $this->log($context, "Processing preferences command", ['body' => mb_substr($body, 0, 100)]);

        // v1.61.0 — Request throttle: max 10 requests per user per minute to prevent abuse
        $throttleKey = "user_prefs:throttle:{$userId}";
        $requestCount = (int) Cache::get($throttleKey, 0);
        if ($requestCount >= 10) {
            $prefs = PreferencesManager::getPreferences($userId);
            $lang  = $prefs['language'] ?? 'fr';
            $throttleMsg = match ($lang) {
                'en' => "⏳ You're sending requests too quickly. Please wait a moment before trying again.\n\n_Limit: 10 requests per minute._",
                'es' => "⏳ Estás enviando solicitudes demasiado rápido. Espera un momento.\n\n_Límite: 10 solicitudes por minuto._",
                'de' => "⏳ Du sendest Anfragen zu schnell. Bitte warte einen Moment.\n\n_Limit: 10 Anfragen pro Minute._",
                'it' => "⏳ Stai inviando richieste troppo velocemente. Aspetta un momento.\n\n_Limite: 10 richieste al minuto._",
                'pt' => "⏳ Você está enviando solicitações rápido demais. Aguarde um momento.\n\n_Limite: 10 solicitações por minuto._",
                default => "⏳ Tu envoies des requêtes trop rapidement. Patiente un instant avant de réessayer.\n\n_Limite : 10 requêtes par minute._",
            };
            return AgentResult::reply($throttleMsg, ['action' => 'throttled', 'request_count' => $requestCount]);
        }
        Cache::put($throttleKey, $requestCount + 1, now()->addMinutes(1));

        // Handle empty or very short messages
        if (mb_strlen($body) < 2) {
            $prefs = PreferencesManager::getPreferences($userId);
            return AgentResult::reply($this->formatShowPreferences($prefs), ['action' => 'show_preferences', 'reason' => 'empty_body']);
        }

        // Truncate excessively long messages to avoid wasting LLM tokens
        if (mb_strlen($body) > 2000) {
            $body = mb_substr($body, 0, 2000);
            $this->log($context, "Message truncated to 2000 chars", [], 'info');
        }

        // Sanitize control characters that could break JSON parsing
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $body);

        // Guard against body becoming empty after sanitization
        if (mb_strlen(trim($body)) < 2) {
            $prefs = PreferencesManager::getPreferences($userId);
            return AgentResult::reply($this->formatShowPreferences($prefs), ['action' => 'show_preferences', 'reason' => 'empty_after_sanitize']);
        }

        // v1.60.0 — Detect emoji-only messages (no actual text content)
        $strippedEmoji = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}\s]/u', '', $body);
        if (mb_strlen($strippedEmoji) === 0 && mb_strlen($body) >= 1) {
            $prefs = PreferencesManager::getPreferences($userId);
            $lang  = $prefs['language'] ?? 'fr';
            $emojiMsg = match ($lang) {
                'en' => "😊 Nice emoji! But I need text to help you. Type *help preferences* to see commands.",
                'es' => "😊 ¡Bonito emoji! Pero necesito texto para ayudarte. Escribe *ayuda preferencias*.",
                'de' => "😊 Schönes Emoji! Aber ich brauche Text. Tippe *Hilfe Einstellungen*.",
                'it' => "😊 Bel emoji! Ma ho bisogno di testo. Scrivi *aiuto preferenze*.",
                'pt' => "😊 Bom emoji! Mas preciso de texto. Digite *ajuda preferências*.",
                default => "😊 Joli emoji ! Mais j'ai besoin de texte pour t'aider. Tape *aide preferences* pour voir les commandes.",
            };
            return AgentResult::reply($emojiMsg, ['action' => 'emoji_only']);
        }

        // Detect spam / gibberish (same char repeated 10+ times, or low entropy random chars)
        $isRepeatedChars = preg_match('/(.)\1{9,}/u', $body);
        $isLowEntropy    = false;
        if (!$isRepeatedChars && mb_strlen($body) >= 8) {
            // Use mb_str_split for proper multibyte character counting (handles CJK, Arabic, etc.)
            $mbChars = mb_str_split(mb_strtolower($body));
            $unique  = count(array_unique($mbChars));
            $ratio   = $unique / mb_strlen($body);
            // Random key-mashing: very high unique-char ratio with no spaces/words
            $isLowEntropy = $ratio > 0.85 && mb_strlen($body) >= 12 && !preg_match('/\s/u', $body);
        }
        if ($isRepeatedChars || $isLowEntropy) {
            $this->log($context, 'Spam detected — repeated characters', [], 'info');
            $prefs = PreferencesManager::getPreferences($userId);
            $lang  = $prefs['language'] ?? 'fr';
            $spamMsg = match ($lang) {
                'en' => "🤔 I couldn't understand your message. Type *help preferences* to see available commands.",
                'es' => "🤔 No pude entender tu mensaje. Escribe *ayuda preferencias* para ver los comandos.",
                'de' => "🤔 Ich konnte deine Nachricht nicht verstehen. Tippe *Hilfe Einstellungen* für die Befehle.",
                'it' => "🤔 Non ho capito il tuo messaggio. Scrivi *aiuto preferenze* per i comandi.",
                'pt' => "🤔 Não entendi sua mensagem. Digite *ajuda preferências* para ver os comandos.",
                'ar' => "🤔 لم أتمكن من فهم رسالتك. اكتب *مساعدة التفضيلات* لرؤية الأوامر.",
                'zh' => "🤔 无法理解您的消息。输入 *帮助 偏好设置* 查看可用命令。",
                'ja' => "🤔 メッセージを理解できませんでした。*help preferences* と入力してコマンドを確認してください。",
                'ko' => "🤔 메시지를 이해하지 못했습니다. *help preferences* 를 입력하여 명령어를 확인하세요.",
                'ru' => "🤔 Не удалось понять ваше сообщение. Введите *help preferences* для списка команд.",
                'nl' => "🤔 Ik kon je bericht niet begrijpen. Typ *help preferences* voor beschikbare commando's.",
                default => "🤔 Je n'ai pas compris ton message. Tape *aide preferences* pour voir les commandes.",
            };
            return AgentResult::reply($spamMsg, ['action' => 'spam_detected']);
        }

        $prefs = PreferencesManager::getPreferences($userId);

        // Pre-detect export blocks pasted by the user (bypass LLM for reliability)
        if ($this->looksLikeExportBlock($body)) {
            return $this->handleImport($context, $userId, ['data' => $body]);
        }

        // Fast-path: common commands that don't need an LLM call
        $bodyLower = mb_strtolower($body);
        $fastAction = $this->detectFastPath($bodyLower);
        if ($fastAction !== null) {
            $this->log($context, "Fast-path detected: {$fastAction['action']}", ['action' => $fastAction['action']]);
            return $this->dispatchParsedAction($context, $userId, $fastAction, $prefs);
        }

        // v1.52.0 — Cache system prompt per user preferences hash (avoids expensive rebuilds)
        $prefsCacheKey = 'user_prefs:sysprompt:' . md5(json_encode($prefs));
        $systemPrompt  = Cache::remember($prefsCacheKey, now()->addMinutes(5), fn () => $this->buildSystemPrompt($prefs));
        $primaryModel = $this->resolveModel($context);
        $response     = $this->claude->chat(
            "Message utilisateur: \"{$body}\"",
            $primaryModel,
            $systemPrompt
        );

        // v1.58.0 — Model fallback: retry with fast model if primary fails
        if ($response === null && $primaryModel !== \App\Services\ModelResolver::fast()) {
            Log::info('UserPreferencesAgent: primary model failed, trying fast model fallback', [
                'primary_model' => $primaryModel,
                'body'          => mb_substr($body, 0, 100),
            ]);
            $response = $this->claude->chat(
                "Message utilisateur: \"{$body}\"",
                \App\Services\ModelResolver::fast(),
                $systemPrompt
            );
        }

        if ($response === null) {
            Log::warning('UserPreferencesAgent: LLM returned null response (all models)', [
                'body' => mb_substr($body, 0, 200),
            ]);
            $lang = $prefs['language'] ?? 'fr';
            $nullMsg = match ($lang) {
                'en' => "⚠️ I couldn't analyze your request. Try again or type *help preferences* to see commands.\n\n_Examples: my profile, what time, timezone Europe/Paris_",
                'es' => "⚠️ No pude analizar tu solicitud. Inténtalo de nuevo o escribe *ayuda preferencias*.\n\n_Ejemplos: mi perfil, qué hora, timezone Europe/Paris_",
                'de' => "⚠️ Ich konnte deine Anfrage nicht verarbeiten. Versuche es erneut oder tippe *Hilfe Einstellungen*.\n\n_Beispiele: mein Profil, wie spät, timezone Europe/Paris_",
                'it' => "⚠️ Non ho potuto analizzare la tua richiesta. Riprova o scrivi *aiuto preferenze*.\n\n_Esempi: il mio profilo, che ora è, timezone Europe/Paris_",
                'pt' => "⚠️ Não consegui analisar seu pedido. Tente novamente ou digite *ajuda preferências*.\n\n_Exemplos: meu perfil, que horas são, timezone Europe/Paris_",
                'ar' => "⚠️ لم أتمكن من تحليل طلبك. حاول مرة أخرى أو اكتب *مساعدة التفضيلات*.\n\n_أمثلة: ملفي الشخصي، الساعة الآن، timezone Europe/Paris_",
                'zh' => "⚠️ 无法分析您的请求。请重试或输入 *帮助 偏好设置*。\n\n_示例：我的资料、现在几点、timezone Europe/Paris_",
                'ja' => "⚠️ リクエストを分析できませんでした。再試行するか、*help preferences* と入力してください。\n\n_例：my profile, what time, timezone Europe/Paris_",
                'ko' => "⚠️ 요청을 분석할 수 없습니다. 다시 시도하거나 *help preferences* 를 입력하세요.\n\n_예: my profile, what time, timezone Europe/Paris_",
                'ru' => "⚠️ Не удалось обработать запрос. Попробуйте снова или введите *help preferences*.\n\n_Примеры: my profile, what time, timezone Europe/Paris_",
                'nl' => "⚠️ Kon je verzoek niet analyseren. Probeer opnieuw of typ *help preferences*.\n\n_Voorbeelden: my profile, what time, timezone Europe/Paris_",
                default => "⚠️ Je n'ai pas pu analyser ta demande. Réessaie ou tape *aide preferences* pour voir les commandes.\n\n_Exemples : mon profil, quelle heure, timezone Europe/Paris_",
            };
            return AgentResult::reply($nullMsg, ['action' => 'error', 'error_type' => 'llm_null_response']);
        }

        $parsed = $this->parseJson($response);

        // Retry once on parse failure with a stricter prompt
        if (!$parsed || empty($parsed['action'])) {
            Log::info('UserPreferencesAgent: first parse failed, retrying with stricter prompt', [
                'body'     => mb_substr($body, 0, 200),
                'response' => mb_substr($response ?? '', 0, 300),
            ]);

            $retryHint = '';
            if ($response !== null) {
                $snippet = mb_substr($response, 0, 100);
                $retryHint = "\n\nTa réponse précédente était invalide: \"{$snippet}...\"\nCorrige et renvoie UNIQUEMENT le JSON.";
            }

            $retryResponse = $this->claude->chat(
                "IMPORTANT: Réponds UNIQUEMENT avec un objet JSON valide, sans texte, sans backticks, sans markdown.{$retryHint}\n\nMessage utilisateur: \"{$body}\"",
                $this->resolveModel($context),
                $systemPrompt
            );

            $parsed = $this->parseJson($retryResponse);

            if (!$parsed || empty($parsed['action'])) {
                Log::warning('UserPreferencesAgent: failed to parse LLM response after retry', [
                    'body'     => mb_substr($body, 0, 200),
                    'response' => mb_substr($response ?? '', 0, 300),
                    'retry'    => mb_substr($retryResponse ?? '', 0, 300),
                ]);
                $lang = $prefs['language'] ?? 'fr';
                $parseMsg = match ($lang) {
                    'en' => "⚠️ I didn't understand your request. Try rephrasing.\n\n_Examples: my profile, what time in Tokyo, timezone Europe/Paris_\n_Type *help preferences* for the full list._",
                    'es' => "⚠️ No entendí tu solicitud. Intenta reformular.\n\n_Ejemplos: mi perfil, qué hora en Tokyo, timezone Europe/Paris_\n_Escribe *ayuda preferencias* para la lista completa._",
                    'de' => "⚠️ Ich habe deine Anfrage nicht verstanden. Versuche es umzuformulieren.\n\n_Beispiele: mein Profil, wie spät in Tokyo, timezone Europe/Paris_\n_Tippe *Hilfe Einstellungen* für die vollständige Liste._",
                    'it' => "⚠️ Non ho capito la tua richiesta. Prova a riformulare.\n\n_Esempi: il mio profilo, che ora a Tokyo, timezone Europe/Paris_\n_Scrivi *aiuto preferenze* per la lista completa._",
                    'pt' => "⚠️ Não entendi seu pedido. Tente reformular.\n\n_Exemplos: meu perfil, que horas em Tokyo, timezone Europe/Paris_\n_Digite *ajuda preferências* para a lista completa._",
                    default => "⚠️ Je n'ai pas compris ta demande. Essaie de la reformuler.\n\n_Exemples : mon profil, quelle heure à Tokyo, timezone Europe/Paris_\n_Tape *aide preferences* pour la liste complète._",
                };
                return AgentResult::reply($parseMsg, ['action' => 'error', 'error_type' => 'parse_failure']);
            }
        }

        $this->log($context, "Action dispatched: {$parsed['action']}", [
            'action' => $parsed['action'],
            'keys'   => array_keys($parsed),
        ]);

        return $this->dispatchParsedAction($context, $userId, $parsed, $prefs);
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
- theme: auto, light, dark
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

Décoder une abréviation de fuseau horaire (PST, CET, EST, etc.) ou lister les abréviations courantes:
{"action": "timezone_abbrev", "abbreviation": "PST"}
{"action": "timezone_abbrev", "abbreviation": "CET"}
{"action": "timezone_abbrev", "abbreviation": ""}

- "c'est quoi PST" / "que veut dire CET" / "signification EST" → {"action": "timezone_abbrev", "abbreviation": "PST"}
- "abréviation PST" / "timezone abbreviation JST" / "decode GMT" → {"action": "timezone_abbrev", "abbreviation": "GMT"}
- "abréviations fuseaux" / "liste abréviations" / "timezone abbreviations" → {"action": "timezone_abbrev", "abbreviation": ""}
- IMPORTANT: abbreviation doit être en majuscules (PST, CET, etc.). Si l'utilisateur demande juste la liste, laisser vide.

Calculer un planning Pomodoro (sessions de travail avec pauses):
{"action": "pomodoro"}
{"action": "pomodoro", "sessions": 6}
{"action": "pomodoro", "sessions": 4, "work_minutes": 50, "break_minutes": 10}
{"action": "pomodoro", "sessions": 8, "work_minutes": 25, "break_minutes": 5, "long_break_minutes": 20}

- "pomodoro" / "lance un pomodoro" / "planning pomodoro" → {"action": "pomodoro"}
- "pomodoro 6 sessions" / "6 pomodoros" → {"action": "pomodoro", "sessions": 6}
- "pomodoro 50 minutes" / "pomodoro 50min travail 10min pause" → {"action": "pomodoro", "work_minutes": 50, "break_minutes": 10}
- "technique pomodoro" / "minuteur pomodoro" / "focus timer" → {"action": "pomodoro"}
- IMPORTANT: sessions max 12, work_minutes entre 5-120 (défaut 25), break_minutes entre 1-30 (défaut 5), long_break_minutes entre 5-60 (défaut 15). Longue pause toutes les 4 sessions.

Calculer la durée écoulée entre deux heures (même jour) ou deux dates+heures:
{"action": "elapsed_time", "from_time": "09:00", "to_time": "17:30"}
{"action": "elapsed_time", "from_time": "08:30", "to_time": "12:45"}
{"action": "elapsed_time", "from_datetime": "2026-03-25 09:00", "to_datetime": "2026-03-27 17:30"}

- "durée entre 9h et 17h30" / "combien de temps entre 9h et 17h30" → {"action": "elapsed_time", "from_time": "09:00", "to_time": "17:30"}
- "temps de travail de 8h30 à 12h45" / "heures entre 8h30 et 12h45" → {"action": "elapsed_time", "from_time": "08:30", "to_time": "12:45"}
- "elapsed time 14h to 22h15" / "durée de 14h à 22h15" → {"action": "elapsed_time", "from_time": "14:00", "to_time": "22:15"}
- "durée entre 2026-03-25 09:00 et 2026-03-27 17:30" → {"action": "elapsed_time", "from_datetime": "2026-03-25 09:00", "to_datetime": "2026-03-27 17:30"}
- IMPORTANT: from_time/to_time pour le même jour, from_datetime/to_datetime pour des dates différentes. Heures au format HH:MM, dates au format AAAA-MM-JJ HH:MM.

Trouver les créneaux de travail profond (focus/deep work) entre le fuseau de l'utilisateur et une ville cible (heures où les deux sont hors bureau 9h-18h):
{"action": "focus_window", "target": "Tokyo"}
{"action": "focus_window", "target": "New York"}

- "focus window Tokyo" / "fenêtre focus avec Tokyo" / "deep work avec New York" → {"action": "focus_window", "target": "Tokyo"}
- "heures calmes entre moi et Tokyo" / "quiet hours avec Londres" → {"action": "focus_window", "target": "London"}
- "quand travailler tranquille avec Dubai" / "créneau focus Singapore" → {"action": "focus_window", "target": "Singapore"}
- "plage de concentration avec Sydney" / "focus time avec Tokyo" → {"action": "focus_window", "target": "Tokyo"}
- IMPORTANT: target est une ville ou un fuseau IANA. Retourne les plages horaires où les deux zones sont hors heures ouvrables (avant 9h ou après 18h).

Calculer l'heure qu'il sera après une durée donnée (ajouter du temps à maintenant ou à une heure spécifique):
{"action": "time_add", "duration": "2h30"}
{"action": "time_add", "duration": "45min"}
{"action": "time_add", "duration": "1h30", "from_time": "14:00"}
{"action": "time_add", "duration": "3h", "target": "Tokyo"}

- "dans 2h30 il sera quelle heure" / "quelle heure dans 2h" / "heure + 2h30" → {"action": "time_add", "duration": "2h30"}
- "dans 45 minutes" / "dans 45min quelle heure" → {"action": "time_add", "duration": "45min"}
- "timer 1h30" / "minuteur 90 minutes" → {"action": "time_add", "duration": "1h30"}
- "si je pars dans 3h quelle heure à Tokyo" / "dans 2h il sera quelle heure à New York" → {"action": "time_add", "duration": "2h", "target": "Tokyo"}
- "14h + 3h45" / "à partir de 14h dans 3h" → {"action": "time_add", "duration": "3h45", "from_time": "14:00"}
- "dans combien de temps je finis si je travaille 4h" → {"action": "time_add", "duration": "4h"}
- IMPORTANT: duration accepte: 2h, 2h30, 45min, 90min, 1:30, 2.5h. from_time optionnel (HH:MM). target optionnel (ville pour voir l'heure d'arrivée ailleurs).

Suggérer les meilleurs créneaux de réunion entre plusieurs villes avec un score de compatibilité:
{"action": "meeting_suggest", "cities": ["Tokyo", "Paris", "New York"]}
{"action": "meeting_suggest", "cities": ["London", "Dubai", "Sydney"], "duration_hours": 2}

- "meilleur horaire réunion Paris Tokyo New York" / "suggest meeting Paris Tokyo NYC" → {"action": "meeting_suggest", "cities": ["Paris", "Tokyo", "New York"]}
- "quel est le meilleur moment pour une réunion avec Tokyo et New York" → {"action": "meeting_suggest", "cities": ["Tokyo", "New York"]}
- "créneau idéal London Dubai Sydney" / "top horaires London Dubai Sydney" → {"action": "meeting_suggest", "cities": ["London", "Dubai", "Sydney"]}
- "meilleur créneau pour 2h de réunion avec Tokyo" → {"action": "meeting_suggest", "cities": ["Tokyo"], "duration_hours": 2}
- IMPORTANT: cities est une liste de 1+ villes. duration_hours optionnel (défaut 1). Retourne les 3 meilleurs créneaux avec un score de compatibilité.

Afficher un résumé complet du fuseau horaire de l'utilisateur ou d'une ville (offset, DST, villes proches, heure locale):
{"action": "timezone_summary"}
{"action": "timezone_summary", "target": "Tokyo"}

- "résumé de mon fuseau" / "infos fuseau" / "timezone summary" / "tz info" → {"action": "timezone_summary"}
- "infos fuseau Tokyo" / "résumé fuseau New York" / "timezone info London" → {"action": "timezone_summary", "target": "Tokyo"}
- "mon fuseau en détail" / "détails de mon fuseau horaire" / "profil fuseau" → {"action": "timezone_summary"}
- IMPORTANT: Si target est absent, utilise le fuseau de l'utilisateur.

Générer une liste de dates entre deux bornes, avec un filtre optionnel par jour de la semaine:
{"action": "date_range", "from_date": "2026-04-01", "to_date": "2026-06-30", "day_filter": "monday"}
{"action": "date_range", "from_date": "2026-04-01", "to_date": "2026-04-30"}

- "tous les lundis entre le 1er avril et le 30 juin 2026" → {"action": "date_range", "from_date": "2026-04-01", "to_date": "2026-06-30", "day_filter": "monday"}
- "dates entre 2026-04-01 et 2026-04-30" / "liste de dates en avril" → {"action": "date_range", "from_date": "2026-04-01", "to_date": "2026-04-30"}
- "tous les vendredis de mai 2026" → {"action": "date_range", "from_date": "2026-05-01", "to_date": "2026-05-31", "day_filter": "friday"}
- "every tuesday in june 2026" → {"action": "date_range", "from_date": "2026-06-01", "to_date": "2026-06-30", "day_filter": "tuesday"}
- IMPORTANT: from_date et to_date au format AAAA-MM-JJ. day_filter optionnel: monday, tuesday, wednesday, thursday, friday, saturday, sunday. Sans day_filter, liste tous les jours.

Changer le thème visuel:
{"action": "set", "key": "theme", "value": "dark"}
{"action": "set", "key": "theme", "value": "light"}
{"action": "set", "key": "theme", "value": "auto"}

- "mode sombre" / "dark mode" / "thème sombre" → {"action": "set", "key": "theme", "value": "dark"}
- "mode clair" / "light mode" / "thème clair" → {"action": "set", "key": "theme", "value": "light"}
- "thème auto" / "mode automatique" → {"action": "set", "key": "theme", "value": "auto"}

Afficher la complétude du profil (barre de progression + suggestions):
{"action": "profile_completeness"}

- "complétude de mon profil" / "mon profil est complet" / "profile completeness" → {"action": "profile_completeness"}
- "est-ce que mon profil est complet" / "taux de remplissage" / "score profil" → {"action": "profile_completeness"}
- "que manque-t-il à mon profil" / "profil incomplet" → {"action": "profile_completeness"}

Snapshot rapide des préférences (résumé en une ligne):
{"action": "preference_snapshot"}

- "snapshot" / "résumé rapide" / "quick summary" / "one liner" → {"action": "preference_snapshot"}
- "snapshot preferences" / "résumé en une ligne" → {"action": "preference_snapshot"}

Gérer les villes favorites (ajouter, supprimer, lister, ou afficher l'horloge des favoris):
{"action": "favorite_cities", "sub_action": "list"}
{"action": "favorite_cities", "sub_action": "add", "cities": ["Tokyo", "Dubai"]}
{"action": "favorite_cities", "sub_action": "remove", "cities": ["Dubai"]}
{"action": "favorite_cities", "sub_action": "clock"}

- "mes villes favorites" / "liste villes" / "favorite cities" → {"action": "favorite_cities", "sub_action": "list"}
- "ajouter Tokyo aux favoris" / "add city Tokyo" / "ajouter ville Dubai" → {"action": "favorite_cities", "sub_action": "add", "cities": ["Tokyo"]}
- "ajouter Tokyo et Dubai aux favoris" → {"action": "favorite_cities", "sub_action": "add", "cities": ["Tokyo", "Dubai"]}
- "supprimer Dubai des favoris" / "remove city Dubai" → {"action": "favorite_cities", "sub_action": "remove", "cities": ["Dubai"]}
- "horloge favoris" / "heure villes favorites" / "fav clock" → {"action": "favorite_cities", "sub_action": "clock"}
- IMPORTANT: sub_action est obligatoire (list, add, remove, clock). cities est un tableau pour add/remove.

Calculer la différence horaire précise entre deux villes ou fuseaux:
{"action": "time_diff", "city_a": "Paris", "city_b": "Tokyo"}

- "différence horaire Paris Tokyo" / "time diff London New York" → {"action": "time_diff", "city_a": "Paris", "city_b": "Tokyo"}
- "combien d'heures entre Dubai et Sydney" / "décalage entre Tokyo et Paris" → {"action": "time_diff", "city_a": "Dubai", "city_b": "Sydney"}
- "écart horaire Londres New York" / "offset entre moi et Tokyo" → {"action": "time_diff", "city_a": "", "city_b": "Tokyo"}
- IMPORTANT: Si city_a est vide ou "moi"/"me", utilise le fuseau de l'utilisateur. city_b est obligatoire.

Appliquer un profil régional prédéfini (langue + fuseau + format de date + unités en un seul coup):
{"action": "locale_preset", "preset": "fr"}
{"action": "locale_preset", "preset": "en_us"}
{"action": "locale_preset", "preset": "list"}

- "profil français" / "profil france" / "locale française" → {"action": "locale_preset", "preset": "fr"}
- "US profile" / "profil américain" / "profil usa" → {"action": "locale_preset", "preset": "en_us"}
- "UK profile" / "profil anglais" / "british profile" → {"action": "locale_preset", "preset": "en_uk"}
- "profil allemand" / "german profile" / "deutsches profil" → {"action": "locale_preset", "preset": "de"}
- "profil espagnol" / "spanish profile" → {"action": "locale_preset", "preset": "es"}
- "profil italien" / "italian profile" → {"action": "locale_preset", "preset": "it"}
- "profil japonais" / "japanese profile" → {"action": "locale_preset", "preset": "ja"}
- "profil chinois" / "chinese profile" → {"action": "locale_preset", "preset": "zh"}
- "profil arabe" / "arabic profile" → {"action": "locale_preset", "preset": "ar"}
- "profil portugais" / "portuguese profile" → {"action": "locale_preset", "preset": "pt"}
- "profils régionaux" / "locale presets" / "profils disponibles" → {"action": "locale_preset", "preset": "list"}
- IMPORTANT: preset accepte un code (fr, en_us, en_uk, de, es, it, pt, ja, ar, zh) ou un nom en langue naturelle. "list" pour afficher tous les profils.

Afficher la progression de la journée de travail (9h-18h) avec statistiques:
{"action": "workday_progress"}

- "progression journée" / "ma journée" / "avancement journée" / "workday progress" → {"action": "workday_progress"}
- "où en est ma journée" / "combien de temps avant la fin" / "journée de travail" → {"action": "workday_progress"}
- "reste combien avant 18h" / "fin de journée" / "temps de travail restant" → {"action": "workday_progress"}
- "progress travail" / "bilan journée" / "my workday" → {"action": "workday_progress"}

Afficher le chevauchement visuel des heures de bureau entre l'utilisateur et une ville cible (timeline 24h colorée):
{"action": "timezone_overlap", "target": "Tokyo"}
{"action": "timezone_overlap", "target": "New York"}

- "overlap Tokyo" / "chevauchement horaire avec New York" / "overlap horaire London" → {"action": "timezone_overlap", "target": "Tokyo"}
- "heures communes avec Dubai" / "overlap avec Singapore" / "heures partagées Tokyo" → {"action": "timezone_overlap", "target": "Dubai"}
- "overlap bureau New York" / "quand on est tous au bureau avec Londres" → {"action": "timezone_overlap", "target": "New York"}
- IMPORTANT: target est une ville ou un fuseau IANA. Affiche une timeline visuelle avec les heures en commun.

Planifier l'adaptation du sommeil après un voyage entre deux fuseaux (jet lag recovery):
{"action": "sleep_schedule", "from": "Paris", "to": "Tokyo"}
{"action": "sleep_schedule", "from": "New York", "to": "London"}

- "sleep schedule Paris Tokyo" / "adaptation horaire Paris vers Tokyo" → {"action": "sleep_schedule", "from": "Paris", "to": "Tokyo"}
- "planning sommeil New York London" / "récupération jet lag Dubai Singapore" → {"action": "sleep_schedule", "from": "New York", "to": "London"}
- "quand dormir après un vol Paris Tokyo" / "adaptation décalage horaire" → {"action": "sleep_schedule", "from": "Paris", "to": "Tokyo"}
- IMPORTANT: from est la ville de départ, to est la ville d'arrivée. Les deux sont obligatoires.

Calculer l'heure d'arrivée d'un vol (avec changement de fuseau horaire):
{"action": "flight_time", "from": "Paris", "to": "Tokyo", "departure_time": "14:00", "duration": "12h"}
{"action": "flight_time", "from": "New York", "to": "London", "departure_time": "22:00", "duration": "7h30"}

- "vol Paris Tokyo départ 14h durée 12h" / "flight Paris to Tokyo at 2pm 12 hours" → {"action": "flight_time", "from": "Paris", "to": "Tokyo", "departure_time": "14:00", "duration": "12h"}
- "si je pars de New York à 22h pour Londres vol de 7h30" → {"action": "flight_time", "from": "New York", "to": "London", "departure_time": "22:00", "duration": "7h30"}
- "heure d'arrivée vol Paris Dubai départ 10h durée 6h" → {"action": "flight_time", "from": "Paris", "to": "Dubai", "departure_time": "10:00", "duration": "6h"}
- IMPORTANT: from et to sont les villes de départ/arrivée. departure_time est l'heure locale de départ (HH:MM). duration accepte: 7h, 7h30, 90min, 1:30. Tous les champs sont obligatoires.

Vérifier une échéance/deadline (jour ouvré, jours restants, rappels suggérés):
{"action": "deadline_check", "date": "2026-06-30", "label": "rapport trimestriel"}
{"action": "deadline_check", "date": "2026-04-15", "label": ""}

- "deadline 30 juin rapport trimestriel" / "deadline check 2026-06-30" → {"action": "deadline_check", "date": "2026-06-30", "label": "rapport trimestriel"}
- "est-ce que le 15 avril est un jour ouvré" / "vérifier échéance 2026-04-15" → {"action": "deadline_check", "date": "2026-04-15", "label": ""}
- "combien de jours avant la deadline du 2026-05-01 livraison" → {"action": "deadline_check", "date": "2026-05-01", "label": "livraison"}
- IMPORTANT: date au format AAAA-MM-JJ. label optionnel (nom de l'échéance).

Afficher la progression du mois en cours (jours passés/restants, jours ouvrés, barre de progression):
{"action": "month_progress"}

- "progression mois" / "month progress" / "avancement mois" / "stats mois" → {"action": "month_progress"}
- "jours ouvrés ce mois" / "bilan mois" / "jours restants ce mois" → {"action": "month_progress"}
- "progression mensuelle" / "mois en cours" → {"action": "month_progress"}

Afficher la progression du trimestre en cours (jours passés/restants, jours ouvrés, barre de progression):
{"action": "quarter_progress"}

- "progression trimestre" / "quarter progress" / "avancement trimestre" / "stats trimestre" → {"action": "quarter_progress"}
- "quel trimestre" / "où en est le trimestre" / "bilan trimestre" → {"action": "quarter_progress"}
- "jours restants ce trimestre" / "trimestre en cours" → {"action": "quarter_progress"}

Trouver la prochaine occurrence d'un jour de la semaine spécifique:
{"action": "next_weekday", "day": "friday"}
{"action": "next_weekday", "day": "monday", "count": 3}

- "prochain vendredi" / "next friday" / "quand est le prochain lundi" → {"action": "next_weekday", "day": "friday"}
- "prochains 3 lundis" / "next 3 mondays" → {"action": "next_weekday", "day": "monday", "count": 3}
- "prochain samedi" / "quand tombe le prochain dimanche" → {"action": "next_weekday", "day": "saturday"}
- IMPORTANT: day accepte les noms en anglais (monday, tuesday, wednesday, thursday, friday, saturday, sunday) ou en français (lundi, mardi, mercredi, jeudi, vendredi, samedi, dimanche). count optionnel (défaut 1, max 8).

Calculer l'heure optimale de coucher ou de réveil basée sur les cycles de sommeil (~90 min):
{"action": "alarm_time", "mode": "wakeup", "time": "7:00"}
{"action": "alarm_time", "mode": "bedtime", "time": "23:00"}
{"action": "alarm_time", "mode": "now"}
Mode "wakeup" + time = "je veux me réveiller à X, quand me coucher ?". Mode "bedtime" + time = "je me couche à X, quand me réveiller ?". Mode "now" = "je me couche maintenant, quand me réveiller ?".

- "je veux me réveiller à 7h, quand me coucher" / "alarm wakeup 7h" → {"action": "alarm_time", "mode": "wakeup", "time": "7:00"}
- "je me couche à 23h, quand me réveiller" / "coucher 23h cycles" → {"action": "alarm_time", "mode": "bedtime", "time": "23:00"}
- "si je dors maintenant" / "alarm now" / "cycles de sommeil maintenant" → {"action": "alarm_time", "mode": "now", "time": ""}
- "à quelle heure dormir pour se réveiller à 6h30" → {"action": "alarm_time", "mode": "wakeup", "time": "6:30"}
- "calculateur sommeil" / "sleep calculator" / "cycles sommeil" → {"action": "alarm_time", "mode": "now", "time": ""}
- IMPORTANT: mode accepte "wakeup", "bedtime" ou "now". time au format HH:MM ou expression libre (7h, 7h30, 11pm). Pour "now", time est vide.

Afficher la progression de la semaine en cours (jours passés/restants, jours ouvrés, barre de progression):
{"action": "week_progress"}

- "progression semaine" / "week progress" / "avancement semaine" / "stats semaine" → {"action": "week_progress"}
- "bilan de la semaine" / "semaine en cours" / "jours restants cette semaine" → {"action": "week_progress"}
- "progression hebdo" / "weekly progress" / "où en est la semaine" → {"action": "week_progress"}

Compte à rebours vers PLUSIEURS événements en même temps:
{"action": "batch_countdown", "events": [{"date": "2026-12-25", "label": "Noël"}, {"date": "2027-01-01", "label": "Nouvel An"}]}

- "countdown Noël et Nouvel An" / "comptes à rebours multiples" → {"action": "batch_countdown", "events": [{"date": "2026-12-25", "label": "Noël"}, {"date": "2027-01-01", "label": "Nouvel An"}]}
- "mes countdowns : vacances 2026-07-01, rentrée 2026-09-01" → {"action": "batch_countdown", "events": [{"date": "2026-07-01", "label": "vacances"}, {"date": "2026-09-01", "label": "rentrée"}]}
- "batch countdown" → {"action": "batch_countdown", "events": []}
- IMPORTANT: events est un tableau de {date (AAAA-MM-JJ), label (optionnel)}. Maximum 10 événements. Si la liste est vide ou manquante, l'agent renvoie un message d'aide.

Trouver le Nième jour de la semaine dans un mois donné (1er lundi, 3ème vendredi, dernier mardi, etc.):
{"action": "nth_weekday", "day": "friday", "nth": 3, "month": 6, "year": 2026}
{"action": "nth_weekday", "day": "monday", "nth": 1, "month": 4, "year": 2026}
{"action": "nth_weekday", "day": "friday", "nth": -1, "month": 5, "year": 2026}

- "3ème vendredi de juin 2026" / "third friday of june 2026" → {"action": "nth_weekday", "day": "friday", "nth": 3, "month": 6, "year": 2026}
- "1er lundi d'avril" / "first monday of april" → {"action": "nth_weekday", "day": "monday", "nth": 1, "month": 4, "year": 2026}
- "dernier vendredi de mai" / "last friday of may" → {"action": "nth_weekday", "day": "friday", "nth": -1, "month": 5, "year": 2026}
- "2ème mardi de mars 2026" / "second tuesday of march" → {"action": "nth_weekday", "day": "tuesday", "nth": 2, "month": 3, "year": 2026}
- "4ème jeudi de novembre" / "fourth thursday of november" (Thanksgiving US) → {"action": "nth_weekday", "day": "thursday", "nth": 4, "month": 11, "year": 2026}
- "dernier lundi de mai" / "last monday of may" (Memorial Day US) → {"action": "nth_weekday", "day": "monday", "nth": -1, "month": 5, "year": 2026}
- IMPORTANT: day en anglais (monday-sunday). nth: 1=premier, 2=deuxième, 3=troisième, 4=quatrième, 5=cinquième, -1=dernier. month: numéro 1-12. year: optionnel (défaut année courante). Si le mois est mentionné en toutes lettres, convertir en numéro. Si non précisé, utiliser le mois courant ou suivant.

Briefing quotidien complet (date, progression journée/semaine/année, trimestre, DST):
{"action": "daily_summary"}

- "briefing du jour" / "daily summary" / "résumé du jour" / "mon briefing" → {"action": "daily_summary"}
- "bilan du jour" / "récap du jour" / "daily briefing" / "today summary" → {"action": "daily_summary"}

Roster des fuseaux horaires — statut en temps réel de plusieurs villes (au bureau, dort, week-end):
{"action": "timezone_roster"}
{"action": "timezone_roster", "cities": ["Tokyo", "London", "New York"]}

- "roster" / "timezone roster" / "roster fuseaux" / "team roster" → {"action": "timezone_roster"}
- "qui travaille" / "qui dort" / "statut équipe" / "team status" → {"action": "timezone_roster"}
- "roster Tokyo London NYC" / "statut villes Paris Dubai Sydney" → {"action": "timezone_roster", "cities": ["Tokyo", "London", "New York"]}
- Si des villes sont mentionnées, les inclure dans le tableau cities. Sinon, utiliser les villes favorites ou les villes par défaut.

Afficher un tableau de bord de productivité (combine progression journée, semaine, mois, trimestre et année):
{"action": "productivity_score"}

- "productivity score" / "score productivité" / "mes stats" → {"action": "productivity_score"}
- "tableau de bord" / "dashboard" / "bilan productivité" / "mes métriques" → {"action": "productivity_score"}
- "ma performance" / "performance du jour" / "my productivity" → {"action": "productivity_score"}

Afficher l'historique des transitions DST (changements d'heure) pour un fuseau sur l'année en cours:
{"action": "timezone_history"}
{"action": "timezone_history", "target": "Tokyo"}

- "historique fuseau" / "timezone history" / "transitions horaires" → {"action": "timezone_history"}
- "historique changement heure Paris" / "dst history New York" → {"action": "timezone_history", "target": "Paris"}
- "changements horaires cette année" / "historique DST" → {"action": "timezone_history"}
- IMPORTANT: Si target est absent, utilise le fuseau de l'utilisateur.

Convertir des unités (température, distance, poids, volume) entre métrique et impérial:
{"action": "unit_convert", "value": 100, "from_unit": "km", "to_unit": "miles"}
{"action": "unit_convert", "value": 37.5, "from_unit": "celsius", "to_unit": "fahrenheit"}
{"action": "unit_convert", "value": 70, "from_unit": "kg", "to_unit": "lbs"}
{"action": "unit_convert", "value": 5, "from_unit": "liters", "to_unit": "gallons"}
Unités supportées:
- Température: celsius (°C), fahrenheit (°F), kelvin (K)
- Distance: km, miles, m, ft, cm, inches
- Poids: kg, lbs, g, oz
- Volume: liters, gallons, ml, fl_oz

- "convertir 100 km en miles" / "100 km in miles" → {"action": "unit_convert", "value": 100, "from_unit": "km", "to_unit": "miles"}
- "37.5 celsius en fahrenheit" / "combien en fahrenheit pour 37.5°C" → {"action": "unit_convert", "value": 37.5, "from_unit": "celsius", "to_unit": "fahrenheit"}
- "70 kg en livres" / "70 kg to lbs" → {"action": "unit_convert", "value": 70, "from_unit": "kg", "to_unit": "lbs"}
- "5 litres en gallons" / "5 liters to gallons" → {"action": "unit_convert", "value": 5, "from_unit": "liters", "to_unit": "gallons"}
- "conversion 180 cm en inches" / "combien de pieds pour 180 cm" → {"action": "unit_convert", "value": 180, "from_unit": "cm", "to_unit": "inches"}
- IMPORTANT: value est un nombre. from_unit et to_unit doivent être parmi les unités supportées. Normaliser les alias (livres→lbs, kilomètres→km, etc.).

Afficher un planning hebdomadaire complet (calendrier + progression + métriques en une seule vue):
{"action": "week_planner"}
{"action": "week_planner", "week_offset": 1}

- "planning hebdomadaire" / "ma semaine complète" / "week planner" → {"action": "week_planner"}
- "mon planning" / "vue semaine" / "weekly overview" / "planner semaine" → {"action": "week_planner"}
- "planning semaine prochaine" / "planner next week" → {"action": "week_planner", "week_offset": 1}
- "weekly planner" / "plan de la semaine" / "planification semaine" → {"action": "week_planner"}

Afficher les informations sur la saison actuelle (saison, équinoxes, solstices, progression):
{"action": "season_info"}
{"action": "season_info", "hemisphere": "south"}

- "quelle saison" / "saison actuelle" / "current season" / "info saison" → {"action": "season_info"}
- "quand commence le printemps" / "prochain solstice" / "next equinox" → {"action": "season_info"}
- "saison hémisphère sud" / "southern hemisphere season" → {"action": "season_info", "hemisphere": "south"}
- "changement de saison" / "season change" / "début été" → {"action": "season_info"}
- Le champ "hemisphere" est optionnel (auto-détecté depuis le fuseau). Valeurs: "north"/"south"/"nord"/"sud".

Calculer l'heure de fin à partir d'une heure de début et d'une durée (minuteur rapide):
{"action": "quick_timer", "start_time": "14h30", "duration": "2h30"}
{"action": "quick_timer", "start_time": "now", "duration": "45m"}
{"action": "quick_timer", "start_time": "9:00", "duration": "8h"}

- "si je commence à 14h pendant 2h30" / "14h + 2h30 = ?" → {"action": "quick_timer", "start_time": "14:00", "duration": "2h30"}
- "minuteur 45 minutes" / "timer 45m" → {"action": "quick_timer", "start_time": "now", "duration": "45m"}
- "quand je finis si je commence à 9h pour 8h de travail" → {"action": "quick_timer", "start_time": "09:00", "duration": "8h"}
- "combien de temps si je commence maintenant pendant 3h" → {"action": "quick_timer", "start_time": "now", "duration": "3h"}
- IMPORTANT: start_time peut être "now"/"maintenant" ou une heure (14h30, 2pm, 09:00). duration doit être au format Xh, XhYY, Xm, X:YY.

Afficher le statut des principaux marchés financiers mondiaux (NYSE, LSE, TSE, HKEX, Euronext, ASX):
{"action": "market_hours"}
{"action": "market_hours", "markets": ["NYSE", "LSE", "TSE"]}

- "marchés financiers" / "bourse ouverte" / "financial markets" / "market hours" → {"action": "market_hours"}
- "est-ce que wall street est ouvert" / "NYSE ouvert" / "is the market open" → {"action": "market_hours"}
- "horaires bourse Tokyo et New York" / "trading hours" → {"action": "market_hours", "markets": ["TSE", "NYSE"]}
- "quelles bourses sont ouvertes" / "marchés ouverts maintenant" → {"action": "market_hours"}
- IMPORTANT: markets est optionnel. Par défaut, affiche les 6 principaux marchés. Valeurs possibles: NYSE, NASDAQ, LSE, TSE, HKEX, Euronext, ASX, SSE.

Afficher un résumé compact de la semaine en cours (jour, progression, jours ouvrés restants, countdown week-end):
{"action": "week_summary"}

- "résumé de ma semaine" / "bilan semaine" / "week summary" / "weekly recap" → {"action": "week_summary"}
- "où en est ma semaine" / "recap semaine" / "how is my week" → {"action": "week_summary"}
- "récap hebdo" / "bilan de la semaine" / "recap hebdomadaire" → {"action": "week_summary"}
- IMPORTANT: week_summary combine jour de la semaine, progression, jours ouvrés restants, countdown week-end et heure actuelle.

Calculer la différence entre deux dates (nombre de jours, semaines, mois):
{"action": "date_diff", "date1": "2026-01-15", "date2": "2026-03-25"}
{"action": "date_diff", "date1": "2025-12-25", "date2": "2026-03-25"}

- "combien de jours entre le 1er janvier et le 15 mars" → {"action": "date_diff", "date1": "2026-01-01", "date2": "2026-03-15"}
- "différence entre 25 décembre 2025 et aujourd'hui" → {"action": "date_diff", "date1": "2025-12-25", "date2": "2026-03-25"}
- "days between march 1 and june 30" → {"action": "date_diff", "date1": "2026-03-01", "date2": "2026-06-30"}
- "écart entre deux dates" / "date interval" / "intervalle" → {"action": "date_diff", "date1": "...", "date2": "..."}
- IMPORTANT: date1 et date2 en format YYYY-MM-DD. Si une date est "aujourd'hui"/"today", utilise la date du jour.

Afficher la distance relative d'une date par rapport à aujourd'hui (il y a X jours / dans X jours):
{"action": "relative_date", "date": "2026-03-01"}
{"action": "relative_date", "date": "2026-04-15"}

- "il y a combien de jours le 1er mars" → {"action": "relative_date", "date": "2026-03-01"}
- "dans combien de jours le 15 avril" → {"action": "relative_date", "date": "2026-04-15"}
- "how long ago was january 1st" → {"action": "relative_date", "date": "2026-01-01"}
- "how many days until december 25" → {"action": "relative_date", "date": "2026-12-25"}
- "jours depuis le 1er janvier" / "days since march 1" → {"action": "relative_date", "date": "..."}
- IMPORTANT: date en format YYYY-MM-DD. Convertis les dates relatives ("hier"="yesterday", etc.) en dates absolues.

Fiche pratique d'un fuseau / ville (quick reference combinant heure, diff, DST, overlap, meilleur créneau en une fiche):
{"action": "timezone_cheatsheet", "target": "Tokyo"}
{"action": "timezone_cheatsheet", "target": "New York"}

- "cheatsheet Tokyo" / "fiche fuseau New York" / "timezone cheatsheet London" → {"action": "timezone_cheatsheet", "target": "Tokyo"}
- "fiche pratique Dubai" / "quick reference Singapore" / "fiche horaire Sydney" → {"action": "timezone_cheatsheet", "target": "Dubai"}
- "résumé horaire Tokyo" / "aide-mémoire fuseau New York" → {"action": "timezone_cheatsheet", "target": "Tokyo"}
- IMPORTANT: target est une ville ou un fuseau IANA. Retourne une fiche combinée: heure actuelle, décalage, DST, heures ouvrables, overlap avec l'utilisateur, meilleur créneau de réunion.

Afficher la progression entre deux dates (suivi de projet/objectif avec barre de progression visuelle):
{"action": "project_progress", "start_date": "2026-01-15", "end_date": "2026-06-30", "label": "Projet Alpha"}
{"action": "project_progress", "start_date": "2026-01-01", "end_date": "2026-12-31", "label": ""}

- "progression projet du 2026-01-15 au 2026-06-30 Projet Alpha" → {"action": "project_progress", "start_date": "2026-01-15", "end_date": "2026-06-30", "label": "Projet Alpha"}
- "où en suis-je entre le 1er janvier et le 30 juin" → {"action": "project_progress", "start_date": "2026-01-01", "end_date": "2026-06-30", "label": ""}
- "project progress 2026-03-01 to 2026-09-30 Sprint 3" → {"action": "project_progress", "start_date": "2026-03-01", "end_date": "2026-09-30", "label": "Sprint 3"}
- "suivi entre 2026-02-01 et 2026-05-31 Formation" → {"action": "project_progress", "start_date": "2026-02-01", "end_date": "2026-05-31", "label": "Formation"}
- IMPORTANT: start_date et end_date au format AAAA-MM-JJ. label optionnel. Affiche une barre de progression, jours écoulés/restants, date médiane, et estimation de fin.

Capsule temporelle — montrer quelle date/heure c'était il y a N jours/semaines/mois/ans à ce moment précis:
{"action": "time_capsule", "amount": 1, "unit": "year"}
{"action": "time_capsule", "amount": 5, "unit": "years"}
{"action": "time_capsule", "amount": 6, "unit": "months"}
{"action": "time_capsule", "amount": 100, "unit": "days"}

- "il y a 1 an" / "capsule 1 an" / "time capsule 1 year" → {"action": "time_capsule", "amount": 1, "unit": "year"}
- "il y a 5 ans" / "capsule 5 ans" / "5 years ago" → {"action": "time_capsule", "amount": 5, "unit": "years"}
- "il y a 6 mois à cette heure" / "capsule 6 mois" → {"action": "time_capsule", "amount": 6, "unit": "months"}
- "il y a 100 jours" / "capsule 100 jours" / "100 days ago" → {"action": "time_capsule", "amount": 100, "unit": "days"}
- "il y a 2 semaines" / "capsule 2 semaines" → {"action": "time_capsule", "amount": 2, "unit": "weeks"}
- IMPORTANT: amount est un entier (1-100). unit accepte: day/days/jour/jours, week/weeks/semaine/semaines, month/months/mois, year/years/an/ans/année/années.

Salut intelligent — suggérer le bon salut pour une ville en fonction de l'heure locale:
{"action": "smart_greeting", "target": "Tokyo"}
{"action": "smart_greeting", "target": "New York"}
{"action": "smart_greeting", "target": ""}

- "salut Tokyo" / "greeting Tokyo" / "comment saluer quelqu'un à Tokyo" → {"action": "smart_greeting", "target": "Tokyo"}
- "bonjour ou bonsoir New York" / "quoi dire à New York" → {"action": "smart_greeting", "target": "New York"}
- "quel salut pour Dubai" / "greeting Dubai" / "salut intelligent London" → {"action": "smart_greeting", "target": "Dubai"}
- "comment saluer" / "quel salut" / "smart greeting" → {"action": "smart_greeting", "target": ""}
- IMPORTANT: target est une ville ou un fuseau IANA. Retourne le salut adapté (bonjour/bonsoir/bonne nuit), l'heure locale, et un conseil de communication.

Afficher le niveau d'énergie estimé selon le rythme circadien et conseiller le type de tâche à effectuer:
{"action": "energy_level"}
{"action": "energy_level", "target": "Tokyo"}

- "niveau énergie" / "energy level" / "mon énergie" / "quand travailler" → {"action": "energy_level"}
- "énergie à Tokyo" / "energy level New York" / "productivité optimale Dubai" → {"action": "energy_level", "target": "Tokyo"}
- "heures productives" / "peak hours" / "quand être productif" / "best time to work" → {"action": "energy_level"}
- "conseil productivité" / "energy advice" / "optimal hours" → {"action": "energy_level"}
- IMPORTANT: Si target est absent, utilise le fuseau de l'utilisateur. Basé sur le rythme circadien humain moyen.

Afficher l'indicatif téléphonique international d'un pays/ville et vérifier si c'est un bon moment pour appeler:
{"action": "international_dialing", "target": "Tokyo"}
{"action": "international_dialing", "target": "France"}

- "indicatif Tokyo" / "dialing code Japan" / "code pays France" → {"action": "international_dialing", "target": "Tokyo"}
- "comment appeler le Japon" / "how to call France" / "quel indicatif pour Dubai" → {"action": "international_dialing", "target": "Japan"}
- "indicatif téléphonique Allemagne" / "country code UK" / "phone code USA" → {"action": "international_dialing", "target": "Germany"}
- "indicatif international Espagne" / "calling code Italy" → {"action": "international_dialing", "target": "Spain"}
- IMPORTANT: target est un nom de ville ou de pays. Retourne l'indicatif, l'heure locale, et un conseil sur le meilleur moment pour appeler.

Rechercher dans les préférences par mot-clé:
{"action": "preferences_search", "query": "langue"}
{"action": "preferences_search", "query": "time"}
- "chercher langue" / "search timezone" / "find style" → {"action": "preferences_search", "query": "..."}

Afficher les détails complets de la locale (langue, fuseau, format, unités):
{"action": "locale_details"}
- "détails locale" / "locale details" / "info locale" / "paramètres régionaux" → {"action": "locale_details"}

Tableau de bord complet (heure, progression journée/semaine/mois, énergie, prochain événement):
{"action": "status_dashboard"}
- "dashboard" / "tableau de bord" / "vue d'ensemble" / "mon statut" / "overview" / "full dashboard" → {"action": "status_dashboard"}
- "status" / "résumé complet" / "complete overview" / "my status" → {"action": "status_dashboard"}

Calculer le temps écoulé depuis une date passée:
{"action": "time_ago", "date": "2024-06-15", "label": "lancement projet"}
{"action": "time_ago", "date": "2020-03-11", "label": "début pandémie"}
- "il y a combien de temps depuis le 2024-06-15" → {"action": "time_ago", "date": "2024-06-15", "label": ""}
- "ça fait combien depuis le lancement" → {"action": "time_ago", "date": "2024-06-15", "label": "lancement"}
- "depuis combien de jours le 2025-01-01" / "how long since 2025-01-01" → {"action": "time_ago", "date": "2025-01-01", "label": ""}
- "temps écoulé depuis mon anniversaire 1990-05-15" → {"action": "time_ago", "date": "1990-05-15", "label": "anniversaire"}
- IMPORTANT: date doit être au format AAAA-MM-JJ. Si la date est dans le futur, utilise countdown à la place.

Score de focus — recommandation de tâche optimale basée sur l'heure et le jour:
{"action": "focus_score"}
- "focus score" / "score de focus" / "que faire maintenant" / "what should I do" / "best task now" / "quoi travailler" → {"action": "focus_score"}
- "concentration" / "focus now" / "what to work on" → {"action": "focus_score"}

Template de standup — génère un template de standup/point quotidien prêt à coller:
{"action": "standup_helper"}
- "standup" / "daily standup" / "mon standup" / "my standup" / "standup template" → {"action": "standup_helper"}
- "scrum daily" / "point quotidien" / "point du jour" / "standup du jour" → {"action": "standup_helper"}

Routine matinale personnalisée — checklist et conseils pour bien démarrer la journée:
{"action": "morning_routine"}
- "morning routine" / "routine matinale" / "routine du matin" / "ma routine" / "my routine" → {"action": "morning_routine"}
- "morning checklist" / "checklist matin" / "commencer ma journée" / "start my day" → {"action": "morning_routine"}
- "daily routine" / "bien démarrer" / "morning plan" / "plan du matin" → {"action": "morning_routine"}

Comparer ses préférences avec les valeurs régionales par défaut d'un pays:
{"action": "preferences_compare"}
{"action": "preferences_compare", "target": "US"}
{"action": "preferences_compare", "target": "JP"}
- "comparer préférences" / "compare preferences" / "compare settings" → {"action": "preferences_compare"}
- "mes préférences vs France" / "compare with US" / "vs locale japonaise" → {"action": "preferences_compare", "target": "JP"}
- "benchmark preferences" / "comparaison locale" / "locale comparison" → {"action": "preferences_compare"}
- Si un pays/locale est mentionné, ajouter "target" avec le code ISO 2 lettres (FR, US, UK, DE, ES, IT, PT, JP, AR, ZH).

Compte à rebours jusqu'au week-end (heures/jours restants avant vendredi 18h ou samedi):
{"action": "weekend_countdown"}
- "vivement le week-end" / "dans combien le week-end" / "weekend countdown" / "c'est bientôt le week-end" → {"action": "weekend_countdown"}
- "combien de temps avant vendredi" / "quand est le week-end" / "how long until weekend" / "is it friday yet" → {"action": "weekend_countdown"}

Transposer un horaire de travail d'un fuseau à un autre (montrer à quoi correspond un créneau horaire vu depuis une autre ville):
{"action": "time_swap", "from": "Paris", "to": "Tokyo", "start": "09:00", "end": "18:00"}
{"action": "time_swap", "from": "New York", "to": "London", "start": "8am", "end": "5pm"}
Si "from" est absent, utilise le fuseau de l'utilisateur. Si "start"/"end" sont absents, utilise 09:00-18:00.
- "mes horaires de travail vus depuis Tokyo" / "mon 9h-18h vu de Tokyo" / "my work hours from Tokyo" → {"action": "time_swap", "from": "", "to": "Tokyo", "start": "09:00", "end": "18:00"}
- "si je travaille 8h-17h à New York c'est quoi à Londres" / "time swap New York London 8-17" → {"action": "time_swap", "from": "New York", "to": "London", "start": "08:00", "end": "17:00"}
- "horaires Paris vus de Dubai" / "swap horaire Paris Dubai" → {"action": "time_swap", "from": "Paris", "to": "Dubai", "start": "09:00", "end": "18:00"}

Bilan hebdomadaire complet (recap semaine écoulée + aperçu semaine suivante, productivité, suggestions):
{"action": "weekly_review"}
- "bilan de la semaine" / "weekly review" / "recap semaine" / "résumé hebdomadaire" → {"action": "weekly_review"}
- "comment s'est passée ma semaine" / "how was my week" / "weekly recap" / "bilan hebdo" → {"action": "weekly_review"}
- "revue de la semaine" / "semaine en revue" / "review my week" → {"action": "weekly_review"}

Suivi d'habitudes quotidiennes (check-in quotidien, score d'hydratation/exercice/lecture basé sur l'heure):
{"action": "habit_tracker"}
- "mes habitudes" / "habit tracker" / "suivi habitudes" / "daily habits" → {"action": "habit_tracker"}
- "check-in habitudes" / "habit check" / "ai-je bien fait aujourd'hui" / "bilan habitudes" → {"action": "habit_tracker"}
- "routine tracker" / "mes objectifs du jour" / "daily goals" / "streak" → {"action": "habit_tracker"}

Suivi d'objectifs personnels avec progression et deadline:
{"action": "goal_tracker"}
{"action": "goal_tracker", "goal": "Apprendre le japonais", "deadline": "2026-12-31", "progress": 40}
- "mes objectifs" / "my goals" / "goal tracker" / "suivi objectifs" → {"action": "goal_tracker"}
- "objectif apprendre japonais deadline 2026-12-31 progress 40" → {"action": "goal_tracker", "goal": "Apprendre le japonais", "deadline": "2026-12-31", "progress": 40}
- "progression objectifs" / "goal progress" / "bilan objectifs" → {"action": "goal_tracker"}
- Si goal/deadline/progress sont absents, affiche un dashboard récapitulatif.

Trouver le fuseau horaire le plus compatible parmi une liste de villes:
{"action": "timezone_buddy", "cities": ["Tokyo", "London", "New York"]}
{"action": "timezone_buddy"}
- "timezone buddy Tokyo, London, New York" → {"action": "timezone_buddy", "cities": ["Tokyo", "London", "New York"]}
- "fuseau ami Paris et Dubai" → {"action": "timezone_buddy", "cities": ["Paris", "Dubai"]}
- "tz buddy" / "partenaire fuseau" / "closest timezone" → {"action": "timezone_buddy"}
- Si cities est vide, utilise les villes favorites ou les villes par défaut du worldclock.

Calculer la somme de plusieurs durées (feuille de temps / timesheet):
{"action": "duration_calc", "durations": ["2h30", "1h45", "3h15"]}
{"action": "duration_calc", "durations": ["8h", "7h30", "8h15", "7h45", "8h"]}

- "2h30 + 1h45 + 3h15" / "additionner 2h30 1h45 3h15" → {"action": "duration_calc", "durations": ["2h30", "1h45", "3h15"]}
- "total heures: 8h 7h30 8h15 7h45 8h" / "timesheet 8h 7h30 8h15" → {"action": "duration_calc", "durations": ["8h", "7h30", "8h15", "7h45", "8h"]}
- "somme heures 45min + 1h20 + 2h" / "cumul 3h + 2h30" → {"action": "duration_calc", "durations": ["45min", "1h20", "2h"]}
- IMPORTANT: Extrais chaque durée séparément. Formats acceptés: 2h30, 2h, 30min, 1h45m, 90min, 1:30.

Suggérer le meilleur moment pour une activité selon le rythme circadien:
{"action": "smart_schedule", "activity": "focus"}
{"action": "smart_schedule", "activity": "meeting"}
{"action": "smart_schedule", "activity": "exercise"}
{"action": "smart_schedule", "activity": "creative"}

- "meilleur moment pour du travail de fond" / "quand faire du deep work" → {"action": "smart_schedule", "activity": "focus"}
- "quand planifier une réunion" / "best time for a meeting" → {"action": "smart_schedule", "activity": "meeting"}
- "quand faire du sport" / "when to exercise" → {"action": "smart_schedule", "activity": "exercise"}
- "meilleur moment pour créer" / "quand être créatif" / "best time for creative work" → {"action": "smart_schedule", "activity": "creative"}
- "quand faire de l'admin" / "when to do admin tasks" → {"action": "smart_schedule", "activity": "admin"}
- Si l'activité n'est pas claire, utilise "focus" par défaut.

Rappel de pause — suggère quand faire une pause selon le rythme de travail:
{"action": "break_reminder"}
{"action": "break_reminder", "work_start": "08:00"}
- "quand faire une pause" / "break reminder" / "pause" / "temps de pause" → {"action": "break_reminder"}
- "j'ai commencé à 8h" / "working since 8am" → {"action": "break_reminder", "work_start": "08:00"}
- "rappel pause" / "stretch break" / "pause café" → {"action": "break_reminder"}

Budget temps — heures de travail restantes aujourd'hui et cette semaine:
{"action": "time_budget"}
{"action": "time_budget", "work_start": "09:00", "work_end": "18:00"}
- "budget temps" / "time budget" / "heures restantes" / "remaining hours" → {"action": "time_budget"}
- "combien d'heures de travail restantes" / "hours left today" / "working hours left" → {"action": "time_budget"}
- "temps de travail restant" / "horaires 9h-18h" → {"action": "time_budget", "work_start": "09:00", "work_end": "18:00"}

Rapport quotidien — vue complète avec heure mondiale, productivité et énergie:
{"action": "time_report"}
- "rapport quotidien" / "daily report" / "rapport temps" / "time report" / "résumé du jour" → {"action": "time_report"}
- "bilan journée" / "daily time report" / "état du monde" → {"action": "time_report"}

Comparer deux villes — heure, bureau, décalage et fenêtre commune:
{"action": "city_compare", "city1": "Paris", "city2": "Tokyo"}
- "comparer Paris et Tokyo" / "compare Paris Tokyo" / "Paris vs Tokyo" → {"action": "city_compare", "city1": "Paris", "city2": "Tokyo"}
- "compare London and New York" / "Londres vs New York" → {"action": "city_compare", "city1": "London", "city2": "New York"}
- Si l'utilisateur dit "comparer X et Y" ou "X vs Y", extrais les deux villes dans city1 et city2.

Compte à rebours jusqu'au prochain anniversaire à partir d'une date de naissance:
{"action": "birthday_countdown", "birthdate": "1990-05-15"}
- "countdown anniversaire 1990-05-15" / "prochain anniversaire né le 1990-05-15" → {"action": "birthday_countdown", "birthdate": "1990-05-15"}
- "my birthday 1985-12-25" / "birthday countdown 1992-07-04" → {"action": "birthday_countdown", "birthdate": "1985-12-25"}
- "mon anniversaire" / "prochain anniversaire" (sans date) → {"action": "birthday_countdown"}
- IMPORTANT: birthdate doit être au format AAAA-MM-JJ.

Configuration rapide — assistant guidé pour configurer les préférences essentielles:
{"action": "quick_setup"}
- "quick setup" / "configuration rapide" / "setup rapide" / "configurer" → {"action": "quick_setup"}
- "guide de configuration" / "démarrage rapide" / "quick start" → {"action": "quick_setup"}

Score d'équilibre vie-travail avec recommandations personnalisées:
{"action": "work_life_balance"}
{"action": "work_life_balance", "work_start": "08:00", "work_end": "17:00"}
- "équilibre vie-travail" / "work-life balance" / "balance travail" → {"action": "work_life_balance"}
- "suis-je en surcharge" / "am I overworking" / "balance score" → {"action": "work_life_balance"}
- "balance horaires 8h-17h" / "work life 9-18" → {"action": "work_life_balance", "work_start": "08:00", "work_end": "17:00"}

Quiz sur les fuseaux horaires — question aléatoire pour tester ses connaissances:
{"action": "timezone_quiz"}
- "quiz timezone" / "quiz fuseau" / "timezone quiz" / "question fuseau" → {"action": "timezone_quiz"}
- "teste mes connaissances" / "quiz horaires" / "timezone game" → {"action": "timezone_quiz"}
- "jouer" / "devinette horaire" / "quiz géo" → {"action": "timezone_quiz"}

Suggestions d'amélioration des préférences — analyse le profil et suggère des optimisations:
{"action": "preferences_suggestions"}
- "suggestions preferences" / "améliorer mes préférences" / "preferences tips" / "conseils profil" → {"action": "preferences_suggestions"}
- "que devrais-je changer" / "optimize my profile" / "analyse preferences" → {"action": "preferences_suggestions"}

Disponibilité mondiale — affiche quelles grandes villes sont actuellement dans les heures ouvrables:
{"action": "availability_now"}
- "disponibilité" / "availability now" / "qui est disponible" / "villes disponibles" → {"action": "availability_now"}
- "qui peut appeler" / "open for calls" / "available cities" / "call now" → {"action": "availability_now"}

Planificateur de productivité (plan d'action pour le reste de la journée basé sur l'heure, l'énergie et le jour):
{"action": "productivity_planner"}
- "productivity planner" / "plan productivité" / "mon plan" / "daily plan" / "planificateur productivité" → {"action": "productivity_planner"}
- "que faire maintenant" / "quoi faire aujourd'hui" / "plan du jour" / "how should I plan my day" → {"action": "productivity_planner"}
- "organise ma journée" / "plan d'action" / "my plan" / "action plan" → {"action": "productivity_planner"}

Amitié fuseau — comparaison complète entre le fuseau de l'utilisateur et une ville (décalage, heures partagées, meilleur créneau, statut actuel):
{"action": "timezone_friendship", "target": "Tokyo"}
{"action": "timezone_friendship", "target": "New York"}
- "timezone friendship Tokyo" / "amitié fuseau New York" / "tz friend London" → {"action": "timezone_friendship", "target": "Tokyo"}
- "relation fuseau Tokyo" / "lien horaire avec Dubai" / "timezone bond Singapore" → {"action": "timezone_friendship", "target": "Tokyo"}
- "comment communiquer avec Tokyo" / "compatibilité horaire New York" → {"action": "timezone_friendship", "target": "New York"}

Roulette de fuseau horaire — découvrir une ville aléatoire (heure locale, fun fact, bon moment pour appeler):
{"action": "timezone_roulette"}
- "roulette fuseau" / "timezone roulette" / "ville aléatoire" / "random city" → {"action": "timezone_roulette"}
- "surprise fuseau" / "discover city" / "explore timezone" / "random timezone" → {"action": "timezone_roulette"}

Coût d'une réunion multi-fuseaux — calcule l'inconvénience horaire pour chaque participant:
{"action": "meeting_cost", "cities": ["Tokyo", "London", "New York"]}
{"action": "meeting_cost", "cities": ["Paris", "Dubai", "Sydney"], "time": "14:00"}
- "meeting cost Tokyo London New York" / "coût réunion Paris Dubai Sydney" → {"action": "meeting_cost", "cities": ["Tokyo", "London", "New York"]}
- "timezone tax Paris Dubai à 14h" → {"action": "meeting_cost", "cities": ["Paris", "Dubai"], "time": "14:00"}
- "qui est le plus gêné pour une réunion Paris Tokyo Sydney" → {"action": "meeting_cost", "cities": ["Paris", "Tokyo", "Sydney"]}
- IMPORTANT: cities est un tableau de 2+ villes. time est optionnel (heure de la réunion dans le fuseau de l'utilisateur, format HH:MM). Par défaut, calcule pour l'heure actuelle. Affiche un score d'inconvénience par ville.

Info devise/monnaie — affiche la devise locale d'une ville ou du fuseau utilisateur, avec symbole et code ISO:
{"action": "currency_info"}
{"action": "currency_info", "target": "Tokyo"}
{"action": "currency_info", "target": "New York"}
- "devise" / "currency" / "monnaie" / "quelle devise" → {"action": "currency_info"}
- "devise à Tokyo" / "currency in New York" / "monnaie en Suisse" → {"action": "currency_info", "target": "Tokyo"}
- "taux de change" / "exchange rate" / "argent local" → {"action": "currency_info"}

Rappel hydratation — suggestion de consommation d'eau basée sur l'heure et les heures de travail:
{"action": "water_reminder"}
- "eau" / "water" / "hydratation" / "hydration" / "rappel eau" → {"action": "water_reminder"}
- "boire de l'eau" / "drink water" / "combien boire" / "how much water" → {"action": "water_reminder"}
- "objectif eau" / "water goal" / "water tracker" / "suivi eau" → {"action": "water_reminder"}

Compte à rebours vers une réunion/événement à une heure précise aujourd'hui:
{"action": "meeting_countdown", "time": "14:30"}
{"action": "meeting_countdown", "time": "9h"}
{"action": "meeting_countdown", "time": "3pm"}

- "réunion à 14h30" / "meeting at 2pm" / "rdv à 10h" → {"action": "meeting_countdown", "time": "14:30"}
- "countdown réunion 15h" / "meeting countdown 3pm" → {"action": "meeting_countdown", "time": "15:00"}
- "dans combien de temps ma réunion de 16h" → {"action": "meeting_countdown", "time": "16:00"}
- "meeting countdown" (sans heure) → affiche un message demandant l'heure
- IMPORTANT: time doit être au format HH:MM, Xh, XhYY, ou Xpm/Xam.

Générer un planning journalier type par blocs horaires basé sur les préférences et le rythme circadien:
{"action": "daily_planner"}

- "mon planning du jour" / "daily planner" / "plan de la journée" → {"action": "daily_planner"}
- "organiser ma journée" / "time blocking" / "blocs horaires" / "plan journalier" → {"action": "daily_planner"}
- "comment organiser ma journée" / "plan my day" → {"action": "daily_planner"}

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

        $lang = $currentPrefs['language'] ?? 'fr';

        if (!$key || $value === null) {
            $msg = match ($lang) {
                'en'    => "I didn't understand which preference to change. Type *show preferences* to see your settings.",
                'es'    => "No entendí qué preferencia modificar. Escribe *show preferences* para ver tus ajustes.",
                'de'    => "Ich habe nicht verstanden, welche Einstellung geändert werden soll. Tippe *show preferences*.",
                default => "Je n'ai pas compris quelle préférence modifier. Tape *show preferences* pour voir tes paramètres actuels.",
            };
            return AgentResult::reply($msg);
        }

        if (!in_array($key, UserPreference::$validKeys)) {
            $validKeys = implode(', ', UserPreference::$validKeys);
            $invalidMsg = match ($lang) {
                'en' => "Invalid key *{$key}*. Valid keys: {$validKeys}",
                'es' => "Clave inválida *{$key}*. Claves válidas: {$validKeys}",
                'de' => "Ungültiger Schlüssel *{$key}*. Gültige Schlüssel: {$validKeys}",
                default => "Clé invalide *{$key}*. Clés valides : {$validKeys}",
            };
            return AgentResult::reply($invalidMsg);
        }

        $validationError = $this->validateValue($key, $value, $lang);
        if ($validationError) {
            return AgentResult::reply($validationError);
        }

        $value    = $this->normalizeValue($key, $value);
        $oldValue = $currentPrefs[$key] ?? null;

        // Skip update if value is already the same
        if ($oldValue !== null && (string) $oldValue === (string) $value) {
            $displayValue = $this->formatValue($key, $value, $lang);
            $noopMsg = match ($lang) {
                'en' => "ℹ️ *{$this->formatKeyLabel($key, $lang)}* is already set to {$displayValue}.\n\n_No change needed._",
                'es' => "ℹ️ *{$this->formatKeyLabel($key, $lang)}* ya está configurado en {$displayValue}.\n\n_No es necesario cambiar._",
                'de' => "ℹ️ *{$this->formatKeyLabel($key, $lang)}* ist bereits auf {$displayValue} gesetzt.\n\n_Keine Änderung nötig._",
                default => "ℹ️ *{$this->formatKeyLabel($key, $lang)}* est déjà défini sur {$displayValue}.\n\n_Aucune modification nécessaire._",
            };
            return AgentResult::reply(
                $noopMsg,
                ['action' => 'set_preference_noop', 'key' => $key, 'value' => $value]
            );
        }

        $success  = PreferencesManager::setPreference($userId, $key, $value);

        if (!$success) {
            $failMsg = match ($lang) {
                'en' => "Error updating *{$key}*. Please try again in a moment.",
                'es' => "Error al actualizar *{$key}*. Inténtalo de nuevo en unos instantes.",
                'de' => "Fehler beim Aktualisieren von *{$key}*. Bitte versuche es gleich nochmal.",
                default => "Erreur lors de la mise à jour de *{$key}*. Réessaie dans quelques instants.",
            };
            return AgentResult::reply($failMsg);
        }

        $this->log($context, "Preference updated: {$key}", [
            'key'       => $key,
            'old_value' => $oldValue,
            'new_value' => $value,
        ]);

        $displayValue = $this->formatValue($key, $value, $lang);
        $displayOld   = $this->formatValue($key, $oldValue, $lang);

        // Use the effective language (if language itself was just changed, use the new one)
        $effectiveLang = $key === 'language' ? (string) $value : $lang;
        $successMsg = match ($effectiveLang) {
            'en'    => "✅ Preference updated!",
            'es'    => "✅ ¡Preferencia actualizada!",
            'de'    => "✅ Einstellung aktualisiert!",
            'it'    => "✅ Preferenza aggiornata!",
            'pt'    => "✅ Preferência atualizada!",
            'ar'    => "✅ تم تحديث التفضيل!",
            'zh'    => "✅ 偏好已更新！",
            'ja'    => "✅ 設定が更新されました！",
            'ko'    => "✅ 설정이 업데이트되었습니다!",
            'ru'    => "✅ Настройка обновлена!",
            'nl'    => "✅ Voorkeur bijgewerkt!",
            default => "✅ Préférence mise à jour !",
        };
        $reply = "{$successMsg}\n\n"
            . "*{$this->formatKeyLabel($key, $effectiveLang)}* : {$displayOld} → {$displayValue}";

        // v1.43.0 — For language changes, show bilingual confirmation (old→new language)
        if ($key === 'language' && $lang !== (string) $value) {
            $oldLangConfirm = match ($lang) {
                'en'    => "Language changed to",
                'es'    => "Idioma cambiado a",
                'de'    => "Sprache geändert zu",
                'it'    => "Lingua cambiata in",
                'pt'    => "Idioma alterado para",
                'ar'    => "تم تغيير اللغة إلى",
                'zh'    => "语言已更改为",
                'ja'    => "言語が変更されました：",
                'ko'    => "언어가 변경되었습니다:",
                'ru'    => "Язык изменён на",
                'nl'    => "Taal gewijzigd naar",
                default => "Langue changée en",
            };
            $newLangLabel = self::LANGUAGE_LABELS[(string) $value] ?? (string) $value;
            $reply .= "\n\n_({$oldLangConfirm} {$newLangLabel})_";
        }

        // For timezone changes, immediately show local time in new timezone
        if ($key === 'timezone') {
            try {
                $tz      = new DateTimeZone($value);
                $now     = new DateTimeImmutable('now', $tz);
                $lang    = $currentPrefs['language'] ?? 'fr';
                $dayName = $this->getDayName((int) $now->format('w'), $lang);
                $tzTimeMsg = match ($lang) {
                    'en' => "It is currently *{$now->format('H:i')}* ({$dayName}) in this timezone.",
                    'es' => "Actualmente son las *{$now->format('H:i')}* ({$dayName}) en esta zona horaria.",
                    'de' => "Es ist derzeit *{$now->format('H:i')}* ({$dayName}) in dieser Zeitzone.",
                    default => "Il est actuellement *{$now->format('H:i')}* ({$dayName}) dans ce fuseau.",
                };
                $reply  .= "\n\n🕐 {$tzTimeMsg}";
            } catch (\Exception) {
                // Silently ignore
            }
        }

        return AgentResult::reply($reply, ['action' => 'set_preference', 'key' => $key, 'value' => $value]);
    }

    private function handleSetMultiple(AgentContext $context, string $userId, array $parsed, array $currentPrefs): AgentResult
    {
        $changes = $parsed['changes'] ?? [];
        $lang    = $currentPrefs['language'] ?? 'fr';

        if (empty($changes) || !is_array($changes)) {
            $emptyMsg = match ($lang) {
                'en' => "No changes to apply. Specify which preferences to modify.",
                'es' => "Ningún cambio a realizar. Especifica las preferencias a modificar.",
                'de' => "Keine Änderungen vorzunehmen. Gib die zu ändernden Einstellungen an.",
                default => "Aucun changement à effectuer. Précise les préférences à modifier.",
            };
            return AgentResult::reply($emptyMsg);
        }

        // Validate ALL changes first before applying any
        $validChanges = [];
        $errors       = [];

        foreach ($changes as $change) {
            $key   = $change['key'] ?? null;
            $value = $change['value'] ?? null;

            if (!$key || $value === null) {
                $incompleteMsg = match ($lang) {
                    'en' => "⚠️ Incomplete change (missing key or value)",
                    'es' => "⚠️ Cambio incompleto (falta clave o valor)",
                    'de' => "⚠️ Unvollständige Änderung (Schlüssel oder Wert fehlt)",
                    default => "⚠️ Changement incomplet (clé ou valeur manquante)",
                };
                $errors[] = $incompleteMsg;
                continue;
            }

            if (!in_array($key, UserPreference::$validKeys)) {
                $invalidKeyMsg = match ($lang) {
                    'en' => "⚠️ Invalid key: *{$key}*",
                    'es' => "⚠️ Clave inválida: *{$key}*",
                    'de' => "⚠️ Ungültiger Schlüssel: *{$key}*",
                    default => "⚠️ Clé invalide : *{$key}*",
                };
                $errors[] = $invalidKeyMsg;
                continue;
            }

            $validationError = $this->validateValue($key, $value, $lang);
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
            $noValidMsg = match ($lang) {
                'en' => "No valid preference to update.",
                'es' => "Ninguna preferencia válida para actualizar.",
                'de' => "Keine gültige Einstellung zum Aktualisieren.",
                default => "Aucune préférence valide à mettre à jour.",
            };
            return AgentResult::reply(
                $noValidMsg . "\n\n" . implode("\n", $errors)
            );
        }

        // v1.52.0 — Apply all validated changes inside a DB transaction for atomicity
        $successTitle = match ($lang) {
            'en' => "✅ *Preferences updated!*",
            'es' => "✅ *¡Preferencias actualizadas!*",
            'de' => "✅ *Einstellungen aktualisiert!*",
            default => "✅ *Préférences mises à jour !*",
        };
        $lines       = ["{$successTitle}\n"];
        $updated     = 0;
        $applyErrors = [];

        try {
            DB::beginTransaction();

            foreach ($validChanges as $change) {
                $key      = $change['key'];
                $value    = $change['value'];
                $oldValue = $change['oldValue'];
                $success  = PreferencesManager::setPreference($userId, $key, $value);

                if ($success) {
                    $lines[] = "• *{$this->formatKeyLabel($key, $lang)}* : {$this->formatValue($key, $oldValue, $lang)} → {$this->formatValue($key, $value, $lang)}";
                    $updated++;

                    $this->log($context, "Preference updated (multi): {$key}", [
                        'key'       => $key,
                        'old_value' => $oldValue,
                        'new_value' => $value,
                    ]);
                } else {
                    $applyErrMsg = match ($lang) {
                        'en' => "⚠️ Error for *{$key}*",
                        'es' => "⚠️ Error para *{$key}*",
                        'de' => "⚠️ Fehler bei *{$key}*",
                        default => "⚠️ Erreur pour *{$key}*",
                    };
                    $applyErrors[] = $applyErrMsg;
                }
            }

            if ($updated > 0) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('UserPreferencesAgent: set_multiple transaction failed', ['error' => $e->getMessage()]);
            $txErrMsg = match ($lang) {
                'en' => "⚠️ Failed to save preferences. Please try again.",
                'es' => "⚠️ Error al guardar las preferencias. Inténtalo de nuevo.",
                'de' => "⚠️ Fehler beim Speichern der Einstellungen. Bitte erneut versuchen.",
                default => "⚠️ Erreur lors de la sauvegarde des préférences. Réessaie.",
            };
            return AgentResult::reply($txErrMsg, ['action' => 'set_multiple_preferences', 'error' => 'transaction_failed']);
        }

        if ($updated === 0) {
            $noUpdateMsg = match ($lang) {
                'en' => "No preference was updated.",
                'es' => "Ninguna preferencia fue actualizada.",
                'de' => "Keine Einstellung wurde aktualisiert.",
                default => "Aucune préférence mise à jour.",
            };
            return AgentResult::reply($noUpdateMsg . "\n\n" . implode("\n", $applyErrors));
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

        // Get language for i18n
        $prefs = PreferencesManager::getPreferences($userId);
        $lang  = $prefs['language'] ?? 'fr';

        if (!$key) {
            $noKeyMsg = match ($lang) {
                'en' => "Specify which preference to reset, or type *reset all* to reset everything.",
                'es' => "Especifica qué preferencia restablecer, o escribe *reset all* para restablecer todo.",
                'de' => "Gib an, welche Einstellung zurückgesetzt werden soll, oder tippe *reset all* für alle.",
                default => "Précise quelle préférence réinitialiser, ou tape *reset all* pour tout réinitialiser.",
            };
            return AgentResult::reply($noKeyMsg);
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
                $partialMsg = match ($lang) {
                    'en' => "⚠️ Partial reset. Errors on: " . implode(', ', $errors) . "\nOther preferences were reset successfully.",
                    'es' => "⚠️ Restablecimiento parcial. Errores en: " . implode(', ', $errors) . "\nLas demás preferencias se restablecieron correctamente.",
                    'de' => "⚠️ Teilweiser Reset. Fehler bei: " . implode(', ', $errors) . "\nDie übrigen Einstellungen wurden erfolgreich zurückgesetzt.",
                    default => "⚠️ Réinitialisation partielle. Erreurs sur : " . implode(', ', $errors) . "\nLes autres préférences ont bien été réinitialisées.",
                };
                return AgentResult::reply($partialMsg);
            }

            $freshPrefs = PreferencesManager::getPreferences($userId);
            $resetAllMsg = match ($lang) {
                'en' => "🔄 All preferences have been reset to defaults.",
                'es' => "🔄 Todas las preferencias han sido restablecidas a los valores predeterminados.",
                'de' => "🔄 Alle Einstellungen wurden auf Standardwerte zurückgesetzt.",
                default => "🔄 Toutes les préférences ont été réinitialisées aux valeurs par défaut.",
            };
            return AgentResult::reply(
                "{$resetAllMsg}\n\n" . $this->formatShowPreferences($freshPrefs),
                ['action' => 'reset_all_preferences']
            );
        }

        if (!in_array($key, UserPreference::$validKeys)) {
            $validKeys = implode(', ', UserPreference::$validKeys);
            $invalidMsg = match ($lang) {
                'en' => "Invalid key *{$key}*. Valid keys: {$validKeys}",
                'es' => "Clave inválida *{$key}*. Claves válidas: {$validKeys}",
                'de' => "Ungültiger Schlüssel *{$key}*. Gültige Schlüssel: {$validKeys}",
                default => "Clé invalide *{$key}*. Clés valides : {$validKeys}",
            };
            return AgentResult::reply($invalidMsg);
        }

        $defaultValue = UserPreference::$defaults[$key] ?? null;
        $success      = PreferencesManager::setPreference($userId, $key, $defaultValue);

        if (!$success) {
            $failMsg = match ($lang) {
                'en' => "Error resetting *{$key}*. Please try again in a moment.",
                'es' => "Error al restablecer *{$key}*. Inténtalo de nuevo en unos instantes.",
                'de' => "Fehler beim Zurücksetzen von *{$key}*. Bitte versuche es gleich nochmal.",
                default => "Erreur lors de la réinitialisation de *{$key}*. Réessaie dans quelques instants.",
            };
            return AgentResult::reply($failMsg);
        }

        $this->log($context, "Preference reset: {$key}", ['default' => $defaultValue]);

        $displayDefault = $this->formatValue($key, $defaultValue);
        $defaultLabel = match ($lang) { 'en' => 'default', 'es' => 'predeterminado', 'de' => 'Standard', default => 'valeur par défaut' };
        $resetMsg = match ($lang) {
            'en' => "🔄 Preference reset!",
            'es' => "🔄 ¡Preferencia restablecida!",
            'de' => "🔄 Einstellung zurückgesetzt!",
            default => "🔄 Préférence réinitialisée !",
        };
        $reply = "{$resetMsg}\n\n"
            . "*{$this->formatKeyLabel($key)}* → {$displayDefault} _({$defaultLabel})_";

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

            // v1.55.0 — i18n-aware labels
            $title     = match ($lang) { 'en' => 'LOCAL TIME', 'es' => 'HORA LOCAL', 'de' => 'ORTSZEIT', 'it' => 'ORA LOCALE', 'pt' => 'HORA LOCAL', 'ar' => 'الوقت المحلي', 'zh' => '本地时间', 'ja' => '現地時間', 'ko' => '현지 시간', 'ru' => 'МЕСТНОЕ ВРЕМЯ', 'nl' => 'LOKALE TIJD', default => 'HEURE LOCALE' };
            $tzLabel   = match ($lang) { 'en' => 'Timezone', 'es' => 'Zona horaria', 'de' => 'Zeitzone', 'it' => 'Fuso orario', 'pt' => 'Fuso horário', 'ar' => 'المنطقة الزمنية', 'zh' => '时区', 'ja' => 'タイムゾーン', 'ko' => '시간대', 'ru' => 'Часовой пояс', 'nl' => 'Tijdzone', default => 'Fuseau' };
            $dateLabel = match ($lang) { 'en' => 'Date', 'es' => 'Fecha', 'de' => 'Datum', 'it' => 'Data', 'pt' => 'Data', 'ar' => 'التاريخ', 'zh' => '日期', 'ja' => '日付', 'ko' => '날짜', 'ru' => 'Дата', 'nl' => 'Datum', default => 'Date' };
            $timeLabel = match ($lang) { 'en' => 'Time', 'es' => 'Hora', 'de' => 'Uhrzeit', 'it' => 'Ora', 'pt' => 'Hora', 'ar' => 'الوقت', 'zh' => '时间', 'ja' => '時刻', 'ko' => '시간', 'ru' => 'Время', 'nl' => 'Tijd', default => 'Heure' };
            $weekLabel = match ($lang) { 'en' => 'week', 'es' => 'semana', 'de' => 'Woche', 'it' => 'settimana', 'pt' => 'semana', 'ar' => 'أسبوع', 'zh' => '周', 'ja' => '週', 'ko' => '주', 'ru' => 'неделя', 'nl' => 'week', default => 'semaine' };
            $tzHint    = match ($lang) { 'en' => 'Change timezone: timezone Europe/Paris', 'es' => 'Cambiar zona: timezone Europe/Paris', 'de' => 'Zeitzone ändern: timezone Europe/Paris', 'it' => 'Cambia fuso: timezone Europe/Paris', 'pt' => 'Mudar fuso: timezone Europe/Paris', default => 'Pour changer ton fuseau : timezone Europe/Paris' };
            $clockHint = match ($lang) { 'en' => 'See other cities: world clock', 'es' => 'Ver otras ciudades: reloj mundial', 'de' => 'Andere Städte: Weltzeit', 'it' => 'Altre città: orologio mondiale', 'pt' => 'Outras cidades: relógio mundial', default => 'Voir d\'autres villes : horloge mondiale' };

            $reply = "🕐 *{$title}*\n"
                . "────────────────\n"
                . "📍 {$tzLabel} : *{$tzName}* (UTC{$offsetStr})\n"
                . "📅 {$dateLabel} : *{$dayName} {$dateStr}* _({$weekLabel} {$weekNum})_\n"
                . "{$dayIcon} {$timeLabel} : *{$timeStr}*\n"
                . "────────────────\n"
                . "_{$tzHint}_\n"
                . "_{$clockHint}_";

        } catch (\Exception $e) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Cannot display time for timezone *{$tzName}*.\nSet a valid timezone: _timezone Europe/Paris_",
                'es' => "⚠️ No se puede mostrar la hora para *{$tzName}*.\nConfigura una zona válida: _timezone Europe/Paris_",
                'de' => "⚠️ Zeit für Zeitzone *{$tzName}* nicht anzeigbar.\nGültige Zeitzone setzen: _timezone Europe/Paris_",
                default => "⚠️ Impossible d'afficher l'heure pour le fuseau *{$tzName}*.\nConfigure un fuseau valide : _timezone Europe/Paris_",
            };
            $reply = $errMsg;
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
            $msg = match ($lang) {
                'en' => "Please specify the target date.\n_Ex: countdown 2026-12-25, how long until Christmas, days until Jan 1st_",
                'es' => "Indica la fecha objetivo.\n_Ej: countdown 2026-12-25, cuántos días hasta Navidad_",
                'de' => "Bitte gib das Zieldatum an.\n_Bsp: countdown 2026-12-25, wie lange bis Weihnachten_",
                default => "Précise la date cible du compte à rebours.\n_Ex : countdown 2026-12-25, dans combien de temps est Noël, jours restants avant le 1er janvier_",
            };
            return AgentResult::reply($msg);
        }

        try {
            $tz         = new DateTimeZone($tzName);
            $now        = new DateTimeImmutable('now', $tz);
            $targetDate = new DateTimeImmutable($targetDateStr . ' 00:00:00', $tz);
        } catch (\Exception) {
            $msg = match ($lang) {
                'en' => "⚠️ Invalid date: *{$targetDateStr}*.\n_Use YYYY-MM-DD format, e.g.: 2026-12-25_",
                'es' => "⚠️ Fecha inválida: *{$targetDateStr}*.\n_Usa el formato AAAA-MM-DD, ej: 2026-12-25_",
                'de' => "⚠️ Ungültiges Datum: *{$targetDateStr}*.\n_Verwende das Format JJJJ-MM-TT, z.B.: 2026-12-25_",
                default => "⚠️ Date invalide : *{$targetDateStr}*.\n_Utilise le format AAAA-MM-JJ, ex : 2026-12-25_",
            };
            return AgentResult::reply($msg);
        }

        // If date already passed this year and no year specified, check if we should roll to next year
        $nowMidnight    = new DateTimeImmutable($now->format('Y-m-d') . ' 00:00:00', new DateTimeZone($tzName));
        $diffFull       = $nowMidnight->diff($targetDate);
        $totalDays      = (int) $diffFull->days;
        $isPast         = $targetDate < $nowMidnight;

        $targetFormatted = $targetDate->format($dateFormat);
        $dayName         = $this->getDayName((int) $targetDate->format('w'), $lang);
        $titleStr        = $label !== '' ? " — *{$label}*" : '';

        $countdownTitle = match ($lang) { 'en' => 'COUNTDOWN', 'es' => 'CUENTA REGRESIVA', 'de' => 'COUNTDOWN', default => 'COMPTE À REBOURS' };
        $dateLabel      = match ($lang) { 'en' => 'Date', 'es' => 'Fecha', 'de' => 'Datum', default => 'Date' };

        if ($isPast) {
            $daysAgo = $totalDays;
            $pastStr = match ($lang) {
                'en' => "This date *passed {$daysAgo} day(s) ago*.",
                'es' => "Esta fecha *pasó hace {$daysAgo} día(s)*.",
                'de' => "Dieses Datum ist *vor {$daysAgo} Tag(en) vergangen*.",
                default => "Cette date est *passée il y a {$daysAgo} jour(s)*.",
            };
            $tipStr = match ($lang) {
                'en' => '_For a future date: countdown 2027-01-01_',
                'es' => '_Para una fecha futura: countdown 2027-01-01_',
                'de' => '_Für ein zukünftiges Datum: countdown 2027-01-01_',
                default => '_Pour une date future : countdown 2027-01-01_',
            };
            $reply = "📅 *{$countdownTitle}*{$titleStr}\n"
                . "────────────────\n"
                . "📌 {$dateLabel} : *{$dayName} {$targetFormatted}*\n"
                . "────────────────\n"
                . "⏮ {$pastStr}\n"
                . "────────────────\n"
                . $tipStr;

            return AgentResult::reply($reply, ['action' => 'countdown', 'days' => -$daysAgo, 'label' => $label]);
        }

        if ($totalDays === 0) {
            $todayStr = match ($lang) { 'en' => "That's today!", 'es' => '¡Es hoy!', 'de' => 'Das ist heute!', default => "C'est aujourd'hui !" };
            $reply = "📅 *{$countdownTitle}*{$titleStr}\n"
                . "────────────────\n"
                . "📌 {$dateLabel} : *{$dayName} {$targetFormatted}*\n"
                . "────────────────\n"
                . "🎉 *{$todayStr}*\n"
                . "────────────────";

            return AgentResult::reply($reply, ['action' => 'countdown', 'days' => 0, 'label' => $label]);
        }

        // Decompose in weeks and days
        $weeks      = intdiv($totalDays, 7);
        $remDays    = $totalDays % 7;
        $weekWord   = match ($lang) { 'en' => 'wk', 'es' => 'sem', 'de' => 'Wo', default => 'sem' };
        $dayWord    = match ($lang) { 'en' => 'd', 'es' => 'd', 'de' => 'T', default => 'j' };
        $weeksWord  = match ($lang) { 'en' => 'week(s)', 'es' => 'semana(s)', 'de' => 'Woche(n)', default => 'semaine(s)' };
        $breakdown  = '';
        if ($weeks > 0 && $remDays > 0) {
            $breakdown = " _({$weeks} {$weekWord}. + {$remDays} {$dayWord})_";
        } elseif ($weeks > 0) {
            $breakdown = " _({$weeks} {$weeksWord})_";
        }

        // Time-of-year context
        $months  = (int) $diffFull->m + ((int) $diffFull->y * 12);
        $approx  = '';
        if ($months >= 24) {
            $years     = round($totalDays / 365.25, 1);
            $yearsWord = match ($lang) { 'en' => 'years', 'es' => 'años', 'de' => 'Jahre', default => 'ans' };
            $approx    = " ≈ {$years} {$yearsWord}";
        } elseif ($months >= 2) {
            $monthsWord = match ($lang) { 'en' => 'months', 'es' => 'meses', 'de' => 'Monate', default => 'mois' };
            $approx     = " ≈ {$months} {$monthsWord}";
        }

        // Progress bar toward year end
        $yearStart    = new DateTimeImmutable($now->format('Y') . '-01-01 00:00:00', new DateTimeZone($tzName));
        $yearEnd      = new DateTimeImmutable($now->format('Y') . '-12-31 23:59:59', new DateTimeZone($tzName));
        $yearDays     = (int) $yearStart->diff($yearEnd)->days + 1;
        $dayOfYear    = (int) $now->format('z') + 1;
        $yearProgress = min(100, (int) round(($dayOfYear / $yearDays) * 10));
        $progressBar  = str_repeat('▓', $yearProgress) . str_repeat('░', 10 - $yearProgress);

        $targetLabel = match ($lang) { 'en' => 'Target date', 'es' => 'Fecha objetivo', 'de' => 'Zieldatum', default => 'Date cible' };
        $tzLabel     = match ($lang) { 'en' => 'Your timezone', 'es' => 'Tu zona horaria', 'de' => 'Deine Zeitzone', default => 'Depuis ton fuseau' };
        $remainStr   = match ($lang) {
            'en' => "*{$totalDays} day(s)* remaining",
            'es' => "Quedan *{$totalDays} día(s)*",
            'de' => "Noch *{$totalDays} Tag(e)*",
            default => "Il reste *{$totalDays} jour(s)*",
        };
        $yearLabel   = match ($lang) { 'en' => 'Year progress', 'es' => 'Progreso del año', 'de' => 'Jahresfortschritt', default => "Progression de l'année" };
        $tipStr      = match ($lang) {
            'en' => '_For another countdown: countdown 2027-01-01_',
            'es' => '_Para otra cuenta: countdown 2027-01-01_',
            'de' => '_Für einen anderen Countdown: countdown 2027-01-01_',
            default => '_Pour un autre compte à rebours : countdown 2027-01-01_',
        };

        $reply = "📅 *{$countdownTitle}*{$titleStr}\n"
            . "────────────────\n"
            . "📌 {$targetLabel} : *{$dayName} {$targetFormatted}*\n"
            . "📍 {$tzLabel} : *{$tzName}*\n"
            . "────────────────\n"
            . "⏳ {$remainStr}{$breakdown}{$approx}\n"
            . "────────────────\n"
            . "📆 {$yearLabel} [{$progressBar}]\n"
            . "────────────────\n"
            . $tipStr;

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

    // -------------------------------------------------------------------------
    // timezone_abbrev — v1.20.0
    // -------------------------------------------------------------------------

    private const TIMEZONE_ABBREVIATIONS = [
        'CET'  => ['name' => 'Central European Time',          'offset' => 'UTC+1',  'iana' => 'Europe/Paris',       'region' => 'Europe'],
        'CEST' => ['name' => 'Central European Summer Time',   'offset' => 'UTC+2',  'iana' => 'Europe/Paris',       'region' => 'Europe'],
        'WET'  => ['name' => 'Western European Time',          'offset' => 'UTC+0',  'iana' => 'Europe/Lisbon',      'region' => 'Europe'],
        'WEST' => ['name' => 'Western European Summer Time',   'offset' => 'UTC+1',  'iana' => 'Europe/Lisbon',      'region' => 'Europe'],
        'EET'  => ['name' => 'Eastern European Time',          'offset' => 'UTC+2',  'iana' => 'Europe/Helsinki',    'region' => 'Europe'],
        'EEST' => ['name' => 'Eastern European Summer Time',   'offset' => 'UTC+3',  'iana' => 'Europe/Helsinki',    'region' => 'Europe'],
        'GMT'  => ['name' => 'Greenwich Mean Time',            'offset' => 'UTC+0',  'iana' => 'Europe/London',      'region' => 'Europe/Afrique'],
        'BST'  => ['name' => 'British Summer Time',            'offset' => 'UTC+1',  'iana' => 'Europe/London',      'region' => 'Europe'],
        'IST'  => ['name' => 'India Standard Time',            'offset' => 'UTC+5:30', 'iana' => 'Asia/Kolkata',     'region' => 'Asie'],
        'JST'  => ['name' => 'Japan Standard Time',            'offset' => 'UTC+9',  'iana' => 'Asia/Tokyo',         'region' => 'Asie'],
        'KST'  => ['name' => 'Korea Standard Time',            'offset' => 'UTC+9',  'iana' => 'Asia/Seoul',         'region' => 'Asie'],
        'CST'  => ['name' => 'China Standard Time',            'offset' => 'UTC+8',  'iana' => 'Asia/Shanghai',      'region' => 'Asie'],
        'HKT'  => ['name' => 'Hong Kong Time',                 'offset' => 'UTC+8',  'iana' => 'Asia/Hong_Kong',     'region' => 'Asie'],
        'SGT'  => ['name' => 'Singapore Time',                 'offset' => 'UTC+8',  'iana' => 'Asia/Singapore',     'region' => 'Asie'],
        'ICT'  => ['name' => 'Indochina Time',                 'offset' => 'UTC+7',  'iana' => 'Asia/Bangkok',       'region' => 'Asie'],
        'GST'  => ['name' => 'Gulf Standard Time',             'offset' => 'UTC+4',  'iana' => 'Asia/Dubai',         'region' => 'Moyen-Orient'],
        'AST'  => ['name' => 'Arabia Standard Time',           'offset' => 'UTC+3',  'iana' => 'Asia/Riyadh',        'region' => 'Moyen-Orient'],
        'PST'  => ['name' => 'Pacific Standard Time',          'offset' => 'UTC-8',  'iana' => 'America/Los_Angeles','region' => 'Amérique du Nord'],
        'PDT'  => ['name' => 'Pacific Daylight Time',          'offset' => 'UTC-7',  'iana' => 'America/Los_Angeles','region' => 'Amérique du Nord'],
        'MST'  => ['name' => 'Mountain Standard Time',         'offset' => 'UTC-7',  'iana' => 'America/Denver',     'region' => 'Amérique du Nord'],
        'MDT'  => ['name' => 'Mountain Daylight Time',         'offset' => 'UTC-6',  'iana' => 'America/Denver',     'region' => 'Amérique du Nord'],
        'EST'  => ['name' => 'Eastern Standard Time',          'offset' => 'UTC-5',  'iana' => 'America/New_York',   'region' => 'Amérique du Nord'],
        'EDT'  => ['name' => 'Eastern Daylight Time',          'offset' => 'UTC-4',  'iana' => 'America/New_York',   'region' => 'Amérique du Nord'],
        'AKST' => ['name' => 'Alaska Standard Time',           'offset' => 'UTC-9',  'iana' => 'America/Anchorage',  'region' => 'Amérique du Nord'],
        'AKDT' => ['name' => 'Alaska Daylight Time',           'offset' => 'UTC-8',  'iana' => 'America/Anchorage',  'region' => 'Amérique du Nord'],
        'HST'  => ['name' => 'Hawaii Standard Time',           'offset' => 'UTC-10', 'iana' => 'Pacific/Honolulu',   'region' => 'Pacifique'],
        'BRT'  => ['name' => 'Brasília Time',                  'offset' => 'UTC-3',  'iana' => 'America/Sao_Paulo',  'region' => 'Amérique du Sud'],
        'ART'  => ['name' => 'Argentina Time',                 'offset' => 'UTC-3',  'iana' => 'America/Argentina/Buenos_Aires', 'region' => 'Amérique du Sud'],
        'AEST' => ['name' => 'Australian Eastern Standard Time','offset' => 'UTC+10', 'iana' => 'Australia/Sydney',  'region' => 'Océanie'],
        'AEDT' => ['name' => 'Australian Eastern Daylight Time','offset' => 'UTC+11', 'iana' => 'Australia/Sydney',  'region' => 'Océanie'],
        'ACST' => ['name' => 'Australian Central Standard Time','offset' => 'UTC+9:30','iana' => 'Australia/Adelaide','region' => 'Océanie'],
        'AWST' => ['name' => 'Australian Western Standard Time','offset' => 'UTC+8',  'iana' => 'Australia/Perth',   'region' => 'Océanie'],
        'NZST' => ['name' => 'New Zealand Standard Time',      'offset' => 'UTC+12', 'iana' => 'Pacific/Auckland',   'region' => 'Océanie'],
        'NZDT' => ['name' => 'New Zealand Daylight Time',      'offset' => 'UTC+13', 'iana' => 'Pacific/Auckland',   'region' => 'Océanie'],
        'WAT'  => ['name' => 'West Africa Time',               'offset' => 'UTC+1',  'iana' => 'Africa/Lagos',       'region' => 'Afrique'],
        'CAT'  => ['name' => 'Central Africa Time',            'offset' => 'UTC+2',  'iana' => 'Africa/Johannesburg','region' => 'Afrique'],
        'EAT'  => ['name' => 'East Africa Time',               'offset' => 'UTC+3',  'iana' => 'Africa/Nairobi',     'region' => 'Afrique'],
    ];

    private function handleTimezoneAbbrev(array $parsed, array $prefs): AgentResult
    {
        $query = strtoupper(trim($parsed['abbreviation'] ?? ''));

        // If no abbreviation given, show a list of common ones
        if ($query === '') {
            $userTz = $prefs['timezone'] ?? 'UTC';
            try {
                $now   = new DateTimeImmutable('now', new DateTimeZone($userTz));
                $abbr  = $now->format('T');
                $lines = [
                    "🔤 *ABRÉVIATIONS DE FUSEAUX HORAIRES*",
                    "────────────────",
                    "",
                    "📍 Ton fuseau ({$userTz}) : *{$abbr}*",
                    "",
                    "*Courants :*",
                ];
                $common = ['CET', 'GMT', 'EST', 'PST', 'JST', 'IST', 'CST', 'AEST', 'GST'];
                foreach ($common as $a) {
                    $info = self::TIMEZONE_ABBREVIATIONS[$a] ?? null;
                    if ($info) {
                        $lines[] = "• *{$a}* — {$info['name']} ({$info['offset']})";
                    }
                }
                $lines[] = "";
                $lines[] = "💡 _Tape ex: c'est quoi PST, que veut dire CET_";

                return AgentResult::reply(implode("\n", $lines), ['action' => 'timezone_abbrev', 'mode' => 'list']);
            } catch (\Exception $e) {
                Log::warning('UserPreferencesAgent: timezone_abbrev list error', ['error' => $e->getMessage()]);
                return AgentResult::reply("⚠️ Erreur lors de l'affichage des abréviations.");
            }
        }

        $info = self::TIMEZONE_ABBREVIATIONS[$query] ?? null;

        if (!$info) {
            // Fuzzy search: find abbreviations that contain the query
            $matches = [];
            foreach (self::TIMEZONE_ABBREVIATIONS as $abbr => $data) {
                if (str_contains($abbr, $query) || str_contains(strtoupper($data['name']), $query)) {
                    $matches[$abbr] = $data;
                }
            }

            if (empty($matches)) {
                return AgentResult::reply(
                    "⚠️ Abréviation *{$query}* non reconnue.\n\n"
                    . "💡 _Exemples : PST, CET, EST, JST, IST, GMT, AEST_\n"
                    . "_Tape *abréviations fuseaux* pour voir la liste complète._"
                );
            }

            $lines = ["🔍 *RÉSULTATS POUR \"{$query}\"*", "────────────────", ""];
            foreach (array_slice($matches, 0, 8) as $abbr => $data) {
                $lines[] = "• *{$abbr}* — {$data['name']}";
                $lines[] = "  {$data['offset']} · {$data['iana']} · {$data['region']}";
            }

            return AgentResult::reply(implode("\n", $lines), ['action' => 'timezone_abbrev', 'mode' => 'search']);
        }

        // Found exact match — show detailed info
        try {
            $tz  = new DateTimeZone($info['iana']);
            $now = new DateTimeImmutable('now', $tz);

            $userTz  = $prefs['timezone'] ?? 'UTC';
            $userNow = new DateTimeImmutable('now', new DateTimeZone($userTz));
            $diffH   = ((int) $now->format('Z') - (int) $userNow->format('Z')) / 3600;
            $diffStr = $diffH >= 0 ? "+{$diffH}h" : "{$diffH}h";

            $lines = [
                "🔤 *{$query}* — {$info['name']}",
                "────────────────",
                "",
                "📌 *Offset :* {$info['offset']}",
                "🌍 *Région :* {$info['region']}",
                "🏷 *Fuseau IANA :* {$info['iana']}",
                "🕐 *Heure actuelle :* {$now->format('H:i')} ({$now->format('l')})",
                "",
                "📍 Par rapport à toi ({$userTz}) : *{$diffStr}*",
                "",
                "💡 _Tape ex: heure à {$info['iana']}, compare timezone {$info['iana']}_",
            ];

            return AgentResult::reply(implode("\n", $lines), [
                'action'       => 'timezone_abbrev',
                'abbreviation' => $query,
                'iana'         => $info['iana'],
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: timezone_abbrev error', [
                'abbreviation' => $query,
                'error'        => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "🔤 *{$query}* — {$info['name']}\n"
                . "Offset : {$info['offset']} · Région : {$info['region']}\n"
                . "IANA : {$info['iana']}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // pomodoro — v1.20.0
    // -------------------------------------------------------------------------

    private function handlePomodoro(array $parsed, array $prefs): AgentResult
    {
        $sessions  = min(max((int) ($parsed['sessions'] ?? 4), 1), 12);
        $workMin   = min(max((int) ($parsed['work_minutes'] ?? 25), 5), 120);
        $breakMin  = min(max((int) ($parsed['break_minutes'] ?? 5), 1), 30);
        $longBreak = min(max((int) ($parsed['long_break_minutes'] ?? 15), 5), 60);

        $userTz = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTz);
            $now = new DateTimeImmutable('now', $tz);

            $lines = [
                "🍅 *PLANNING POMODORO*",
                "────────────────",
                "",
                "⚙️ *Config :* {$workMin}min travail / {$breakMin}min pause / {$longBreak}min longue pause",
                "📍 Début : *{$now->format('H:i')}* ({$userTz})",
                "",
            ];

            $cursor        = $now;
            $totalWork     = 0;
            $totalBreak    = 0;

            for ($i = 1; $i <= $sessions; $i++) {
                $workEnd = $cursor->modify("+{$workMin} minutes");
                $lines[] = "🍅 *Session {$i}* : {$cursor->format('H:i')} → {$workEnd->format('H:i')}  _{$workMin}min travail_";
                $totalWork += $workMin;

                if ($i < $sessions) {
                    // Every 4 sessions, take a long break
                    $isLong    = ($i % 4 === 0);
                    $brkLen    = $isLong ? $longBreak : $breakMin;
                    $brkLabel  = $isLong ? 'longue pause' : 'pause';
                    $breakEnd  = $workEnd->modify("+{$brkLen} minutes");
                    $lines[]   = "  ☕ _{$brkLabel} : {$workEnd->format('H:i')} → {$breakEnd->format('H:i')} ({$brkLen}min)_";
                    $totalBreak += $brkLen;
                    $cursor     = $breakEnd;
                } else {
                    $cursor = $workEnd;
                }
            }

            $totalMinutes = $totalWork + $totalBreak;
            $endTime      = $cursor->format('H:i');
            $totalHours   = intdiv($totalMinutes, 60);
            $totalMins    = $totalMinutes % 60;
            $durationStr  = $totalHours > 0
                ? "{$totalHours}h" . ($totalMins > 0 ? sprintf('%02d', $totalMins) : '')
                : "{$totalMins}min";

            $lines[] = "";
            $lines[] = "────────────────";
            $lines[] = "✅ *Fin prévue :* {$endTime}";
            $lines[] = "⏱ *Durée totale :* {$durationStr} ({$totalWork}min travail + {$totalBreak}min pauses)";
            $lines[] = "📊 *Sessions :* {$sessions} × {$workMin}min = " . ($totalWork >= 60 ? intdiv($totalWork, 60) . "h" . (($totalWork % 60) > 0 ? sprintf('%02d', $totalWork % 60) : '') : "{$totalWork}min") . " de focus";
            $lines[] = "";
            $lines[] = "💡 _Ex : pomodoro 6 sessions, pomodoro 50min travail 10min pause_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'   => 'pomodoro',
                'sessions' => $sessions,
                'work_min' => $workMin,
                'end_time' => $endTime,
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: pomodoro error', ['error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Erreur lors du calcul du planning Pomodoro.\n"
                . "_Vérifie ton fuseau horaire avec *mon profil*._"
            );
        }
    }

    // -------------------------------------------------------------------------
    // elapsed_time — v1.21.0
    // -------------------------------------------------------------------------

    private function handleElapsedTime(array $parsed, array $prefs): AgentResult
    {
        $lang = $prefs['language'] ?? 'fr';

        try {
            // Mode 1: Same-day (from_time / to_time)
            $fromTime = $parsed['from_time'] ?? null;
            $toTime   = $parsed['to_time'] ?? null;

            // Mode 2: Cross-day (from_datetime / to_datetime)
            $fromDt = $parsed['from_datetime'] ?? null;
            $toDt   = $parsed['to_datetime'] ?? null;

            if ($fromDt && $toDt) {
                // Cross-day mode
                $start = new DateTimeImmutable($fromDt);
                $end   = new DateTimeImmutable($toDt);
            } elseif ($fromTime && $toTime) {
                // Same-day mode
                $parsedFrom = $this->parseTimeString($fromTime);
                $parsedTo   = $this->parseTimeString($toTime);

                if (!$parsedFrom || !$parsedTo) {
                    return AgentResult::reply(
                        "⚠️ Format d'heure invalide. Utilise *HH:MM*, *14h30*, *2pm*, etc.\n\n"
                        . "_Exemples : durée entre 9h et 17h30, elapsed time 8:00 to 18:30_"
                    );
                }

                $tzName = $prefs['timezone'] ?? 'UTC';
                $tz     = new DateTimeZone($tzName);
                $today  = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
                $start  = new DateTimeImmutable("{$today} {$parsedFrom}", $tz);
                $end    = new DateTimeImmutable("{$today} {$parsedTo}", $tz);

                // If end is before start, assume next day
                if ($end <= $start) {
                    $end = $end->modify('+1 day');
                }
            } else {
                return AgentResult::reply(
                    "⚠️ Précise les deux heures à comparer.\n\n"
                    . "_Exemples :_\n"
                    . "• _durée entre 9h et 17h30_\n"
                    . "• _temps entre 2026-03-25 09:00 et 2026-03-27 17:30_"
                );
            }

            $interval    = $start->diff($end);
            $totalMinutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

            // Format duration
            $days  = $interval->days;
            $hours = $interval->h;
            $mins  = $interval->i;

            $parts = [];
            if ($days > 0) {
                $parts[] = "*{$days}* jour" . ($days > 1 ? 's' : '');
            }
            if ($hours > 0) {
                $parts[] = "*{$hours}* heure" . ($hours > 1 ? 's' : '');
            }
            if ($mins > 0) {
                $parts[] = "*{$mins}* minute" . ($mins > 1 ? 's' : '');
            }

            $durationStr = implode(', ', $parts) ?: '*0* minutes';

            // Compact format
            $compactParts = [];
            if ($days > 0) {
                $compactParts[] = "{$days}j";
            }
            $compactParts[] = sprintf('%02dh%02d', $hours, $mins);
            $compact = implode(' ', $compactParts);

            $lines = [
                "⏱ *DURÉE CALCULÉE*",
                "────────────────",
            ];

            if ($fromDt && $toDt) {
                $lines[] = "📍 De : *{$start->format('d/m/Y H:i')}*";
                $lines[] = "📍 À : *{$end->format('d/m/Y H:i')}*";
            } else {
                $lines[] = "📍 De *{$parsedFrom}* à *{$parsedTo}*";
            }

            $lines[] = "";
            $lines[] = "⏳ Durée : {$durationStr}";
            $lines[] = "🔢 Compact : *{$compact}*";
            $lines[] = "📊 Total : *{$totalMinutes}* minutes";

            // If > 60 minutes, show decimal hours too
            if ($totalMinutes >= 60) {
                $decimalHours = round($totalMinutes / 60, 2);
                $lines[] = "📈 Décimal : *{$decimalHours}h*";
            }

            $lines[] = "────────────────";
            $lines[] = "💡 _Ex : durée entre 8h et 18h30, temps entre 2026-04-01 09:00 et 2026-04-03 17:00_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'        => 'elapsed_time',
                'total_minutes' => $totalMinutes,
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: elapsed_time error', ['error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Erreur lors du calcul de la durée.\n"
                . "_Vérifie le format des heures : 9h, 14:30, 2pm, ou 2026-03-25 09:00._"
            );
        }
    }

    // -------------------------------------------------------------------------
    // focus_window — v1.21.0
    // -------------------------------------------------------------------------

    private function handleFocusWindow(array $parsed, array $prefs): AgentResult
    {
        $target = $parsed['target'] ?? '';
        $lang   = $prefs['language'] ?? 'fr';
        $userTz = $prefs['timezone'] ?? 'UTC';

        if (empty($target)) {
            return AgentResult::reply(
                "⚠️ Précise une ville ou un fuseau horaire.\n\n"
                . "_Exemples : focus window Tokyo, deep work New York, heures calmes Londres_"
            );
        }

        $targetTz = $this->resolveTimezoneString($target);
        if (!$targetTz) {
            $suggestion = $this->suggestTimezone($target);
            $extra      = $suggestion ? "\n💡 Suggestion : _{$suggestion}_" : '';
            return AgentResult::reply(
                "⚠️ Fuseau horaire non trouvé pour *{$target}*.{$extra}\n\n"
                . "_Exemples : focus window Tokyo, deep work America/New_York_"
            );
        }

        try {
            $userZone   = new DateTimeZone($userTz);
            $targetZone = new DateTimeZone($targetTz);
            $now        = new DateTimeImmutable('now', $userZone);

            // Build a 24-hour map of focus windows
            // Focus = hours where BOTH timezones are outside 9:00-18:00 (business hours)
            $focusSlots    = [];
            $earlySlots    = []; // Only user is off (target in business)
            $lateSlots     = []; // Only target is off (user in business)

            for ($h = 0; $h < 24; $h++) {
                $userTime   = $now->setTime($h, 0);
                $targetTime = $userTime->setTimezone($targetZone);

                $userHour   = (int) $userTime->format('G');
                $targetHour = (int) $targetTime->format('G');

                $userInBusiness   = ($userHour >= 9 && $userHour < 18);
                $targetInBusiness = ($targetHour >= 9 && $targetHour < 18);

                if (!$userInBusiness && !$targetInBusiness) {
                    $focusSlots[] = $h;
                } elseif (!$userInBusiness && $targetInBusiness) {
                    $earlySlots[] = $h;
                } elseif ($userInBusiness && !$targetInBusiness) {
                    $lateSlots[] = $h;
                }
            }

            // Format time ranges from consecutive hours
            $formatRanges = function (array $hours) use ($userTz, $targetTz, $userZone, $targetZone, $now): array {
                if (empty($hours)) {
                    return [];
                }

                $ranges = [];
                $start  = $hours[0];
                $prev   = $hours[0];

                for ($i = 1; $i <= count($hours); $i++) {
                    if ($i < count($hours) && $hours[$i] === $prev + 1) {
                        $prev = $hours[$i];
                        continue;
                    }

                    $startUser   = $now->setTime($start, 0);
                    $endUser     = $now->setTime($prev + 1, 0);
                    $startTarget = $startUser->setTimezone($targetZone);
                    $endTarget   = $endUser->setTimezone($targetZone);

                    $ranges[] = sprintf(
                        '%s–%s _(→ %s–%s %s)_',
                        $startUser->format('H:i'),
                        $endUser->format('H:i'),
                        $startTarget->format('H:i'),
                        $endTarget->format('H:i'),
                        $this->getShortTzName($targetTz)
                    );

                    if ($i < count($hours)) {
                        $start = $hours[$i];
                        $prev  = $hours[$i];
                    }
                }

                return $ranges;
            };

            // Get timezone offset difference
            $userOffset   = $userZone->getOffset($now) / 3600;
            $targetOffset = $targetZone->getOffset($now) / 3600;
            $diff         = $targetOffset - $userOffset;
            $diffStr      = ($diff >= 0 ? '+' : '') . $diff . 'h';

            $displayTarget = ucfirst($target);

            $lines = [
                "🎯 *FENÊTRE FOCUS* — {$displayTarget}",
                "────────────────",
                "🏠 Toi : *{$userTz}* (UTC" . ($userOffset >= 0 ? '+' : '') . "{$userOffset})",
                "🌍 Cible : *{$targetTz}* (UTC" . ($targetOffset >= 0 ? '+' : '') . "{$targetOffset})",
                "⏱ Décalage : *{$diffStr}*",
                "",
            ];

            // Mutual focus (both off work)
            if (!empty($focusSlots)) {
                $lines[] = "🟢 *Focus mutuel* (les 2 hors bureau) :";
                foreach ($formatRanges($focusSlots) as $range) {
                    $lines[] = "  • {$range}";
                }
            } else {
                $lines[] = "🔴 *Aucun créneau focus mutuel* — les heures de bureau se chevauchent complètement.";
            }

            $lines[] = "";

            // User off, target working
            if (!empty($earlySlots)) {
                $lines[] = "🟡 *Toi libre, {$displayTarget} au bureau* :";
                foreach ($formatRanges($earlySlots) as $range) {
                    $lines[] = "  • {$range}";
                }
                $lines[] = "";
            }

            // User working, target off
            if (!empty($lateSlots)) {
                $lines[] = "🟠 *Toi au bureau, {$displayTarget} libre* :";
                foreach ($formatRanges($lateSlots) as $range) {
                    $lines[] = "  • {$range}";
                }
                $lines[] = "";
            }

            $lines[] = "────────────────";
            $lines[] = "📌 _Heures de bureau : 9h–18h lun–ven_";
            $lines[] = "💡 _Ex : focus window London, deep work Singapore_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'            => 'focus_window',
                'target'            => $targetTz,
                'focus_hours_count' => count($focusSlots),
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: focus_window error', ['error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Erreur lors du calcul des créneaux focus.\n"
                . "_Vérifie ton fuseau horaire avec *mon profil*._"
            );
        }
    }

    // -------------------------------------------------------------------------
    // time_add — v1.22.0
    // -------------------------------------------------------------------------

    private function handleTimeAdd(array $parsed, array $prefs): AgentResult
    {
        $durationStr = trim($parsed['duration'] ?? '');
        $fromTime    = $parsed['from_time'] ?? null;
        $target      = $parsed['target'] ?? '';
        $userTz      = $prefs['timezone'] ?? 'UTC';
        $lang        = $prefs['language'] ?? 'fr';
        $dateFmt     = $prefs['date_format'] ?? 'd/m/Y';

        if (empty($durationStr)) {
            return AgentResult::reply(
                "⚠️ Précise une durée.\n\n"
                . "_Exemples : dans 2h30, timer 45min, heure + 1h30_"
            );
        }

        // Parse duration string into total minutes
        $totalMinutes = $this->parseDurationToMinutes($durationStr);
        if ($totalMinutes === null || $totalMinutes <= 0) {
            return AgentResult::reply(
                "⚠️ Durée invalide : *{$durationStr}*\n\n"
                . "_Formats acceptés : 2h, 2h30, 45min, 90min, 1:30, 2.5h_"
            );
        }

        try {
            $userZone = new DateTimeZone($userTz);
            $now      = new DateTimeImmutable('now', $userZone);

            // Determine start time
            if ($fromTime) {
                $parsedTime = $this->parseTimeString($fromTime);
                if (!$parsedTime) {
                    return AgentResult::reply("⚠️ Heure de départ invalide : *{$fromTime}*\n_Formats : 14:30, 14h30, 2pm_");
                }
                [$h, $m] = explode(':', $parsedTime);
                $start = $now->setTime((int) $h, (int) $m);
            } else {
                $start = $now;
            }

            $end = $start->modify("+{$totalMinutes} minutes");

            $durationH = intdiv($totalMinutes, 60);
            $durationM = $totalMinutes % 60;
            $durationDisplay = $durationH > 0
                ? ($durationM > 0 ? sprintf('%dh%02d', $durationH, $durationM) : "{$durationH}h")
                : "{$durationM}min";

            $dayName    = $this->getDayName((int) $end->format('w'), $lang, short: true);
            $isNextDay  = $end->format('Y-m-d') !== $start->format('Y-m-d');
            $dayNote    = $isNextDay ? " _{$dayName} " . $end->format($dateFmt) . "_" : '';

            $lines = [
                "⏱ *CALCUL DE DURÉE*",
                "────────────────",
                "🕐 Départ : *{$start->format('H:i')}* ({$userTz})",
                "➕ Durée : *{$durationDisplay}*",
                "🏁 Arrivée : *{$end->format('H:i')}*{$dayNote}",
            ];

            // If target city requested, show time there too
            if (!empty($target)) {
                $targetTz = $this->resolveTimezoneString($target);
                if ($targetTz) {
                    $targetZone = new DateTimeZone($targetTz);
                    $endTarget  = $end->setTimezone($targetZone);
                    $targetDay  = $this->getDayName((int) $endTarget->format('w'), $lang, short: true);
                    $lines[]    = "🌍 À *" . ucfirst($target) . "* : *{$endTarget->format('H:i')}* ({$targetDay})";
                } else {
                    $lines[] = "⚠️ Ville non reconnue : _{$target}_";
                }
            }

            $lines[] = "────────────────";
            $lines[] = "💡 _Ex : dans 3h, timer 45min, 14h + 2h30, dans 1h à Tokyo_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'        => 'time_add',
                'duration_min'  => $totalMinutes,
                'start'         => $start->format('H:i'),
                'end'           => $end->format('H:i'),
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: time_add error', ['error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Erreur lors du calcul.\n_Vérifie ton fuseau avec *mon profil*._"
            );
        }
    }

    /**
     * Parse a duration string into total minutes.
     * Accepts: 2h, 2h30, 45min, 90min, 1:30, 2.5h
     */
    private function parseDurationToMinutes(string $duration): ?int
    {
        $duration = trim(mb_strtolower($duration));

        // Format: 2h30, 1h45, 0h15
        if (preg_match('/^(\d+)h(\d{1,2})$/i', $duration, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }

        // Format: 2h, 3h (hours only)
        if (preg_match('/^(\d+)h$/i', $duration, $m)) {
            return ((int) $m[1]) * 60;
        }

        // Format: 45min, 90min
        if (preg_match('/^(\d+)\s*min$/i', $duration, $m)) {
            return (int) $m[1];
        }

        // Format: 1:30, 2:45 (H:MM)
        if (preg_match('/^(\d+):(\d{2})$/', $duration, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }

        // Format: 2.5h, 1.25h (decimal hours)
        if (preg_match('/^(\d+(?:\.\d+)?)h$/i', $duration, $m)) {
            return (int) round((float) $m[1] * 60);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // meeting_suggest — v1.22.0
    // -------------------------------------------------------------------------

    private function handleMeetingSuggest(array $parsed, array $prefs): AgentResult
    {
        $cities       = $parsed['cities'] ?? [];
        $durationH    = min((int) ($parsed['duration_hours'] ?? 1), 4);
        $userTz       = $prefs['timezone'] ?? 'UTC';
        $lang         = $prefs['language'] ?? 'fr';

        if (empty($cities) || !is_array($cities)) {
            return AgentResult::reply(
                "⚠️ Précise au moins une ville.\n\n"
                . "_Exemples : meilleur horaire réunion Tokyo Paris, suggest meeting London Dubai Sydney_"
            );
        }

        if ($durationH < 1) {
            $durationH = 1;
        }

        try {
            // Resolve all timezones (user + cities)
            $zones = [['name' => 'Toi', 'tz' => $userTz, 'zone' => new DateTimeZone($userTz)]];

            foreach ($cities as $city) {
                $resolved = $this->resolveTimezoneString(trim($city));
                if (!$resolved) {
                    return AgentResult::reply("⚠️ Ville non reconnue : *{$city}*\n_Vérifie l'orthographe ou utilise un fuseau IANA._");
                }
                $zones[] = ['name' => ucfirst(trim($city)), 'tz' => $resolved, 'zone' => new DateTimeZone($resolved)];
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            // Score each hour slot (0-23 UTC) for business compatibility
            $slots = [];
            for ($utcHour = 0; $utcHour < 24; $utcHour++) {
                $testTime = $now->setTime($utcHour, 0);
                $score    = 0;
                $details  = [];

                foreach ($zones as $z) {
                    $local = $testTime->setTimezone($z['zone']);
                    $h     = (int) $local->format('G');
                    $dow   = (int) $local->format('N'); // 1=Mon, 7=Sun

                    // Score based on how "comfortable" the hour is
                    if ($h >= 9 && $h < 18 && $dow <= 5) {
                        // Core business hours = high score
                        $score += ($h >= 10 && $h < 17) ? 10 : 8;
                    } elseif (($h >= 8 && $h < 9) || ($h >= 18 && $h < 19)) {
                        // Early morning / just after work = acceptable
                        $score += 4;
                    } elseif (($h >= 7 && $h < 8) || ($h >= 19 && $h < 21)) {
                        // Stretch hours = low score
                        $score += 2;
                    } else {
                        // Night hours = penalty
                        $score += 0;
                    }

                    // Check duration compatibility (all hours in the block should be decent)
                    if ($durationH > 1) {
                        for ($d = 1; $d < $durationH; $d++) {
                            $laterH = ($h + $d) % 24;
                            if ($laterH >= 9 && $laterH < 18) {
                                $score += 3;
                            } elseif ($laterH >= 7 && $laterH < 21) {
                                $score += 1;
                            }
                        }
                    }

                    $details[] = ['name' => $z['name'], 'hour' => $local->format('H:i')];
                }

                $slots[] = [
                    'utc_hour' => $utcHour,
                    'score'    => $score,
                    'details'  => $details,
                ];
            }

            // Sort by score descending, take top 3
            usort($slots, fn($a, $b) => $b['score'] <=> $a['score']);
            $topSlots = array_slice($slots, 0, 3);

            $maxScore = $topSlots[0]['score'] ?? 1;

            $lines = [
                "🏆 *MEILLEURS CRÉNEAUX DE RÉUNION*",
                "────────────────",
                "👥 Participants : *" . implode(', ', array_map(fn($z) => $z['name'], $zones)) . "*",
                "⏱ Durée : *{$durationH}h*",
                "",
            ];

            $medals = ['🥇', '🥈', '🥉'];
            foreach ($topSlots as $i => $slot) {
                $medal      = $medals[$i] ?? '•';
                $pct        = $maxScore > 0 ? (int) round(($slot['score'] / $maxScore) * 100) : 0;
                $bar        = str_repeat('█', (int) round($pct / 10)) . str_repeat('░', 10 - (int) round($pct / 10));
                $cityTimes  = array_map(fn($d) => "*{$d['name']}* {$d['hour']}", $slot['details']);

                $lines[] = "{$medal} *Créneau " . ($i + 1) . "* — Score : {$bar} {$pct}%";
                $lines[] = "   " . implode(' · ', $cityTimes);

                if ($i < count($topSlots) - 1) {
                    $lines[] = "";
                }
            }

            $lines[] = "────────────────";
            $lines[] = "📌 _Score basé sur heures ouvrables 9h–18h lun–ven_";
            $lines[] = "💡 _Ex : suggest meeting Tokyo London, meilleur créneau 2h Paris Dubai_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'    => 'meeting_suggest',
                'cities'    => array_map(fn($z) => $z['tz'], $zones),
                'top_score' => $topSlots[0]['score'] ?? 0,
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: meeting_suggest error', ['error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Erreur lors de la suggestion de créneaux.\n"
                . "_Vérifie les noms de villes et ton fuseau avec *mon profil*._"
            );
        }
    }

    // -------------------------------------------------------------------------
    // timezone_summary — v1.23.0
    // -------------------------------------------------------------------------

    private function handleTimezoneSummary(array $parsed, array $prefs): AgentResult
    {
        $target  = trim($parsed['target'] ?? '');
        $userTz  = $prefs['timezone'] ?? 'UTC';
        $lang    = $prefs['language'] ?? 'fr';
        $dateFmt = $prefs['date_format'] ?? 'd/m/Y';

        try {
            $tzName = !empty($target) ? $this->resolveTimezoneString($target) : $userTz;
            if (!$tzName) {
                return AgentResult::reply(
                    "⚠️ Fuseau non reconnu : *{$target}*\n\n"
                    . "_Exemples : résumé fuseau Tokyo, timezone summary New York, infos fuseau Europe/Paris_"
                );
            }

            $zone = new DateTimeZone($tzName);
            $now  = new DateTimeImmutable('now', $zone);
            $utc  = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $offset    = $zone->getOffset($now);
            $offsetH   = intdiv($offset, 3600);
            $offsetM   = (int) abs(($offset % 3600) / 60);
            $offsetStr = sprintf('UTC%+d', $offsetH) . ($offsetM > 0 ? sprintf(':%02d', $offsetM) : '');

            // DST detection
            $isDst  = (bool) $now->format('I');
            $dstLabel = $isDst ? '☀️ Heure d\'été (DST actif)' : '❄️ Heure d\'hiver (DST inactif)';
            $jan    = new DateTimeImmutable('January 15', $zone);
            $jul    = new DateTimeImmutable('July 15', $zone);
            $hasDst = $zone->getOffset($jan) !== $zone->getOffset($jul);

            // Find next DST transition
            $transitions    = $zone->getTransitions((int) $now->format('U'), (int) $now->modify('+1 year')->format('U'));
            $nextTransition = null;
            foreach ($transitions as $i => $t) {
                if ($i === 0) continue;
                $nextTransition = $t;
                break;
            }

            // Find cities with same offset
            $sameCities = [];
            foreach (self::CITY_TIMEZONE_MAP as $city => $ianaTz) {
                if ($ianaTz === $tzName) continue;
                try {
                    $cityZone = new DateTimeZone($ianaTz);
                    if ($cityZone->getOffset($utc) === $offset && count($sameCities) < 5) {
                        $sameCities[] = ucfirst($city);
                    }
                } catch (\Exception) {}
            }

            $dayName = $this->getDayName((int) $now->format('w'), $lang);
            $h       = (int) $now->format('G');
            $period  = match (true) {
                $h >= 6 && $h < 12  => '🌅 Matin',
                $h >= 12 && $h < 14 => '☀️ Midi',
                $h >= 14 && $h < 18 => '🌤️ Après-midi',
                $h >= 18 && $h < 22 => '🌆 Soirée',
                default             => '🌙 Nuit',
            };

            $isBusinessHours = $h >= 9 && $h < 18 && (int) $now->format('N') <= 5;
            $businessIcon    = $isBusinessHours ? '🟢 Ouvert (heures de bureau)' : '🔴 Fermé (hors horaires)';

            $displayName = !empty($target) ? ucfirst($target) : $this->getShortTzName($tzName);

            $lines = [
                "🌐 *RÉSUMÉ FUSEAU — {$displayName}*",
                "────────────────",
                "🕐 Heure locale : *{$now->format('H:i:s')}* — {$dayName} {$now->format($dateFmt)}",
                "📍 Fuseau : *{$tzName}* ({$offsetStr})",
                "🕰️ Période : {$period}",
                "🏢 Bureau : {$businessIcon}",
            ];

            if ($hasDst) {
                $lines[] = "🔄 DST : {$dstLabel}";
                if ($nextTransition) {
                    $transDate = new DateTimeImmutable('@' . $nextTransition['ts']);
                    $transDate = $transDate->setTimezone($zone);
                    $daysUntil = (int) $now->diff($transDate)->days;
                    $lines[]   = "📅 Prochain changement : *{$transDate->format($dateFmt)}* (dans {$daysUntil}j)";
                }
            } else {
                $lines[] = "🔄 DST : _Pas de changement d'heure dans ce fuseau_";
            }

            if (!empty($sameCities)) {
                $lines[] = "🌍 Même offset : " . implode(', ', $sameCities);
            }

            // Show difference from user's timezone if looking at a different one
            if (!empty($target) && $tzName !== $userTz) {
                try {
                    $userZone   = new DateTimeZone($userTz);
                    $userOffset = $userZone->getOffset($now);
                    $diffH      = ($offset - $userOffset) / 3600;
                    $diffStr    = $diffH >= 0 ? "+{$diffH}h" : "{$diffH}h";
                    $userNow    = $now->setTimezone($userZone);
                    $lines[]    = "⏱ Décalage vs toi : *{$diffStr}* (chez toi : {$userNow->format('H:i')})";
                } catch (\Exception) {}
            }

            $lines[] = "────────────────";
            $lines[] = "💡 _Ex : résumé fuseau Tokyo, timezone summary, infos fuseau London_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'   => 'timezone_summary',
                'timezone' => $tzName,
                'offset'   => $offsetStr,
                'has_dst'  => $hasDst,
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: timezone_summary error', ['error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Erreur lors du résumé du fuseau.\n_Vérifie le nom de la ville ou tape *mon profil*._"
            );
        }
    }

    // -------------------------------------------------------------------------
    // date_range — v1.23.0
    // -------------------------------------------------------------------------

    private function handleDateRange(array $parsed, array $prefs): AgentResult
    {
        $fromStr   = trim($parsed['from_date'] ?? '');
        $toStr     = trim($parsed['to_date'] ?? '');
        $dayFilter = mb_strtolower(trim($parsed['day_filter'] ?? ''));
        $lang      = $prefs['language'] ?? 'fr';
        $dateFmt   = $prefs['date_format'] ?? 'd/m/Y';
        $userTz    = $prefs['timezone'] ?? 'UTC';

        if (empty($fromStr) || empty($toStr)) {
            return AgentResult::reply(
                "⚠️ Précise une date de début et une date de fin.\n\n"
                . "_Exemples : tous les lundis entre 2026-04-01 et 2026-06-30, dates entre 2026-05-01 et 2026-05-31_"
            );
        }

        try {
            $zone = new DateTimeZone($userTz);
            $from = new DateTimeImmutable($fromStr === 'today' ? 'today' : $fromStr, $zone);
            $to   = new DateTimeImmutable($toStr === 'today' ? 'today' : $toStr, $zone);

            if ($to < $from) {
                return AgentResult::reply("⚠️ La date de fin doit être après la date de début.");
            }

            $diffDays = (int) $from->diff($to)->days;
            if ($diffDays > 366) {
                return AgentResult::reply("⚠️ Plage trop large (max 1 an / 366 jours). Réduis l'intervalle.");
            }

            // Map day filter to PHP day number (1=Mon, 7=Sun)
            $dayMap = [
                'monday' => 1, 'lundi' => 1,
                'tuesday' => 2, 'mardi' => 2,
                'wednesday' => 3, 'mercredi' => 3,
                'thursday' => 4, 'jeudi' => 4,
                'friday' => 5, 'vendredi' => 5,
                'saturday' => 6, 'samedi' => 6,
                'sunday' => 7, 'dimanche' => 7,
            ];

            $filterDow = null;
            if (!empty($dayFilter) && isset($dayMap[$dayFilter])) {
                $filterDow = $dayMap[$dayFilter];
            } elseif (!empty($dayFilter)) {
                return AgentResult::reply(
                    "⚠️ Jour invalide : *{$dayFilter}*\n\n"
                    . "_Jours acceptés : monday/lundi, tuesday/mardi, wednesday/mercredi, thursday/jeudi, friday/vendredi, saturday/samedi, sunday/dimanche_"
                );
            }

            $dates   = [];
            $current = $from;
            while ($current <= $to) {
                $dow = (int) $current->format('N');
                if ($filterDow === null || $dow === $filterDow) {
                    $dates[] = $current;
                }
                $current = $current->modify('+1 day');
            }

            if (empty($dates)) {
                $dayLabel = $dayFilter ? " ({$dayFilter})" : '';
                return AgentResult::reply("📅 Aucune date trouvée{$dayLabel} entre le {$from->format($dateFmt)} et le {$to->format($dateFmt)}.");
            }

            // Limit display to 52 entries max
            $total     = count($dates);
            $truncated = $total > 52;
            $display   = $truncated ? array_slice($dates, 0, 52) : $dates;

            $filterLabel = $filterDow !== null
                ? ' — ' . ucfirst($dayFilter) . 's'
                : '';

            $lines = [
                "📅 *PLAGE DE DATES*{$filterLabel}",
                "────────────────",
                "📍 Du *{$from->format($dateFmt)}* au *{$to->format($dateFmt)}*",
                "📊 Total : *{$total}* date(s)",
                "",
            ];

            // Group by month for better readability
            $byMonth = [];
            foreach ($display as $d) {
                $monthKey = $d->format('Y-m');
                $byMonth[$monthKey][] = $d;
            }

            foreach ($byMonth as $monthKey => $monthDates) {
                $monthDate = new DateTimeImmutable($monthKey . '-01', $zone);
                $monthName = $this->getMonthName((int) $monthDate->format('n'), $lang);
                $lines[]   = "*{$monthName} {$monthDate->format('Y')}* :";

                $dateParts = [];
                foreach ($monthDates as $d) {
                    $dayName     = $this->getDayName((int) $d->format('w'), $lang, short: true);
                    $dateParts[] = "{$dayName} {$d->format($dateFmt)}";
                }
                $lines[] = implode(' · ', $dateParts);
                $lines[] = "";
            }

            if ($truncated) {
                $lines[] = "⚠️ _Affichage limité aux 52 premières dates sur {$total}_";
            }

            $lines[] = "────────────────";
            $lines[] = "💡 _Ex : tous les lundis entre 2026-04-01 et 2026-06-30, dates de mai 2026_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'     => 'date_range',
                'from'       => $from->format('Y-m-d'),
                'to'         => $to->format('Y-m-d'),
                'day_filter' => $dayFilter ?: null,
                'count'      => $total,
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: date_range error', ['error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Dates invalides. Utilise le format AAAA-MM-JJ.\n\n"
                . "_Exemples : tous les lundis entre 2026-04-01 et 2026-06-30_"
            );
        }
    }


    // -------------------------------------------------------------------------
    // timezone_overlap — Visual business hours overlap between two timezones
    // -------------------------------------------------------------------------

    private function handleTimezoneOverlap(array $parsed, array $prefs): AgentResult
    {
        $target = trim($parsed['target'] ?? '');
        if ($target === '') {
            return AgentResult::reply(
                "⚠️ Précise une ville ou un fuseau horaire.\n\n"
                . "_Exemple : overlap Tokyo, overlap New York_"
            );
        }

        try {
            $userTzName   = $prefs['timezone'] ?? 'UTC';
            $resolvedUser = $this->resolveTimezoneString($userTzName) ?? 'UTC';
            $resolvedTgt  = $this->resolveTimezoneString($target);
            if (!$resolvedTgt) {
                return AgentResult::reply(
                    "⚠️ Fuseau inconnu : *{$target}*.\n\n_Exemples : overlap Tokyo, overlap New York, overlap Europe/London_",
                    ['action' => 'timezone_overlap', 'error' => 'unknown_target']
                );
            }
            $userTz   = new DateTimeZone($resolvedUser);
            $targetTz = new DateTimeZone($resolvedTgt);

            $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $userNow   = $now->setTimezone($userTz);
            $targetNow = $now->setTimezone($targetTz);

            $userOffset   = $userTz->getOffset($now) / 3600;
            $targetOffset = $targetTz->getOffset($now) / 3600;
            $diff         = $targetOffset - $userOffset;

            // Calculate business hours overlap (9h-18h)
            $userStart   = 9;
            $userEnd     = 18;
            $targetStart = 9 - $diff;
            $targetEnd   = 18 - $diff;

            $overlapStart = max($userStart, $targetStart);
            $overlapEnd   = min($userEnd, $targetEnd);
            $overlapHours = max(0, $overlapEnd - $overlapStart);

            $overlapPercent = round(($overlapHours / 9) * 100);

            $userShort   = $this->getShortTzName($userTz->getName());
            $targetShort = $this->getShortTzName($targetTz->getName());

            $lines = [
                "📊 *OVERLAP HORAIRE*",
                "────────────────",
                "📍 Toi : *{$userShort}* (UTC" . ($userOffset >= 0 ? '+' : '') . "{$userOffset})",
                "📍 Cible : *{$targetShort}* (UTC" . ($targetOffset >= 0 ? '+' : '') . "{$targetOffset})",
                "⏱ Décalage : *" . ($diff >= 0 ? '+' : '') . "{$diff}h*",
                "",
            ];

            // Visual timeline bar (24h, user perspective)
            $lines[] = "*Timeline (heures chez toi) :*";
            $bar = '';
            for ($h = 0; $h < 24; $h++) {
                $userBiz   = ($h >= 9 && $h < 18);
                $targetH   = $h + $diff;
                if ($targetH < 0) {
                    $targetH += 24;
                }
                if ($targetH >= 24) {
                    $targetH -= 24;
                }
                $targetBiz = ($targetH >= 9 && $targetH < 18);

                if ($userBiz && $targetBiz) {
                    $bar .= '🟩';
                } elseif ($userBiz) {
                    $bar .= '🟦';
                } elseif ($targetBiz) {
                    $bar .= '🟨';
                } else {
                    $bar .= '⬜';
                }
            }

            $lines[] = "`0h        12h       23h`";
            $lines[] = $bar;
            $lines[] = "";
            $lines[] = "🟩 = Les deux au bureau";
            $lines[] = "🟦 = Toi uniquement";
            $lines[] = "🟨 = {$targetShort} uniquement";
            $lines[] = "⬜ = Aucun";
            $lines[] = "";

            if ($overlapHours > 0) {
                $lines[] = "✅ *{$overlapHours}h d'overlap* ({$overlapPercent}%) — de *" . sprintf('%02d:00', (int) $overlapStart) . "* à *" . sprintf('%02d:00', (int) $overlapEnd) . "* (ton heure)";
            } else {
                $lines[] = "❌ *Aucun overlap* pendant les heures de bureau.";
                $lines[] = "💡 _Utilise meeting_suggest pour trouver le meilleur créneau possible._";
            }

            $lines[] = "";
            $lines[] = "🕐 Maintenant : *{$userNow->format('H:i')}* chez toi · *{$targetNow->format('H:i')}* à {$targetShort}";
            $lines[] = "────────────────";
            $lines[] = "💡 _Ex : overlap London, overlap Dubai, overlap America/New_York_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'          => 'timezone_overlap',
                'target'          => $target,
                'overlap_hours'   => $overlapHours,
                'overlap_percent' => $overlapPercent,
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: timezone_overlap error', ['target' => $target, 'error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Impossible de calculer l'overlap pour *{$target}*.\n"
                . "Vérifie le nom de la ville ou du fuseau.\n\n"
                . "_Exemples : overlap Tokyo, overlap New York_"
            );
        }
    }

    // -------------------------------------------------------------------------
    // sleep_schedule — Jet lag recovery planner with sleep recommendations
    // -------------------------------------------------------------------------

    private function handleSleepSchedule(array $parsed, array $prefs): AgentResult
    {
        $from = trim($parsed['from'] ?? '');
        $to   = trim($parsed['to'] ?? '');

        if ($from === '' || $to === '') {
            return AgentResult::reply(
                "⚠️ Précise les villes de départ et d'arrivée.\n\n"
                . "_Exemple : sleep schedule Paris Tokyo, adaptation horaire New York London_"
            );
        }

        try {
            $resolvedFrom = $this->resolveTimezoneString($from);
            $resolvedTo   = $this->resolveTimezoneString($to);
            if (!$resolvedFrom || !$resolvedTo) {
                $bad = !$resolvedFrom ? $from : $to;
                return AgentResult::reply(
                    "⚠️ Ville inconnue : *{$bad}*.\n\n_Exemple : sleep schedule Paris Tokyo, adaptation horaire New York London_",
                    ['action' => 'sleep_schedule', 'error' => 'unknown_city']
                );
            }
            $fromTz = new DateTimeZone($resolvedFrom);
            $toTz   = new DateTimeZone($resolvedTo);

            $now        = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $fromOffset = $fromTz->getOffset($now) / 3600;
            $toOffset   = $toTz->getOffset($now) / 3600;
            $diff       = $toOffset - $fromOffset;
            $absDiff    = abs($diff);

            $fromShort = $this->getShortTzName($fromTz->getName());
            $toShort   = $this->getShortTzName($toTz->getName());

            $recoveryDays = (int) ceil($absDiff);
            $direction    = $diff > 0 ? 'est (avancer)' : 'ouest (reculer)';

            $lines = [
                "😴 *PLANNING ADAPTATION HORAIRE*",
                "────────────────",
                "✈️ *{$fromShort}* → *{$toShort}*",
                "⏱ Décalage : *" . ($diff >= 0 ? '+' : '') . "{$diff}h* (direction {$direction})",
                "📅 Adaptation estimée : *~{$recoveryDays} jour" . ($recoveryDays > 1 ? 's' : '') . "*",
                "",
            ];

            if ($absDiff < 3) {
                $lines[] = "✅ Décalage léger — adaptation rapide !";
                $lines[] = "";
                $lines[] = "*Conseils :*";
                $lines[] = "• Adopte immédiatement l'heure locale à l'arrivée";
                $lines[] = "• Expose-toi à la lumière naturelle le matin";
                $lines[] = "• Évite les siestes longues (max 20 min)";
            } else {
                $lines[] = "*Planning jour par jour :*";
                $lines[] = "";

                $baseBedtime   = 23.0;
                $baseWakeup    = 7.0;
                $targetBedtime = $baseBedtime - $diff;
                $targetWakeup  = $baseWakeup - $diff;

                while ($targetBedtime < 0) {
                    $targetBedtime += 24;
                }
                while ($targetBedtime >= 24) {
                    $targetBedtime -= 24;
                }
                while ($targetWakeup < 0) {
                    $targetWakeup += 24;
                }
                while ($targetWakeup >= 24) {
                    $targetWakeup -= 24;
                }

                $maxDays = min($recoveryDays + 1, 7);

                for ($day = 0; $day <= $maxDays; $day++) {
                    $progress = min(1.0, $day / max(1, $recoveryDays));
                    $bedH     = $baseBedtime + ($targetBedtime - $baseBedtime) * $progress;
                    $wakeH    = $baseWakeup + ($targetWakeup - $baseWakeup) * $progress;

                    while ($bedH < 0) {
                        $bedH += 24;
                    }
                    while ($bedH >= 24) {
                        $bedH -= 24;
                    }
                    while ($wakeH < 0) {
                        $wakeH += 24;
                    }
                    while ($wakeH >= 24) {
                        $wakeH -= 24;
                    }

                    $bedStr  = sprintf('%02d:%02d', (int) $bedH, (int) (($bedH - (int) $bedH) * 60));
                    $wakeStr = sprintf('%02d:%02d', (int) $wakeH, (int) (($wakeH - (int) $wakeH) * 60));

                    $progressBar = str_repeat('▓', (int) ($progress * 10)) . str_repeat('░', 10 - (int) ($progress * 10));

                    if ($day === 0) {
                        $lines[] = "📍 *Arrivée* : coucher *{$bedStr}* · réveil *{$wakeStr}* (heure locale)";
                    } else {
                        $pct = (int) ($progress * 100);
                        $lines[] = "📅 *J+{$day}* : coucher *{$bedStr}* · réveil *{$wakeStr}* [{$progressBar}] {$pct}%";
                    }
                }

                $lines[] = "";
                $lines[] = "*Conseils clés :*";

                if ($diff > 0) {
                    $lines[] = "• ☀️ Lumière vive le *matin* pour avancer ton horloge";
                    $lines[] = "• 🕶 Évite la lumière forte le *soir*";
                    $lines[] = "• 💊 Mélatonine possible le soir (consulte un médecin)";
                } else {
                    $lines[] = "• ☀️ Lumière vive en *fin d'après-midi* pour retarder ton horloge";
                    $lines[] = "• 🕶 Évite la lumière forte le *matin*";
                    $lines[] = "• ☕ Caféine modérée le matin uniquement";
                }

                $lines[] = "• 💧 Hydrate-toi bien pendant le vol";
                $lines[] = "• 🚫 Évite l'alcool et les écrans 2h avant le coucher";
            }

            $lines[] = "";
            $lines[] = "────────────────";
            $lines[] = "💡 _Ex : sleep schedule Paris Tokyo, adaptation New York London_";

            return AgentResult::reply(implode("\n", $lines), [
                'action'        => 'sleep_schedule',
                'from'          => $from,
                'to'            => $to,
                'offset_hours'  => $diff,
                'recovery_days' => $recoveryDays,
            ]);
        } catch (\Exception $e) {
            Log::warning('UserPreferencesAgent: sleep_schedule error', ['from' => $from, 'to' => $to, 'error' => $e->getMessage()]);
            return AgentResult::reply(
                "⚠️ Impossible de calculer le planning d'adaptation.\n"
                . "Vérifie les noms de villes.\n\n"
                . "_Exemple : sleep schedule Paris Tokyo_"
            );
        }
    }

    // -------------------------------------------------------------------------
    // v1.29.0 — month_progress & alarm_time
    // -------------------------------------------------------------------------

    private function handleMonthProgress(array $prefs): AgentResult
    {
        $userTz    = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now       = new DateTimeImmutable('now', $userTz);
        $lang      = $prefs['language'] ?? 'fr';

        $dayOfMonth  = (int) $now->format('j');
        $daysInMonth = (int) $now->format('t');
        $remaining   = $daysInMonth - $dayOfMonth;
        $progress    = $dayOfMonth / $daysInMonth;
        $pct         = (int) round($progress * 100);

        $monthName   = $this->getMonthName((int) $now->format('n'), $lang);
        $year        = $now->format('Y');

        // Progress bar (20 chars)
        $filled = (int) round($progress * 20);
        $bar    = str_repeat('▓', $filled) . str_repeat('░', 20 - $filled);

        // Working days in month
        $firstDay      = new DateTimeImmutable($now->format('Y-m-01'), $userTz);
        $totalWorkDays = 0;
        $elapsedWork   = 0;
        for ($d = 0; $d < $daysInMonth; $d++) {
            $date = $firstDay->modify("+{$d} days");
            if ((int) $date->format('N') <= 5) {
                $totalWorkDays++;
                if ((int) $date->format('j') <= $dayOfMonth) {
                    $elapsedWork++;
                }
            }
        }
        $remainingWork = $totalWorkDays - $elapsedWork;

        // Weekends remaining
        $weekendsRemaining = 0;
        for ($d = $dayOfMonth; $d < $daysInMonth; $d++) {
            $date = $firstDay->modify("+{$d} days");
            if ((int) $date->format('N') >= 6) {
                $weekendsRemaining++;
            }
        }
        $weekendsRemaining = (int) ceil($weekendsRemaining / 2);

        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
        $lastDay    = new DateTimeImmutable($now->format('Y-m-t'), $userTz);

        $titleLabel     = match ($lang) { 'en' => 'MONTH PROGRESS', 'es' => 'PROGRESO DEL MES', 'de' => 'MONATSFORTSCHRITT', default => 'PROGRESSION DU MOIS' };
        $dayLabel       = match ($lang) { 'en' => 'Day', 'es' => 'Día', 'de' => 'Tag', default => 'Jour' };
        $remainLabel    = match ($lang) { 'en' => 'Days remaining', 'es' => 'Días restantes', 'de' => 'Verbleibende Tage', default => 'Jours restants' };
        $workLabel      = match ($lang) { 'en' => 'Workdays', 'es' => 'Días laborables', 'de' => 'Arbeitstage', default => 'Jours ouvrés' };
        $passedStr      = match ($lang) { 'en' => 'elapsed', 'es' => 'pasados', 'de' => 'vergangen', default => 'passés' };
        $remainingStr   = match ($lang) { 'en' => 'remaining', 'es' => 'restantes', 'de' => 'verbleibend', default => 'restants' };
        $weekendLabel   = match ($lang) { 'en' => 'Weekends remaining', 'es' => 'Fines de semana restantes', 'de' => 'Verbleibende Wochenenden', default => 'Week-ends restants' };
        $todayLabel     = match ($lang) { 'en' => 'Today', 'es' => 'Hoy', 'de' => 'Heute', default => "Aujourd'hui" };
        $endLabel       = match ($lang) { 'en' => 'End of month', 'es' => 'Fin de mes', 'de' => 'Monatsende', default => 'Fin du mois' };

        $lines = [
            "📅 *{$titleLabel} — {$monthName} {$year}*",
            "────────────────",
            "",
            "[{$bar}] *{$pct}%*",
            "",
            "📆 {$dayLabel} : *{$dayOfMonth}* / {$daysInMonth}",
            "📊 {$remainLabel} : *{$remaining}*",
            "💼 {$workLabel} : *{$elapsedWork}* {$passedStr} / *{$remainingWork}* {$remainingStr} (total: {$totalWorkDays})",
            "🗓 {$weekendLabel} : *{$weekendsRemaining}*",
            "",
            "📍 {$todayLabel} : *{$now->format($dateFormat)}*",
            "🏁 {$endLabel} : *{$lastDay->format($dateFormat)}* ({$this->getDayName((int) $lastDay->format('w'), $lang)})",
        ];

        // Milestones
        $milestones = [25, 50, 75, 100];
        $nextMilestone = null;
        foreach ($milestones as $m) {
            if ($pct < $m) {
                $nextMilestone = $m;
                break;
            }
        }
        if ($nextMilestone !== null) {
            $targetDay = (int) ceil($daysInMonth * $nextMilestone / 100);
            $targetDate = $firstDay->modify('+' . ($targetDay - 1) . ' days');
            $milestoneLabel = match ($lang) { 'en' => 'Next milestone', 'es' => 'Próximo hito', 'de' => 'Nächster Meilenstein', default => 'Prochain palier' };
            $lines[] = "";
            $lines[] = "🎯 {$milestoneLabel} ({$nextMilestone}%) : *{$targetDate->format($dateFormat)}*";
        }

        $tipLabel = match ($lang) { 'en' => 'Ex: month progress', 'es' => 'Ej: progreso mes, month progress', 'de' => 'Bsp: Monatsfortschritt, month progress', default => 'Ex : progression mois, month progress' };
        $lines[] = "";
        $lines[] = "────────────────";
        $lines[] = "💡 _{$tipLabel}_";

        return AgentResult::reply(implode("\n", $lines), [
            'action'          => 'month_progress',
            'day_of_month'    => $dayOfMonth,
            'days_in_month'   => $daysInMonth,
            'progress_pct'    => $pct,
            'working_days'    => $totalWorkDays,
        ]);
    }

    private function handleAlarmTime(array $parsed, array $prefs): AgentResult
    {
        $mode      = trim($parsed['mode'] ?? 'bedtime');  // 'bedtime' or 'wakeup'
        $timeStr   = trim($parsed['time'] ?? '');
        $sleepH    = (float) ($parsed['hours'] ?? 8);
        $lang      = $prefs['language'] ?? 'fr';

        if ($sleepH < 1 || $sleepH > 16) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Invalid sleep duration. Between 1h and 16h.\n\n_Example: alarm bedtime 23h sleep 7h30_",
                'es' => "⚠️ Duración de sueño no válida. Entre 1h y 16h.\n\n_Ejemplo: alarm bedtime 23h sleep 7h30_",
                'de' => "⚠️ Ungültige Schlafdauer. Zwischen 1h und 16h.\n\n_Beispiel: alarm bedtime 23h sleep 7h30_",
                default => "⚠️ Durée de sommeil invalide. Entre 1h et 16h.\n\n_Exemple : alarm bedtime 23h sleep 7h30_",
            };
            return AgentResult::reply($errMsg);
        }

        $userTz = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now    = new DateTimeImmutable('now', $userTz);

        // Sleep cycle: ~90 min each
        $cycleMin   = 90;
        $sleepMin   = (int) round($sleepH * 60);
        $numCycles  = (int) round($sleepMin / $cycleMin);
        $fallAsleep = 15; // average time to fall asleep

        if ($mode === 'wakeup' && $timeStr !== '') {
            // User wants to wake up at X → calculate bedtime options
            $wakeTime = $this->parseTimeString($timeStr);
            if (!$wakeTime) {
                return AgentResult::reply("⚠️ Heure invalide : *{$timeStr}*.\n\n_Formats acceptés : 7h, 7h30, 7:00, 7am_");
            }

            $wakeDateTime = new DateTimeImmutable("{$now->format('Y-m-d')} {$wakeTime}:00", $userTz);
            if ($wakeDateTime <= $now) {
                $wakeDateTime = $wakeDateTime->modify('+1 day');
            }

            $lines = [
                "⏰ *CALCUL HEURE DE COUCHER*",
                "────────────────",
                "🌅 Réveil souhaité : *{$wakeDateTime->format('H:i')}*",
                "😴 Durée sommeil : *{$sleepH}h* (~{$numCycles} cycles)",
                "",
                "*Options de coucher :*",
            ];

            // Show options for 4, 5, 6 cycles
            $options = [6, 5, 4];
            foreach ($options as $cycles) {
                $totalMin  = ($cycles * $cycleMin) + $fallAsleep;
                $bedtime   = $wakeDateTime->modify("-{$totalMin} minutes");
                $hoursLabel = round(($cycles * $cycleMin) / 60, 1);
                $lines[]   = "• *{$bedtime->format('H:i')}* → {$hoursLabel}h de sommeil ({$cycles} cycles)";
            }

            $lines[] = "";
            $lines[] = "💡 _Un cycle de sommeil dure ~90 min._";
            $lines[] = "💡 _+15 min pour s'endormir inclus._";

        } elseif ($mode === 'bedtime' && $timeStr !== '') {
            // User goes to bed at X → calculate wake up options
            $bedTime = $this->parseTimeString($timeStr);
            if (!$bedTime) {
                return AgentResult::reply("⚠️ Heure invalide : *{$timeStr}*.\n\n_Formats acceptés : 23h, 23h30, 11pm_");
            }

            $bedDateTime = new DateTimeImmutable("{$now->format('Y-m-d')} {$bedTime}:00", $userTz);
            if ($bedDateTime <= $now->modify('-2 hours')) {
                $bedDateTime = $bedDateTime->modify('+1 day');
            }

            $lines = [
                "⏰ *CALCUL HEURE DE RÉVEIL*",
                "────────────────",
                "🛏 Coucher prévu : *{$bedDateTime->format('H:i')}*",
                "",
                "*Options de réveil (cycles complets) :*",
            ];

            $options = [4, 5, 6];
            foreach ($options as $cycles) {
                $totalMin   = ($cycles * $cycleMin) + $fallAsleep;
                $wakeTime2  = $bedDateTime->modify("+{$totalMin} minutes");
                $hoursLabel = round(($cycles * $cycleMin) / 60, 1);
                $lines[]    = "• *{$wakeTime2->format('H:i')}* → {$hoursLabel}h de sommeil ({$cycles} cycles)";
            }

            $lines[] = "";
            $lines[] = "💡 _Se réveiller en fin de cycle = meilleure énergie._";
            $lines[] = "💡 _+15 min pour s'endormir inclus._";

        } else {
            // No time given → calculate from now
            $lines = [
                "⏰ *CALCUL SOMMEIL — à partir de maintenant*",
                "────────────────",
                "🕐 Heure actuelle : *{$now->format('H:i')}*",
                "",
                "*Si tu te couches maintenant, réveille-toi à :*",
            ];

            $options = [4, 5, 6];
            foreach ($options as $cycles) {
                $totalMin   = ($cycles * $cycleMin) + $fallAsleep;
                $wakeTime2  = $now->modify("+{$totalMin} minutes");
                $hoursLabel = round(($cycles * $cycleMin) / 60, 1);
                $lines[]    = "• *{$wakeTime2->format('H:i')}* → {$hoursLabel}h de sommeil ({$cycles} cycles)";
            }

            $lines[] = "";
            $lines[] = "💡 _Cycles de 90 min + 15 min pour s'endormir._";
        }

        $lines[] = "";
        $lines[] = "────────────────";
        $lines[] = "💡 _Ex : réveil 7h, coucher 23h, alarm now_";

        return AgentResult::reply(implode("\n", $lines), [
            'action' => 'alarm_time',
            'mode'   => $mode,
            'time'   => $timeStr,
        ]);
    }


    /**
     * Get a short display name for a timezone (last segment of IANA id).
     */
    private function getShortTzName(string $tz): string
    {
        $parts = explode('/', $tz);
        return str_replace('_', ' ', end($parts));
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

    private function validateValue(string $key, mixed $value, string $lang = 'fr'): ?string
    {
        $langsList = implode(', ', array_map(fn($l) => "*{$l}* (" . (self::LANGUAGE_LABELS[$l] ?? $l) . ")", self::VALID_LANGUAGES));
        $formatsList = implode(', ', array_map(fn($f) => "*{$f}*", self::VALID_DATE_FORMATS));
        $stylesList = implode(', ', array_map(fn($s) => "*{$s}*", self::VALID_STYLES));

        return match ($key) {
            'language' => !in_array($value, self::VALID_LANGUAGES)
                ? match ($lang) {
                    'en' => "Invalid language *{$value}*. Supported: {$langsList}",
                    'es' => "Idioma inválido *{$value}*. Soportados: {$langsList}",
                    'de' => "Ungültige Sprache *{$value}*. Unterstützt: {$langsList}",
                    default => "Langue invalide *{$value}*. Langues supportées : {$langsList}",
                }
                : null,

            'timezone' => $this->validateTimezone($value, $lang),

            'unit_system' => !in_array($value, self::VALID_UNIT_SYSTEMS)
                ? match ($lang) {
                    'en' => "Invalid unit system. Accepted: *metric*, *imperial*",
                    'es' => "Sistema de unidades inválido. Aceptados: *metric*, *imperial*",
                    'de' => "Ungültiges Einheitensystem. Akzeptiert: *metric*, *imperial*",
                    default => "Système d'unités invalide. Valeurs acceptées : *metric*, *imperial*",
                }
                : null,

            'date_format' => !in_array($value, self::VALID_DATE_FORMATS)
                ? match ($lang) {
                    'en' => "Invalid date format. Accepted: {$formatsList}",
                    'es' => "Formato de fecha inválido. Aceptados: {$formatsList}",
                    'de' => "Ungültiges Datumsformat. Akzeptiert: {$formatsList}",
                    default => "Format de date invalide. Formats acceptés : {$formatsList}",
                }
                : null,

            'communication_style' => !in_array($value, self::VALID_STYLES)
                ? match ($lang) {
                    'en' => "Invalid style. Accepted: {$stylesList}",
                    'es' => "Estilo inválido. Aceptados: {$stylesList}",
                    'de' => "Ungültiger Stil. Akzeptiert: {$stylesList}",
                    default => "Style invalide. Styles acceptés : {$stylesList}",
                }
                : null,

            'email' => ($value && !filter_var($value, FILTER_VALIDATE_EMAIL))
                ? match ($lang) {
                    'en' => "Invalid email address. Example: _john@example.com_",
                    'es' => "Dirección de email inválida. Ejemplo: _juan@ejemplo.com_",
                    'de' => "Ungültige E-Mail-Adresse. Beispiel: _hans@beispiel.de_",
                    default => "Adresse email invalide. Exemple : _jean@exemple.com_",
                }
                : null,

            'phone' => $this->validatePhone($value),

            'theme' => !in_array($value, ['auto', 'light', 'dark'])
                ? match ($lang) {
                    'en' => "Invalid theme. Accepted: *auto*, *light*, *dark*",
                    'es' => "Tema inválido. Aceptados: *auto*, *light*, *dark*",
                    'de' => "Ungültiges Thema. Akzeptiert: *auto*, *light*, *dark*",
                    default => "Thème invalide. Valeurs acceptées : *auto*, *light*, *dark*",
                }
                : null,

            default => null,
        };
    }

    private function validateTimezone(mixed $value, string $lang = 'fr'): ?string
    {
        $examples = '_Europe/Paris_, _America/New\_York_, _UTC_, _UTC+2_';

        if (!is_string($value) || trim($value) === '') {
            return match ($lang) {
                'en' => "Invalid timezone. Examples: {$examples}",
                'es' => "Zona horaria inválida. Ejemplos: {$examples}",
                'de' => "Ungültige Zeitzone. Beispiele: {$examples}",
                'it' => "Fuso orario non valido. Esempi: {$examples}",
                'pt' => "Fuso horário inválido. Exemplos: {$examples}",
                default => "Fuseau horaire invalide. Exemples : {$examples}",
            };
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
            $suggestionLabel = match ($lang) {
                'en' => 'Suggestion', 'es' => 'Sugerencia', 'de' => 'Vorschlag',
                'it' => 'Suggerimento', 'pt' => 'Sugestão', default => 'Suggestion',
            };
            $extra = $suggestion ? "\n💡 {$suggestionLabel} : _{$suggestion}_" : '';
            return match ($lang) {
                'en' => "Invalid timezone *{$value}*. Valid examples: {$examples}{$extra}",
                'es' => "Zona horaria inválida *{$value}*. Ejemplos válidos: {$examples}{$extra}",
                'de' => "Ungültige Zeitzone *{$value}*. Gültige Beispiele: {$examples}{$extra}",
                'it' => "Fuso orario non valido *{$value}*. Esempi validi: {$examples}{$extra}",
                'pt' => "Fuso horário inválido *{$value}*. Exemplos válidos: {$examples}{$extra}",
                default => "Fuseau horaire invalide *{$value}*. Exemples valides : {$examples}{$extra}",
            };
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

    // -------------------------------------------------------------------------
    // Profile completeness & snapshot
    // -------------------------------------------------------------------------

    private function handleProfileCompleteness(array $prefs): AgentResult
    {
        $defaults     = UserPreference::$defaults;
        $total        = count($defaults);
        $customized   = 0;
        $missing      = [];
        $personalised = [];

        foreach ($defaults as $key => $defaultValue) {
            $current = $prefs[$key] ?? null;

            if (in_array($key, ['phone', 'email'])) {
                if (!empty($current)) {
                    $customized++;
                    $personalised[] = $key;
                } else {
                    $missing[] = $key;
                }
                continue;
            }

            if ($current !== null && (string) $current !== (string) $defaultValue) {
                $customized++;
                $personalised[] = $key;
            }
        }

        $filled     = $total - count($missing);
        $percent    = $total > 0 ? (int) round(($filled / $total) * 100) : 0;
        $barLength  = 10;
        $filledBars = (int) round($percent / 100 * $barLength);
        $bar        = str_repeat('█', $filledBars) . str_repeat('░', $barLength - $filledBars);

        $lines = [
            "📊 *COMPLÉTUDE DU PROFIL*",
            "────────────────",
            "{$bar} *{$percent}%*",
            "",
            "✅ *Renseignés :* {$filled}/{$total}",
            "✏️ *Personnalisés :* {$customized}/{$total}",
        ];

        if (!empty($personalised)) {
            $labels = array_map(fn($k) => $this->formatKeyLabel($k), $personalised);
            $lines[] = "";
            $lines[] = "🎯 _Personnalisations :_ " . implode(', ', $labels);
        }

        if (!empty($missing)) {
            $lines[] = "";
            $lines[] = "💡 *Suggestions pour compléter ton profil :*";
            foreach ($missing as $key) {
                $lines[] = match ($key) {
                    'phone' => "• _mon numéro +33612345678_ — ajouter ton téléphone",
                    'email' => "• _mon email jean@exemple.com_ — ajouter ton email",
                    default => "• _set {$key}_ — définir {$this->formatKeyLabel($key)}",
                };
            }
        }

        if ($percent === 100) {
            $lines[] = "";
            $lines[] = "🏆 _Profil 100% complet ! Bravo !_";
        }

        return AgentResult::reply(implode("\n", $lines), ['action' => 'profile_completeness', 'percent' => $percent]);
    }

    private function handlePreferenceSnapshot(array $prefs): AgentResult
    {
        $lang    = self::LANGUAGE_LABELS[$prefs['language']] ?? $prefs['language'];
        $tz      = $prefs['timezone'] ?? 'UTC';
        $style   = self::STYLE_LABELS[$prefs['communication_style']] ?? $prefs['communication_style'];
        $units   = $prefs['unit_system'] ?? 'metric';
        $theme   = $prefs['theme'] ?? 'auto';
        $notif   = ($prefs['notification_enabled'] ?? true) ? '🔔' : '🔕';

        $localTime = '';
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone($tz));
            $localTime = " | 🕐 {$now->format('H:i')}";
        } catch (\Exception) {
        }

        $snapshot = "📋 *Snapshot :* {$lang} | {$tz}{$localTime} | {$style} | {$units} | 🎨 {$theme} | {$notif}";

        return AgentResult::reply($snapshot, ['action' => 'preference_snapshot']);
    }

    // -------------------------------------------------------------------------
    // v1.26.0 — locale_preset & workday_progress
    // -------------------------------------------------------------------------

    private const LOCALE_PRESETS = [
        'fr' => [
            'label'    => '🇫🇷 Profil Français',
            'language' => 'fr', 'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y', 'unit_system' => 'metric',
        ],
        'en_us' => [
            'label'    => '🇺🇸 US Profile',
            'language' => 'en', 'timezone' => 'America/New_York',
            'date_format' => 'm/d/Y', 'unit_system' => 'imperial',
        ],
        'en_uk' => [
            'label'    => '🇬🇧 UK Profile',
            'language' => 'en', 'timezone' => 'Europe/London',
            'date_format' => 'd/m/Y', 'unit_system' => 'metric',
        ],
        'de' => [
            'label'    => '🇩🇪 Deutsches Profil',
            'language' => 'de', 'timezone' => 'Europe/Berlin',
            'date_format' => 'd.m.Y', 'unit_system' => 'metric',
        ],
        'es' => [
            'label'    => '🇪🇸 Perfil Español',
            'language' => 'es', 'timezone' => 'Europe/Madrid',
            'date_format' => 'd/m/Y', 'unit_system' => 'metric',
        ],
        'it' => [
            'label'    => '🇮🇹 Profilo Italiano',
            'language' => 'it', 'timezone' => 'Europe/Rome',
            'date_format' => 'd/m/Y', 'unit_system' => 'metric',
        ],
        'pt' => [
            'label'    => '🇵🇹 Perfil Português',
            'language' => 'pt', 'timezone' => 'Europe/Lisbon',
            'date_format' => 'd/m/Y', 'unit_system' => 'metric',
        ],
        'ja' => [
            'label'    => '🇯🇵 日本プロファイル',
            'language' => 'ja', 'timezone' => 'Asia/Tokyo',
            'date_format' => 'Y-m-d', 'unit_system' => 'metric',
        ],
        'ar' => [
            'label'    => '🇸🇦 الملف العربي',
            'language' => 'ar', 'timezone' => 'Asia/Riyadh',
            'date_format' => 'd/m/Y', 'unit_system' => 'metric',
        ],
        'zh' => [
            'label'    => '🇨🇳 中文配置',
            'language' => 'zh', 'timezone' => 'Asia/Shanghai',
            'date_format' => 'Y-m-d', 'unit_system' => 'metric',
        ],
    ];

    private function handleLocalePreset(AgentContext $context, string $userId, array $parsed, array $currentPrefs): AgentResult
    {
        $preset = mb_strtolower(trim($parsed['preset'] ?? ''));

        // List available presets
        if ($preset === '' || $preset === 'list') {
            $lines = [
                "🌍 *PROFILS RÉGIONAUX DISPONIBLES*",
                "────────────────",
            ];
            foreach (self::LOCALE_PRESETS as $code => $config) {
                $lines[] = "• *{$code}* — {$config['label']}";
            }
            $lines[] = "";
            $lines[] = "💡 _Exemple : profil français, US profile, profil japonais_";
            return AgentResult::reply(implode("\n", $lines), ['action' => 'locale_preset', 'sub_action' => 'list']);
        }

        // Resolve preset by code or partial name match
        $resolved = null;
        if (isset(self::LOCALE_PRESETS[$preset])) {
            $resolved = $preset;
        } else {
            // Fuzzy match: "français" → fr, "us" → en_us, "japan" → ja, etc.
            $aliases = [
                'français' => 'fr', 'francais' => 'fr', 'french' => 'fr', 'france' => 'fr',
                'us' => 'en_us', 'usa' => 'en_us', 'american' => 'en_us', 'américain' => 'en_us', 'americain' => 'en_us',
                'uk' => 'en_uk', 'british' => 'en_uk', 'anglais' => 'en_uk', 'english' => 'en_uk', 'england' => 'en_uk',
                'german' => 'de', 'allemand' => 'de', 'deutsch' => 'de', 'germany' => 'de', 'allemagne' => 'de',
                'spanish' => 'es', 'espagnol' => 'es', 'español' => 'es', 'spain' => 'es', 'espagne' => 'es',
                'italian' => 'it', 'italien' => 'it', 'italiano' => 'it', 'italy' => 'it', 'italie' => 'it',
                'portuguese' => 'pt', 'portugais' => 'pt', 'portugal' => 'pt', 'brésil' => 'pt', 'bresil' => 'pt',
                'japanese' => 'ja', 'japonais' => 'ja', 'japan' => 'ja', 'japon' => 'ja',
                'arabic' => 'ar', 'arabe' => 'ar', 'arab' => 'ar', 'saudi' => 'ar',
                'chinese' => 'zh', 'chinois' => 'zh', 'china' => 'zh', 'chine' => 'zh',
            ];
            $resolved = $aliases[$preset] ?? null;
        }

        if (!$resolved || !isset(self::LOCALE_PRESETS[$resolved])) {
            $codes = implode(', ', array_map(fn($c) => "*{$c}*", array_keys(self::LOCALE_PRESETS)));
            return AgentResult::reply(
                "⚠️ Profil régional inconnu : *{$preset}*\n\n"
                . "Profils disponibles : {$codes}\n\n"
                . "_Tape *profils régionaux* pour voir la liste complète._",
                ['action' => 'locale_preset', 'error' => 'unknown_preset']
            );
        }

        $config  = self::LOCALE_PRESETS[$resolved];
        $changes = [];
        $lines   = ["✅ *{$config['label']}* appliqué !\n"];

        foreach (['language', 'timezone', 'date_format', 'unit_system'] as $key) {
            if (!isset($config[$key])) {
                continue;
            }
            $oldValue = $currentPrefs[$key] ?? null;
            $newValue = $config[$key];

            if ((string) $oldValue === (string) $newValue) {
                $lines[] = "• *{$this->formatKeyLabel($key)}* : {$this->formatValue($key, $newValue)} _(inchangé)_";
                continue;
            }

            $success = PreferencesManager::setPreference($userId, $key, $newValue);
            if ($success) {
                $lines[] = "• *{$this->formatKeyLabel($key)}* : {$this->formatValue($key, $oldValue)} → {$this->formatValue($key, $newValue)}";
                $changes[] = $key;
                $this->log($context, "Locale preset {$resolved}: {$key}", [
                    'key' => $key, 'old_value' => $oldValue, 'new_value' => $newValue,
                ]);
            }
        }

        // Show local time in new timezone
        try {
            $tz  = new DateTimeZone($config['timezone']);
            $now = new DateTimeImmutable('now', $tz);
            $lang = $config['language'];
            $dayName = $this->getDayName((int) $now->format('w'), $lang);
            $lines[] = "";
            $lines[] = "🕐 Heure locale : *{$now->format('H:i')}* ({$dayName}) — {$config['timezone']}";
        } catch (\Exception) {
        }

        if (empty($changes)) {
            $lines[] = "";
            $lines[] = "ℹ️ _Tes préférences correspondaient déjà à ce profil._";
        }

        return AgentResult::reply(implode("\n", $lines), ['action' => 'locale_preset', 'preset' => $resolved, 'changed' => $changes]);
    }

    private function handleWorkdayProgress(array $prefs): AgentResult
    {
        $tz   = $prefs['timezone'] ?? 'UTC';
        $lang = $prefs['language'] ?? 'fr';

        try {
            $tzObj = new DateTimeZone($tz);
            $now   = new DateTimeImmutable('now', $tzObj);
        } catch (\Exception) {
            return AgentResult::reply(
                "⚠️ Fuseau horaire invalide. Vérifie ton fuseau avec *mon profil*.",
                ['action' => 'workday_progress', 'error' => 'invalid_tz']
            );
        }

        $dayOfWeek = (int) $now->format('N'); // 1=Mon, 7=Sun
        $hour      = (int) $now->format('G');
        $minute    = (int) $now->format('i');
        $dayName   = $this->getDayName((int) $now->format('w'), $lang);
        $currentMinutes = $hour * 60 + $minute;

        // Workday: 9:00–18:00 (540–1080 minutes)
        $workStart = 540;  // 9:00
        $workEnd   = 1080; // 18:00
        $workTotal = $workEnd - $workStart; // 540 min = 9h

        $isWeekend  = $dayOfWeek >= 6;
        $isWorkHour = !$isWeekend && $currentMinutes >= $workStart && $currentMinutes < $workEnd;

        $lines = [
            "💼 *PROGRESSION DE LA JOURNÉE*",
            "────────────────",
            "📅 {$dayName} — *{$now->format('H:i')}* ({$tz})",
            "",
        ];

        if ($isWeekend) {
            $emoji = $dayOfWeek === 6 ? '🎉' : '😴';
            $lines[] = "{$emoji} *C'est le week-end !*";

            // Calculate time until Monday 9:00
            $daysUntilMon = $dayOfWeek === 6 ? 2 : 1;
            $monday = $now->modify("+{$daysUntilMon} days")->setTime(9, 0);
            $diff   = $now->diff($monday);
            $lines[] = "";
            $lines[] = "⏳ Reprise lundi à 9h dans *{$diff->h}h{$diff->i}min*" . ($diff->d > 0 ? " ({$diff->d}j)" : "");
        } elseif ($currentMinutes < $workStart) {
            // Before work
            $minutesUntilStart = $workStart - $currentMinutes;
            $h = intdiv($minutesUntilStart, 60);
            $m = $minutesUntilStart % 60;
            $lines[] = "☕ *Avant la journée de travail*";
            $lines[] = "";
            $lines[] = "⏳ Début dans *{$h}h" . str_pad((string) $m, 2, '0') . "*";
            $lines[] = "🎯 Journée : 09:00 → 18:00 (9h)";
        } elseif ($currentMinutes >= $workEnd) {
            // After work
            $lines[] = "🌙 *Journée de travail terminée !*";
            $elapsed = $workTotal;
            $lines[] = "✅ 9h de travail accomplies";

            // Time until next workday
            $nextDay = $dayOfWeek === 5
                ? $now->modify('+3 days')->setTime(9, 0) // Friday → Monday
                : $now->modify('+1 day')->setTime(9, 0);
            $diff = $now->diff($nextDay);
            $nextLabel = $dayOfWeek === 5 ? 'lundi' : 'demain';
            $lines[] = "";
            $lines[] = "⏳ Prochaine journée {$nextLabel} à 9h dans *{$diff->h}h{$diff->i}min*" . ($diff->d > 0 ? " ({$diff->d}j)" : "");
        } else {
            // During work hours
            $elapsed   = $currentMinutes - $workStart;
            $remaining = $workEnd - $currentMinutes;
            $percent   = (int) round(($elapsed / $workTotal) * 100);

            $barLength  = 10;
            $filledBars = (int) round($percent / 100 * $barLength);
            $bar        = str_repeat('█', $filledBars) . str_repeat('░', $barLength - $filledBars);

            $elapsedH = intdiv($elapsed, 60);
            $elapsedM = $elapsed % 60;
            $remainH  = intdiv($remaining, 60);
            $remainM  = $remaining % 60;

            $lines[] = "{$bar} *{$percent}%*";
            $lines[] = "";
            $lines[] = "✅ Écoulé : *{$elapsedH}h" . str_pad((string) $elapsedM, 2, '0') . "*";
            $lines[] = "⏳ Restant : *{$remainH}h" . str_pad((string) $remainM, 2, '0') . "*";
            $lines[] = "🏁 Fin à *18:00*";

            // Milestones
            $milestones = [
                ['min' => 720, 'label' => '🍽️ Pause déjeuner (12h)'],
                ['min' => 900, 'label' => '☕ Pause café (15h)'],
                ['min' => 1020, 'label' => '🏠 Dernière heure (17h)'],
            ];

            $nextMilestone = null;
            foreach ($milestones as $ms) {
                if ($currentMinutes < $ms['min']) {
                    $untilMs = $ms['min'] - $currentMinutes;
                    $msH = intdiv($untilMs, 60);
                    $msM = $untilMs % 60;
                    $nextMilestone = "{$ms['label']} dans *{$msH}h" . str_pad((string) $msM, 2, '0') . "*";
                    break;
                }
            }
            if ($nextMilestone) {
                $lines[] = "";
                $lines[] = $nextMilestone;
            }
        }

        // Week progress
        $lines[] = "";
        $lines[] = "────────────────";
        $weekDaysElapsed = min($dayOfWeek, 5);
        if (!$isWeekend && $isWorkHour) {
            $dayFraction = $elapsed / $workTotal;
            $weekPercent = (int) round((($weekDaysElapsed - 1 + $dayFraction) / 5) * 100);
        } elseif ($isWeekend || $currentMinutes >= $workEnd) {
            $weekPercent = (int) round(($weekDaysElapsed / 5) * 100);
        } else {
            $weekPercent = (int) round((($weekDaysElapsed - 1) / 5) * 100);
        }
        $weekPercent = min(100, max(0, $weekPercent));
        $lines[] = "📊 Semaine : *{$weekPercent}%* ({$weekDaysElapsed}/5 jours)";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'workday_progress', 'is_work_hour' => $isWorkHour]);
    }

    // -------------------------------------------------------------------------
    // v1.25.0 — favorite_cities & time_diff
    // -------------------------------------------------------------------------

    private function handleFavoriteCities(AgentContext $context, string $userId, array $parsed, array $prefs): AgentResult
    {
        $subAction = $parsed['sub_action'] ?? 'list';
        $tz        = $prefs['timezone'] ?? 'UTC';
        $lang      = $prefs['language'] ?? 'fr';

        // Load favorite cities from preferences (stored as JSON array in 'favorite_cities' key)
        $currentFavs = [];
        $rawFavs     = $prefs['favorite_cities'] ?? null;
        if (is_string($rawFavs) && $rawFavs !== '') {
            $decoded = json_decode($rawFavs, true);
            if (is_array($decoded)) {
                $currentFavs = $decoded;
            }
        } elseif (is_array($rawFavs)) {
            $currentFavs = $rawFavs;
        }

        switch ($subAction) {
            case 'add':
                $cities = $parsed['cities'] ?? [];
                if (empty($cities)) {
                    return AgentResult::reply(
                        "⚠️ Indique au moins une ville à ajouter.\n\n_Exemple : ajouter Tokyo aux favoris_",
                        ['action' => 'favorite_cities', 'sub_action' => 'add', 'error' => 'no_cities']
                    );
                }
                $added   = [];
                $invalid = [];
                $already = [];
                foreach ($cities as $city) {
                    $resolved = $this->resolveTimezoneString(trim($city));
                    if (!$resolved) {
                        $invalid[] = $city;
                        continue;
                    }
                    $cityName = trim($city);
                    if (in_array($cityName, $currentFavs, true)) {
                        $already[] = $cityName;
                        continue;
                    }
                    $currentFavs[] = $cityName;
                    $added[]       = $cityName;
                }
                if (count($currentFavs) > 20) {
                    return AgentResult::reply(
                        "⚠️ Maximum 20 villes favorites. Tu en as déjà " . count($currentFavs) . ".\n\n_Supprime des villes avec : supprimer ville Tokyo_",
                        ['action' => 'favorite_cities', 'sub_action' => 'add', 'error' => 'max_reached']
                    );
                }
                PreferencesManager::setPreference($userId, 'favorite_cities', json_encode(array_values($currentFavs)));
                $lines = [];
                if (!empty($added)) {
                    $lines[] = "✅ Ville(s) ajoutée(s) : *" . implode(', ', $added) . "*";
                }
                if (!empty($already)) {
                    $lines[] = "ℹ️ Déjà dans tes favoris : _" . implode(', ', $already) . "_";
                }
                if (!empty($invalid)) {
                    $lines[] = "⚠️ Ville(s) non reconnue(s) : _" . implode(', ', $invalid) . "_";
                }
                $lines[] = "";
                $lines[] = "⭐ *Tes villes favorites (" . count($currentFavs) . ") :* " . implode(', ', $currentFavs);
                $lines[] = "\n💡 _Tape *horloge favoris* pour voir l'heure dans tes villes._";
                return AgentResult::reply(implode("\n", $lines), ['action' => 'favorite_cities', 'sub_action' => 'add', 'added' => $added]);

            case 'remove':
                $cities = $parsed['cities'] ?? [];
                if (empty($cities)) {
                    return AgentResult::reply(
                        "⚠️ Indique au moins une ville à supprimer.\n\n_Exemple : supprimer Dubai des favoris_",
                        ['action' => 'favorite_cities', 'sub_action' => 'remove', 'error' => 'no_cities']
                    );
                }
                $removed  = [];
                $notFound = [];
                foreach ($cities as $city) {
                    $cityName = trim($city);
                    $idx      = array_search($cityName, $currentFavs, true);
                    // Case-insensitive fallback
                    if ($idx === false) {
                        foreach ($currentFavs as $i => $fav) {
                            if (mb_strtolower($fav) === mb_strtolower($cityName)) {
                                $idx = $i;
                                break;
                            }
                        }
                    }
                    if ($idx !== false) {
                        $removed[] = $currentFavs[$idx];
                        unset($currentFavs[$idx]);
                    } else {
                        $notFound[] = $cityName;
                    }
                }
                $currentFavs = array_values($currentFavs);
                PreferencesManager::setPreference($userId, 'favorite_cities', json_encode($currentFavs));
                $lines = [];
                if (!empty($removed)) {
                    $lines[] = "🗑️ Ville(s) retirée(s) : *" . implode(', ', $removed) . "*";
                }
                if (!empty($notFound)) {
                    $lines[] = "⚠️ Pas dans tes favoris : _" . implode(', ', $notFound) . "_";
                }
                if (!empty($currentFavs)) {
                    $lines[] = "";
                    $lines[] = "⭐ *Tes villes favorites (" . count($currentFavs) . ") :* " . implode(', ', $currentFavs);
                } else {
                    $lines[] = "";
                    $lines[] = "_Aucune ville favorite. Ajoute-en avec : ajouter ville Tokyo_";
                }
                return AgentResult::reply(implode("\n", $lines), ['action' => 'favorite_cities', 'sub_action' => 'remove', 'removed' => $removed]);

            case 'clock':
                if (empty($currentFavs)) {
                    return AgentResult::reply(
                        "⭐ _Tu n'as pas encore de villes favorites._\n\n"
                        . "Ajoute-en avec :\n• _ajouter ville Tokyo_\n• _ajouter Tokyo et Dubai aux favoris_",
                        ['action' => 'favorite_cities', 'sub_action' => 'clock', 'error' => 'empty']
                    );
                }
                $lines = ["⭐ *HORLOGE FAVORIS*", "────────────────"];
                try {
                    $userTz  = new DateTimeZone($tz);
                    $userNow = new DateTimeImmutable('now', $userTz);
                    $lines[] = "📍 *Chez toi* ({$tz}) : *{$userNow->format('H:i')}* " . $this->getDayName((int) $userNow->format('w'), $lang, true);
                    $lines[] = "";
                } catch (\Exception) {
                    // Skip user time if timezone invalid
                }
                foreach ($currentFavs as $city) {
                    $resolved = $this->resolveTimezoneString($city);
                    if (!$resolved) {
                        $lines[] = "❓ {$city} — _fuseau inconnu_";
                        continue;
                    }
                    try {
                        $cityTz  = new DateTimeZone($resolved);
                        $cityNow = new DateTimeImmutable('now', $cityTz);
                        $hour    = (int) $cityNow->format('G');
                        $icon    = ($hour >= 6 && $hour < 18) ? '☀️' : '🌙';
                        $dayName = $this->getDayName((int) $cityNow->format('w'), $lang, true);
                        $offset  = $cityNow->format('P');
                        $lines[] = "{$icon} *{$city}* : *{$cityNow->format('H:i')}* {$dayName} _(UTC{$offset})_";
                    } catch (\Exception) {
                        $lines[] = "❓ {$city} — _erreur_";
                    }
                }
                $lines[] = "";
                $lines[] = "💡 _Gère tes favoris : ajouter ville X, supprimer ville X_";
                return AgentResult::reply(implode("\n", $lines), ['action' => 'favorite_cities', 'sub_action' => 'clock', 'count' => count($currentFavs)]);

            case 'list':
            default:
                if (empty($currentFavs)) {
                    return AgentResult::reply(
                        "⭐ _Tu n'as pas encore de villes favorites._\n\n"
                        . "Ajoute-en avec :\n• _ajouter ville Tokyo_\n• _ajouter Tokyo et Dubai aux favoris_\n\n"
                        . "💡 _Les favoris permettent d'afficher rapidement l'heure dans tes villes préférées._",
                        ['action' => 'favorite_cities', 'sub_action' => 'list', 'count' => 0]
                    );
                }
                $lines = ["⭐ *MES VILLES FAVORITES* (" . count($currentFavs) . "/20)", "────────────────"];
                foreach ($currentFavs as $i => $city) {
                    $resolved = $this->resolveTimezoneString($city);
                    $tzLabel  = $resolved ? "_{$resolved}_" : '_inconnu_';
                    $lines[]  = ($i + 1) . ". *{$city}* — {$tzLabel}";
                }
                $lines[] = "";
                $lines[] = "💡 _Commandes :_";
                $lines[] = "• _ajouter ville X_ — ajouter une ville";
                $lines[] = "• _supprimer ville X_ — retirer une ville";
                $lines[] = "• _horloge favoris_ — voir l'heure dans tes favoris";
                return AgentResult::reply(implode("\n", $lines), ['action' => 'favorite_cities', 'sub_action' => 'list', 'count' => count($currentFavs)]);
        }
    }

    private function handleTimeDiff(array $parsed, array $prefs): AgentResult
    {
        $cityA = trim($parsed['city_a'] ?? '');
        $cityB = trim($parsed['city_b'] ?? '');
        $userTz = $prefs['timezone'] ?? 'UTC';
        $lang   = $prefs['language'] ?? 'fr';

        // If city_a is empty or "moi"/"me", use user's timezone
        if ($cityA === '' || in_array(mb_strtolower($cityA), ['moi', 'me', 'my', 'chez moi', 'mon fuseau'])) {
            $cityA    = '';
            $resolvedA = $userTz;
            $labelA    = $userTz . ' _(ton fuseau)_';
        } else {
            $resolvedA = $this->resolveTimezoneString($cityA);
            if (!$resolvedA) {
                return AgentResult::reply(
                    "⚠️ Ville ou fuseau non reconnu : *{$cityA}*\n\n_Exemples valides : Paris, Tokyo, America/New_York, UTC+2_",
                    ['action' => 'time_diff', 'error' => 'invalid_city_a']
                );
            }
            $labelA = "*{$cityA}* _{$resolvedA}_";
        }

        if ($cityB === '') {
            return AgentResult::reply(
                "⚠️ Indique deux villes pour comparer.\n\n_Exemple : différence horaire Paris Tokyo_",
                ['action' => 'time_diff', 'error' => 'missing_city_b']
            );
        }

        $resolvedB = $this->resolveTimezoneString($cityB);
        if (!$resolvedB) {
            return AgentResult::reply(
                "⚠️ Ville ou fuseau non reconnu : *{$cityB}*\n\n_Exemples valides : Paris, Tokyo, America/New_York, UTC+2_",
                ['action' => 'time_diff', 'error' => 'invalid_city_b']
            );
        }
        $labelB = "*{$cityB}* _{$resolvedB}_";

        try {
            $tzA = new DateTimeZone($resolvedA);
            $tzB = new DateTimeZone($resolvedB);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $offsetA = $tzA->getOffset($now);
            $offsetB = $tzB->getOffset($now);
            $diffSeconds = $offsetB - $offsetA;
            $diffHours   = $diffSeconds / 3600;

            $nowA = $now->setTimezone($tzA);
            $nowB = $now->setTimezone($tzB);

            $dayNameA = $this->getDayName((int) $nowA->format('w'), $lang, true);
            $dayNameB = $this->getDayName((int) $nowB->format('w'), $lang, true);

            // Format the difference nicely
            $absDiff  = abs($diffHours);
            $sign     = $diffHours >= 0 ? '+' : '-';
            if (floor($absDiff) == $absDiff) {
                $diffLabel = $sign . (int)$absDiff . 'h';
            } else {
                $hours   = (int) floor($absDiff);
                $minutes = (int) round(($absDiff - $hours) * 60);
                $diffLabel = $sign . $hours . 'h' . str_pad((string)$minutes, 2, '0');
            }

            // Direction text
            if ($diffHours > 0) {
                $direction = "*{$cityB}* est en avance de *" . ltrim($diffLabel, '+') . "* sur " . ($cityA ?: $userTz);
            } elseif ($diffHours < 0) {
                $direction = "*{$cityB}* est en retard de *" . ltrim($diffLabel, '-') . "* sur " . ($cityA ?: $userTz);
            } else {
                $direction = "Les deux villes sont dans le *même fuseau horaire* !";
            }

            // Same day check
            $sameDay = $nowA->format('Y-m-d') === $nowB->format('Y-m-d');
            $dayNote = $sameDay ? '' : " ⚠️ _Jour différent !_";

            $lines = [
                "🔄 *DIFFÉRENCE HORAIRE*",
                "────────────────",
                "",
                "📍 {$labelA}",
                "   🕐 *{$nowA->format('H:i')}* {$dayNameA} {$nowA->format('d/m')} _(UTC{$nowA->format('P')})_",
                "",
                "📍 {$labelB}",
                "   🕐 *{$nowB->format('H:i')}* {$dayNameB} {$nowB->format('d/m')} _(UTC{$nowB->format('P')})_",
                "",
                "⏱️ Décalage : *{$diffLabel}*{$dayNote}",
                $direction,
            ];

            // Add business hours overlap info
            $hourA = (int) $nowA->format('G');
            $hourB = (int) $nowB->format('G');
            $aOpen = ($hourA >= 9 && $hourA < 18);
            $bOpen = ($hourB >= 9 && $hourB < 18);
            $lines[] = "";
            if ($aOpen && $bOpen) {
                $lines[] = "🟢 Les deux villes sont en heures ouvrables (9h-18h)";
            } elseif ($aOpen || $bOpen) {
                $openCity  = $aOpen ? ($cityA ?: $userTz) : $cityB;
                $closedCity = $aOpen ? $cityB : ($cityA ?: $userTz);
                $lines[] = "🟡 *{$openCity}* est en heures ouvrables, *{$closedCity}* non";
            } else {
                $lines[] = "🔴 Aucune des deux villes n'est en heures ouvrables";
            }

            return AgentResult::reply(implode("\n", $lines), [
                'action'     => 'time_diff',
                'diff_hours' => $diffHours,
                'city_a'     => $cityA ?: $userTz,
                'city_b'     => $cityB,
            ]);
        } catch (\Exception $e) {
            return AgentResult::reply(
                "⚠️ Erreur lors du calcul de la différence horaire.\n\n_Vérifie les noms de villes et réessaie._",
                ['action' => 'time_diff', 'error' => $e->getMessage()]
            );
        }
    }

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
        $unitIcon   = $prefs['unit_system'] === 'imperial' ? '🇺🇸' : '🌍';
        $lang       = $prefs['language'] ?? 'fr';

        $notif = match ($lang) {
            'en' => $prefs['notification_enabled'] ? '🔔 Enabled' : '🔕 Disabled',
            'es' => $prefs['notification_enabled'] ? '🔔 Activadas' : '🔕 Desactivadas',
            'de' => $prefs['notification_enabled'] ? '🔔 Aktiviert' : '🔕 Deaktiviert',
            'it' => $prefs['notification_enabled'] ? '🔔 Attivate' : '🔕 Disattivate',
            'pt' => $prefs['notification_enabled'] ? '🔔 Ativadas' : '🔕 Desativadas',
            'ar' => $prefs['notification_enabled'] ? '🔔 مفعّلة' : '🔕 معطّلة',
            'zh' => $prefs['notification_enabled'] ? '🔔 已启用' : '🔕 已禁用',
            'ja' => $prefs['notification_enabled'] ? '🔔 有効' : '🔕 無効',
            'ko' => $prefs['notification_enabled'] ? '🔔 활성화' : '🔕 비활성화',
            'ru' => $prefs['notification_enabled'] ? '🔔 Включены' : '🔕 Отключены',
            'nl' => $prefs['notification_enabled'] ? '🔔 Ingeschakeld' : '🔕 Uitgeschakeld',
            default => $prefs['notification_enabled'] ? '🔔 Activées' : '🔕 Désactivées',
        };
        $notSet = match ($lang) {
            'en' => '_not set_', 'es' => '_no definido_', 'de' => '_nicht definiert_',
            'it' => '_non impostato_', 'pt' => '_não definido_', 'ar' => '_غير محدد_',
            'zh' => '_未设置_', 'ja' => '_未設定_', 'ko' => '_설정되지 않음_',
            'ru' => '_не задано_', 'nl' => '_niet ingesteld_',
            default => '_non défini_',
        };

        $localTimeStr = '';
        try {
            $tz           = new DateTimeZone($prefs['timezone'] ?? 'UTC');
            $now          = new DateTimeImmutable('now', $tz);
            $dayName      = $this->getDayName((int) $now->format('w'), $lang, short: true);
            $localLabel   = match ($lang) {
                'en' => 'local time', 'es' => 'hora local', 'de' => 'Ortszeit',
                'it' => 'ora locale', 'pt' => 'hora local', 'ar' => 'التوقيت المحلي',
                'zh' => '本地时间', 'ja' => '現地時間', 'ko' => '현지 시간',
                'ru' => 'местное время', 'nl' => 'lokale tijd',
                default => 'heure locale',
            };
            $localTimeStr = " _({$localLabel} : *{$now->format('H:i')}* {$dayName} UTC{$now->format('P')})_";
        } catch (\Exception) {
            // Silently ignore if timezone is invalid
        }

        $title = match ($lang) {
            'en' => '👤 *MY PROFILE*', 'es' => '👤 *MI PERFIL*', 'de' => '👤 *MEIN PROFIL*',
            'it' => '👤 *MIO PROFILO*', 'pt' => '👤 *MEU PERFIL*', 'ar' => '👤 *ملفي الشخصي*',
            'zh' => '👤 *我的资料*', 'ja' => '👤 *マイプロフィール*', 'ko' => '👤 *내 프로필*',
            'ru' => '👤 *МОЙ ПРОФИЛЬ*', 'nl' => '👤 *MIJN PROFIEL*',
            default => '👤 *MON PROFIL*',
        };
        $lblLang  = match ($lang) { 'en' => 'Language', 'es' => 'Idioma', 'de' => 'Sprache', 'it' => 'Lingua', 'pt' => 'Idioma', 'ja' => '言語', 'zh' => '语言', 'ko' => '언어', 'ru' => 'Язык', 'nl' => 'Taal', 'ar' => 'اللغة', default => 'Langue' };
        $lblTz    = match ($lang) { 'en' => 'Timezone', 'es' => 'Zona horaria', 'de' => 'Zeitzone', 'it' => 'Fuso orario', 'pt' => 'Fuso horário', 'ja' => 'タイムゾーン', 'zh' => '时区', 'ko' => '시간대', 'ru' => 'Часовой пояс', 'nl' => 'Tijdzone', 'ar' => 'المنطقة الزمنية', default => 'Fuseau' };
        $lblDate  = match ($lang) { 'en' => 'Date format', 'es' => 'Formato fecha', 'de' => 'Datumsformat', 'it' => 'Formato data', 'pt' => 'Formato data', 'ja' => '日付形式', 'zh' => '日期格式', 'ko' => '날짜 형식', 'ru' => 'Формат даты', 'nl' => 'Datumformaat', 'ar' => 'صيغة التاريخ', default => 'Format date' };
        $lblUnit  = match ($lang) { 'en' => 'Units', 'es' => 'Unidades', 'de' => 'Einheiten', 'it' => 'Unità', 'pt' => 'Unidades', 'ja' => '単位', 'zh' => '单位', 'ko' => '단위', 'ru' => 'Единицы', 'nl' => 'Eenheden', 'ar' => 'الوحدات', default => 'Unités' };
        $lblStyle = match ($lang) { 'en' => 'Style', 'es' => 'Estilo', 'de' => 'Stil', 'it' => 'Stile', 'pt' => 'Estilo', 'ja' => 'スタイル', 'zh' => '风格', 'ko' => '스타일', 'ru' => 'Стиль', 'nl' => 'Stijl', 'ar' => 'الأسلوب', default => 'Style' };
        $lblTheme = match ($lang) { 'en' => 'Theme', 'es' => 'Tema', 'de' => 'Thema', 'it' => 'Tema', 'pt' => 'Tema', 'ja' => 'テーマ', 'zh' => '主题', 'ko' => '테마', 'ru' => 'Тема', 'nl' => 'Thema', 'ar' => 'السمة', default => 'Thème' };
        $lblNotif = match ($lang) { 'en' => 'Notifications', 'es' => 'Notificaciones', 'de' => 'Benachrichtigungen', 'it' => 'Notifiche', 'pt' => 'Notificações', 'ja' => '通知', 'zh' => '通知', 'ko' => '알림', 'ru' => 'Уведомления', 'nl' => 'Meldingen', 'ar' => 'الإشعارات', default => 'Notifications' };
        $lblPhone = match ($lang) { 'en' => 'Phone', 'es' => 'Teléfono', 'de' => 'Telefon', 'it' => 'Telefono', 'pt' => 'Telefone', 'ja' => '電話', 'zh' => '电话', 'ko' => '전화', 'ru' => 'Телефон', 'nl' => 'Telefoon', 'ar' => 'الهاتف', default => 'Téléphone' };
        $lblEmail = match ($lang) { 'en' => 'Email', 'es' => 'Correo', 'de' => 'E-Mail', 'it' => 'Email', 'pt' => 'Email', 'ja' => 'メール', 'zh' => '邮箱', 'ko' => '이메일', 'ru' => 'Эл. почта', 'nl' => 'E-mail', 'ar' => 'البريد', default => 'Email' };

        $lines = [
            $title,
            "────────────────",
            "🌐 {$lblLang} : *{$langLabel}* ({$prefs['language']})",
            "🕐 {$lblTz} : *{$prefs['timezone']}*{$localTimeStr}",
            "📅 {$lblDate} : *{$prefs['date_format']}* _(ex: " . date($prefs['date_format'] ?? 'd/m/Y') . ")_",
            "📏 {$lblUnit} : {$unitIcon} *{$prefs['unit_system']}*",
            "💬 {$lblStyle} : *{$styleLabel}*",
            "🎨 {$lblTheme} : *{$prefs['theme']}*",
            "🔔 {$lblNotif} : *{$notif}*",
            "📱 {$lblPhone} : " . ($prefs['phone'] ? "*{$prefs['phone']}*" : $notSet),
            "📧 {$lblEmail} : " . ($prefs['email'] ? "*{$prefs['email']}*" : $notSet),
        ];

        // Show favorite cities count if any
        $rawFavs = $prefs['favorite_cities'] ?? null;
        $favCount = 0;
        if (is_string($rawFavs) && $rawFavs !== '') {
            $decoded = json_decode($rawFavs, true);
            $favCount = is_array($decoded) ? count($decoded) : 0;
        } elseif (is_array($rawFavs)) {
            $favCount = count($rawFavs);
        }
        if ($favCount > 0) {
            $favLabel = match ($lang) {
                'en' => "Favorite cities", 'es' => "Ciudades favoritas", 'de' => "Lieblingsstädte",
                default => "Villes favorites",
            };
            $lines[] = "⭐ {$favLabel} : *{$favCount}* _(tape *horloge favoris*)_";
        }

        // v1.49.0 — Profile completeness mini-indicator
        $filledCount = 0;
        $totalFields  = 9;
        foreach (['language', 'timezone', 'date_format', 'unit_system', 'communication_style', 'theme', 'notification_enabled'] as $k) {
            if (isset($prefs[$k]) && $prefs[$k] !== null) $filledCount++;
        }
        if (!empty($prefs['phone'])) $filledCount++;
        if (!empty($prefs['email'])) $filledCount++;
        $completePct = (int) round($filledCount / $totalFields * 100);
        $miniBar     = str_repeat('█', (int) round($completePct / 10)) . str_repeat('░', 10 - (int) round($completePct / 10));
        $completeLabel = match ($lang) {
            'en' => 'Profile', 'es' => 'Perfil', 'de' => 'Profil',
            'it' => 'Profilo', 'pt' => 'Perfil',
            default => 'Profil',
        };
        $lines[] = "📊 {$completeLabel} : {$miniBar} {$completePct}%";

        $lines[] = "────────────────";

        $examplesLabel = match ($lang) {
            'en' => 'Example commands:', 'es' => 'Comandos de ejemplo:', 'de' => 'Beispielbefehle:',
            default => 'Exemples de commandes :',
        };
        $lines[] = "💡 _{$examplesLabel}_";

        $examples = match ($lang) {
            'en' => [
                "set language fr", "timezone America/New\\_York", "style formal",
                "what time is it", "time in Tokyo", "world clock",
                "business hours Dubai", "meeting planner Tokyo",
                "flight Paris Tokyo depart 14h duration 12h",
                "deadline 2026-06-30 quarterly report",
                "overlap Tokyo", "sleep schedule Paris Tokyo",
                "export my preferences", "help preferences",
            ],
            'es' => [
                "set language en", "timezone America/New\\_York", "style formal",
                "qué hora es", "hora en Tokyo", "reloj mundial",
                "horario oficina Dubai", "planificar reunión Tokyo",
                "vuelo Paris Tokyo salida 14h duración 12h",
                "deadline 2026-06-30 informe trimestral",
                "overlap Tokyo", "sleep schedule Paris Tokyo",
                "exportar preferencias", "ayuda preferencias",
            ],
            default => [
                "set language en", "timezone America/New\\_York", "style formel",
                "quelle heure est-il", "heure à Tokyo", "horloge mondiale",
                "heures ouvrables Dubai", "planifier réunion Tokyo",
                "vol Paris Tokyo départ 14h durée 12h",
                "deadline 2026-06-30 rapport trimestriel",
                "overlap Tokyo", "sleep schedule Paris Tokyo",
                "exporter mes préférences", "aide preferences",
            ],
        };
        foreach ($examples as $ex) {
            $lines[] = "• _{$ex}_";
        }

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
            "",
            "*🔤 Abréviations de fuseaux horaires :*",
            "• _c'est quoi PST_ / _que veut dire CET_ / _signification EST_",
            "• _abréviations fuseaux_ / _liste abréviations_",
            "• Décode PST, CET, GMT, JST, IST et 30+ abréviations",
            "",
            "*🍅 Planning Pomodoro :*",
            "• _pomodoro_ — 4 sessions de 25min par défaut",
            "• _pomodoro 6 sessions_ / _6 pomodoros_",
            "• _pomodoro 50min travail 10min pause_",
            "• Calcule les horaires de chaque session et pause",
            "",
            "*⏱ Durée écoulée (elapsed time) :*",
            "• _durée entre 9h et 17h30_ / _combien de temps entre 8h30 et 12h45_",
            "• _elapsed time 14h to 22h15_ / _temps de travail de 9h à 18h_",
            "• _durée entre 2026-03-25 09:00 et 2026-03-27 17:30_ — cross-day",
            "• Affiche durée en h/min, décimal, et total minutes",
            "",
            "*🎯 Fenêtre Focus / Deep Work :*",
            "• _focus window Tokyo_ / _deep work New York_",
            "• _heures calmes entre moi et Londres_ / _quiet hours Dubai_",
            "• _créneau focus Singapore_ / _plage de concentration Sydney_",
            "• Trouve les créneaux où les 2 fuseaux sont hors bureau",
            "",
            "*⏱ Calcul de durée (time add) :*",
            "• _dans 2h30 il sera quelle heure_ / _timer 45min_",
            "• _14h + 3h45_ / _dans 1h30 quelle heure_",
            "• _dans 3h quelle heure à Tokyo_ — avec ville cible",
            "• Formats : 2h, 2h30, 45min, 1:30, 2.5h",
            "",
            "*🏆 Suggestion de créneau réunion :*",
            "• _meilleur horaire réunion Tokyo Paris New York_",
            "• _suggest meeting London Dubai Sydney_",
            "• _meilleur créneau 2h avec Tokyo et NYC_",
            "• Top 3 créneaux avec score de compatibilité",
            "",
            "*🌐 Résumé complet d'un fuseau (timezone summary) :*",
            "• _résumé fuseau_ / _infos fuseau_ / _timezone summary_",
            "• _résumé fuseau Tokyo_ / _tz info New York_ / _profil fuseau London_",
            "• Combine : heure, offset, DST, période, bureau, villes proches",
            "",
            "*📅 Plage de dates (date range) :*",
            "• _tous les lundis entre 2026-04-01 et 2026-06-30_",
            "• _dates entre 2026-05-01 et 2026-05-31_",
            "• _tous les vendredis de mai 2026_ / _every tuesday in june_",
            "• Filtre optionnel par jour, groupé par mois",
            "",
            "*🎨 Thème visuel :*",
            "• _mode sombre_ / _dark mode_ / _thème sombre_",
            "• _mode clair_ / _light mode_ / _thème auto_",
            "",
            "*📊 Complétude du profil :*",
            "• _complétude profil_ / _profil complet_ / _score profil_",
            "• Barre de progression + suggestions pour compléter",
            "",
            "*📋 Snapshot rapide :*",
            "• _snapshot_ / _résumé rapide_ / _quick summary_",
            "• Résumé en une ligne de toutes tes préférences",
            "",
            "*⭐ Villes favorites (v1.25) :*",
            "• _mes villes favorites_ / _liste villes_ — voir la liste",
            "• _ajouter ville Tokyo_ / _ajouter Tokyo et Dubai_ — ajouter",
            "• _supprimer ville Dubai_ — retirer une ville",
            "• _horloge favoris_ / _fav clock_ — heure dans tes favoris",
            "• Maximum 20 villes, persistantes entre sessions",
            "",
            "*🔄 Différence horaire (v1.25) :*",
            "• _différence horaire Paris Tokyo_ / _time diff London NYC_",
            "• _décalage entre moi et Tokyo_ / _combien d'heures entre Dubai et Sydney_",
            "• Affiche : décalage, heures locales, statut bureau",
            "",
            "*🌍 Profils régionaux (v1.26) :*",
            "• _profil français_ / _US profile_ / _profil allemand_ — appliquer un preset",
            "• _profils régionaux_ / _locale presets_ — voir la liste",
            "• Configure langue + fuseau + format date + unités en un seul coup",
            "• Presets : FR, US, UK, DE, ES, IT, PT, JA, AR, ZH",
            "",
            "*💼 Progression de la journée (v1.26) :*",
            "• _progression journée_ / _ma journée_ / _workday progress_",
            "• _fin de journée_ / _bilan journée_ / _temps de travail restant_",
            "• Barre de progression 9h-18h, milestones, stats semaine",
            "",
            "*📊 Overlap horaire (v1.27) :*",
            "• _overlap Tokyo_ / _chevauchement horaire New York_",
            "• _heures communes Dubai_ / _overlap bureau London_",
            "• Timeline visuelle 24h colorée + heures en commun",
            "",
            "*😴 Planning adaptation sommeil (v1.27) :*",
            "• _sleep schedule Paris Tokyo_ / _adaptation horaire NYC London_",
            "• _planning sommeil Dubai Singapore_ / _récupération jet lag_",
            "• Planning jour par jour avec conseils anti-jet-lag",
            "",
            "*✈️ Calculateur heure d'arrivée (v1.28) :*",
            "• _vol Paris Tokyo départ 14h durée 12h_",
            "• _flight New York London depart 22h duration 7h30_",
            "• _heure arrivée vol Dubai Singapore départ 8h durée 7h_",
            "• Calcule l'heure d'arrivée locale avec changement de fuseau",
            "",
            "*📋 Vérification d'échéance (v1.28) :*",
            "• _deadline 2026-06-30 rapport trimestriel_",
            "• _vérifier échéance 2026-04-15_ / _deadline check 2026-12-31_",
            "• Jours restants, jours ouvrés, rappels suggérés, détection week-end",
            "",
            "*📅 Progression du mois (v1.29) :*",
            "• _progression mois_ / _month progress_ / _stats mois_",
            "• _jours ouvrés ce mois_ / _bilan mois_ / _mois en cours_",
            "• Barre de progression, jours ouvrés passés/restants, paliers",
            "",
            "*📊 Progression du trimestre (v1.31) :*",
            "• _progression trimestre_ / _quarter progress_ / _stats trimestre_",
            "• Barre de progression, jours ouvrés, prochain trimestre",
            "",
            "*📆 Prochain jour de la semaine (v1.31) :*",
            "• _prochain vendredi_ / _next friday_ / _prochains 3 lundis_",
            "• Date, distance en jours, numéro de semaine ISO",
            "",
            "*⏰ Calculateur sommeil / alarme (v1.29) :*",
            "• _réveil 7h_ / _alarm wakeup 7h_ — quand me coucher ?",
            "• _coucher 23h_ / _alarm bedtime 23h_ — quand me réveiller ?",
            "• _alarm now_ / _si je dors maintenant_ — cycles depuis maintenant",
            "• Basé sur les cycles de sommeil (~90 min) pour un réveil optimal",
            "",
            "*📅 Nième jour du mois (v1.33) :*",
            "• _3ème vendredi de juin 2026_ / _third friday of june_",
            "• _1er lundi d'avril_ / _first monday of april_",
            "• _dernier vendredi de mai_ / _last friday of may_",
            "• _4ème jeudi de novembre_ (Thanksgiving US)",
            "• Trouve le Nième jour de la semaine dans un mois donné",
            "",
            "*📋 Briefing quotidien (v1.34) :*",
            "• _briefing du jour_ / _daily summary_ / _mon briefing_",
            "• _résumé du jour_ / _récap du jour_ / _bilan du jour_",
            "• Combine : date, progression journée/semaine/année, trimestre, DST",
            "",
            "*👥 Roster des fuseaux (v1.34) :*",
            "• _roster_ / _timezone roster_ / _team roster_",
            "• _roster Tokyo London NYC_ — villes spécifiques",
            "• _qui travaille_ / _qui dort_ / _statut équipe_",
            "• Statut en temps réel : 🟢 au bureau, 😴 dort, 🏖 week-end",
            "• Utilise tes villes favorites si aucune ville n'est précisée",
            "",
            "*📊 Score de productivité (v1.35) :*",
            "• _productivity score_ / _score productivité_ / _mes stats_",
            "• _tableau de bord_ / _dashboard_ / _bilan productivité_",
            "• Combine : progression journée, semaine, mois, trimestre, année",
            "",
            "*🕰 Historique des transitions horaires (v1.35) :*",
            "• _timezone history_ / _historique fuseau_ / _transitions horaires_",
            "• _historique changement heure Paris_ / _dst history NYC_",
            "• Affiche tous les changements DST du fuseau sur l'année en cours",
            "",
            "*🌍 Info saison (v1.37) :*",
            "• _quelle saison_ / _saison actuelle_ / _current season_",
            "• _info saison_ / _season info_ / _prochain solstice_",
            "• _saison hémisphère sud_ — forcer l'hémisphère",
            "• Saison actuelle, progression, dates clés (équinoxes, solstices)",
            "",
            "*⏱ Minuteur rapide (v1.37) :*",
            "• _minuteur 14h 2h30_ / _timer 9h 8h_ / _quick timer_",
            "• _si je commence à 14h pendant 2h30_ / _timer 45m_",
            "• _quand je finis si je commence à 9h pour 8h_ ",
            "• Calcule l'heure de fin à partir d'un début + durée",
            "",
            "*📈 Marchés financiers (v1.38) :*",
            "• _marchés financiers_ / _market hours_ / _bourse ouverte_",
            "• _est-ce que wall street est ouvert_ / _NYSE ouvert_",
            "• _horaires bourse_ / _trading hours_ / _quelles bourses sont ouvertes_",
            "• Statut en temps réel des principaux marchés mondiaux",
            "",
            "*📋 Résumé de la semaine (v1.38) :*",
            "• _résumé semaine_ / _week summary_ / _bilan semaine_",
            "• _recap semaine_ / _où en est ma semaine_ / _weekly recap_",
            "• Progression, jours ouvrés restants, countdown week-end",
            "",
            "*📐 Différence entre dates (v1.39) :*",
            "• _combien de jours entre le 1er janvier et le 15 mars_",
            "• _différence entre 25/12/2025 et aujourd'hui_",
            "• _days between march 1 and june 30_",
            "• Jours, semaines+jours, mois+jours, jours ouvrés/week-ends",
            "",
            "*🕐 Date relative (v1.39) :*",
            "• _il y a combien de jours le 1er mars_ / _how long ago was jan 1_",
            "• _dans combien de jours le 15 avril_ / _days until dec 25_",
            "• _jours depuis le 1er janvier_ / _days since march 1_",
            "• Distance passée ou future, avec détail semaines/mois",
            "",
            "*📇 Fiche pratique fuseau (v1.40) :*",
            "• _cheatsheet Tokyo_ / _fiche fuseau New York_ / _tz card London_",
            "• _fiche pratique Dubai_ / _quick reference Singapore_",
            "• Fiche combinée : heure, décalage, DST, overlap, meilleur créneau",
            "",
            "*📊 Progression de projet (v1.40) :*",
            "• _project progress 2026-01-15 2026-06-30 Projet Alpha_",
            "• _progression projet du 1er janvier au 30 juin_",
            "• _suivi projet entre 2026-03-01 et 2026-09-30_",
            "• Barre de progression, jours écoulés/restants, % complet",
            "",
            "*⏳ Capsule temporelle (v1.41) :*",
            "• _capsule 1 an_ / _time capsule 5 years_ / _il y a 6 mois_",
            "• _capsule 100 jours_ / _il y a 2 semaines_ / _100 days ago_",
            "• Date, jour, heure exacte à ce moment-là + écart en jours",
            "",
            "*⚡ Niveau d'énergie / Rythme circadien (v1.42) :*",
            "• _niveau énergie_ / _energy level_ / _mon énergie_",
            "• _energy level Tokyo_ / _énergie New York_ / _quand travailler_",
            "• _heures productives_ / _peak hours_ / _conseil productivité_",
            "• Niveau d'énergie estimé + type de tâches recommandées",
            "",
            "*📞 Indicatif international (v1.42) :*",
            "• _indicatif France_ / _dialing code Japan_ / _code pays UK_",
            "• _comment appeler le Japon_ / _how to call Germany_",
            "• _indicatif Dubai_ / _country code USA_ / _phone code Italy_",
            "• Indicatif + heure locale + conseil d'appel + heures bureau converties",
            "",
            "*🌅 Salut intelligent (v1.41) :*",
            "• _salut Tokyo_ / _greeting New York_ / _comment saluer Dubai_",
            "• _bonjour ou bonsoir London_ / _quel salut pour Singapore_",
            "• Salut adapté, heure locale, traduction locale, conseil communication",
            "",
            "*🌅 Routine matinale (v1.46) :*",
            "• _morning routine_ / _routine matinale_ / _ma routine_",
            "• _start my day_ / _commencer ma journée_ / _checklist matin_",
            "• Checklist personnalisée selon l'heure, le jour et tes préférences",
            "",
            "*📊 Comparaison des préférences (v1.46) :*",
            "• _compare preferences_ / _comparer préférences_ / _benchmark preferences_",
            "• _compare with US_ / _vs locale japonaise_ / _comparaison locale_",
            "• Compare tes réglages avec les valeurs par défaut régionales",
            "",
            "*📋 Bilan hebdomadaire (v1.48) :*",
            "• _bilan de la semaine_ / _weekly review_ / _recap semaine_",
            "• _résumé hebdomadaire_ / _revue de la semaine_ / _bilan hebdo_",
            "• Recap complète : productivité, progression, aperçu semaine suivante",
            "",
            "*🎯 Suivi d'habitudes (v1.48) :*",
            "• _mes habitudes_ / _habit tracker_ / _suivi habitudes_",
            "• _daily habits_ / _habit check_ / _mes objectifs du jour_",
            "• Check-in quotidien : hydratation, exercice, lecture, concentration",
            "",
            "*🎯 Suivi d'objectifs (v1.49) :*",
            "• _mes objectifs_ / _goal tracker_ / _my goals_",
            "• _goal tracker Apprendre japonais deadline 2026-12-31 progress 40_",
            "• Dashboard motivationnel + barre de progression + rythme quotidien",
            "",
            "*🤝 Partenaire de fuseau (v1.49) :*",
            "• _timezone buddy Tokyo, London, Sydney_ — trouve le fuseau le plus compatible",
            "• _tz buddy_ / _fuseau ami_ / _partenaire fuseau_ — utilise tes favoris",
            "• Classement par écart horaire + conseil de collaboration",
            "",
            "*🎂 Compte à rebours anniversaire (v1.55) :*",
            "• _countdown anniversaire 1990-05-15_ / _birthday countdown 1985-12-25_",
            "• _prochain anniversaire né le 1992-07-04_ / _my birthday 2000-01-01_",
            "• Jours restants, âge actuel, progression + message spécial si imminent",
            "",
            "*⚡ Configuration rapide (v1.55) :*",
            "• _quick setup_ / _setup rapide_ / _configuration rapide_ / _configurer_",
            "• _quick start_ / _démarrage rapide_ / _guide configuration_",
            "• Assistant guidé : voir l'état du profil + suggestions pour chaque paramètre",
            "",
            "*🎲 Roulette fuseau (v1.59) :*",
            "• _timezone roulette_ / _roulette fuseau_ / _random city_",
            "• _ville aléatoire_ / _surprise fuseau_ / _discover city_",
            "• Découvre une ville au hasard : heure, fun fact, bon moment pour appeler",
            "",
            "*💰 Coût de réunion multi-fuseaux (v1.59) :*",
            "• _meeting cost Tokyo London New York_ / _coût réunion Paris Dubai Sydney_",
            "• _timezone tax Paris Tokyo à 14h_ / _meeting cost_ + villes",
            "• Score d'inconvénience par participant + équité globale + suggestion",
        ];

        return implode("\n", $lines);
    }

    private function i18nError(string $lang, string $type): string
    {
        $messages = [
            'fr' => [
                'db'          => "⚠️ Erreur temporaire de base de données. Réessaie dans quelques instants.",
                'rate_limit'  => "⚠️ Trop de requêtes en ce moment. Réessaie dans 30 secondes.",
                'timeout'     => "⚠️ Le service a mis trop de temps à répondre. Réessaie dans quelques instants.",
                'overloaded'  => "⚠️ Le service est temporairement surchargé. Réessaie dans 1-2 minutes.",
                'connection'  => "⚠️ Problème de connexion au service. Vérifie ta connexion et réessaie.",
                'auth'        => "⚠️ Erreur d'authentification du service. Contacte l'administrateur.",
                'memory'      => "⚠️ Le service a manqué de mémoire. Essaie une demande plus simple.",
                'input_error' => "⚠️ Données invalides reçues. Vérifie ta demande et réessaie.\n\n_Tape *aide preferences* pour voir les commandes._",
                'json_error'  => "⚠️ Erreur de traitement de la réponse. Réessaie avec une demande plus simple.\n\n_Tape *aide preferences* pour voir les commandes._",
                'default'     => "⚠️ Une erreur inattendue s'est produite. Réessaie ou tape *aide preferences* pour voir les commandes disponibles.",
            ],
            'en' => [
                'db'          => "⚠️ Temporary database error. Please try again in a moment.",
                'rate_limit'  => "⚠️ Too many requests right now. Please try again in 30 seconds.",
                'timeout'     => "⚠️ The service took too long to respond. Please try again.",
                'overloaded'  => "⚠️ The service is temporarily overloaded. Please try again in 1-2 minutes.",
                'connection'  => "⚠️ Connection issue. Please check your connection and try again.",
                'auth'        => "⚠️ Service authentication error. Please contact the administrator.",
                'memory'      => "⚠️ The service ran out of memory. Try a simpler request.",
                'input_error' => "⚠️ Invalid data received. Please check your request and try again.\n\n_Type *help preferences* for available commands._",
                'json_error'  => "⚠️ Response processing error. Try again with a simpler request.\n\n_Type *help preferences* for available commands._",
                'default'     => "⚠️ An unexpected error occurred. Try again or type *help preferences* to see available commands.",
            ],
            'es' => [
                'db'          => "⚠️ Error temporal de base de datos. Inténtalo de nuevo en unos instantes.",
                'rate_limit'  => "⚠️ Demasiadas solicitudes. Inténtalo de nuevo en 30 segundos.",
                'timeout'     => "⚠️ El servicio tardó demasiado en responder. Inténtalo de nuevo.",
                'overloaded'  => "⚠️ El servicio está temporalmente sobrecargado. Inténtalo en 1-2 minutos.",
                'connection'  => "⚠️ Problema de conexión. Verifica tu conexión e inténtalo de nuevo.",
                'auth'        => "⚠️ Error de autenticación del servicio. Contacta al administrador.",
                'memory'      => "⚠️ El servicio se quedó sin memoria. Intenta una solicitud más simple.",
                'input_error' => "⚠️ Datos inválidos recibidos. Verifica tu solicitud e inténtalo de nuevo.\n\n_Escribe *ayuda preferencias* para los comandos._",
                'json_error'  => "⚠️ Error al procesar la respuesta. Inténtalo con una solicitud más simple.\n\n_Escribe *ayuda preferencias* para los comandos._",
                'default'     => "⚠️ Ocurrió un error inesperado. Inténtalo de nuevo o escribe *ayuda preferencias*.",
            ],
            'de' => [
                'db'          => "⚠️ Vorübergehender Datenbankfehler. Bitte versuche es gleich nochmal.",
                'rate_limit'  => "⚠️ Zu viele Anfragen. Bitte versuche es in 30 Sekunden erneut.",
                'timeout'     => "⚠️ Der Dienst hat zu lange gebraucht. Bitte versuche es erneut.",
                'overloaded'  => "⚠️ Der Dienst ist vorübergehend überlastet. Bitte versuche es in 1-2 Minuten.",
                'connection'  => "⚠️ Verbindungsproblem. Bitte überprüfe deine Verbindung und versuche es erneut.",
                'auth'        => "⚠️ Authentifizierungsfehler. Bitte kontaktiere den Administrator.",
                'memory'      => "⚠️ Dem Dienst ging der Speicher aus. Versuche eine einfachere Anfrage.",
                'input_error' => "⚠️ Ungültige Daten empfangen. Überprüfe deine Anfrage und versuche es erneut.\n\n_Tippe *Hilfe Einstellungen* für die Befehle._",
                'json_error'  => "⚠️ Fehler bei der Antwortverarbeitung. Versuche es mit einer einfacheren Anfrage.\n\n_Tippe *Hilfe Einstellungen* für die Befehle._",
                'default'     => "⚠️ Ein unerwarteter Fehler ist aufgetreten. Versuche es erneut oder tippe *Hilfe Einstellungen*.",
            ],
            'it' => [
                'db'          => "⚠️ Errore temporaneo del database. Riprova tra qualche istante.",
                'rate_limit'  => "⚠️ Troppe richieste. Riprova tra 30 secondi.",
                'timeout'     => "⚠️ Il servizio ha impiegato troppo tempo. Riprova.",
                'overloaded'  => "⚠️ Il servizio è temporaneamente sovraccarico. Riprova tra 1-2 minuti.",
                'connection'  => "⚠️ Problema di connessione. Verifica la tua connessione e riprova.",
                'auth'        => "⚠️ Errore di autenticazione. Contatta l'amministratore.",
                'memory'      => "⚠️ Il servizio ha esaurito la memoria. Prova una richiesta più semplice.",
                'input_error' => "⚠️ Dati non validi ricevuti. Verifica la tua richiesta e riprova.\n\n_Scrivi *aiuto preferenze* per i comandi._",
                'json_error'  => "⚠️ Errore nell'elaborazione della risposta. Riprova con una richiesta più semplice.\n\n_Scrivi *aiuto preferenze* per i comandi._",
                'default'     => "⚠️ Si è verificato un errore imprevisto. Riprova o scrivi *aiuto preferenze*.",
            ],
            'pt' => [
                'db'          => "⚠️ Erro temporário de banco de dados. Tente novamente em instantes.",
                'rate_limit'  => "⚠️ Muitas requisições. Tente novamente em 30 segundos.",
                'timeout'     => "⚠️ O serviço demorou demais. Tente novamente.",
                'overloaded'  => "⚠️ O serviço está temporariamente sobrecarregado. Tente em 1-2 minutos.",
                'connection'  => "⚠️ Problema de conexão. Verifique sua conexão e tente novamente.",
                'auth'        => "⚠️ Erro de autenticação. Entre em contato com o administrador.",
                'memory'      => "⚠️ O serviço ficou sem memória. Tente uma solicitação mais simples.",
                'input_error' => "⚠️ Dados inválidos recebidos. Verifique sua solicitação e tente novamente.\n\n_Digite *ajuda preferências* para os comandos._",
                'json_error'  => "⚠️ Erro ao processar a resposta. Tente novamente com uma solicitação mais simples.\n\n_Digite *ajuda preferências* para os comandos._",
                'default'     => "⚠️ Ocorreu um erro inesperado. Tente novamente ou digite *ajuda preferências*.",
            ],
            // v1.43.0 — additional languages
            'ar' => [
                'db'          => "⚠️ خطأ مؤقت في قاعدة البيانات. حاول مرة أخرى بعد لحظات.",
                'rate_limit'  => "⚠️ طلبات كثيرة جداً. حاول مرة أخرى بعد 30 ثانية.",
                'timeout'     => "⚠️ استغرقت الخدمة وقتاً طويلاً. حاول مرة أخرى.",
                'overloaded'  => "⚠️ الخدمة محملة مؤقتاً. حاول بعد 1-2 دقيقة.",
                'connection'  => "⚠️ مشكلة في الاتصال. تحقق من اتصالك وحاول مرة أخرى.",
                'auth'        => "⚠️ خطأ في المصادقة. اتصل بالمسؤول.",
                'memory'      => "⚠️ نفدت ذاكرة الخدمة. جرّب طلباً أبسط.",
                'input_error' => "⚠️ بيانات غير صالحة. تحقق من طلبك وحاول مرة أخرى.\n\n_اكتب *مساعدة التفضيلات* للأوامر._",
                'default'     => "⚠️ حدث خطأ غير متوقع. حاول مرة أخرى أو اكتب *مساعدة التفضيلات*.",
            ],
            'zh' => [
                'db'          => "⚠️ 数据库临时错误，请稍后重试。",
                'rate_limit'  => "⚠️ 请求过多，请30秒后重试。",
                'timeout'     => "⚠️ 服务响应超时，请重试。",
                'overloaded'  => "⚠️ 服务暂时过载，请1-2分钟后重试。",
                'connection'  => "⚠️ 连接问题，请检查网络后重试。",
                'auth'        => "⚠️ 认证错误，请联系管理员。",
                'memory'      => "⚠️ 服务内存不足，请尝试更简单的请求。",
                'input_error' => "⚠️ 收到无效数据，请检查请求后重试。\n\n_输入 *帮助 偏好设置* 查看命令。_",
                'default'     => "⚠️ 发生意外错误。请重试或输入 *帮助 偏好设置*。",
            ],
            'ja' => [
                'db'          => "⚠️ データベースの一時的なエラーです。しばらくしてから再試行してください。",
                'rate_limit'  => "⚠️ リクエストが多すぎます。30秒後に再試行してください。",
                'timeout'     => "⚠️ サービスの応答がタイムアウトしました。再試行してください。",
                'overloaded'  => "⚠️ サービスが一時的に過負荷です。1-2分後に再試行してください。",
                'connection'  => "⚠️ 接続の問題です。接続を確認して再試行してください。",
                'auth'        => "⚠️ 認証エラーです。管理者に連絡してください。",
                'memory'      => "⚠️ サービスのメモリが不足しています。より簡単なリクエストをお試しください。",
                'input_error' => "⚠️ 無効なデータを受信しました。リクエストを確認して再試行してください。\n\n_*help preferences* と入力してコマンドを確認してください。_",
                'json_error'  => "⚠️ レスポンスの処理でエラーが発生しました。より簡単なリクエストで再試行してください。\n\n_*help preferences* と入力してコマンドを確認してください。_",
                'default'     => "⚠️ 予期しないエラーが発生しました。再試行するか、*help preferences* と入力してください。",
            ],
            'ko' => [
                'db'          => "⚠️ 일시적인 데이터베이스 오류입니다. 잠시 후 다시 시도해 주세요.",
                'rate_limit'  => "⚠️ 요청이 너무 많습니다. 30초 후에 다시 시도해 주세요.",
                'timeout'     => "⚠️ 서비스 응답 시간이 초과되었습니다. 다시 시도해 주세요.",
                'overloaded'  => "⚠️ 서비스가 일시적으로 과부하 상태입니다. 1-2분 후에 다시 시도해 주세요.",
                'connection'  => "⚠️ 연결 문제입니다. 연결을 확인하고 다시 시도해 주세요.",
                'auth'        => "⚠️ 인증 오류입니다. 관리자에게 문의해 주세요.",
                'memory'      => "⚠️ 서비스 메모리가 부족합니다. 더 간단한 요청을 시도해 주세요.",
                'input_error' => "⚠️ 잘못된 데이터가 수신되었습니다. 요청을 확인하고 다시 시도해 주세요.\n\n_*help preferences*를 입력하여 명령어를 확인하세요._",
                'json_error'  => "⚠️ 응답 처리 중 오류가 발생했습니다. 더 간단한 요청으로 다시 시도해 주세요.\n\n_*help preferences*를 입력하여 명령어를 확인하세요._",
                'default'     => "⚠️ 예기치 않은 오류가 발생했습니다. 다시 시도하거나 *help preferences*를 입력하세요.",
            ],
            'ru' => [
                'db'          => "⚠️ Временная ошибка базы данных. Попробуйте через несколько секунд.",
                'rate_limit'  => "⚠️ Слишком много запросов. Попробуйте через 30 секунд.",
                'timeout'     => "⚠️ Сервис не ответил вовремя. Попробуйте ещё раз.",
                'overloaded'  => "⚠️ Сервис временно перегружен. Попробуйте через 1-2 минуты.",
                'connection'  => "⚠️ Проблема с подключением. Проверьте соединение и попробуйте снова.",
                'auth'        => "⚠️ Ошибка аутентификации. Обратитесь к администратору.",
                'memory'      => "⚠️ Сервису не хватило памяти. Попробуйте более простой запрос.",
                'input_error' => "⚠️ Получены неверные данные. Проверьте запрос и попробуйте снова.\n\n_Введите *help preferences* для списка команд._",
                'json_error'  => "⚠️ Ошибка обработки ответа. Попробуйте более простой запрос.\n\n_Введите *help preferences* для списка команд._",
                'default'     => "⚠️ Произошла непредвиденная ошибка. Попробуйте снова или введите *help preferences*.",
            ],
            'nl' => [
                'db'          => "⚠️ Tijdelijke databasefout. Probeer het over enkele ogenblikken opnieuw.",
                'rate_limit'  => "⚠️ Te veel verzoeken. Probeer het over 30 seconden opnieuw.",
                'timeout'     => "⚠️ De service duurde te lang. Probeer het opnieuw.",
                'overloaded'  => "⚠️ De service is tijdelijk overbelast. Probeer het over 1-2 minuten.",
                'connection'  => "⚠️ Verbindingsprobleem. Controleer je verbinding en probeer opnieuw.",
                'auth'        => "⚠️ Authenticatiefout. Neem contact op met de beheerder.",
                'memory'      => "⚠️ De service heeft onvoldoende geheugen. Probeer een eenvoudiger verzoek.",
                'input_error' => "⚠️ Ongeldige gegevens ontvangen. Controleer je verzoek en probeer opnieuw.\n\n_Typ *help preferences* voor de opdrachten._",
                'json_error'  => "⚠️ Fout bij het verwerken van het antwoord. Probeer een eenvoudiger verzoek.\n\n_Typ *help preferences* voor de opdrachten._",
                'default'     => "⚠️ Er is een onverwachte fout opgetreden. Probeer opnieuw of typ *help preferences*.",
            ],
        ];

        return $messages[$lang][$type] ?? $messages['fr'][$type] ?? $messages['fr']['default'];
    }

    private function formatKeyLabel(string $key, string $lang = 'fr'): string
    {
        $labels = [
            'fr' => [
                'language' => 'Langue', 'timezone' => 'Fuseau horaire', 'date_format' => 'Format date',
                'unit_system' => 'Unités', 'communication_style' => 'Style', 'theme' => 'Thème',
                'notification_enabled' => 'Notifications', 'phone' => 'Téléphone', 'email' => 'Email',
            ],
            'en' => [
                'language' => 'Language', 'timezone' => 'Timezone', 'date_format' => 'Date format',
                'unit_system' => 'Units', 'communication_style' => 'Style', 'theme' => 'Theme',
                'notification_enabled' => 'Notifications', 'phone' => 'Phone', 'email' => 'Email',
            ],
            'es' => [
                'language' => 'Idioma', 'timezone' => 'Zona horaria', 'date_format' => 'Formato fecha',
                'unit_system' => 'Unidades', 'communication_style' => 'Estilo', 'theme' => 'Tema',
                'notification_enabled' => 'Notificaciones', 'phone' => 'Teléfono', 'email' => 'Correo',
            ],
            'de' => [
                'language' => 'Sprache', 'timezone' => 'Zeitzone', 'date_format' => 'Datumsformat',
                'unit_system' => 'Einheiten', 'communication_style' => 'Stil', 'theme' => 'Thema',
                'notification_enabled' => 'Benachrichtigungen', 'phone' => 'Telefon', 'email' => 'E-Mail',
            ],
        ];

        return $labels[$lang][$key] ?? $labels['fr'][$key] ?? $key;
    }

    private function formatValue(string $key, mixed $value, string $lang = 'fr'): string
    {
        if ($value === null || $value === '') {
            return match ($lang) {
                'en' => '_not set_', 'es' => '_no definido_', 'de' => '_nicht definiert_',
                default => '_non défini_',
            };
        }

        return match ($key) {
            'language'             => (self::LANGUAGE_LABELS[$value] ?? $value) . " ({$value})",
            'communication_style'  => (self::STYLE_LABELS[$value] ?? $value),
            'notification_enabled' => match ($lang) {
                'en' => ($value || $value === true || $value === 1) ? '🔔 Enabled' : '🔕 Disabled',
                'es' => ($value || $value === true || $value === 1) ? '🔔 Activadas' : '🔕 Desactivadas',
                'de' => ($value || $value === true || $value === 1) ? '🔔 Aktiviert' : '🔕 Deaktiviert',
                default => ($value || $value === true || $value === 1) ? '🔔 Activées' : '🔕 Désactivées',
            },
            'unit_system'          => ($value === 'imperial' ? '🇺🇸 ' : '🌍 ') . $value,
            'theme'                => match ($value) { 'dark' => '🌙 dark', 'light' => '☀️ light', default => '🔄 auto' },
            default                => (string) $value,
        };
    }

    // -------------------------------------------------------------------------
    // v1.32.0 — week_progress
    // -------------------------------------------------------------------------

    private function handleWeekProgress(array $prefs): AgentResult
    {
        $userTz    = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now       = new DateTimeImmutable('now', $userTz);
        $lang      = $prefs['language'] ?? 'fr';
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';

        $currentDow = (int) $now->format('N'); // 1=Mon .. 7=Sun
        $monday     = $now->modify('-' . ($currentDow - 1) . ' days')->setTime(0, 0, 0);
        $sunday     = $monday->modify('+6 days');

        $totalDays   = 7;
        $elapsedDays = $currentDow;
        $remaining   = $totalDays - $elapsedDays;
        $progress    = $elapsedDays / $totalDays;
        $pct         = (int) round($progress * 100);

        // Progress bar (20 chars)
        $filled = (int) round($progress * 20);
        $bar    = str_repeat('▓', $filled) . str_repeat('░', 20 - $filled);

        // Workdays elapsed / remaining
        $elapsedWork  = min($currentDow, 5);
        $remainWork   = max(0, 5 - $elapsedWork);
        $weekendDays  = max(0, $currentDow - 5);

        $weekNum = (int) $now->format('W');

        $titleLabel   = match ($lang) { 'en' => 'WEEK PROGRESS', 'es' => 'PROGRESO DE LA SEMANA', 'de' => 'WOCHENFORTSCHRITT', default => 'PROGRESSION DE LA SEMAINE' };
        $dayLabel     = match ($lang) { 'en' => 'Day', 'es' => 'Día', 'de' => 'Tag', default => 'Jour' };
        $remainLabel  = match ($lang) { 'en' => 'Days remaining', 'es' => 'Días restantes', 'de' => 'Verbleibende Tage', default => 'Jours restants' };
        $workLabel    = match ($lang) { 'en' => 'Workdays', 'es' => 'Días laborables', 'de' => 'Arbeitstage', default => 'Jours ouvrés' };
        $passedStr    = match ($lang) { 'en' => 'elapsed', 'es' => 'pasados', 'de' => 'vergangen', default => 'passés' };
        $remainingStr = match ($lang) { 'en' => 'remaining', 'es' => 'restantes', 'de' => 'verbleibend', default => 'restants' };
        $todayLabel   = match ($lang) { 'en' => 'Today', 'es' => 'Hoy', 'de' => 'Heute', default => "Aujourd'hui" };
        $weekLabel    = match ($lang) { 'en' => 'Week', 'es' => 'Semana', 'de' => 'Woche', default => 'Semaine' };
        $periodLabel  = match ($lang) { 'en' => 'Period', 'es' => 'Período', 'de' => 'Zeitraum', default => 'Période' };

        $todayDayName = $this->getDayName((int) $now->format('w'), $lang);

        $lines = [
            "📅 *{$titleLabel} — {$weekLabel} {$weekNum}*",
            "────────────────",
            "",
            "[{$bar}] *{$pct}%*",
            "",
            "📅 {$periodLabel} : *{$monday->format($dateFormat)}* → *{$sunday->format($dateFormat)}*",
            "📆 {$dayLabel} : *{$elapsedDays}* / {$totalDays} ({$todayDayName})",
            "📊 {$remainLabel} : *{$remaining}*",
            "💼 {$workLabel} : *{$elapsedWork}* {$passedStr} / *{$remainWork}* {$remainingStr}",
            "",
            "📍 {$todayLabel} : *{$now->format($dateFormat . ' H:i')}*",
        ];

        // Day-by-day view
        $calLabel = match ($lang) { 'en' => 'This week', 'es' => 'Esta semana', 'de' => 'Diese Woche', default => 'Cette semaine' };
        $lines[] = "";
        $lines[] = "🗓 *{$calLabel} :*";
        for ($d = 0; $d < 7; $d++) {
            $date     = $monday->modify("+{$d} days");
            $dayName  = $this->getDayName((int) $date->format('w'), $lang, short: true);
            $isToday  = $date->format('Y-m-d') === $now->format('Y-m-d');
            $marker   = $isToday ? ' 👈' : '';
            $isWeekend = $d >= 5;
            $prefix   = $isWeekend ? '  ' : '';
            $lines[]  = "{$prefix}• {$dayName} *{$date->format('d/m')}*{$marker}";
        }

        $tipLabel = match ($lang) { 'en' => 'Ex: week progress', 'es' => 'Ej: progreso semana', 'de' => 'Bsp: Wochenfortschritt', default => 'Ex : progression semaine, week progress' };
        $lines[] = "";
        $lines[] = "────────────────";
        $lines[] = "💡 _{$tipLabel}_";

        return AgentResult::reply(implode("\n", $lines), [
            'action'        => 'week_progress',
            'week_number'   => $weekNum,
            'day_of_week'   => $currentDow,
            'elapsed_days'  => $elapsedDays,
            'remaining'     => $remaining,
            'progress_pct'  => $pct,
            'workdays_done' => $elapsedWork,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.32.0 — batch_countdown
    // -------------------------------------------------------------------------

    private function handleBatchCountdown(array $parsed, array $prefs): AgentResult
    {
        $events = $parsed['events'] ?? [];
        $lang   = $prefs['language'] ?? 'fr';

        if (!is_array($events) || count($events) === 0) {
            $msg = match ($lang) {
                'en' => "⚠️ Please provide events with dates.\n\n_Example: countdown Christmas 2026-12-25 and New Year 2027-01-01_\n_Or: batch countdown vacances 2026-07-01, rentrée 2026-09-01_",
                'es' => "⚠️ Indica eventos con fechas.\n\n_Ejemplo: countdown Navidad 2026-12-25 y Año Nuevo 2027-01-01_",
                'de' => "⚠️ Bitte gib Ereignisse mit Datum an.\n\n_Beispiel: Countdown Weihnachten 2026-12-25 und Neujahr 2027-01-01_",
                default => "⚠️ Précise des événements avec leurs dates.\n\n_Exemple : countdown Noël 2026-12-25 et Nouvel An 2027-01-01_\n_Ou : batch countdown vacances 2026-07-01, rentrée 2026-09-01_",
            };
            return AgentResult::reply($msg, ['action' => 'batch_countdown', 'error' => 'no_events']);
        }

        $events = array_slice($events, 0, 10);

        $userTz     = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now        = new DateTimeImmutable('now', $userTz);
        $todayDate  = $now->setTime(0, 0, 0);
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';

        $items = [];
        foreach ($events as $evt) {
            $dateStr = trim($evt['date'] ?? '');
            $label   = trim($evt['label'] ?? '');
            if ($dateStr === '') {
                continue;
            }
            try {
                $target    = new DateTimeImmutable($dateStr, $userTz);
                $targetDay = $target->setTime(0, 0, 0);
                $diff      = (int) $todayDate->diff($targetDay)->format('%r%a');
                $items[]   = ['date' => $target, 'label' => $label, 'days' => $diff];
            } catch (\Exception) {
                // Skip invalid dates silently
            }
        }

        if (count($items) === 0) {
            $msg = match ($lang) {
                'en' => "⚠️ No valid dates found. Use format YYYY-MM-DD.",
                'es' => "⚠️ No se encontraron fechas válidas. Usa el formato AAAA-MM-DD.",
                'de' => "⚠️ Keine gültigen Daten gefunden. Verwende das Format JJJJ-MM-TT.",
                default => "⚠️ Aucune date valide trouvée. Utilise le format AAAA-MM-JJ.",
            };
            return AgentResult::reply($msg, ['action' => 'batch_countdown', 'error' => 'invalid_dates']);
        }

        // Sort by days remaining (closest first, past events last)
        usort($items, function ($a, $b) {
            if ($a['days'] >= 0 && $b['days'] >= 0) return $a['days'] - $b['days'];
            if ($a['days'] < 0 && $b['days'] < 0) return $b['days'] - $a['days'];
            return $a['days'] >= 0 ? -1 : 1;
        });

        $titleLabel = match ($lang) { 'en' => 'COUNTDOWNS', 'es' => 'CUENTAS ATRÁS', 'de' => 'COUNTDOWNS', default => 'COMPTES À REBOURS' };
        $lines = [
            "⏳ *{$titleLabel}*",
            "────────────────",
        ];

        foreach ($items as $item) {
            $days   = $item['days'];
            $label  = $item['label'] !== '' ? $item['label'] : $item['date']->format($dateFormat);
            $dateDisp = $item['date']->format($dateFormat);
            $dayName = $this->getDayName((int) $item['date']->format('w'), $lang, short: true);
            $absDays = abs($days);

            if ($days < 0) {
                $pastStr = match ($lang) {
                    'en' => "{$absDays} day" . ($absDays > 1 ? 's' : '') . " ago",
                    'es' => "hace {$absDays} día" . ($absDays > 1 ? 's' : ''),
                    'de' => "vor {$absDays} Tag" . ($absDays > 1 ? 'en' : ''),
                    default => "il y a {$absDays} jour" . ($absDays > 1 ? 's' : ''),
                };
                $lines[] = "";
                $lines[] = "🔴 *{$label}* — {$dateDisp} ({$dayName})";
                $lines[] = "   _{$pastStr}_";
            } elseif ($days === 0) {
                $todayStr = match ($lang) { 'en' => "Today!", 'es' => "¡Hoy!", 'de' => "Heute!", default => "Aujourd'hui !" };
                $lines[] = "";
                $lines[] = "🎉 *{$label}* — {$dateDisp} ({$dayName})";
                $lines[] = "   *{$todayStr}*";
            } else {
                $urgency = match (true) {
                    $days <= 3  => '🔴',
                    $days <= 7  => '🟠',
                    $days <= 30 => '🟡',
                    default     => '🟢',
                };
                $inStr = match ($lang) {
                    'en' => "in *{$days}* day" . ($days > 1 ? 's' : ''),
                    'es' => "en *{$days}* día" . ($days > 1 ? 's' : ''),
                    'de' => "in *{$days}* Tag" . ($days > 1 ? 'en' : ''),
                    default => "dans *{$days}* jour" . ($days > 1 ? 's' : ''),
                };
                // Mini progress bar (10 chars, capped at 365 days)
                $cappedDays = min($days, 365);
                $pct     = max(0, (int) round((1 - $cappedDays / 365) * 100));
                $filled  = (int) round($pct / 10);
                $bar     = str_repeat('▓', $filled) . str_repeat('░', 10 - $filled);
                $lines[] = "";
                $lines[] = "{$urgency} *{$label}* — {$dateDisp} ({$dayName})";
                $lines[] = "   {$inStr} [{$bar}]";
            }
        }

        $tipLabel = match ($lang) {
            'en' => "Ex: countdown Christmas and New Year",
            'es' => "Ej: countdown Navidad y Año Nuevo",
            'de' => "Bsp: Countdown Weihnachten und Neujahr",
            default => "Ex : countdown Noël et Nouvel An",
        };
        $lines[] = "";
        $lines[] = "────────────────";
        $lines[] = "💡 _{$tipLabel}_";

        return AgentResult::reply(implode("\n", $lines), [
            'action' => 'batch_countdown',
            'count'  => count($items),
            'events' => array_map(fn($i) => ['label' => $i['label'], 'days' => $i['days']], $items),
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.30.0 — quarter_progress
    // -------------------------------------------------------------------------

    private function handleQuarterProgress(array $prefs): AgentResult
    {
        $userTz = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now    = new DateTimeImmutable('now', $userTz);
        $lang   = $prefs['language'] ?? 'fr';

        $month   = (int) $now->format('n');
        $quarter = (int) ceil($month / 3);
        $year    = (int) $now->format('Y');

        $qStartMonth = ($quarter - 1) * 3 + 1;
        $qEndMonth   = $quarter * 3;

        $qStart = new DateTimeImmutable("{$year}-{$qStartMonth}-01", $userTz);
        $qEnd   = new DateTimeImmutable("{$year}-{$qEndMonth}-01", $userTz);
        $qEnd   = new DateTimeImmutable($qEnd->format('Y-m-t'), $userTz);

        $totalDays  = (int) $qStart->diff($qEnd)->days + 1;
        $today      = $now->setTime(0, 0, 0);
        $elapsedDays = (int) $qStart->diff($today)->days + 1;
        $remainDays  = $totalDays - $elapsedDays;

        $progress = $elapsedDays / $totalDays;
        $pct      = (int) round($progress * 100);

        // Progress bar (20 chars)
        $filled = (int) round($progress * 20);
        $bar    = str_repeat('▓', $filled) . str_repeat('░', 20 - $filled);

        // Workdays
        $totalWorkDays = 0;
        $elapsedWork   = 0;
        $cursor        = clone $qStart;
        for ($d = 0; $d < $totalDays; $d++) {
            $date = $qStart->modify("+{$d} days");
            if ((int) $date->format('N') <= 5) {
                $totalWorkDays++;
                if ($date <= $today) {
                    $elapsedWork++;
                }
            }
        }
        $remainingWork = $totalWorkDays - $elapsedWork;

        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';

        $qLabel = "Q{$quarter}";
        $titleLabel   = match ($lang) { 'en' => 'QUARTER PROGRESS', 'es' => 'PROGRESO DEL TRIMESTRE', 'de' => 'QUARTALSFORTSCHRITT', default => 'PROGRESSION DU TRIMESTRE' };
        $periodLabel  = match ($lang) { 'en' => 'Period', 'es' => 'Período', 'de' => 'Zeitraum', default => 'Période' };
        $dayLabel     = match ($lang) { 'en' => 'Day', 'es' => 'Día', 'de' => 'Tag', default => 'Jour' };
        $remainLabel  = match ($lang) { 'en' => 'Days remaining', 'es' => 'Días restantes', 'de' => 'Verbleibende Tage', default => 'Jours restants' };
        $workLabel    = match ($lang) { 'en' => 'Workdays', 'es' => 'Días laborables', 'de' => 'Arbeitstage', default => 'Jours ouvrés' };
        $passedStr    = match ($lang) { 'en' => 'elapsed', 'es' => 'pasados', 'de' => 'vergangen', default => 'passés' };
        $remainingStr = match ($lang) { 'en' => 'remaining', 'es' => 'restantes', 'de' => 'verbleibend', default => 'restants' };

        $startMonthName = $this->getMonthName($qStartMonth, $lang);
        $endMonthName   = $this->getMonthName($qEndMonth, $lang);

        $lines = [
            "📊 *{$titleLabel} — {$qLabel} {$year}*",
            "────────────────",
            "",
            "[{$bar}] *{$pct}%*",
            "",
            "📅 {$periodLabel} : *{$startMonthName}* → *{$endMonthName}* {$year}",
            "📆 {$dayLabel} : *{$elapsedDays}* / {$totalDays}",
            "📊 {$remainLabel} : *{$remainDays}*",
            "💼 {$workLabel} : *{$elapsedWork}* {$passedStr} / *{$remainingWork}* {$remainingStr} (total: {$totalWorkDays})",
        ];

        // Next quarter info
        if ($quarter < 4) {
            $nextQ = $quarter + 1;
            $nextQStart = new DateTimeImmutable("{$year}-" . (($nextQ - 1) * 3 + 1) . "-01", $userTz);
            $daysToNext = (int) $today->diff($nextQStart)->days;
            $nextLabel = match ($lang) { 'en' => "Next quarter (Q{$nextQ})", 'es' => "Próximo trimestre (Q{$nextQ})", 'de' => "Nächstes Quartal (Q{$nextQ})", default => "Prochain trimestre (Q{$nextQ})" };
            $inLabel   = match ($lang) { 'en' => 'in', 'es' => 'en', 'de' => 'in', default => 'dans' };
            $daysWord  = match ($lang) { 'en' => 'days', 'es' => 'días', 'de' => 'Tagen', default => 'jours' };
            $lines[] = "";
            $lines[] = "🔜 {$nextLabel} : {$inLabel} *{$daysToNext}* {$daysWord}";
        }

        $tipLabel = match ($lang) { 'en' => 'Ex: quarter progress', 'es' => 'Ej: progreso trimestre', 'de' => 'Bsp: Quartalsfortschritt', default => 'Ex : progression trimestre' };
        $lines[] = "";
        $lines[] = "────────────────";
        $lines[] = "💡 _{$tipLabel}_";

        return AgentResult::reply(implode("\n", $lines), [
            'action'        => 'quarter_progress',
            'quarter'       => $quarter,
            'year'          => $year,
            'elapsed_days'  => $elapsedDays,
            'total_days'    => $totalDays,
            'progress_pct'  => $pct,
            'working_days'  => $totalWorkDays,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.30.0 — next_weekday
    // -------------------------------------------------------------------------

    private function handleNextWeekday(array $parsed, array $prefs): AgentResult
    {
        $dayStr = strtolower(trim($parsed['day'] ?? ''));
        $count  = max(1, min(8, (int) ($parsed['count'] ?? 1)));
        $lang   = $prefs['language'] ?? 'fr';

        // Map day names to ISO day number (1=Monday .. 7=Sunday)
        $dayMap = [
            'monday' => 1, 'lundi' => 1,
            'tuesday' => 2, 'mardi' => 2,
            'wednesday' => 3, 'mercredi' => 3,
            'thursday' => 4, 'jeudi' => 4,
            'friday' => 5, 'vendredi' => 5,
            'saturday' => 6, 'samedi' => 6,
            'sunday' => 7, 'dimanche' => 7,
        ];

        $targetDow = $dayMap[$dayStr] ?? null;

        if ($targetDow === null) {
            $msg = match ($lang) {
                'en' => "⚠️ Please specify a day of the week.\n\n_Example: next friday, next monday, prochains 3 vendredis_",
                'es' => "⚠️ Indica un día de la semana.\n\n_Ejemplo: next friday, next monday_",
                'de' => "⚠️ Bitte gib einen Wochentag an.\n\n_Beispiel: next friday, next monday_",
                default => "⚠️ Précise un jour de la semaine.\n\n_Exemple : prochain vendredi, prochain lundi, prochains 3 vendredis_",
            };
            return AgentResult::reply($msg, ['action' => 'next_weekday', 'error' => 'missing_day']);
        }

        $userTz    = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now       = new DateTimeImmutable('now', $userTz);
        $today     = $now->setTime(0, 0, 0);
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';

        $dayFullName = $this->getDayName($targetDow % 7, $lang); // getDayName uses 0=Sun convention

        $titleLabel = match ($lang) {
            'en' => $count > 1 ? "NEXT {$count} " . strtoupper($dayFullName) . "S" : "NEXT " . strtoupper($dayFullName),
            'es' => $count > 1 ? "PRÓXIMOS {$count} " . strtoupper($dayFullName) : "PRÓXIMO " . strtoupper($dayFullName),
            'de' => $count > 1 ? "NÄCHSTE {$count} " . strtoupper($dayFullName) : "NÄCHSTER " . strtoupper($dayFullName),
            default => $count > 1 ? "{$count} PROCHAINS " . strtoupper($dayFullName) . "S" : "PROCHAIN " . strtoupper($dayFullName),
        };

        $lines = [
            "📆 *{$titleLabel}*",
            "────────────────",
        ];

        $cursor = clone $today;
        $found  = 0;
        // Move to the next occurrence of the target day
        for ($i = 1; $i <= 400 && $found < $count; $i++) {
            $candidate = $today->modify("+{$i} days");
            if ((int) $candidate->format('N') === $targetDow) {
                $found++;
                $dayName   = $this->getDayName((int) $candidate->format('w'), $lang);
                $daysUntil = $i;
                $inLabel   = match ($lang) { 'en' => 'in', 'es' => 'en', 'de' => 'in', default => 'dans' };
                $daysWord  = match ($lang) { 'en' => $daysUntil > 1 ? 'days' : 'day', 'es' => $daysUntil > 1 ? 'días' : 'día', 'de' => $daysUntil > 1 ? 'Tagen' : 'Tag', default => $daysUntil > 1 ? 'jours' : 'jour' };
                $weekNum   = (int) $candidate->format('W');
                $weekLabel = match ($lang) { 'en' => "W{$weekNum}", 'es' => "S{$weekNum}", 'de' => "KW{$weekNum}", default => "S{$weekNum}" };
                $lines[]   = "• *{$candidate->format($dateFormat)}* ({$dayName}) — {$inLabel} *{$daysUntil}* {$daysWord} — {$weekLabel}";
            }
        }

        $tipLabel = match ($lang) {
            'en' => "Ex: next friday, next 3 mondays",
            'es' => "Ej: next friday, prochains 3 lundis",
            'de' => "Bsp: next friday, nächste 3 Montage",
            default => "Ex : prochain vendredi, prochains 3 lundis",
        };
        $lines[] = "";
        $lines[] = "────────────────";
        $lines[] = "💡 _{$tipLabel}_";

        return AgentResult::reply(implode("\n", $lines), [
            'action' => 'next_weekday',
            'day'    => $dayStr,
            'count'  => $count,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.28.0 — flight_time
    // -------------------------------------------------------------------------

    private function handleFlightTime(array $parsed, array $prefs): AgentResult
    {
        $from          = trim($parsed['from'] ?? '');
        $to            = trim($parsed['to'] ?? '');
        $departureTime = trim($parsed['departure_time'] ?? '');
        $duration      = trim($parsed['duration'] ?? '');
        $lang          = $prefs['language'] ?? 'fr';

        if ($from === '' || $to === '' || $departureTime === '' || $duration === '') {
            $msg = match ($lang) {
                'en' => "⚠️ Please provide all flight details: departure city, destination, departure time, and duration.\n\n_Example: flight Paris Tokyo depart 14h duration 12h_",
                'es' => "⚠️ Indica todos los detalles del vuelo: ciudad de salida, destino, hora de salida y duración.\n\n_Ejemplo: vuelo Paris Tokyo salida 14h duración 12h_",
                'de' => "⚠️ Bitte gib alle Flugdetails an: Abflugstadt, Ziel, Abflugzeit und Dauer.\n\n_Beispiel: Flug Paris Tokyo Abflug 14h Dauer 12h_",
                default => "⚠️ Précise tous les détails du vol : ville de départ, destination, heure de départ et durée.\n\n_Exemple : vol Paris Tokyo départ 14h durée 12h_",
            };
            return AgentResult::reply($msg, ['action' => 'flight_time', 'error' => 'missing_params']);
        }

        try {
            $resolvedFrom = $this->resolveTimezoneString($from);
            $resolvedTo   = $this->resolveTimezoneString($to);
            if (!$resolvedFrom || !$resolvedTo) {
                $bad = !$resolvedFrom ? $from : $to;
                return AgentResult::reply(
                    "⚠️ " . match ($lang) {
                        'en' => "Unknown city: *{$bad}*.", 'es' => "Ciudad desconocida: *{$bad}*.",
                        'de' => "Unbekannte Stadt: *{$bad}*.", default => "Ville inconnue : *{$bad}*.",
                    } . "\n\n_" . match ($lang) {
                        'en' => "Example: flight Paris Tokyo depart 14h duration 12h",
                        'es' => "Ejemplo: vuelo Paris Tokyo salida 14h duración 12h",
                        default => "Exemple : vol Paris Tokyo départ 14h durée 12h",
                    } . "_",
                    ['action' => 'flight_time', 'error' => 'unknown_city']
                );
            }

            $fromTz = new DateTimeZone($resolvedFrom);
            $toTz   = new DateTimeZone($resolvedTo);

            // Parse departure time
            $depNorm = str_ireplace(['h', 'am', 'pm'], [':', '', ''], $departureTime);
            $depNorm = preg_replace('/[^0-9:]/', '', $depNorm);
            if (!str_contains($depNorm, ':')) {
                $depNorm .= ':00';
            }
            $depParts = explode(':', $depNorm);
            $depHour  = (int) ($depParts[0] ?? 0);
            $depMin   = (int) ($depParts[1] ?? 0);
            // Handle PM for 12h format
            if (stripos($departureTime, 'pm') !== false && $depHour < 12) {
                $depHour += 12;
            }
            if (stripos($departureTime, 'am') !== false && $depHour === 12) {
                $depHour = 0;
            }
            $depHour = max(0, min(23, $depHour));
            $depMin  = max(0, min(59, $depMin));

            // Parse duration
            $durationMinutes = 0;
            if (preg_match('/(\d+)\s*h\s*(\d+)?/i', $duration, $m)) {
                $durationMinutes = (int) $m[1] * 60 + (int) ($m[2] ?? 0);
            } elseif (preg_match('/(\d+)\s*min/i', $duration, $m)) {
                $durationMinutes = (int) $m[1];
            } elseif (preg_match('/(\d+):(\d+)/', $duration, $m)) {
                $durationMinutes = (int) $m[1] * 60 + (int) $m[2];
            } elseif (preg_match('/(\d+\.?\d*)/', $duration, $m)) {
                $durationMinutes = (int) ((float) $m[1] * 60);
            }

            if ($durationMinutes <= 0 || $durationMinutes > 1440) {
                return AgentResult::reply(
                    "⚠️ " . match ($lang) {
                        'en' => "Invalid flight duration. Use formats like: 7h, 7h30, 90min, 1:30",
                        'es' => "Duración de vuelo no válida. Formatos: 7h, 7h30, 90min, 1:30",
                        default => "Durée de vol invalide. Formats acceptés : 7h, 7h30, 90min, 1:30",
                    }
                );
            }

            // Build departure datetime in departure timezone
            $today     = new DateTimeImmutable('now', $fromTz);
            $departure = $today->setTime($depHour, $depMin, 0);

            // Calculate arrival in UTC then convert to destination timezone
            $arrivalUtc = $departure->setTimezone(new DateTimeZone('UTC'))
                                    ->modify("+{$durationMinutes} minutes");
            $arrival    = $arrivalUtc->setTimezone($toTz);

            $fromShort = $this->getShortTzName($fromTz->getName());
            $toShort   = $this->getShortTzName($toTz->getName());

            $dH = intdiv($durationMinutes, 60);
            $dM = $durationMinutes % 60;
            $durationStr = $dM > 0 ? "{$dH}h{$dM}" : "{$dH}h";

            $depDayName = $this->getDayName((int) $departure->format('w'), $lang, short: true);
            $arrDayName = $this->getDayName((int) $arrival->format('w'), $lang, short: true);

            $daysDiff = (int) $arrival->format('z') - (int) $departure->setTimezone($fromTz)->format('z');
            $dayNote  = '';
            if ($daysDiff > 0) {
                $dayNote = match ($lang) {
                    'en' => " _(+{$daysDiff} day" . ($daysDiff > 1 ? 's' : '') . ")_",
                    'es' => " _(+{$daysDiff} día" . ($daysDiff > 1 ? 's' : '') . ")_",
                    'de' => " _(+{$daysDiff} Tag" . ($daysDiff > 1 ? 'e' : '') . ")_",
                    default => " _(+{$daysDiff} jour" . ($daysDiff > 1 ? 's' : '') . ")_",
                };
            } elseif ($daysDiff < 0) {
                $dayNote = match ($lang) {
                    'en' => " _({$daysDiff} day" . (abs($daysDiff) > 1 ? 's' : '') . ")_",
                    default => " _({$daysDiff} jour" . (abs($daysDiff) > 1 ? 's' : '') . ")_",
                };
            }

            $flightLabel = match ($lang) { 'en' => 'FLIGHT ARRIVAL CALCULATOR', 'es' => 'CALCULADORA DE LLEGADA', 'de' => 'ANKUNFTSRECHNER', default => 'CALCULATEUR HEURE D\'ARRIVÉE' };
            $depLabel    = match ($lang) { 'en' => 'Departure', 'es' => 'Salida', 'de' => 'Abflug', default => 'Départ' };
            $arrLabel    = match ($lang) { 'en' => 'Arrival', 'es' => 'Llegada', 'de' => 'Ankunft', default => 'Arrivée' };
            $durLabel    = match ($lang) { 'en' => 'Flight duration', 'es' => 'Duración vuelo', 'de' => 'Flugdauer', default => 'Durée vol' };

            $lines = [
                "✈️ *{$flightLabel}*",
                "────────────────",
                "🛫 *{$depLabel}* : *{$fromShort}* — *{$departure->format('H:i')}* ({$depDayName})",
                "🛬 *{$arrLabel}* : *{$toShort}* — *{$arrival->format('H:i')}* ({$arrDayName}){$dayNote}",
                "⏱ {$durLabel} : *{$durationStr}*",
                "",
                "📅 {$departure->format('d/m/Y')} → {$arrival->format('d/m/Y')}",
                "🕐 UTC{$departure->format('P')} → UTC{$arrival->format('P')}",
            ];

            return AgentResult::reply(implode("\n", $lines), [
                'action'    => 'flight_time',
                'from'      => $fromShort,
                'to'        => $toShort,
                'departure' => $departure->format('H:i'),
                'arrival'   => $arrival->format('H:i'),
                'duration'  => $durationStr,
            ]);
        } catch (\Exception $e) {
            return AgentResult::reply(
                "⚠️ " . match ($lang) {
                    'en' => "Error calculating flight time. Check your inputs.",
                    'es' => "Error al calcular el tiempo de vuelo. Verifica los datos.",
                    default => "Erreur lors du calcul. Vérifie tes paramètres.",
                },
                ['action' => 'flight_time', 'error' => $e->getMessage()]
            );
        }
    }

    // -------------------------------------------------------------------------
    // v1.28.0 — deadline_check
    // -------------------------------------------------------------------------

    private function handleDeadlineCheck(array $parsed, array $prefs): AgentResult
    {
        $dateStr = trim($parsed['date'] ?? '');
        $label   = trim($parsed['label'] ?? '');
        $lang    = $prefs['language'] ?? 'fr';

        if ($dateStr === '') {
            $msg = match ($lang) {
                'en' => "⚠️ Please provide a deadline date.\n\n_Example: deadline 2026-06-30 quarterly report_",
                'es' => "⚠️ Indica una fecha de vencimiento.\n\n_Ejemplo: deadline 2026-06-30 informe trimestral_",
                'de' => "⚠️ Bitte gib ein Fälligkeitsdatum an.\n\n_Beispiel: Deadline 2026-06-30 Quartalsbericht_",
                default => "⚠️ Précise une date d'échéance.\n\n_Exemple : deadline 2026-06-30 rapport trimestriel_",
            };
            return AgentResult::reply($msg, ['action' => 'deadline_check', 'error' => 'missing_date']);
        }

        try {
            $tz  = new DateTimeZone($prefs['timezone'] ?? 'UTC');
            $now = new DateTimeImmutable('now', $tz);

            if (strtolower($dateStr) === 'today') {
                $deadline = $now;
            } else {
                $deadline = new DateTimeImmutable($dateStr, $tz);
            }

            $deadlineDate = $deadline->setTime(0, 0, 0);
            $todayDate    = $now->setTime(0, 0, 0);

            $diff        = $todayDate->diff($deadlineDate);
            $totalDays   = (int) $diff->format('%r%a');
            $isPast      = $totalDays < 0;
            $absDays     = abs($totalDays);
            $dayOfWeek   = (int) $deadline->format('N'); // 1=Mon .. 7=Sun
            $isWorkday   = $dayOfWeek <= 5;

            // Count workdays between now and deadline
            $workdays = 0;
            $step     = $isPast ? -1 : 1;
            $cursor   = clone $todayDate;
            for ($i = 0; $i < $absDays; $i++) {
                $cursor = $cursor->modify("{$step} day");
                $dow    = (int) $cursor->format('N');
                if ($dow <= 5) {
                    $workdays++;
                }
            }

            // Determine urgency level
            $urgency = match (true) {
                $isPast      => '🔴',
                $totalDays <= 3  => '🔴',
                $totalDays <= 7  => '🟠',
                $totalDays <= 14 => '🟡',
                default          => '🟢',
            };

            $deadlineDayName = $this->getDayName((int) $deadline->format('w'), $lang);

            $titleLabel = match ($lang) { 'en' => 'DEADLINE CHECK', 'es' => 'VERIFICACIÓN DE FECHA LÍMITE', 'de' => 'FRISTPRÜFUNG', default => 'VÉRIFICATION ÉCHÉANCE' };
            $labelDisp  = $label !== '' ? " — _{$label}_" : '';

            $lines = [
                "{$urgency} *{$titleLabel}*{$labelDisp}",
                "────────────────",
                "📅 *{$deadline->format($prefs['date_format'] ?? 'd/m/Y')}* ({$deadlineDayName})",
            ];

            // Is it a workday?
            $workdayStr = match ($lang) {
                'en' => $isWorkday ? '✅ Workday' : '⚠️ Weekend',
                'es' => $isWorkday ? '✅ Día laborable' : '⚠️ Fin de semana',
                'de' => $isWorkday ? '✅ Werktag' : '⚠️ Wochenende',
                default => $isWorkday ? '✅ Jour ouvré' : '⚠️ Week-end',
            };
            $lines[] = "💼 {$workdayStr}";

            if ($isPast) {
                $passedStr = match ($lang) {
                    'en' => "Passed *{$absDays}* day" . ($absDays > 1 ? 's' : '') . " ago (*{$workdays}* workday" . ($workdays > 1 ? 's' : '') . ")",
                    'es' => "Pasó hace *{$absDays}* día" . ($absDays > 1 ? 's' : '') . " (*{$workdays}* laborable" . ($workdays > 1 ? 's' : '') . ")",
                    'de' => "Vor *{$absDays}* Tag" . ($absDays > 1 ? 'en' : '') . " abgelaufen (*{$workdays}* Werktag" . ($workdays > 1 ? 'e' : '') . ")",
                    default => "Passée depuis *{$absDays}* jour" . ($absDays > 1 ? 's' : '') . " (*{$workdays}* ouvré" . ($workdays > 1 ? 's' : '') . ")",
                };
                $lines[] = "⏰ {$passedStr}";
            } else {
                $remainStr = match ($lang) {
                    'en' => "*{$totalDays}* day" . ($totalDays > 1 ? 's' : '') . " remaining (*{$workdays}* workday" . ($workdays > 1 ? 's' : '') . ")",
                    'es' => "*{$totalDays}* día" . ($totalDays > 1 ? 's' : '') . " restante" . ($totalDays > 1 ? 's' : '') . " (*{$workdays}* laborable" . ($workdays > 1 ? 's' : '') . ")",
                    'de' => "*{$totalDays}* Tag" . ($totalDays > 1 ? 'e' : '') . " verbleibend (*{$workdays}* Werktag" . ($workdays > 1 ? 'e' : '') . ")",
                    default => "*{$totalDays}* jour" . ($totalDays > 1 ? 's' : '') . " restant" . ($totalDays > 1 ? 's' : '') . " (*{$workdays}* ouvré" . ($workdays > 1 ? 's' : '') . ")",
                };
                $lines[] = "⏰ {$remainStr}";

                // Progress bar
                if ($totalDays > 0 && $totalDays <= 365) {
                    $progressPct = max(0, min(100, (int) round((1 - $totalDays / max($totalDays + 1, 30)) * 100)));
                    $filled  = (int) round($progressPct / 10);
                    $bar     = str_repeat('▓', $filled) . str_repeat('░', 10 - $filled);
                    $lines[] = "📊 [{$bar}] {$progressPct}%";
                }

                // Suggest reminder dates
                $reminderLabel = match ($lang) { 'en' => 'Suggested reminders', 'es' => 'Recordatorios sugeridos', 'de' => 'Vorgeschlagene Erinnerungen', default => 'Rappels suggérés' };
                $lines[] = "";
                $lines[] = "🔔 *{$reminderLabel} :*";

                $reminderOffsets = [];
                if ($totalDays > 30) {
                    $reminderOffsets[] = 30;
                }
                if ($totalDays > 7) {
                    $reminderOffsets[] = 7;
                }
                if ($totalDays > 3) {
                    $reminderOffsets[] = 3;
                }
                if ($totalDays > 1) {
                    $reminderOffsets[] = 1;
                }

                foreach ($reminderOffsets as $offset) {
                    $reminderDate = $deadlineDate->modify("-{$offset} days");
                    $reminderDay  = $this->getDayName((int) $reminderDate->format('w'), $lang, short: true);
                    $beforeStr    = match ($lang) {
                        'en' => "{$offset} day" . ($offset > 1 ? 's' : '') . " before",
                        'es' => "{$offset} día" . ($offset > 1 ? 's' : '') . " antes",
                        'de' => "{$offset} Tag" . ($offset > 1 ? 'e' : '') . " vorher",
                        default => "J-{$offset}",
                    };
                    $lines[] = "• {$reminderDate->format($prefs['date_format'] ?? 'd/m/Y')} ({$reminderDay}) — {$beforeStr}";
                }

                // If deadline is on weekend, suggest nearest workday
                if (!$isWorkday) {
                    $nearestWorkday = clone $deadlineDate;
                    while ((int) $nearestWorkday->format('N') > 5) {
                        $nearestWorkday = $nearestWorkday->modify('-1 day');
                    }
                    $nwDay = $this->getDayName((int) $nearestWorkday->format('w'), $lang);
                    $tipStr = match ($lang) {
                        'en' => "Nearest workday before: *{$nearestWorkday->format($prefs['date_format'] ?? 'd/m/Y')}* ({$nwDay})",
                        'es' => "Día laborable más cercano antes: *{$nearestWorkday->format($prefs['date_format'] ?? 'd/m/Y')}* ({$nwDay})",
                        'de' => "Nächster Werktag davor: *{$nearestWorkday->format($prefs['date_format'] ?? 'd/m/Y')}* ({$nwDay})",
                        default => "Jour ouvré le plus proche avant : *{$nearestWorkday->format($prefs['date_format'] ?? 'd/m/Y')}* ({$nwDay})",
                    };
                    $lines[] = "";
                    $lines[] = "💡 {$tipStr}";
                }
            }

            return AgentResult::reply(implode("\n", $lines), [
                'action'        => 'deadline_check',
                'date'          => $deadline->format('Y-m-d'),
                'label'         => $label,
                'days_remaining' => $totalDays,
                'workdays'      => $workdays,
                'is_workday'    => $isWorkday,
            ]);
        } catch (\Exception $e) {
            return AgentResult::reply(
                "⚠️ " . match ($lang) {
                    'en' => "Invalid date format. Use YYYY-MM-DD.",
                    'es' => "Formato de fecha inválido. Usa AAAA-MM-DD.",
                    'de' => "Ungültiges Datumsformat. Verwende JJJJ-MM-TT.",
                    default => "Format de date invalide. Utilise AAAA-MM-JJ.",
                },
                ['action' => 'deadline_check', 'error' => $e->getMessage()]
            );
        }
    }

    // -------------------------------------------------------------------------
    // v1.33.0 — nth_weekday
    // -------------------------------------------------------------------------

    private function handleNthWeekday(array $parsed, array $prefs): AgentResult
    {
        $day        = mb_strtolower(trim($parsed['day'] ?? ''));
        $nth        = (int) ($parsed['nth'] ?? 1);
        $lang       = $prefs['language'] ?? 'fr';
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
        $userTz     = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now        = new DateTimeImmutable('now', $userTz);

        $month = (int) ($parsed['month'] ?? 0);
        $year  = (int) ($parsed['year'] ?? 0);
        if ($month === 0) {
            $month = (int) $now->format('n');
        }
        if ($year === 0) {
            $year = (int) $now->format('Y');
        }

        // Map day names to ISO day number (1=Mon..7=Sun)
        $dayMap = [
            'monday' => 1, 'lundi' => 1, 'montag' => 1, 'lunes' => 1,
            'tuesday' => 2, 'mardi' => 2, 'dienstag' => 2, 'martes' => 2,
            'wednesday' => 3, 'mercredi' => 3, 'mittwoch' => 3, 'miércoles' => 3, 'miercoles' => 3,
            'thursday' => 4, 'jeudi' => 4, 'donnerstag' => 4, 'jueves' => 4,
            'friday' => 5, 'vendredi' => 5, 'freitag' => 5, 'viernes' => 5,
            'saturday' => 6, 'samedi' => 6, 'samstag' => 6, 'sábado' => 6, 'sabado' => 6,
            'sunday' => 7, 'dimanche' => 7, 'sonntag' => 7, 'domingo' => 7,
        ];

        $isoDow = $dayMap[$day] ?? null;
        if ($isoDow === null) {
            $msg = match ($lang) {
                'en' => "⚠️ Invalid day name: *{$day}*.\n\n_Valid days: monday, tuesday, wednesday, thursday, friday, saturday, sunday_\n_Example: 3rd friday of june 2026_",
                'es' => "⚠️ Nombre de día inválido: *{$day}*.\n\n_Días válidos: lunes, martes, miércoles, jueves, viernes, sábado, domingo_\n_Ejemplo: 3er viernes de junio 2026_",
                'de' => "⚠️ Ungültiger Tagname: *{$day}*.\n\n_Gültige Tage: montag, dienstag, mittwoch, donnerstag, freitag, samstag, sonntag_\n_Beispiel: 3. Freitag im Juni 2026_",
                default => "⚠️ Nom de jour invalide : *{$day}*.\n\n_Jours valides : lundi, mardi, mercredi, jeudi, vendredi, samedi, dimanche_\n_Exemple : 3ème vendredi de juin 2026_",
            };
            return AgentResult::reply($msg, ['action' => 'nth_weekday', 'error' => 'invalid_day']);
        }

        // Validate month
        if ($month < 1 || $month > 12) {
            $msg = match ($lang) {
                'en' => "⚠️ Invalid month: *{$month}*. Must be between 1 and 12.",
                'es' => "⚠️ Mes inválido: *{$month}*. Debe estar entre 1 y 12.",
                'de' => "⚠️ Ungültiger Monat: *{$month}*. Muss zwischen 1 und 12 liegen.",
                default => "⚠️ Mois invalide : *{$month}*. Doit être entre 1 et 12.",
            };
            return AgentResult::reply($msg, ['action' => 'nth_weekday', 'error' => 'invalid_month']);
        }

        $isLast        = ($nth <= 0 || $nth === -1);
        $firstOfMonth  = new DateTimeImmutable("{$year}-{$month}-01", $userTz);
        $daysInMonth   = (int) $firstOfMonth->format('t');
        $monthName     = $this->getMonthName($month, $lang);

        if ($isLast) {
            // Find last occurrence of this weekday in the month
            $lastDay    = new DateTimeImmutable("{$year}-{$month}-{$daysInMonth}", $userTz);
            $lastDow    = (int) $lastDay->format('N');
            $daysBack   = ($lastDow - $isoDow + 7) % 7;
            $targetDate = $lastDay->modify("-{$daysBack} days");
            $posLabel   = match ($lang) {
                'en' => 'Last', 'es' => 'Último', 'de' => 'Letzter', default => 'Dernier',
            };
        } else {
            // Clamp nth to valid range
            $nth = max(1, min($nth, 5));

            // Find the first occurrence of this weekday in the month
            $firstDow    = (int) $firstOfMonth->format('N');
            $daysUntil   = ($isoDow - $firstDow + 7) % 7;
            $firstOccurrence = $firstOfMonth->modify("+{$daysUntil} days");
            $targetDate  = $firstOccurrence->modify('+' . ($nth - 1) . ' weeks');

            // Check if still in same month
            if ((int) $targetDate->format('n') !== $month) {
                $maxOccurrences = (int) floor(($daysInMonth - 1 - $daysUntil) / 7) + 1;
                $dayNameLocal   = $this->getDayName($isoDow % 7, $lang);
                $msg = match ($lang) {
                    'en' => "⚠️ There is no {$nth}th {$dayNameLocal} in {$monthName} {$year}.\n\n_There are only *{$maxOccurrences}* {$dayNameLocal}s in this month._\n_Try: last {$day} of {$monthName}_",
                    'es' => "⚠️ No existe el {$nth}° {$dayNameLocal} en {$monthName} {$year}.\n\n_Solo hay *{$maxOccurrences}* {$dayNameLocal}s en este mes._",
                    'de' => "⚠️ Es gibt keinen {$nth}. {$dayNameLocal} im {$monthName} {$year}.\n\n_Es gibt nur *{$maxOccurrences}* {$dayNameLocal}e in diesem Monat._",
                    default => "⚠️ Il n'y a pas de {$nth}ème {$dayNameLocal} en {$monthName} {$year}.\n\n_Il n'y a que *{$maxOccurrences}* {$dayNameLocal}(s) dans ce mois._\n_Essaie : dernier {$day} de {$monthName}_",
                };
                return AgentResult::reply($msg, ['action' => 'nth_weekday', 'error' => 'out_of_range', 'max' => $maxOccurrences]);
            }

            $ordinals = match ($lang) {
                'en' => ['', '1st', '2nd', '3rd', '4th', '5th'],
                'es' => ['', '1°', '2°', '3°', '4°', '5°'],
                'de' => ['', '1.', '2.', '3.', '4.', '5.'],
                default => ['', '1er', '2ème', '3ème', '4ème', '5ème'],
            };
            $posLabel = $ordinals[$nth] ?? "{$nth}ème";
        }

        $dayNameLocal  = $this->getDayName($isoDow % 7, $lang);
        $formatted     = $targetDate->format($dateFormat);
        $weekNum       = (int) $targetDate->format('W');

        // Distance from today
        $todayMidnight = new DateTimeImmutable($now->format('Y-m-d'), $userTz);
        $targetMidnight = new DateTimeImmutable($targetDate->format('Y-m-d'), $userTz);
        $diff          = $todayMidnight->diff($targetMidnight);
        $diffDays      = (int) $diff->days;
        $isPast        = $targetMidnight < $todayMidnight;
        $isToday       = $diffDays === 0;

        $distanceStr = '';
        if ($isToday) {
            $distanceStr = match ($lang) {
                'en' => "🎯 *That's today!*",
                'es' => "🎯 *¡Es hoy!*",
                'de' => "🎯 *Das ist heute!*",
                default => "🎯 *C'est aujourd'hui !*",
            };
        } elseif ($isPast) {
            $distanceStr = match ($lang) {
                'en' => "⏮ *{$diffDays} day(s) ago*",
                'es' => "⏮ *Hace {$diffDays} día(s)*",
                'de' => "⏮ *Vor {$diffDays} Tag(en)*",
                default => "⏮ *Il y a {$diffDays} jour(s)*",
            };
        } else {
            $distanceStr = match ($lang) {
                'en' => "⏳ *In {$diffDays} day(s)*",
                'es' => "⏳ *En {$diffDays} día(s)*",
                'de' => "⏳ *In {$diffDays} Tag(en)*",
                default => "⏳ *Dans {$diffDays} jour(s)*",
            };
        }

        $titleLabel  = match ($lang) { 'en' => 'NTH WEEKDAY', 'es' => 'ENÉSIMO DÍA', 'de' => 'N-TER WOCHENTAG', default => 'NIÈME JOUR' };
        $resultLabel = match ($lang) { 'en' => 'Result', 'es' => 'Resultado', 'de' => 'Ergebnis', default => 'Résultat' };
        $dateLabel   = match ($lang) { 'en' => 'Date', 'es' => 'Fecha', 'de' => 'Datum', default => 'Date' };
        $weekLabel   = match ($lang) { 'en' => 'ISO week', 'es' => 'Semana ISO', 'de' => 'ISO-Woche', default => 'Semaine ISO' };
        $queryLabel  = match ($lang) { 'en' => 'Query', 'es' => 'Consulta', 'de' => 'Anfrage', default => 'Recherche' };

        $lines = [
            "📅 *{$titleLabel}*",
            "────────────────",
            "",
            "🔎 {$queryLabel} : *{$posLabel} {$dayNameLocal}* de *{$monthName} {$year}*",
            "",
            "📌 {$resultLabel} : *{$dayNameLocal}*",
            "📆 {$dateLabel} : *{$formatted}*",
            "📊 {$weekLabel} : *W{$weekNum}*",
            $distanceStr,
        ];

        // List all occurrences of this weekday in the month
        $allLabel = match ($lang) {
            'en' => "All {$dayNameLocal}s in {$monthName}",
            'es' => "Todos los {$dayNameLocal}s en {$monthName}",
            'de' => "Alle {$dayNameLocal}e im {$monthName}",
            default => "Tous les {$dayNameLocal}(s) en {$monthName}",
        };
        $lines[] = "";
        $lines[] = "🗓 *{$allLabel} :*";

        $firstDow    = (int) $firstOfMonth->format('N');
        $daysUntil   = ($isoDow - $firstDow + 7) % 7;
        $occurrence   = $firstOfMonth->modify("+{$daysUntil} days");
        $count = 1;
        while ((int) $occurrence->format('n') === $month) {
            $marker = ($occurrence->format('Y-m-d') === $targetDate->format('Y-m-d')) ? ' 👈' : '';
            $todayMark = ($occurrence->format('Y-m-d') === $now->format('Y-m-d')) ? ' _(today)_' : '';
            $lines[] = "  {$count}. *{$occurrence->format($dateFormat)}*{$marker}{$todayMark}";
            $occurrence = $occurrence->modify('+1 week');
            $count++;
        }

        $tipLabel = match ($lang) {
            'en' => 'Ex: 3rd friday of june, last monday of may, 1st tuesday of april',
            'es' => 'Ej: 3er viernes de junio, último lunes de mayo',
            'de' => 'Bsp: 3. Freitag im Juni, letzter Montag im Mai',
            default => 'Ex : 3ème vendredi de juin, dernier lundi de mai, 1er mardi d\'avril',
        };
        $lines[] = "";
        $lines[] = "────────────────";
        $lines[] = "💡 _{$tipLabel}_";

        return AgentResult::reply(implode("\n", $lines), [
            'action'     => 'nth_weekday',
            'date'       => $targetDate->format('Y-m-d'),
            'day'        => $day,
            'nth'        => $nth,
            'month'      => $month,
            'year'       => $year,
            'week_number'=> $weekNum,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.34.0 — daily_summary
    // -------------------------------------------------------------------------

    private function handleDailySummary(array $prefs): AgentResult
    {
        $lang   = $prefs['language'] ?? 'fr';
        $userTz = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now    = new DateTimeImmutable('now', $userTz);

        $dayNum     = (int) $now->format('w');
        $dayName    = $this->getDayName($dayNum, $lang);
        $monthNum   = (int) $now->format('n');
        $monthName  = self::MONTH_NAMES[$lang][$monthNum] ?? self::MONTH_NAMES['fr'][$monthNum] ?? '';
        $dateStr    = $now->format($prefs['date_format'] ?? 'd/m/Y');
        $timeStr    = $now->format('H:i');
        $weekNum    = (int) $now->format('W');
        $dayOfYear  = (int) $now->format('z') + 1;
        $totalDays  = (int) $now->format('L') ? 366 : 365;
        $remaining  = $totalDays - $dayOfYear;
        $pctYear    = round(($dayOfYear / $totalDays) * 100, 1);

        // Title
        $title = match ($lang) {
            'en' => "📋 *DAILY BRIEFING*",
            'es' => "📋 *RESUMEN DEL DÍA*",
            'de' => "📋 *TAGESBRIEFING*",
            default => "📋 *BRIEFING DU JOUR*",
        };

        $lines = [
            $title,
            "────────────────",
            "",
        ];

        // Date & time
        $dateLabel = match ($lang) {
            'en' => 'Date', 'es' => 'Fecha', 'de' => 'Datum', default => 'Date',
        };
        $timeLabel = match ($lang) {
            'en' => 'Local time', 'es' => 'Hora local', 'de' => 'Ortszeit', default => 'Heure locale',
        };
        $lines[] = "📅 {$dateLabel} : *{$dayName} {$dateStr}* _{$monthName}_";
        $lines[] = "🕐 {$timeLabel} : *{$timeStr}* ({$prefs['timezone']})";
        $lines[] = "";

        // Workday progress (if weekday 9h-18h)
        $isWeekday = $dayNum >= 1 && $dayNum <= 5;
        if ($isWeekday) {
            $hour    = (int) $now->format('G');
            $minute  = (int) $now->format('i');
            $current = ($hour * 60) + $minute;
            $start   = 9 * 60;  // 9h
            $end     = 18 * 60; // 18h
            $workLen  = $end - $start;

            if ($current < $start) {
                $statusMsg = match ($lang) {
                    'en' => "Before working hours — starts at 09:00",
                    'es' => "Antes del horario laboral — empieza a las 09:00",
                    'de' => "Vor der Arbeitszeit — Beginn um 09:00",
                    default => "Avant les heures de travail — début à 09:00",
                };
                $lines[] = "💼 {$statusMsg}";
            } elseif ($current >= $end) {
                $statusMsg = match ($lang) {
                    'en' => "After working hours — day complete!",
                    'es' => "Después del horario laboral — ¡día terminado!",
                    'de' => "Nach der Arbeitszeit — Tag erledigt!",
                    default => "Après les heures de travail — journée terminée !",
                };
                $lines[] = "💼 {$statusMsg} ✅";
            } else {
                $elapsed   = $current - $start;
                $pctWork   = round(($elapsed / $workLen) * 100);
                $remainMin = $end - $current;
                $remainH   = intdiv($remainMin, 60);
                $remainM   = $remainMin % 60;
                $bar       = $this->progressBar($pctWork);

                $remainLabel = match ($lang) {
                    'en' => "remaining", 'es' => "restante", 'de' => "verbleibend", default => "restant",
                };
                $remainMPad = str_pad((string) $remainM, 2, '0', STR_PAD_LEFT);
                $lines[] = "💼 {$bar} *{$pctWork}%* — {$remainH}h{$remainMPad} {$remainLabel}";
            }
        } else {
            $weekendMsg = match ($lang) {
                'en' => "Weekend — enjoy your day off!",
                'es' => "Fin de semana — ¡disfruta tu descanso!",
                'de' => "Wochenende — genieße deinen freien Tag!",
                default => "Week-end — profite de ton jour de repos !",
            };
            $lines[] = "🏖 {$weekendMsg}";
        }
        $lines[] = "";

        // Week progress
        $isoDay    = (int) $now->format('N'); // 1=Mon, 7=Sun
        $pctWeek   = round(($isoDay / 7) * 100);
        $weekBar   = $this->progressBar($pctWeek);
        $weekLabel = match ($lang) {
            'en' => "Week W{$weekNum}", 'es' => "Semana S{$weekNum}", 'de' => "Woche W{$weekNum}",
            default => "Semaine S{$weekNum}",
        };
        $lines[] = "📊 {$weekLabel} : {$weekBar} *{$pctWeek}%* ({$isoDay}/7)";

        // Year progress
        $yearBar   = $this->progressBar((int) $pctYear);
        $yearLabel = match ($lang) {
            'en' => "Year {$now->format('Y')}", 'es' => "Año {$now->format('Y')}", 'de' => "Jahr {$now->format('Y')}",
            default => "Année {$now->format('Y')}",
        };
        $remainDaysLabel = match ($lang) {
            'en' => "days remaining", 'es' => "días restantes", 'de' => "Tage verbleibend", default => "jours restants",
        };
        $lines[] = "📊 {$yearLabel} : {$yearBar} *{$pctYear}%* (J{$dayOfYear} — {$remaining} {$remainDaysLabel})";
        $lines[] = "";

        // Quarter info
        $quarter     = (int) ceil($monthNum / 3);
        $quarterEnd  = new DateTimeImmutable("{$now->format('Y')}-" . ($quarter * 3) . "-" . match ($quarter) { 1 => '31', 2 => '30', 3 => '30', 4 => '31' }, $userTz);
        $daysToEndQ  = max(0, (int) $now->diff($quarterEnd)->days);
        $quarterLabel = match ($lang) {
            'en' => "Quarter Q{$quarter}", 'es' => "Trimestre T{$quarter}", 'de' => "Quartal Q{$quarter}",
            default => "Trimestre T{$quarter}",
        };
        $endsLabel = match ($lang) {
            'en' => "ends in", 'es' => "termina en", 'de' => "endet in", default => "se termine dans",
        };
        $daysLabel = match ($lang) { 'en' => 'days', 'es' => 'días', 'de' => 'Tagen', default => 'jours' };
        $lines[] = "📈 {$quarterLabel} — {$endsLabel} *{$daysToEndQ}* {$daysLabel}";

        // DST info
        $transitions = (new DateTimeZone($prefs['timezone'] ?? 'UTC'))->getTransitions((int) $now->format('U'), (int) $now->modify('+6 months')->format('U'));
        if (count($transitions) > 1) {
            $next = $transitions[1];
            $nextDst = new DateTimeImmutable('@' . $next['ts']);
            $nextDst = $nextDst->setTimezone($userTz);
            $dstDays = (int) $now->diff($nextDst)->days;
            $dstLabel = match ($lang) {
                'en' => "Next DST change in *{$dstDays}* days ({$nextDst->format('d/m')})",
                'es' => "Próximo cambio horario en *{$dstDays}* días ({$nextDst->format('d/m')})",
                'de' => "Nächste Zeitumstellung in *{$dstDays}* Tagen ({$nextDst->format('d/m')})",
                default => "Prochain changement d'heure dans *{$dstDays}* jours ({$nextDst->format('d/m')})",
            };
            $lines[] = "🔄 {$dstLabel}";
        }

        $lines[] = "────────────────";
        $tipLabel = match ($lang) {
            'en' => "Try: _workday progress_, _week progress_, _countdown [date]_",
            'es' => "Prueba: _progresión jornada_, _progresión semana_, _countdown [fecha]_",
            'de' => "Versuche: _Arbeitstag Fortschritt_, _Wochen Fortschritt_, _Countdown [Datum]_",
            default => "Essaie : _progression journée_, _progression semaine_, _countdown [date]_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'    => 'daily_summary',
            'date'      => $now->format('Y-m-d'),
            'time'      => $timeStr,
            'week'      => $weekNum,
            'day_of_year' => $dayOfYear,
            'year_pct'  => $pctYear,
        ]);
    }

    private function progressBar(int $pct): string
    {
        $filled = (int) round($pct / 10);
        $empty  = 10 - $filled;
        return str_repeat('▓', $filled) . str_repeat('░', $empty);
    }

    // -------------------------------------------------------------------------
    // v1.34.0 — timezone_roster
    // -------------------------------------------------------------------------

    private function handleTimezoneRoster(array $parsed, array $prefs): AgentResult
    {
        $lang   = $prefs['language'] ?? 'fr';
        $userTz = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now    = new DateTimeImmutable('now', $userTz);

        // Determine cities list: from parsed, favorite_cities, or defaults
        $cities = $parsed['cities'] ?? [];
        if (empty($cities)) {
            // Try favorite_cities
            $rawFavs = $prefs['favorite_cities'] ?? null;
            if (is_string($rawFavs) && $rawFavs !== '') {
                $decoded = json_decode($rawFavs, true);
                if (is_array($decoded)) {
                    $cities = $decoded;
                }
            } elseif (is_array($rawFavs) && !empty($rawFavs)) {
                $cities = $rawFavs;
            }
        }
        if (empty($cities)) {
            $cities = ['London', 'New York', 'Tokyo', 'Dubai', 'Sydney', 'Singapore'];
        }

        // Limit to 12 cities
        $cities = array_slice($cities, 0, 12);

        $title = match ($lang) {
            'en' => "👥 *TIMEZONE ROSTER*",
            'es' => "👥 *ROSTER DE ZONAS HORARIAS*",
            'de' => "👥 *ZEITZONEN-ROSTER*",
            default => "👥 *ROSTER DES FUSEAUX*",
        };

        $lines = [
            $title,
            "────────────────",
            "",
        ];

        // User's own timezone first
        $userTimeStr = $now->format('H:i');
        $userDay     = $this->getDayName((int) $now->format('w'), $lang, short: true);
        $userStatus  = $this->getTimeStatus((int) $now->format('G'), (int) $now->format('N'), $lang);
        $youLabel    = match ($lang) { 'en' => 'You', 'es' => 'Tú', 'de' => 'Du', default => 'Toi' };
        $lines[] = "{$userStatus['icon']} *{$youLabel}* ({$prefs['timezone']}) — *{$userTimeStr}* {$userDay} _{$userStatus['label']}_";
        $lines[] = "";

        $rosterData = [];

        foreach ($cities as $city) {
            $cityLower = mb_strtolower(trim($city));
            $tzId = self::CITY_TIMEZONE_MAP[$cityLower] ?? null;

            if (!$tzId) {
                // Try direct IANA
                if (in_array($city, DateTimeZone::listIdentifiers())) {
                    $tzId = $city;
                } else {
                    // Try partial match
                    foreach (self::CITY_TIMEZONE_MAP as $k => $v) {
                        if (str_contains($k, $cityLower) || str_contains($cityLower, $k)) {
                            $tzId = $v;
                            break;
                        }
                    }
                }
            }

            if (!$tzId) {
                $lines[] = "❓ *{$city}* — " . match ($lang) {
                    'en' => '_unknown timezone_', 'es' => '_zona desconocida_',
                    'de' => '_unbekannte Zeitzone_', default => '_fuseau inconnu_',
                };
                continue;
            }

            try {
                $tz       = new DateTimeZone($tzId);
                $cityNow  = new DateTimeImmutable('now', $tz);
                $cityTime = $cityNow->format('H:i');
                $cityDay  = $this->getDayName((int) $cityNow->format('w'), $lang, short: true);
                $hour     = (int) $cityNow->format('G');
                $isoDay   = (int) $cityNow->format('N');
                $status   = $this->getTimeStatus($hour, $isoDay, $lang);

                // Offset from user
                $diffSec  = $tz->getOffset($cityNow) - $userTz->getOffset($now);
                $diffH    = $diffSec / 3600;
                $sign     = $diffH >= 0 ? '+' : '';
                $diffStr  = ($diffH == (int) $diffH) ? "{$sign}" . (int) $diffH . "h" : "{$sign}" . $diffH . "h";

                $displayCity = ucfirst($city);
                $lines[] = "{$status['icon']} *{$displayCity}* — *{$cityTime}* {$cityDay} _{$status['label']}_ ({$diffStr})";

                $rosterData[] = ['city' => $city, 'time' => $cityTime, 'status' => $status['key']];
            } catch (\Exception) {
                $lines[] = "❓ *{$city}* — " . match ($lang) {
                    'en' => '_error_', 'es' => '_error_', 'de' => '_Fehler_', default => '_erreur_',
                };
            }
        }

        $lines[] = "";
        $lines[] = "────────────────";

        // Legend
        $legendLabel = match ($lang) { 'en' => 'Legend', 'es' => 'Leyenda', 'de' => 'Legende', default => 'Légende' };
        $lines[] = "📖 *{$legendLabel} :*";
        $lines[] = match ($lang) {
            'en' => "🟢 _Working_ · 🌅 _Early/Late_ · 🔴 _Off hours_ · 😴 _Sleeping_ · 🏖 _Weekend_",
            'es' => "🟢 _Trabajando_ · 🌅 _Temprano/Tarde_ · 🔴 _Fuera_ · 😴 _Durmiendo_ · 🏖 _Fin de semana_",
            'de' => "🟢 _Arbeitszeit_ · 🌅 _Früh/Spät_ · 🔴 _Feierabend_ · 😴 _Schlafenszeit_ · 🏖 _Wochenende_",
            default => "🟢 _Au bureau_ · 🌅 _Tôt/Tard_ · 🔴 _Hors bureau_ · 😴 _Dort_ · 🏖 _Week-end_",
        };

        $tipLabel = match ($lang) {
            'en' => "Try: _roster Tokyo London NYC_, _daily summary_, _overlap [city]_",
            'es' => "Prueba: _roster Tokyo London NYC_, _resumen del día_, _overlap [ciudad]_",
            'de' => "Versuche: _Roster Tokyo London NYC_, _Tagesbriefing_, _Overlap [Stadt]_",
            default => "Essaie : _roster Tokyo London NYC_, _briefing du jour_, _overlap [ville]_",
        };
        $lines[] = "";
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action' => 'timezone_roster',
            'cities' => count($rosterData),
            'roster' => $rosterData,
        ]);
    }

    private function getTimeStatus(int $hour, int $isoDay, string $lang): array
    {
        // Weekend (Sat=6, Sun=7)
        if ($isoDay >= 6) {
            return [
                'key'   => 'weekend',
                'icon'  => '🏖',
                'label' => match ($lang) { 'en' => 'Weekend', 'es' => 'Fin de semana', 'de' => 'Wochenende', default => 'Week-end' },
            ];
        }

        // Sleeping (22h-6h)
        if ($hour >= 22 || $hour < 6) {
            return [
                'key'   => 'sleeping',
                'icon'  => '😴',
                'label' => match ($lang) { 'en' => 'Sleeping', 'es' => 'Durmiendo', 'de' => 'Schlafenszeit', default => 'Dort' },
            ];
        }

        // Early morning or late evening (6-8h, 19-22h)
        if ($hour < 8 || $hour >= 19) {
            return [
                'key'   => 'off_peak',
                'icon'  => '🌅',
                'label' => match ($lang) { 'en' => 'Early/Late', 'es' => 'Temprano/Tarde', 'de' => 'Früh/Spät', default => 'Tôt/Tard' },
            ];
        }

        // Working hours (9-18h)
        if ($hour >= 9 && $hour < 18) {
            return [
                'key'   => 'working',
                'icon'  => '🟢',
                'label' => match ($lang) { 'en' => 'Working', 'es' => 'Trabajando', 'de' => 'Arbeitszeit', default => 'Au bureau' },
            ];
        }

        // Transition (8-9h, 18-19h)
        return [
            'key'   => 'off_hours',
            'icon'  => '🔴',
            'label' => match ($lang) { 'en' => 'Off hours', 'es' => 'Fuera', 'de' => 'Feierabend', default => 'Hors bureau' },
        ];
    }

    // -------------------------------------------------------------------------
    // v1.43.0 — preferences_search: search preferences by keyword
    // -------------------------------------------------------------------------

    private function handlePreferencesSearch(array $parsed, array $prefs): AgentResult
    {
        $query = mb_strtolower(trim($parsed['query'] ?? ''));
        $lang  = $prefs['language'] ?? 'fr';

        if ($query === '') {
            $msg = match ($lang) {
                'en' => "🔍 Please specify a search term.\n\n_Example: *search language* or *search timezone*_",
                'es' => "🔍 Especifica un término de búsqueda.\n\n_Ejemplo: *search language* o *search timezone*_",
                'de' => "🔍 Bitte gib einen Suchbegriff an.\n\n_Beispiel: *search language* oder *search timezone*_",
                default => "🔍 Précise un terme de recherche.\n\n_Exemple : *chercher langue* ou *chercher fuseau*_",
            };
            return AgentResult::reply($msg, ['action' => 'preferences_search', 'query' => '']);
        }

        // Search in key names, labels and values
        $matches = [];
        foreach ($prefs as $key => $value) {
            $label     = $this->formatKeyLabel($key, $lang);
            $formatted = $this->formatValue($key, $value, $lang);
            $searchable = mb_strtolower("{$key} {$label} {$value} {$formatted}");

            if (str_contains($searchable, $query)) {
                $matches[$key] = [
                    'label' => $label,
                    'value' => $formatted,
                    'raw'   => $value,
                ];
            }
        }

        if (empty($matches)) {
            $msg = match ($lang) {
                'en' => "🔍 No preference found matching *{$query}*.\n\n_Type *show preferences* to see all settings._",
                'es' => "🔍 No se encontró ninguna preferencia que coincida con *{$query}*.\n\n_Escribe *show preferences* para ver todos los ajustes._",
                'de' => "🔍 Keine Einstellung gefunden für *{$query}*.\n\n_Tippe *show preferences* für alle Einstellungen._",
                default => "🔍 Aucune préférence trouvée pour *{$query}*.\n\n_Tape *show preferences* pour voir tous les paramètres._",
            };
            return AgentResult::reply($msg, ['action' => 'preferences_search', 'query' => $query, 'results' => 0]);
        }

        $title = match ($lang) {
            'en' => "🔍 *Search results for \"{$query}\":*",
            'es' => "🔍 *Resultados para \"{$query}\":*",
            'de' => "🔍 *Suchergebnisse für \"{$query}\":*",
            default => "🔍 *Résultats pour \"{$query}\" :*",
        };
        $lines = [$title, ''];

        foreach ($matches as $key => $info) {
            $lines[] = "• *{$info['label']}* : {$info['value']}";
            $tipSet = match ($lang) {
                'en' => "set {$key} <value>",
                'es' => "set {$key} <valor>",
                'de' => "set {$key} <Wert>",
                default => "set {$key} <valeur>",
            };
            $lines[] = "  _→ {$tipSet}_";
        }

        $lines[] = '';
        $countLabel = match ($lang) {
            'en' => count($matches) . " result(s) found",
            'es' => count($matches) . " resultado(s) encontrado(s)",
            'de' => count($matches) . " Ergebnis(se) gefunden",
            default => count($matches) . " résultat(s) trouvé(s)",
        };
        $lines[] = "_{$countLabel}_";

        return AgentResult::reply(implode("\n", $lines), [
            'action'  => 'preferences_search',
            'query'   => $query,
            'results' => count($matches),
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.43.0 — locale_details: show full locale information
    // -------------------------------------------------------------------------

    private function handleLocaleDetails(array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $tz         = $prefs['timezone'] ?? 'UTC';
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
        $unitSystem = $prefs['unit_system'] ?? 'metric';
        $style      = $prefs['communication_style'] ?? 'friendly';

        $title = match ($lang) {
            'en' => "🌐 *Your Locale Details*",
            'es' => "🌐 *Detalles de tu Configuración Regional*",
            'de' => "🌐 *Deine Regionaleinstellungen*",
            'it' => "🌐 *Dettagli Impostazioni Regionali*",
            'pt' => "🌐 *Detalhes da sua Localidade*",
            default => "🌐 *Détails de ta Locale*",
        };

        $lines = [$title, ''];

        // Language section
        $langLabel  = self::LANGUAGE_LABELS[$lang] ?? $lang;
        $langHeader = match ($lang) { 'en' => 'Language', 'es' => 'Idioma', 'de' => 'Sprache', default => 'Langue' };
        $lines[] = "🗣 *{$langHeader}* : {$langLabel} ({$lang})";

        // Timezone section with current time
        try {
            $tzObj   = new DateTimeZone($tz);
            $now     = new DateTimeImmutable('now', $tzObj);
            $offset  = $now->format('P');
            $dayName = $this->getDayName((int) $now->format('w'), $lang);
            $isDst   = (bool) $now->format('I');
            $dstLabel = $isDst
                ? (match ($lang) { 'en' => 'DST active', 'es' => 'Horario de verano', 'de' => 'Sommerzeit', default => 'Heure d\'été' })
                : (match ($lang) { 'en' => 'Standard time', 'es' => 'Horario estándar', 'de' => 'Normalzeit', default => 'Heure standard' });

            $tzHeader = match ($lang) { 'en' => 'Timezone', 'es' => 'Zona horaria', 'de' => 'Zeitzone', default => 'Fuseau horaire' };
            $lines[] = "🕐 *{$tzHeader}* : {$tz} (UTC{$offset})";
            $lines[] = "   📅 {$dayName} {$now->format($dateFormat)} — {$now->format('H:i:s')}";
            $lines[] = "   ⏰ {$dstLabel}";
        } catch (\Exception) {
            $lines[] = "🕐 *Timezone* : {$tz}";
        }

        // Date format section with examples
        $dateHeader = match ($lang) { 'en' => 'Date format', 'es' => 'Formato de fecha', 'de' => 'Datumsformat', default => 'Format de date' };
        $nowForDate = new DateTimeImmutable('now', new DateTimeZone($tz));
        $lines[] = '';
        $lines[] = "📅 *{$dateHeader}* : `{$dateFormat}`";
        $exampleLabel = match ($lang) { 'en' => 'Example', 'es' => 'Ejemplo', 'de' => 'Beispiel', default => 'Exemple' };
        $lines[] = "   {$exampleLabel} : {$nowForDate->format($dateFormat)}";

        // Unit system
        $unitHeader = match ($lang) { 'en' => 'Units', 'es' => 'Unidades', 'de' => 'Einheiten', default => 'Unités' };
        $unitDetail = match ($unitSystem) {
            'metric'   => match ($lang) { 'en' => 'km, kg, °C', 'es' => 'km, kg, °C', default => 'km, kg, °C' },
            'imperial' => match ($lang) { 'en' => 'mi, lb, °F', 'es' => 'mi, lb, °F', default => 'mi, lb, °F' },
            default    => $unitSystem,
        };
        $lines[] = "📏 *{$unitHeader}* : {$unitSystem} ({$unitDetail})";

        // Communication style
        $styleHeader = match ($lang) { 'en' => 'Style', 'es' => 'Estilo', 'de' => 'Stil', default => 'Style' };
        $styleLabel  = self::STYLE_LABELS[$style] ?? $style;
        $lines[] = "💬 *{$styleHeader}* : {$styleLabel}";

        // Tip
        $lines[] = '';
        $tip = match ($lang) {
            'en' => "💡 _To change: *set language en*, *timezone Europe/Paris*, *set style formal*_",
            'es' => "💡 _Para cambiar: *set language es*, *timezone Europe/Madrid*, *set style formal*_",
            'de' => "💡 _Zum Ändern: *set language de*, *timezone Europe/Berlin*, *set style formal*_",
            default => "💡 _Pour modifier : *set language fr*, *timezone Europe/Paris*, *set style formal*_",
        };
        $lines[] = $tip;

        return AgentResult::reply(implode("\n", $lines), ['action' => 'locale_details']);
    }

    // -------------------------------------------------------------------------
    // i18n helper — unknown action
    // -------------------------------------------------------------------------

    private function formatUnknownAction(string $action, string $lang): string
    {
        $safeAction = mb_substr(preg_replace('/[*_~`]/', '', $action), 0, 50);

        return match ($lang) {
            'en' => "🤔 Unrecognized action: *{$safeAction}*\n\nType *help preferences* to see all available commands.\n_Or type *my profile* to see your current preferences._",
            'es' => "🤔 Acción no reconocida: *{$safeAction}*\n\nEscribe *ayuda preferencias* para ver todos los comandos.\n_O escribe *mi perfil* para ver tus preferencias actuales._",
            'de' => "🤔 Unbekannte Aktion: *{$safeAction}*\n\nTippe *Hilfe Einstellungen* für alle verfügbaren Befehle.\n_Oder tippe *mein Profil* für deine aktuellen Einstellungen._",
            'it' => "🤔 Azione non riconosciuta: *{$safeAction}*\n\nScrivi *aiuto preferenze* per vedere tutti i comandi.\n_O scrivi *il mio profilo* per le tue preferenze._",
            'pt' => "🤔 Ação não reconhecida: *{$safeAction}*\n\nDigite *ajuda preferências* para ver todos os comandos.\n_Ou digite *meu perfil* para ver suas preferências._",
            'ar' => "🤔 إجراء غير معروف: *{$safeAction}*\n\nاكتب *مساعدة التفضيلات* لرؤية جميع الأوامر.\n_أو اكتب *ملفي الشخصي* لعرض تفضيلاتك._",
            'zh' => "🤔 无法识别的操作：*{$safeAction}*\n\n输入 *帮助 偏好设置* 查看所有可用命令。\n_或输入 *我的资料* 查看当前偏好设置。_",
            'ja' => "🤔 認識できないアクション：*{$safeAction}*\n\n*help preferences* と入力してコマンド一覧を表示。\n_または *my profile* で現在の設定を確認。_",
            'ko' => "🤔 인식할 수 없는 작업: *{$safeAction}*\n\n*help preferences* 를 입력하여 명령어 목록을 확인하세요.\n_또는 *my profile* 로 현재 설정을 확인하세요._",
            'ru' => "🤔 Неизвестное действие: *{$safeAction}*\n\nВведите *help preferences* для списка команд.\n_Или *my profile* для просмотра настроек._",
            'nl' => "🤔 Niet-herkende actie: *{$safeAction}*\n\nTyp *help preferences* voor alle beschikbare commando's.\n_Of typ *mijn profiel* voor je huidige instellingen._",
            default => "🤔 Action non reconnue : *{$safeAction}*\n\nTape *aide preferences* pour voir toutes les commandes disponibles.\n_Ou tape *mon profil* pour voir tes préférences actuelles._",
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

        // v1.50.0 — Strip UTF-8 BOM and zero-width characters that break JSON parsing
        $clean = preg_replace('/^\x{FEFF}/u', '', $clean);
        $clean = str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], ['', ' '], $clean);

        // v1.50.0 — Normalize escaped literal newlines inside JSON string values
        $clean = str_replace(["\r\n", "\r"], "\n", $clean);

        // Strip markdown code blocks (```json ... ``` or ``` ... ```)
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        // Strip leading/trailing prose that some LLMs add (e.g. "Here is the JSON:")
        $clean = preg_replace('/^[^{\[]*(?=[{\[])/', '', $clean);
        $clean = preg_replace('/[^}\]]*$/', '', $clean);
        $clean = trim($clean);

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

        // Fix single quotes used instead of double quotes (common LLM error)
        if (!str_contains($clean, '"') && str_contains($clean, "'")) {
            $clean = str_replace("'", '"', $clean);
        }

        $decoded = json_decode($clean, true);

        // Fallback: fix unquoted keys (e.g. {action: "show"} → {"action": "show"})
        if (!is_array($decoded) && str_contains($clean, '{')) {
            $fixed = preg_replace('/(?<=[\{,])\s*(\w+)\s*:/', ' "$1":', $clean);
            if ($fixed !== null) {
                $decoded = json_decode($fixed, true);
            }
        }

        // v1.53.0 — Handle truncated JSON (missing closing braces/brackets)
        if (!is_array($decoded) && str_contains($clean, '{')) {
            $openBraces   = substr_count($clean, '{') - substr_count($clean, '}');
            $openBrackets = substr_count($clean, '[') - substr_count($clean, ']');
            if ($openBraces > 0 || $openBrackets > 0) {
                $repaired = $clean;
                // Remove trailing incomplete key-value (e.g. , "key": or , "key)
                $repaired = preg_replace('/,\s*"[^"]*"?\s*:?\s*$/', '', $repaired);
                for ($i = 0; $i < $openBrackets; $i++) $repaired .= ']';
                for ($i = 0; $i < $openBraces; $i++) $repaired .= '}';
                $decoded = json_decode($repaired, true);
            }
        }

        return is_array($decoded) ? $decoded : null;
    }

    // -------------------------------------------------------------------------
    // v1.35.0 — productivity_score
    // -------------------------------------------------------------------------

    private function handleProductivityScore(array $prefs): AgentResult
    {
        $lang   = $prefs['language'] ?? 'fr';
        $userTz = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now    = new DateTimeImmutable('now', $userTz);

        $dayNum    = (int) $now->format('w');
        $isoDay    = (int) $now->format('N');
        $hour      = (int) $now->format('G');
        $minute    = (int) $now->format('i');
        $dayOfYear = (int) $now->format('z') + 1;
        $totalDays = (int) $now->format('L') ? 366 : 365;
        $monthNum  = (int) $now->format('n');
        $dayOfMonth = (int) $now->format('j');
        $daysInMonth = (int) $now->format('t');
        $weekNum   = (int) $now->format('W');

        // Title
        $title = match ($lang) {
            'en' => "📊 *PRODUCTIVITY DASHBOARD*",
            'es' => "📊 *PANEL DE PRODUCTIVIDAD*",
            'de' => "📊 *PRODUKTIVITÄTS-DASHBOARD*",
            'it' => "📊 *DASHBOARD PRODUTTIVITÀ*",
            'pt' => "📊 *PAINEL DE PRODUTIVIDADE*",
            default => "📊 *TABLEAU DE BORD PRODUCTIVITÉ*",
        };

        $lines = [$title, "────────────────", ""];

        // 1. Workday progress
        $isWeekday = $dayNum >= 1 && $dayNum <= 5;
        $workLabel = match ($lang) {
            'en' => 'Workday', 'es' => 'Jornada', 'de' => 'Arbeitstag', 'it' => 'Giornata', 'pt' => 'Dia de trabalho',
            default => 'Journée',
        };

        if ($isWeekday) {
            $current = ($hour * 60) + $minute;
            $start = 9 * 60;
            $end   = 18 * 60;

            if ($current < $start) {
                $pctWork = 0;
            } elseif ($current >= $end) {
                $pctWork = 100;
            } else {
                $pctWork = (int) round((($current - $start) / ($end - $start)) * 100);
            }
            $workBar = $this->progressBar($pctWork);
            $lines[] = "💼 {$workLabel} : {$workBar} *{$pctWork}%*";
        } else {
            $weekendLabel = match ($lang) {
                'en' => 'Weekend', 'es' => 'Fin de semana', 'de' => 'Wochenende', 'it' => 'Fine settimana', 'pt' => 'Fim de semana',
                default => 'Week-end',
            };
            $lines[] = "🏖 {$workLabel} : _{$weekendLabel}_";
        }

        // 2. Week progress
        $pctWeek = (int) round(($isoDay / 7) * 100);
        $weekBar = $this->progressBar($pctWeek);
        $weekLabel = match ($lang) {
            'en' => "Week W{$weekNum}", 'es' => "Semana S{$weekNum}", 'de' => "Woche W{$weekNum}",
            'it' => "Settimana S{$weekNum}", 'pt' => "Semana S{$weekNum}",
            default => "Semaine S{$weekNum}",
        };
        $lines[] = "📅 {$weekLabel} : {$weekBar} *{$pctWeek}%* ({$isoDay}/7)";

        // 3. Month progress
        $pctMonth = (int) round(($dayOfMonth / $daysInMonth) * 100);
        $monthBar = $this->progressBar($pctMonth);
        $monthName = self::MONTH_NAMES[$lang][$monthNum] ?? self::MONTH_NAMES['fr'][$monthNum] ?? '';
        $lines[] = "📆 {$monthName} : {$monthBar} *{$pctMonth}%* ({$dayOfMonth}/{$daysInMonth})";

        // 4. Quarter progress
        $quarter      = (int) ceil($monthNum / 3);
        $quarterStart = new DateTimeImmutable("{$now->format('Y')}-" . (($quarter - 1) * 3 + 1) . "-01", $userTz);
        $quarterEndMonth = $quarter * 3;
        $quarterEndDay = match ($quarter) { 1 => '31', 2 => '30', 3 => '30', 4 => '31' };
        $quarterEnd   = new DateTimeImmutable("{$now->format('Y')}-{$quarterEndMonth}-{$quarterEndDay}", $userTz);
        $totalQDays   = max(1, (int) $quarterStart->diff($quarterEnd)->days + 1);
        $elapsedQDays = (int) $quarterStart->diff($now)->days + 1;
        $pctQuarter   = min(100, (int) round(($elapsedQDays / $totalQDays) * 100));
        $quarterBar   = $this->progressBar($pctQuarter);
        $lines[] = "📈 Q{$quarter} : {$quarterBar} *{$pctQuarter}%*";

        // 5. Year progress
        $pctYear = round(($dayOfYear / $totalDays) * 100, 1);
        $yearBar = $this->progressBar((int) $pctYear);
        $remaining = $totalDays - $dayOfYear;
        $remainLabel = match ($lang) {
            'en' => 'days left', 'es' => 'días restantes', 'de' => 'Tage übrig',
            'it' => 'giorni rimasti', 'pt' => 'dias restantes',
            default => 'jours restants',
        };
        $lines[] = "🗓 {$now->format('Y')} : {$yearBar} *{$pctYear}%* ({$remaining} {$remainLabel})";

        $lines[] = "";

        // Composite score (average of all percentages)
        $scores = [$pctWeek, $pctMonth, $pctQuarter, (int) $pctYear];
        if ($isWeekday) {
            $scores[] = $pctWork;
        }
        $avgScore = (int) round(array_sum($scores) / count($scores));
        $scoreLabel = match ($lang) {
            'en' => 'Time elapsed score', 'es' => 'Puntuación temporal', 'de' => 'Zeitfortschritt',
            'it' => 'Punteggio temporale', 'pt' => 'Pontuação temporal',
            default => 'Score temporel',
        };
        $lines[] = "⚡ {$scoreLabel} : *{$avgScore}%*";

        $lines[] = "────────────────";
        $tipLabel = match ($lang) {
            'en' => "Try: _workday progress_, _week progress_, _month progress_",
            'es' => "Prueba: _progresión jornada_, _progresión semana_, _progresión mes_",
            'de' => "Versuche: _Arbeitstag_, _Woche Fortschritt_, _Monat Fortschritt_",
            'it' => "Prova: _progresso giornata_, _progresso settimana_, _progresso mese_",
            'pt' => "Tente: _progresso dia_, _progresso semana_, _progresso mês_",
            default => "Essaie : _progression journée_, _progression semaine_, _progression mois_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'      => 'productivity_score',
            'avg_score'   => $avgScore,
            'week_pct'    => $pctWeek,
            'month_pct'   => $pctMonth,
            'quarter_pct' => $pctQuarter,
            'year_pct'    => $pctYear,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.35.0 — timezone_history
    // -------------------------------------------------------------------------

    private function handleTimezoneHistory(array $parsed, array $prefs): AgentResult
    {
        $lang    = $prefs['language'] ?? 'fr';
        $target  = $parsed['target'] ?? '';
        $userTz  = $prefs['timezone'] ?? 'UTC';

        // Resolve target timezone
        $tzName = $userTz;
        if ($target !== '') {
            $resolved = $this->resolveTimezoneString($target);
            if ($resolved) {
                $tzName = $resolved;
            } else {
                $errorMsg = match ($lang) {
                    'en' => "⚠️ Unknown timezone or city: *{$target}*\n\n_Try: timezone history Paris, dst history New York_",
                    'es' => "⚠️ Zona horaria o ciudad desconocida: *{$target}*\n\n_Prueba: timezone history Paris, dst history New York_",
                    'de' => "⚠️ Unbekannte Zeitzone oder Stadt: *{$target}*\n\n_Versuche: timezone history Paris, dst history New York_",
                    'it' => "⚠️ Fuso orario o città sconosciuta: *{$target}*\n\n_Prova: timezone history Paris, dst history New York_",
                    'pt' => "⚠️ Fuso horário ou cidade desconhecida: *{$target}*\n\n_Tente: timezone history Paris, dst history New York_",
                    default => "⚠️ Fuseau horaire ou ville inconnu : *{$target}*\n\n_Essaie : historique fuseau Paris, dst history New York_",
                };
                return AgentResult::reply($errorMsg, ['action' => 'timezone_history', 'error' => 'unknown_timezone']);
            }
        }

        try {
            $tz = new DateTimeZone($tzName);
        } catch (\Exception) {
            $errorMsg = match ($lang) {
                'en' => "⚠️ Invalid timezone: *{$tzName}*",
                default => "⚠️ Fuseau horaire invalide : *{$tzName}*",
            };
            return AgentResult::reply($errorMsg, ['action' => 'timezone_history', 'error' => 'invalid_timezone']);
        }

        $now  = new DateTimeImmutable('now', $tz);
        $year = (int) $now->format('Y');

        // Get transitions for the entire year
        $yearStart = (new DateTimeImmutable("{$year}-01-01 00:00:00", $tz))->getTimestamp();
        $yearEnd   = (new DateTimeImmutable("{$year}-12-31 23:59:59", $tz))->getTimestamp();
        $transitions = $tz->getTransitions($yearStart, $yearEnd);

        // Title
        $title = match ($lang) {
            'en' => "🕰 *TIMEZONE HISTORY — {$year}*",
            'es' => "🕰 *HISTORIAL DE ZONA HORARIA — {$year}*",
            'de' => "🕰 *ZEITZONEN-HISTORIE — {$year}*",
            'it' => "🕰 *STORICO FUSO ORARIO — {$year}*",
            'pt' => "🕰 *HISTÓRICO DE FUSO HORÁRIO — {$year}*",
            default => "🕰 *HISTORIQUE FUSEAU HORAIRE — {$year}*",
        };

        $lines = [$title, "────────────────"];
        $tzLabel = match ($lang) {
            'en' => 'Timezone', 'es' => 'Zona', 'de' => 'Zeitzone', 'it' => 'Fuso', 'pt' => 'Fuso',
            default => 'Fuseau',
        };
        $lines[] = "🌐 {$tzLabel} : *{$tzName}*";
        $lines[] = "";

        // Current state
        $currentOffset = $now->format('P');
        $isDst = (bool) $now->format('I');
        $currentLabel = match ($lang) {
            'en' => 'Current state', 'es' => 'Estado actual', 'de' => 'Aktueller Status',
            'it' => 'Stato attuale', 'pt' => 'Estado atual',
            default => 'État actuel',
        };
        $dstStr = $isDst
            ? match ($lang) { 'en' => 'DST active', 'es' => 'Horario verano', 'de' => 'Sommerzeit', 'it' => 'Ora legale', 'pt' => 'Horário verão', default => "Heure d'été" }
            : match ($lang) { 'en' => 'Standard time', 'es' => 'Horario estándar', 'de' => 'Normalzeit', 'it' => 'Ora solare', 'pt' => 'Horário padrão', default => "Heure d'hiver" };
        $lines[] = "📍 {$currentLabel} : UTC{$currentOffset} — {$dstStr}";
        $lines[] = "";

        // List transitions (skip the first one which is the initial state)
        $transitionCount = 0;
        $transLabel = match ($lang) {
            'en' => 'TRANSITIONS', 'es' => 'TRANSICIONES', 'de' => 'ÜBERGÄNGE',
            'it' => 'TRANSIZIONI', 'pt' => 'TRANSIÇÕES',
            default => 'TRANSITIONS',
        };
        $lines[] = "*{$transLabel} :*";

        foreach ($transitions as $i => $t) {
            if ($i === 0) {
                continue; // Skip initial state
            }
            $transitionCount++;
            $dt = (new DateTimeImmutable('@' . $t['ts']))->setTimezone($tz);

            $dayName = $this->getDayName((int) $dt->format('w'), $lang);
            $dateStr = $dt->format($prefs['date_format'] ?? 'd/m/Y');
            $timeStr = $dt->format('H:i');

            $offsetHours = $t['offset'] / 3600;
            $sign = $offsetHours >= 0 ? '+' : '';
            $offsetStr = "UTC{$sign}" . (floor($offsetHours) == $offsetHours ? (int) $offsetHours : $offsetHours);

            $dstIcon = $t['isdst'] ? '☀️' : '❄️';
            $dstNote = $t['isdst']
                ? match ($lang) { 'en' => 'DST begins', 'es' => 'Horario verano', 'de' => 'Sommerzeit', 'it' => 'Ora legale', 'pt' => 'Horário verão', default => "Passage heure d'été" }
                : match ($lang) { 'en' => 'DST ends', 'es' => 'Horario invierno', 'de' => 'Winterzeit', 'it' => 'Ora solare', 'pt' => 'Horário inverno', default => "Passage heure d'hiver" };
            $abbrStr = $t['abbr'] ?? '';

            // Check if past or future
            $isPast = $t['ts'] < $now->getTimestamp();
            $marker = $isPast ? '✅' : '🔜';

            $lines[] = "{$marker} {$dstIcon} *{$dayName} {$dateStr}* {$timeStr} → {$offsetStr} ({$abbrStr})";
            $lines[] = "   _{$dstNote}_";
        }

        if ($transitionCount === 0) {
            $noChangeMsg = match ($lang) {
                'en' => "No DST changes for this timezone in {$year}.",
                'es' => "Sin cambios horarios para esta zona en {$year}.",
                'de' => "Keine Zeitumstellungen für diese Zeitzone in {$year}.",
                'it' => "Nessun cambio orario per questo fuso nel {$year}.",
                'pt' => "Sem mudanças horárias para este fuso em {$year}.",
                default => "Aucun changement d'heure pour ce fuseau en {$year}.",
            };
            $lines[] = "ℹ️ _{$noChangeMsg}_";
        }

        $lines[] = "";
        $lines[] = "────────────────";
        $tipLabel = match ($lang) {
            'en' => "Try: _dst info_, _timezone summary_, _quick brief [city]_",
            'es' => "Prueba: _info DST_, _resumen fuseau_, _brief [ciudad]_",
            'de' => "Versuche: _DST Info_, _Zeitzonen-Zusammenfassung_, _Brief [Stadt]_",
            'it' => "Prova: _info DST_, _riepilogo fuso_, _brief [città]_",
            'pt' => "Tente: _info DST_, _resumo fuso_, _brief [cidade]_",
            default => "Essaie : _dst info_, _résumé fuseau_, _aperçu [ville]_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'      => 'timezone_history',
            'timezone'    => $tzName,
            'year'        => $year,
            'transitions' => $transitionCount,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.36.0 — unit_convert
    // -------------------------------------------------------------------------

    private function handleUnitConvert(array $parsed, array $prefs): AgentResult
    {
        $lang     = $prefs['language'] ?? 'fr';
        $value    = $parsed['value'] ?? null;
        $fromUnit = mb_strtolower(trim($parsed['from_unit'] ?? ''));
        $toUnit   = mb_strtolower(trim($parsed['to_unit'] ?? ''));

        // Normalize common aliases
        $aliases = [
            '°c' => 'celsius', '°f' => 'fahrenheit', 'c' => 'celsius', 'f' => 'fahrenheit', 'k' => 'kelvin',
            'kilomètres' => 'km', 'kilometres' => 'km', 'kilometers' => 'km',
            'mètres' => 'm', 'metres' => 'm', 'meters' => 'm',
            'centimètres' => 'cm', 'centimetres' => 'cm', 'centimeters' => 'cm',
            'pieds' => 'ft', 'feet' => 'ft', 'foot' => 'ft',
            'pouces' => 'inches', 'inch' => 'inches', 'in' => 'inches',
            'kilogrammes' => 'kg', 'kilograms' => 'kg', 'kilos' => 'kg', 'kilo' => 'kg',
            'livres' => 'lbs', 'pounds' => 'lbs', 'lb' => 'lbs', 'pound' => 'lbs',
            'grammes' => 'g', 'grams' => 'g', 'gramme' => 'g', 'gram' => 'g',
            'onces' => 'oz', 'ounces' => 'oz', 'ounce' => 'oz',
            'litres' => 'liters', 'litre' => 'liters', 'liter' => 'liters', 'l' => 'liters',
            'gallon' => 'gallons', 'gal' => 'gallons',
            'millilitres' => 'ml', 'milliliters' => 'ml',
            'fl oz' => 'fl_oz', 'fluid ounce' => 'fl_oz', 'fluid ounces' => 'fl_oz',
        ];
        $fromUnit = $aliases[$fromUnit] ?? $fromUnit;
        $toUnit   = $aliases[$toUnit] ?? $toUnit;

        if ($value === null || !is_numeric($value)) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Please provide a numeric value to convert.\n\n_Examples: 100 km to miles, 37°C to °F, 70 kg to lbs_",
                'es' => "⚠️ Proporciona un valor numérico para convertir.\n\n_Ejemplos: 100 km a miles, 37°C a °F, 70 kg a lbs_",
                'de' => "⚠️ Bitte gib einen numerischen Wert an.\n\n_Beispiele: 100 km to miles, 37°C to °F, 70 kg to lbs_",
                default => "⚠️ Indique une valeur numérique à convertir.\n\n_Exemples : 100 km en miles, 37°C en °F, 70 kg en livres_",
            };
            return AgentResult::reply($errMsg, ['action' => 'unit_convert', 'error' => 'missing_value']);
        }

        $val = (float) $value;

        // Conversion tables: [from][to] = function
        $conversions = [
            // Temperature
            'celsius'    => ['fahrenheit' => fn($v) => $v * 9 / 5 + 32, 'kelvin' => fn($v) => $v + 273.15],
            'fahrenheit' => ['celsius' => fn($v) => ($v - 32) * 5 / 9, 'kelvin' => fn($v) => ($v - 32) * 5 / 9 + 273.15],
            'kelvin'     => ['celsius' => fn($v) => $v - 273.15, 'fahrenheit' => fn($v) => ($v - 273.15) * 9 / 5 + 32],
            // Distance
            'km'     => ['miles' => fn($v) => $v * 0.621371, 'm' => fn($v) => $v * 1000, 'ft' => fn($v) => $v * 3280.84],
            'miles'  => ['km' => fn($v) => $v * 1.60934, 'm' => fn($v) => $v * 1609.34, 'ft' => fn($v) => $v * 5280],
            'm'      => ['km' => fn($v) => $v / 1000, 'miles' => fn($v) => $v / 1609.34, 'ft' => fn($v) => $v * 3.28084, 'cm' => fn($v) => $v * 100, 'inches' => fn($v) => $v * 39.3701],
            'ft'     => ['m' => fn($v) => $v * 0.3048, 'km' => fn($v) => $v * 0.0003048, 'miles' => fn($v) => $v / 5280, 'cm' => fn($v) => $v * 30.48, 'inches' => fn($v) => $v * 12],
            'cm'     => ['inches' => fn($v) => $v / 2.54, 'm' => fn($v) => $v / 100, 'ft' => fn($v) => $v / 30.48],
            'inches' => ['cm' => fn($v) => $v * 2.54, 'm' => fn($v) => $v * 0.0254, 'ft' => fn($v) => $v / 12],
            // Weight
            'kg'  => ['lbs' => fn($v) => $v * 2.20462, 'g' => fn($v) => $v * 1000, 'oz' => fn($v) => $v * 35.274],
            'lbs' => ['kg' => fn($v) => $v * 0.453592, 'g' => fn($v) => $v * 453.592, 'oz' => fn($v) => $v * 16],
            'g'   => ['kg' => fn($v) => $v / 1000, 'lbs' => fn($v) => $v / 453.592, 'oz' => fn($v) => $v / 28.3495],
            'oz'  => ['g' => fn($v) => $v * 28.3495, 'kg' => fn($v) => $v * 0.0283495, 'lbs' => fn($v) => $v / 16],
            // Volume
            'liters' => ['gallons' => fn($v) => $v * 0.264172, 'ml' => fn($v) => $v * 1000, 'fl_oz' => fn($v) => $v * 33.814],
            'gallons' => ['liters' => fn($v) => $v * 3.78541, 'ml' => fn($v) => $v * 3785.41, 'fl_oz' => fn($v) => $v * 128],
            'ml'     => ['liters' => fn($v) => $v / 1000, 'gallons' => fn($v) => $v / 3785.41, 'fl_oz' => fn($v) => $v / 29.5735],
            'fl_oz'  => ['ml' => fn($v) => $v * 29.5735, 'liters' => fn($v) => $v / 33.814, 'gallons' => fn($v) => $v / 128],
        ];

        // Auto-detect target unit if not specified
        if ($toUnit === '' && isset($conversions[$fromUnit])) {
            $unitSystem = $prefs['unit_system'] ?? 'metric';
            $autoMap = [
                'km' => $unitSystem === 'imperial' ? 'miles' : 'miles',
                'miles' => 'km',
                'celsius' => 'fahrenheit',
                'fahrenheit' => 'celsius',
                'kg' => 'lbs',
                'lbs' => 'kg',
                'liters' => 'gallons',
                'gallons' => 'liters',
                'm' => 'ft',
                'ft' => 'm',
                'cm' => 'inches',
                'inches' => 'cm',
                'g' => 'oz',
                'oz' => 'g',
                'ml' => 'fl_oz',
                'fl_oz' => 'ml',
                'kelvin' => 'celsius',
            ];
            $toUnit = $autoMap[$fromUnit] ?? '';
        }

        if (!isset($conversions[$fromUnit][$toUnit])) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Unsupported conversion: *{$fromUnit}* → *{$toUnit}*\n\n_Supported: celsius↔fahrenheit↔kelvin, km↔miles, m↔ft, cm↔inches, kg↔lbs, g↔oz, liters↔gallons, ml↔fl_oz_",
                'es' => "⚠️ Conversión no soportada: *{$fromUnit}* → *{$toUnit}*\n\n_Soportadas: celsius↔fahrenheit↔kelvin, km↔miles, m↔ft, cm↔inches, kg↔lbs, g↔oz, liters↔gallons, ml↔fl_oz_",
                default => "⚠️ Conversion non supportée : *{$fromUnit}* → *{$toUnit}*\n\n_Supportées : celsius↔fahrenheit↔kelvin, km↔miles, m↔ft, cm↔inches, kg↔lbs, g↔oz, litres↔gallons, ml↔fl_oz_",
            };
            return AgentResult::reply($errMsg, ['action' => 'unit_convert', 'error' => 'unsupported_conversion']);
        }

        $result = $conversions[$fromUnit][$toUnit]($val);

        // Format result with appropriate precision
        $isTemperature = in_array($fromUnit, ['celsius', 'fahrenheit', 'kelvin']);
        $precision = $isTemperature ? 1 : ($result >= 100 ? 1 : ($result >= 1 ? 2 : 4));
        $formatted = rtrim(rtrim(number_format($result, $precision, '.', ''), '0'), '.');
        $inputFormatted = rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.');

        // Unit display symbols
        $symbols = [
            'celsius' => '°C', 'fahrenheit' => '°F', 'kelvin' => 'K',
            'km' => 'km', 'miles' => 'mi', 'm' => 'm', 'ft' => 'ft', 'cm' => 'cm', 'inches' => 'in',
            'kg' => 'kg', 'lbs' => 'lbs', 'g' => 'g', 'oz' => 'oz',
            'liters' => 'L', 'gallons' => 'gal', 'ml' => 'mL', 'fl_oz' => 'fl oz',
        ];
        $fromSym = $symbols[$fromUnit] ?? $fromUnit;
        $toSym   = $symbols[$toUnit] ?? $toUnit;

        // Category icon
        $icon = match (true) {
            $isTemperature => '🌡',
            in_array($fromUnit, ['km', 'miles', 'm', 'ft', 'cm', 'inches']) => '📏',
            in_array($fromUnit, ['kg', 'lbs', 'g', 'oz']) => '⚖️',
            default => '🧪',
        };

        $title = match ($lang) {
            'en' => "🔄 *UNIT CONVERSION*",
            'es' => "🔄 *CONVERSIÓN DE UNIDADES*",
            'de' => "🔄 *EINHEITENUMRECHNUNG*",
            'it' => "🔄 *CONVERSIONE UNITÀ*",
            'pt' => "🔄 *CONVERSÃO DE UNIDADES*",
            default => "🔄 *CONVERSION D'UNITÉS*",
        };

        $lines = [$title, "────────────────", ""];
        $lines[] = "{$icon} *{$inputFormatted} {$fromSym}* → *{$formatted} {$toSym}*";
        $lines[] = "";

        // Add reverse conversion as bonus
        $reverseFormatted = rtrim(rtrim(number_format($val, $precision, '.', ''), '0'), '.');
        $reverseLabel = match ($lang) {
            'en' => 'Reverse', 'es' => 'Inverso', 'de' => 'Umgekehrt', 'it' => 'Inverso', 'pt' => 'Inverso',
            default => 'Inverse',
        };
        $reverseResult = $conversions[$toUnit][$fromUnit] ?? null;
        if ($reverseResult) {
            $revVal = $reverseResult($result);
            $revFormatted = rtrim(rtrim(number_format($revVal, $precision, '.', ''), '0'), '.');
            $lines[] = "↩️ {$reverseLabel} : *{$formatted} {$toSym}* → *{$revFormatted} {$fromSym}*";
        }

        // Quick reference table for temperature
        if ($isTemperature && in_array($fromUnit, ['celsius', 'fahrenheit'])) {
            $lines[] = "";
            $refLabel = match ($lang) {
                'en' => 'Quick reference', 'es' => 'Referencia rápida', 'de' => 'Kurzreferenz',
                default => 'Référence rapide',
            };
            $lines[] = "📋 _{$refLabel} :_";
            $refs = $fromUnit === 'celsius' ? [0, 20, 37, 100] : [32, 68, 98.6, 212];
            foreach ($refs as $ref) {
                $refResult = $conversions[$fromUnit][$toUnit]($ref);
                $refFmt = rtrim(rtrim(number_format($refResult, 1, '.', ''), '0'), '.');
                $refIn  = rtrim(rtrim(number_format($ref, 1, '.', ''), '0'), '.');
                $lines[] = "  {$refIn}{$fromSym} = {$refFmt}{$toSym}";
            }
        }

        $lines[] = "";
        $lines[] = "────────────────";
        $tipLabel = match ($lang) {
            'en' => "Try: _37°C to °F_, _100 km to miles_, _70 kg to lbs_",
            'es' => "Prueba: _37°C a °F_, _100 km a millas_, _70 kg a libras_",
            default => "Essaie : _37°C en °F_, _100 km en miles_, _70 kg en livres_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'    => 'unit_convert',
            'from'      => $fromUnit,
            'to'        => $toUnit,
            'input'     => $val,
            'result'    => $result,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.36.0 — week_planner
    // -------------------------------------------------------------------------

    private function handleWeekPlanner(array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $userTz     = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
        $now        = new DateTimeImmutable('now', $userTz);

        $isoDay   = (int) $now->format('N'); // 1=Monday, 7=Sunday
        $hour     = (int) $now->format('G');
        $minute   = (int) $now->format('i');
        $weekNum  = (int) $now->format('W');

        // Calculate Monday and Sunday of this week
        $mondayOffset = $isoDay - 1;
        $monday = $now->modify("-{$mondayOffset} days")->setTime(0, 0);
        $sunday = $monday->modify('+6 days');

        // Title
        $title = match ($lang) {
            'en' => "📋 *WEEKLY PLANNER — W{$weekNum}*",
            'es' => "📋 *PLANIFICADOR SEMANAL — S{$weekNum}*",
            'de' => "📋 *WOCHENPLANER — W{$weekNum}*",
            'it' => "📋 *PIANIFICATORE SETTIMANALE — S{$weekNum}*",
            'pt' => "📋 *PLANEJADOR SEMANAL — S{$weekNum}*",
            default => "📋 *PLANNING HEBDOMADAIRE — S{$weekNum}*",
        };

        $lines = [$title, "────────────────"];

        // Date range
        $rangeLabel = match ($lang) {
            'en' => 'Period', 'es' => 'Período', 'de' => 'Zeitraum', 'it' => 'Periodo', 'pt' => 'Período',
            default => 'Période',
        };
        $lines[] = "📅 {$rangeLabel} : *{$monday->format($dateFormat)}* → *{$sunday->format($dateFormat)}*";
        $lines[] = "";

        // Day-by-day calendar with current day highlighted
        $dayLabels = match ($lang) {
            'en' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'es' => ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
            'de' => ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'],
            'it' => ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'],
            'pt' => ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
            default => ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'],
        };

        for ($d = 0; $d < 7; $d++) {
            $day = $monday->modify("+{$d} days");
            $dayNum = $d + 1;
            $isToday = $dayNum === $isoDay;
            $isPast  = $dayNum < $isoDay;
            $isWeekend = $dayNum >= 6;

            $marker = $isToday ? '👉' : ($isPast ? '✅' : ($isWeekend ? '🏖' : '⬜'));
            $label  = $dayLabels[$d];
            $dateStr = $day->format('d/m');

            $status = '';
            if ($isToday) {
                $nowLabel = match ($lang) {
                    'en' => 'TODAY', 'es' => 'HOY', 'de' => 'HEUTE', 'it' => 'OGGI', 'pt' => 'HOJE',
                    default => "AUJOURD'HUI",
                };
                $status = " ← *{$nowLabel}*";
            }

            $dayLine = "{$marker} *{$label}* {$dateStr}{$status}";
            $lines[] = $dayLine;
        }

        $lines[] = "";

        // Week progress
        $pctWeek = (int) round(($isoDay / 7) * 100);
        $weekBar = $this->progressBar($pctWeek);
        $weekProgressLabel = match ($lang) {
            'en' => 'Week progress', 'es' => 'Progreso semana', 'de' => 'Woche Fortschritt',
            'it' => 'Progresso settimana', 'pt' => 'Progresso semana',
            default => 'Progression semaine',
        };
        $lines[] = "📊 {$weekProgressLabel} : {$weekBar} *{$pctWeek}%* ({$isoDay}/7)";

        // Workday progress (if weekday)
        $isWeekday = $isoDay <= 5;
        if ($isWeekday) {
            $currentMinutes = $hour * 60 + $minute;
            $workStart = 9 * 60;
            $workEnd   = 18 * 60;

            if ($currentMinutes < $workStart) {
                $pctWork = 0;
            } elseif ($currentMinutes >= $workEnd) {
                $pctWork = 100;
            } else {
                $pctWork = (int) round((($currentMinutes - $workStart) / ($workEnd - $workStart)) * 100);
            }
            $workBar = $this->progressBar($pctWork);
            $workLabel = match ($lang) {
                'en' => 'Workday (9h-18h)', 'es' => 'Jornada (9h-18h)', 'de' => 'Arbeitstag (9-18h)',
                default => 'Journée (9h-18h)',
            };
            $lines[] = "💼 {$workLabel} : {$workBar} *{$pctWork}%*";

            // Remaining work hours
            if ($currentMinutes < $workEnd && $currentMinutes >= $workStart) {
                $remainMinutes = $workEnd - $currentMinutes;
                $remainH = intdiv($remainMinutes, 60);
                $remainM = $remainMinutes % 60;
                $remainLabel = match ($lang) {
                    'en' => 'remaining', 'es' => 'restante', 'de' => 'verbleibend',
                    default => 'restant',
                };
                $remainMStr = str_pad((string) $remainM, 2, '0', STR_PAD_LEFT);
                $lines[] = "   ⏳ {$remainH}h{$remainMStr} {$remainLabel}";
            }
        }

        // Working days left this week
        $workdaysLeft = max(0, 5 - $isoDay);
        if ($isWeekday && $hour < 18) {
            // Current day still counts
        } else {
            // Current day is done
        }
        $workdaysLabel = match ($lang) {
            'en' => 'Work days left this week', 'es' => 'Días laborales restantes',
            'de' => 'Verbleibende Arbeitstage', 'it' => 'Giorni lavorativi rimasti',
            default => 'Jours ouvrés restants',
        };
        $lines[] = "📌 {$workdaysLabel} : *{$workdaysLeft}*";

        $lines[] = "";

        // Time info
        $timeLabel = match ($lang) {
            'en' => 'Current time', 'es' => 'Hora actual', 'de' => 'Aktuelle Zeit',
            default => 'Heure actuelle',
        };
        $tzShort = $this->getShortTzName($prefs['timezone'] ?? 'UTC', $now);
        $lines[] = "🕐 {$timeLabel} : *{$now->format('H:i')}* ({$tzShort})";

        $lines[] = "";
        $lines[] = "────────────────";
        $tipLabel = match ($lang) {
            'en' => "Try: _calendar week_, _workday progress_, _week progress_, _daily summary_",
            'es' => "Prueba: _calendario semana_, _progreso jornada_, _progreso semana_",
            'de' => "Versuche: _Kalender Woche_, _Arbeitstag Fortschritt_, _Woche Fortschritt_",
            default => "Essaie : _calendrier semaine_, _progression journée_, _briefing du jour_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'       => 'week_planner',
            'week_number'  => $weekNum,
            'iso_day'      => $isoDay,
            'week_pct'     => $pctWeek,
            'workdays_left'=> $workdaysLeft,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.37.0 — season_info
    // -------------------------------------------------------------------------

    private function handleSeasonInfo(array $parsed, array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $userTz     = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
        $now        = new DateTimeImmutable('now', $userTz);
        $year       = (int) $now->format('Y');

        // Determine hemisphere from timezone offset (rough heuristic)
        // Positive latitude zones (Europe, Asia, North America) = Northern
        $tzId       = $prefs['timezone'] ?? 'UTC';
        $southern   = false;
        $southernPrefixes = ['Australia/', 'Pacific/Auckland', 'Pacific/Fiji', 'America/Buenos_Aires', 'America/Santiago', 'Africa/Johannesburg', 'Africa/Harare', 'Antarctica/'];
        foreach ($southernPrefixes as $prefix) {
            if (str_starts_with($tzId, $prefix)) {
                $southern = true;
                break;
            }
        }

        // Also check explicit hemisphere param from LLM
        $hemisphere = mb_strtolower(trim($parsed['hemisphere'] ?? ''));
        if ($hemisphere === 'south' || $hemisphere === 'sud' || $hemisphere === 'sur') {
            $southern = true;
        } elseif ($hemisphere === 'north' || $hemisphere === 'nord' || $hemisphere === 'norte') {
            $southern = false;
        }

        // Approximate equinox/solstice dates for the current year
        $springEquinox  = new DateTimeImmutable("{$year}-03-20", $userTz);
        $summerSolstice = new DateTimeImmutable("{$year}-06-21", $userTz);
        $autumnEquinox  = new DateTimeImmutable("{$year}-09-22", $userTz);
        $winterSolstice = new DateTimeImmutable("{$year}-12-21", $userTz);

        // Season boundaries (Northern hemisphere)
        $seasons = [
            ['start' => $springEquinox,  'end' => $summerSolstice->modify('-1 day'), 'n' => 'spring', 's' => 'autumn'],
            ['start' => $summerSolstice, 'end' => $autumnEquinox->modify('-1 day'),  'n' => 'summer', 's' => 'winter'],
            ['start' => $autumnEquinox,  'end' => $winterSolstice->modify('-1 day'), 'n' => 'autumn', 's' => 'spring'],
            ['start' => $winterSolstice, 'end' => new DateTimeImmutable("{$year}-12-31", $userTz), 'n' => 'winter', 's' => 'summer'],
        ];

        // Winter wraps around — also check Jan 1 to spring equinox
        $currentSeason = 'winter';
        $nextSeasonDate = $springEquinox;
        $nextSeasonKey  = 'spring';

        if ($now < $springEquinox) {
            $currentSeason  = $southern ? 'summer' : 'winter';
            $nextSeasonKey  = $southern ? 'autumn' : 'spring';
            $nextSeasonDate = $springEquinox;
        } else {
            foreach ($seasons as $s) {
                if ($now >= $s['start'] && $now <= $s['end']) {
                    $currentSeason  = $southern ? $s['s'] : $s['n'];
                    break;
                }
            }
            // Determine next season transition
            $transitions = [$springEquinox, $summerSolstice, $autumnEquinox, $winterSolstice];
            $nextSeasonDate = new DateTimeImmutable(($year + 1) . "-03-20", $userTz);
            $nextSeasonKey  = $southern ? 'autumn' : 'spring';
            foreach ($transitions as $i => $t) {
                if ($now < $t) {
                    $nextSeasonDate = $t;
                    $nextKeys = $southern
                        ? ['autumn', 'winter', 'spring', 'summer']
                        : ['spring', 'summer', 'autumn', 'winter'];
                    $nextSeasonKey = $nextKeys[$i];
                    break;
                }
            }
        }

        $daysUntilNext = max(0, (int) $now->diff($nextSeasonDate)->days);

        // Season labels
        $seasonLabels = [
            'fr' => ['spring' => '🌸 Printemps', 'summer' => '☀️ Été', 'autumn' => '🍂 Automne', 'winter' => '❄️ Hiver'],
            'en' => ['spring' => '🌸 Spring', 'summer' => '☀️ Summer', 'autumn' => '🍂 Autumn', 'winter' => '❄️ Winter'],
            'es' => ['spring' => '🌸 Primavera', 'summer' => '☀️ Verano', 'autumn' => '🍂 Otoño', 'winter' => '❄️ Invierno'],
            'de' => ['spring' => '🌸 Frühling', 'summer' => '☀️ Sommer', 'autumn' => '🍂 Herbst', 'winter' => '❄️ Winter'],
            'it' => ['spring' => '🌸 Primavera', 'summer' => '☀️ Estate', 'autumn' => '🍂 Autunno', 'winter' => '❄️ Inverno'],
            'pt' => ['spring' => '🌸 Primavera', 'summer' => '☀️ Verão', 'autumn' => '🍂 Outono', 'winter' => '❄️ Inverno'],
        ];
        $labels = $seasonLabels[$lang] ?? $seasonLabels['fr'];

        $title = match ($lang) {
            'en' => "🌍 *SEASON INFO*",
            'es' => "🌍 *INFO DE ESTACIÓN*",
            'de' => "🌍 *JAHRESZEIT INFO*",
            'it' => "🌍 *INFO STAGIONE*",
            'pt' => "🌍 *INFO DA ESTAÇÃO*",
            default => "🌍 *INFO SAISON*",
        };

        $hemisphereLabel = match ($lang) {
            'en' => $southern ? 'Southern hemisphere' : 'Northern hemisphere',
            'es' => $southern ? 'Hemisferio sur' : 'Hemisferio norte',
            'de' => $southern ? 'Südhalbkugel' : 'Nordhalbkugel',
            default => $southern ? 'Hémisphère sud' : 'Hémisphère nord',
        };

        $lines = [$title, "────────────────", ""];
        $lines[] = "📍 {$hemisphereLabel} _({$tzId})_";
        $lines[] = "";

        $currentLabel = match ($lang) {
            'en' => 'Current season', 'es' => 'Estación actual', 'de' => 'Aktuelle Jahreszeit',
            default => 'Saison actuelle',
        };
        $lines[] = "🗓 {$currentLabel} : *{$labels[$currentSeason]}*";

        // Season progress
        $seasonDurations = [
            'spring' => [$springEquinox, $summerSolstice],
            'summer' => [$summerSolstice, $autumnEquinox],
            'autumn' => [$autumnEquinox, $winterSolstice],
        ];

        $rawSeason = $southern ? array_search($currentSeason, ['autumn' => 'spring', 'winter' => 'summer', 'spring' => 'autumn', 'summer' => 'winter']) : $currentSeason;
        if ($rawSeason === false) {
            $rawSeason = $currentSeason;
        }

        // Calculate percentage of current season elapsed
        $totalDays = 90; // approximate
        $seasonPct = min(100, max(0, (int) round((1 - $daysUntilNext / $totalDays) * 100)));
        $seasonBar = $this->progressBar($seasonPct);
        $lines[] = "📊 {$seasonBar} *{$seasonPct}%*";
        $lines[] = "";

        // Next season
        $nextLabel = match ($lang) {
            'en' => 'Next season', 'es' => 'Próxima estación', 'de' => 'Nächste Jahreszeit',
            default => 'Prochaine saison',
        };
        $daysLabel = match ($lang) {
            'en' => 'days', 'es' => 'días', 'de' => 'Tage', 'it' => 'giorni', 'pt' => 'dias',
            default => 'jours',
        };
        $lines[] = "⏭ {$nextLabel} : *{$labels[$nextSeasonKey]}* — {$daysUntilNext} {$daysLabel} ({$nextSeasonDate->format($dateFormat)})";
        $lines[] = "";

        // Key dates
        $keyDatesLabel = match ($lang) {
            'en' => 'KEY DATES', 'es' => 'FECHAS CLAVE', 'de' => 'WICHTIGE DATEN',
            default => 'DATES CLÉS',
        };
        $lines[] = "📅 *{$keyDatesLabel} {$year} :*";

        $equinoxLabel = match ($lang) {
            'en' => ['Spring equinox', 'Summer solstice', 'Autumn equinox', 'Winter solstice'],
            'es' => ['Equinoccio primavera', 'Solsticio verano', 'Equinoccio otoño', 'Solsticio invierno'],
            'de' => ['Frühlingsäquinoktium', 'Sommersonnenwende', 'Herbstäquinoktium', 'Wintersonnenwende'],
            default => ['Équinoxe printemps', 'Solstice été', 'Équinoxe automne', 'Solstice hiver'],
        };

        $keyDates = [$springEquinox, $summerSolstice, $autumnEquinox, $winterSolstice];
        foreach ($keyDates as $i => $date) {
            $isPast = $now > $date;
            $marker = $isPast ? '✅' : '⬜';
            $lines[] = "  {$marker} {$equinoxLabel[$i]} : *{$date->format($dateFormat)}*";
        }

        $lines[] = "";
        $lines[] = "────────────────";
        $tipLabel = match ($lang) {
            'en' => "Try: _year progress_, _sun times_, _calendar month_",
            'es' => "Prueba: _progreso año_, _horas del sol_, _calendario mes_",
            default => "Essaie : _progression année_, _lever du soleil_, _calendrier mois_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'         => 'season_info',
            'current_season' => $currentSeason,
            'hemisphere'     => $southern ? 'south' : 'north',
            'days_until_next'=> $daysUntilNext,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.37.0 — quick_timer
    // -------------------------------------------------------------------------

    private function handleQuickTimer(array $parsed, array $prefs): AgentResult
    {
        $lang   = $prefs['language'] ?? 'fr';
        $userTz = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now    = new DateTimeImmutable('now', $userTz);

        $startTime = trim($parsed['start_time'] ?? '');
        $durationStr = trim($parsed['duration'] ?? '');

        // Parse start time — if empty or "now", use current time
        if ($startTime === '' || mb_strtolower($startTime) === 'now' || mb_strtolower($startTime) === 'maintenant') {
            $startHour   = (int) $now->format('G');
            $startMinute = (int) $now->format('i');
            $startParsed = sprintf('%02d:%02d', $startHour, $startMinute);
            $usedNow     = true;
        } else {
            $startParsed = $this->parseTimeString($startTime);
            $usedNow     = false;
            if ($startParsed === null) {
                $errMsg = match ($lang) {
                    'en' => "⚠️ Invalid start time: *{$startTime}*\n\n_Use formats like: 14h30, 2pm, 09:00_",
                    'es' => "⚠️ Hora de inicio inválida: *{$startTime}*\n\n_Usa formatos como: 14h30, 2pm, 09:00_",
                    'de' => "⚠️ Ungültige Startzeit: *{$startTime}*\n\n_Formate: 14h30, 2pm, 09:00_",
                    default => "⚠️ Heure de début invalide : *{$startTime}*\n\n_Formats acceptés : 14h30, 2pm, 09:00_",
                };
                return AgentResult::reply($errMsg, ['action' => 'quick_timer', 'error' => 'invalid_start']);
            }
        }

        // Parse duration — support: 2h, 2h30, 1h45, 30m, 90m, 2:30, 45min
        $durHours = 0;
        $durMinutes = 0;

        if (preg_match('/^(\d+)\s*h\s*(\d+)?$/i', $durationStr, $dm)) {
            $durHours   = (int) $dm[1];
            $durMinutes = isset($dm[2]) && $dm[2] !== '' ? (int) $dm[2] : 0;
        } elseif (preg_match('/^(\d+)\s*(?:m|min|mins|minutes?)$/i', $durationStr, $dm)) {
            $durMinutes = (int) $dm[1];
        } elseif (preg_match('/^(\d+):(\d{2})$/', $durationStr, $dm)) {
            $durHours   = (int) $dm[1];
            $durMinutes = (int) $dm[2];
        } elseif (is_numeric($durationStr)) {
            // Bare number: assume minutes if < 24, else minutes
            $val = (int) $durationStr;
            if ($val > 0 && $val < 24) {
                $durHours = $val;
            } else {
                $durMinutes = $val;
            }
        } else {
            $errMsg = match ($lang) {
                'en' => "⚠️ Invalid duration: *{$durationStr}*\n\n_Use formats like: 2h, 2h30, 90m, 1:45_",
                'es' => "⚠️ Duración inválida: *{$durationStr}*\n\n_Usa formatos como: 2h, 2h30, 90m, 1:45_",
                'de' => "⚠️ Ungültige Dauer: *{$durationStr}*\n\n_Formate: 2h, 2h30, 90m, 1:45_",
                default => "⚠️ Durée invalide : *{$durationStr}*\n\n_Formats acceptés : 2h, 2h30, 90m, 1:45_",
            };
            return AgentResult::reply($errMsg, ['action' => 'quick_timer', 'error' => 'invalid_duration']);
        }

        $totalDurMinutes = $durHours * 60 + $durMinutes;
        if ($totalDurMinutes <= 0 || $totalDurMinutes > 1440) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Duration must be between 1 minute and 24 hours.",
                'es' => "⚠️ La duración debe estar entre 1 minuto y 24 horas.",
                default => "⚠️ La durée doit être entre 1 minute et 24 heures.",
            };
            return AgentResult::reply($errMsg, ['action' => 'quick_timer', 'error' => 'out_of_range']);
        }

        // Calculate end time
        [$startH, $startM] = array_map('intval', explode(':', $startParsed));
        $startTotalMin = $startH * 60 + $startM;
        $endTotalMin   = $startTotalMin + $totalDurMinutes;

        $crossesMidnight = $endTotalMin >= 1440;
        $endH = intdiv($endTotalMin % 1440, 60);
        $endM = $endTotalMin % 60;
        $endTime = sprintf('%02d:%02d', $endH, $endM);

        // Format duration display
        $durDisplay = '';
        $dH = intdiv($totalDurMinutes, 60);
        $dM = $totalDurMinutes % 60;
        if ($dH > 0 && $dM > 0) {
            $durDisplay = "{$dH}h" . str_pad((string) $dM, 2, '0', STR_PAD_LEFT);
        } elseif ($dH > 0) {
            $durDisplay = "{$dH}h";
        } else {
            $durDisplay = "{$dM}min";
        }

        // Build output
        $title = match ($lang) {
            'en' => "⏱ *QUICK TIMER*",
            'es' => "⏱ *TEMPORIZADOR RÁPIDO*",
            'de' => "⏱ *SCHNELLER TIMER*",
            'it' => "⏱ *TIMER RAPIDO*",
            'pt' => "⏱ *TEMPORIZADOR RÁPIDO*",
            default => "⏱ *MINUTEUR RAPIDE*",
        };

        $lines = [$title, "────────────────", ""];

        $startLabel = match ($lang) {
            'en' => 'Start', 'es' => 'Inicio', 'de' => 'Start', 'it' => 'Inizio', 'pt' => 'Início',
            default => 'Début',
        };
        $endLabel = match ($lang) {
            'en' => 'End', 'es' => 'Fin', 'de' => 'Ende', 'it' => 'Fine', 'pt' => 'Fim',
            default => 'Fin',
        };
        $durLabel = match ($lang) {
            'en' => 'Duration', 'es' => 'Duración', 'de' => 'Dauer', 'it' => 'Durata', 'pt' => 'Duração',
            default => 'Durée',
        };

        $nowIndicator = $usedNow ? match ($lang) {
            'en' => ' _(now)_', 'es' => ' _(ahora)_', 'de' => ' _(jetzt)_',
            default => ' _(maintenant)_',
        } : '';

        $lines[] = "🟢 {$startLabel} : *{$startParsed}*{$nowIndicator}";
        $lines[] = "⏳ {$durLabel} : *{$durDisplay}*";
        $lines[] = "🏁 {$endLabel} : *{$endTime}*";

        if ($crossesMidnight) {
            $nextDayLabel = match ($lang) {
                'en' => 'next day', 'es' => 'día siguiente', 'de' => 'nächster Tag',
                default => 'lendemain',
            };
            $lines[] = "   ⚠️ _({$nextDayLabel})_";
        }

        // If started from "now", show remaining time
        if ($usedNow) {
            $lines[] = "";
            $remainLabel = match ($lang) {
                'en' => 'Ends in', 'es' => 'Termina en', 'de' => 'Endet in',
                default => 'Se termine dans',
            };
            $lines[] = "⏰ {$remainLabel} *{$durDisplay}*";
        }

        // Visual timeline
        $lines[] = "";
        $lines[] = "📍 {$startParsed} ━━━ {$durDisplay} ━━━▶ {$endTime}";

        $lines[] = "";
        $lines[] = "────────────────";
        $tipLabel = match ($lang) {
            'en' => "Try: _timer 14h 2h30_, _pomodoro_, _time until 18h_",
            'es' => "Prueba: _timer 14h 2h30_, _pomodoro_, _tiempo hasta 18h_",
            default => "Essaie : _minuteur 14h 2h30_, _pomodoro_, _temps avant 18h_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'      => 'quick_timer',
            'start_time'  => $startParsed,
            'duration_min'=> $totalDurMinutes,
            'end_time'    => $endTime,
            'crosses_midnight' => $crossesMidnight,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.38.0 — market_hours
    // -------------------------------------------------------------------------

    private function handleMarketHours(array $parsed, array $prefs): AgentResult
    {
        $lang   = $prefs['language'] ?? 'fr';
        $userTz = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now    = new DateTimeImmutable('now', $userTz);

        $markets = [
            'NYSE'     => ['tz' => 'America/New_York',   'open' => '09:30', 'close' => '16:00', 'label' => 'NYSE (New York)',       'flag' => '🇺🇸'],
            'NASDAQ'   => ['tz' => 'America/New_York',   'open' => '09:30', 'close' => '16:00', 'label' => 'NASDAQ (New York)',     'flag' => '🇺🇸'],
            'LSE'      => ['tz' => 'Europe/London',      'open' => '08:00', 'close' => '16:30', 'label' => 'LSE (London)',           'flag' => '🇬🇧'],
            'Euronext' => ['tz' => 'Europe/Paris',       'open' => '09:00', 'close' => '17:30', 'label' => 'Euronext (Paris)',       'flag' => '🇪🇺'],
            'TSE'      => ['tz' => 'Asia/Tokyo',         'open' => '09:00', 'close' => '15:00', 'label' => 'TSE (Tokyo)',            'flag' => '🇯🇵'],
            'HKEX'     => ['tz' => 'Asia/Hong_Kong',     'open' => '09:30', 'close' => '16:00', 'label' => 'HKEX (Hong Kong)',      'flag' => '🇭🇰'],
            'SSE'      => ['tz' => 'Asia/Shanghai',      'open' => '09:30', 'close' => '15:00', 'label' => 'SSE (Shanghai)',         'flag' => '🇨🇳'],
            'ASX'      => ['tz' => 'Australia/Sydney',   'open' => '10:00', 'close' => '16:00', 'label' => 'ASX (Sydney)',           'flag' => '🇦🇺'],
        ];

        // Filter to requested markets if specified
        $requestedMarkets = $parsed['markets'] ?? [];
        if (!empty($requestedMarkets) && is_array($requestedMarkets)) {
            $filtered = [];
            foreach ($requestedMarkets as $m) {
                $key = strtoupper(trim($m));
                if (isset($markets[$key])) {
                    $filtered[$key] = $markets[$key];
                }
            }
            if (!empty($filtered)) {
                $markets = $filtered;
            }
        }

        $title = match ($lang) {
            'en' => "📈 *FINANCIAL MARKETS STATUS*",
            'es' => "📈 *ESTADO DE LOS MERCADOS FINANCIEROS*",
            'de' => "📈 *STATUS DER FINANZMÄRKTE*",
            'it' => "📈 *STATO DEI MERCATI FINANZIARI*",
            'pt' => "📈 *STATUS DOS MERCADOS FINANCEIROS*",
            default => "📈 *STATUT DES MARCHÉS FINANCIERS*",
        };

        $lines = [$title, "────────────────", ""];

        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
        $lines[] = "📅 " . $now->format($dateFormat) . " — " . $now->format('H:i') . " _(" . $this->getShortTzName($prefs['timezone'] ?? 'UTC') . ")_";
        $lines[] = "";

        $openCount  = 0;
        $closeCount = 0;

        foreach ($markets as $code => $info) {
            $marketTz   = new DateTimeZone($info['tz']);
            $marketNow  = new DateTimeImmutable('now', $marketTz);
            $marketTime = $marketNow->format('H:i');
            $dayOfWeek  = (int) $marketNow->format('N');

            $isWeekend = $dayOfWeek >= 6;
            $openTime  = $info['open'];
            $closeTime = $info['close'];

            if ($isWeekend) {
                $isOpen  = false;
                $statusIcon = '🔴';
                $statusText = match ($lang) {
                    'en' => 'Closed (weekend)', 'es' => 'Cerrado (fin de semana)',
                    'de' => 'Geschlossen (Wochenende)', 'it' => 'Chiuso (weekend)',
                    'pt' => 'Fechado (fim de semana)',
                    default => 'Fermé (week-end)',
                };
            } elseif ($marketTime >= $openTime && $marketTime < $closeTime) {
                $isOpen  = true;
                $statusIcon = '🟢';

                // Calculate time until close
                [$ch, $cm] = array_map('intval', explode(':', $closeTime));
                [$nh, $nm] = array_map('intval', explode(':', $marketTime));
                $remainMin = ($ch * 60 + $cm) - ($nh * 60 + $nm);
                $rH = intdiv($remainMin, 60);
                $rM = $remainMin % 60;
                $remainStr = $rH > 0 ? "{$rH}h" . ($rM > 0 ? str_pad((string) $rM, 2, '0', STR_PAD_LEFT) : '') : "{$rM}min";

                $closesIn = match ($lang) {
                    'en' => "closes in {$remainStr}", 'es' => "cierra en {$remainStr}",
                    'de' => "schließt in {$remainStr}", 'it' => "chiude tra {$remainStr}",
                    'pt' => "fecha em {$remainStr}",
                    default => "ferme dans {$remainStr}",
                };
                $statusText = match ($lang) {
                    'en' => "Open ({$closesIn})", 'es' => "Abierto ({$closesIn})",
                    'de' => "Geöffnet ({$closesIn})", 'it' => "Aperto ({$closesIn})",
                    'pt' => "Aberto ({$closesIn})",
                    default => "Ouvert ({$closesIn})",
                };
                $openCount++;
            } else {
                $isOpen  = false;
                $statusIcon = '🔴';

                // Calculate time until open (next session)
                if ($marketTime < $openTime) {
                    [$oh, $om] = array_map('intval', explode(':', $openTime));
                    [$nh, $nm] = array_map('intval', explode(':', $marketTime));
                    $untilMin = ($oh * 60 + $om) - ($nh * 60 + $nm);
                } else {
                    // After close — next open is tomorrow
                    [$oh, $om] = array_map('intval', explode(':', $openTime));
                    [$nh, $nm] = array_map('intval', explode(':', $marketTime));
                    $untilMin = (24 * 60 - ($nh * 60 + $nm)) + ($oh * 60 + $om);
                }
                $uH = intdiv($untilMin, 60);
                $uM = $untilMin % 60;
                $untilStr = $uH > 0 ? "{$uH}h" . ($uM > 0 ? str_pad((string) $uM, 2, '0', STR_PAD_LEFT) : '') : "{$uM}min";

                $opensIn = match ($lang) {
                    'en' => "opens in {$untilStr}", 'es' => "abre en {$untilStr}",
                    'de' => "öffnet in {$untilStr}", 'it' => "apre tra {$untilStr}",
                    'pt' => "abre em {$untilStr}",
                    default => "ouvre dans {$untilStr}",
                };
                $statusText = match ($lang) {
                    'en' => "Closed ({$opensIn})", 'es' => "Cerrado ({$opensIn})",
                    'de' => "Geschlossen ({$opensIn})", 'it' => "Chiuso ({$opensIn})",
                    'pt' => "Fechado ({$opensIn})",
                    default => "Fermé ({$opensIn})",
                };
                $closeCount++;
            }

            $lines[] = "{$info['flag']} {$statusIcon} *{$info['label']}*";
            $lines[] = "   🕐 {$marketTime} — {$openTime}–{$closeTime}";
            $lines[] = "   {$statusText}";
            $lines[] = "";
        }

        // Summary
        $lines[] = "────────────────";
        $totalMarkets = count($markets);
        $summaryLabel = match ($lang) {
            'en' => "{$openCount}/{$totalMarkets} markets open",
            'es' => "{$openCount}/{$totalMarkets} mercados abiertos",
            'de' => "{$openCount}/{$totalMarkets} Märkte geöffnet",
            'it' => "{$openCount}/{$totalMarkets} mercati aperti",
            'pt' => "{$openCount}/{$totalMarkets} mercados abertos",
            default => "{$openCount}/{$totalMarkets} marchés ouverts",
        };
        $lines[] = "📊 {$summaryLabel}";

        $lines[] = "";
        $tipLabel = match ($lang) {
            'en' => "Try: _market hours NYSE LSE_, _business hours Tokyo_, _worldclock_",
            'es' => "Prueba: _market hours NYSE LSE_, _horarios Tokyo_, _worldclock_",
            default => "Essaie : _market hours NYSE LSE_, _heures ouvrables Tokyo_, _horloge mondiale_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'       => 'market_hours',
            'open_count'   => $openCount,
            'total_markets'=> $totalMarkets,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.38.0 — week_summary
    // -------------------------------------------------------------------------

    private function handleWeekSummary(array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $userTz     = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $now        = new DateTimeImmutable('now', $userTz);
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';

        $isoDay    = (int) $now->format('N'); // 1=Mon ... 7=Sun
        $hour      = (int) $now->format('G');
        $minute    = (int) $now->format('i');
        $weekNum   = (int) $now->format('W');

        $title = match ($lang) {
            'en' => "📋 *WEEK SUMMARY*",
            'es' => "📋 *RESUMEN DE LA SEMANA*",
            'de' => "📋 *WOCHENZUSAMMENFASSUNG*",
            'it' => "📋 *RIEPILOGO SETTIMANALE*",
            'pt' => "📋 *RESUMO DA SEMANA*",
            default => "📋 *RÉSUMÉ DE LA SEMAINE*",
        };

        $lines = [$title, "────────────────", ""];

        // Current day and date (format('w'): 0=Sun..6=Sat matches DAY_NAMES index)
        $dayName = $this->getDayName((int) $now->format('w'), $lang);
        $lines[] = "📅 *{$dayName}* — " . $now->format($dateFormat);
        $lines[] = "🕐 " . $now->format('H:i') . " _(" . $this->getShortTzName($prefs['timezone'] ?? 'UTC') . ")_";

        $weekLabel = match ($lang) {
            'en' => 'Week', 'es' => 'Semana', 'de' => 'Woche', 'it' => 'Settimana', 'pt' => 'Semana',
            default => 'Semaine',
        };
        $lines[] = "📆 {$weekLabel} {$weekNum}";
        $lines[] = "";

        // Week progress bar
        $weekPct = (int) round((($isoDay - 1) * 24 + $hour) / (7 * 24) * 100);
        $weekPct = min(100, max(0, $weekPct));
        $progressLabel = match ($lang) {
            'en' => 'Week progress', 'es' => 'Progreso semana', 'de' => 'Wochenfortschritt',
            'it' => 'Progresso settimana', 'pt' => 'Progresso semana',
            default => 'Progression semaine',
        };
        $lines[] = "📊 {$progressLabel} : {$this->progressBar($weekPct)} {$weekPct}%";
        $lines[] = "";

        // Working days status (Mon-Fri)
        $workingDaysLabel = match ($lang) {
            'en' => 'Working days', 'es' => 'Días laborables', 'de' => 'Arbeitstage',
            'it' => 'Giorni lavorativi', 'pt' => 'Dias úteis',
            default => 'Jours ouvrés',
        };

        $dayIcons = [];
        for ($d = 1; $d <= 5; $d++) {
            $shortDay = $this->getDayName($d, $lang, true);
            if ($d < $isoDay) {
                $dayIcons[] = "✅ {$shortDay}";
            } elseif ($d === $isoDay) {
                $dayIcons[] = "🔵 *{$shortDay}*";
            } else {
                $dayIcons[] = "⬜ {$shortDay}";
            }
        }
        $lines[] = "*{$workingDaysLabel}* :";
        $lines[] = implode("  ", $dayIcons);
        $lines[] = "";

        // Days remaining this work week
        $workDaysLeft = max(0, 5 - $isoDay);
        if ($isoDay > 5) {
            $workDaysLeft = 0;
        }
        $remainLabel = match ($lang) {
            'en' => $workDaysLeft === 0 ? 'Weekend! No working days left' : "{$workDaysLeft} working day(s) left",
            'es' => $workDaysLeft === 0 ? '¡Fin de semana! Sin días laborables' : "{$workDaysLeft} día(s) laborable(s) restante(s)",
            'de' => $workDaysLeft === 0 ? 'Wochenende! Keine Arbeitstage übrig' : "{$workDaysLeft} Arbeitstag(e) übrig",
            'it' => $workDaysLeft === 0 ? 'Weekend! Nessun giorno lavorativo' : "{$workDaysLeft} giorno/i lavorativo/i restante/i",
            'pt' => $workDaysLeft === 0 ? 'Fim de semana! Sem dias úteis' : "{$workDaysLeft} dia(s) útil(eis) restante(s)",
            default => $workDaysLeft === 0 ? 'Week-end ! Aucun jour ouvré restant' : "{$workDaysLeft} jour(s) ouvré(s) restant(s)",
        };
        $remainIcon = $workDaysLeft === 0 ? '🏖' : '💼';
        $lines[] = "{$remainIcon} {$remainLabel}";
        $lines[] = "";

        // Weekend countdown
        if ($isoDay <= 5) {
            // Calculate hours until Friday 18:00
            $hoursUntilWeekend = (5 - $isoDay) * 24 + (18 - $hour);
            if ($minute > 0 && $hour >= 18) {
                $hoursUntilWeekend--;
            }
            $hoursUntilWeekend = max(0, $hoursUntilWeekend);
            $wkdH = intdiv($hoursUntilWeekend, 1); // already hours
            $wkdLabel = match ($lang) {
                'en' => "Weekend in ~{$hoursUntilWeekend}h",
                'es' => "Fin de semana en ~{$hoursUntilWeekend}h",
                'de' => "Wochenende in ~{$hoursUntilWeekend}h",
                'it' => "Weekend tra ~{$hoursUntilWeekend}h",
                'pt' => "Fim de semana em ~{$hoursUntilWeekend}h",
                default => "Week-end dans ~{$hoursUntilWeekend}h",
            };
            $lines[] = "⏳ {$wkdLabel}";
        } else {
            $mondayIn = (8 - $isoDay) * 24 - $hour;
            $mondayIn = max(0, $mondayIn);
            $mondayLabel = match ($lang) {
                'en' => "Monday in ~{$mondayIn}h — enjoy your weekend!",
                'es' => "Lunes en ~{$mondayIn}h — ¡disfruta tu fin de semana!",
                'de' => "Montag in ~{$mondayIn}h — genieße dein Wochenende!",
                'it' => "Lunedì tra ~{$mondayIn}h — goditi il weekend!",
                'pt' => "Segunda em ~{$mondayIn}h — aproveite o fim de semana!",
                default => "Lundi dans ~{$mondayIn}h — profite de ton week-end !",
            };
            $lines[] = "🏖 {$mondayLabel}";
        }

        // Workday progress (if during a workday)
        if ($isoDay <= 5 && $hour >= 9 && $hour < 18) {
            $workElapsed = ($hour - 9) * 60 + $minute;
            $workTotal   = 9 * 60; // 9h-18h = 9 hours
            $workPct     = (int) round($workElapsed / $workTotal * 100);
            $lines[] = "";
            $dayProgressLabel = match ($lang) {
                'en' => 'Workday progress (9h–18h)',
                'es' => 'Progreso jornada (9h–18h)',
                'de' => 'Arbeitstag-Fortschritt (9–18h)',
                'it' => 'Progresso giornata (9h–18h)',
                'pt' => 'Progresso jornada (9h–18h)',
                default => 'Progression journée (9h–18h)',
            };
            $lines[] = "⏱ {$dayProgressLabel} : {$this->progressBar($workPct)} {$workPct}%";
        }

        $lines[] = "";
        $lines[] = "────────────────";
        $tipLabel = match ($lang) {
            'en' => "Try: _week progress_, _calendar week_, _daily summary_, _productivity score_",
            'es' => "Prueba: _progreso semana_, _calendario semana_, _resumen diario_",
            default => "Essaie : _progression semaine_, _calendrier semaine_, _daily summary_, _score productivité_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'          => 'week_summary',
            'iso_day'         => $isoDay,
            'week_number'     => $weekNum,
            'week_pct'        => $weekPct,
            'work_days_left'  => $workDaysLeft,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.39.0 — date_diff
    // -------------------------------------------------------------------------

    private function handleDateDiff(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTz    = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';

        $date1Str = trim($parsed['date1'] ?? '');
        $date2Str = trim($parsed['date2'] ?? '');

        if ($date1Str === '' || $date2Str === '') {
            $errMsg = match ($lang) {
                'en' => "⚠️ Please provide two dates.\n\n_Example: difference between 2026-01-01 and 2026-03-25_",
                'es' => "⚠️ Proporciona dos fechas.\n\n_Ejemplo: diferencia entre 2026-01-01 y 2026-03-25_",
                'de' => "⚠️ Bitte gib zwei Daten an.\n\n_Beispiel: Unterschied zwischen 2026-01-01 und 2026-03-25_",
                default => "⚠️ Indique deux dates.\n\n_Exemple : différence entre 2026-01-01 et 2026-03-25_",
            };
            return AgentResult::reply($errMsg, ['action' => 'date_diff', 'error' => 'missing_dates']);
        }

        try {
            $d1 = new DateTimeImmutable($date1Str, $userTz);
            $d2 = new DateTimeImmutable($date2Str, $userTz);
        } catch (\Throwable) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Invalid date format. Use YYYY-MM-DD.\n\n_Example: days between 2026-01-01 and 2026-06-30_",
                'es' => "⚠️ Formato de fecha inválido. Usa AAAA-MM-DD.\n\n_Ejemplo: días entre 2026-01-01 y 2026-06-30_",
                default => "⚠️ Format de date invalide. Utilise AAAA-MM-JJ.\n\n_Exemple : jours entre 2026-01-01 et 2026-06-30_",
            };
            return AgentResult::reply($errMsg, ['action' => 'date_diff', 'error' => 'invalid_date']);
        }

        // Ensure d1 <= d2 for display, keep track of order
        $swapped = false;
        if ($d1 > $d2) {
            [$d1, $d2] = [$d2, $d1];
            $swapped = true;
        }

        $diff       = $d1->diff($d2);
        $totalDays  = (int) $diff->days;
        $weeks      = intdiv($totalDays, 7);
        $extraDays  = $totalDays % 7;
        $months     = $diff->m + ($diff->y * 12);
        $monthDays  = $diff->d;

        // Count weekdays & weekends
        $weekdays = 0;
        $weekends = 0;
        $cursor = $d1;
        for ($i = 0; $i < $totalDays; $i++) {
            $dayOfWeek = (int) $cursor->format('N');
            if ($dayOfWeek <= 5) {
                $weekdays++;
            } else {
                $weekends++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        // Build output
        $titleLabel = match ($lang) {
            'en' => 'DATE DIFFERENCE', 'es' => 'DIFERENCIA DE FECHAS',
            'de' => 'DATUMSUNTERSCHIED', 'it' => 'DIFFERENZA DATE',
            'pt' => 'DIFERENÇA DE DATAS',
            default => 'DIFFÉRENCE ENTRE DATES',
        };

        $lines = [
            "📐 *{$titleLabel}*",
            "────────────────",
            "",
        ];

        $fromLabel = match ($lang) { 'en' => 'From', 'es' => 'Desde', 'de' => 'Von', default => 'Du' };
        $toLabel   = match ($lang) { 'en' => 'To', 'es' => 'Hasta', 'de' => 'Bis', default => 'Au' };

        $d1DayName = $this->getDayName((int) $d1->format('w'), $lang);
        $d2DayName = $this->getDayName((int) $d2->format('w'), $lang);

        $lines[] = "📅 {$fromLabel} : *{$d1->format($dateFormat)}* ({$d1DayName})";
        $lines[] = "📅 {$toLabel} : *{$d2->format($dateFormat)}* ({$d2DayName})";
        $lines[] = "";

        // Total days
        $daysLabel = match ($lang) {
            'en' => 'Total days', 'es' => 'Días totales', 'de' => 'Tage gesamt',
            default => 'Jours total',
        };
        $lines[] = "📊 {$daysLabel} : *{$totalDays}*";

        // Weeks + days
        if ($weeks > 0) {
            $weeksLabel = match ($lang) {
                'en' => 'weeks', 'es' => 'semanas', 'de' => 'Wochen', default => 'semaines',
            };
            $andDaysLabel = match ($lang) {
                'en' => 'days', 'es' => 'días', 'de' => 'Tage', default => 'jours',
            };
            $weeksStr = "{$weeks} {$weeksLabel}";
            if ($extraDays > 0) {
                $weeksStr .= " + {$extraDays} {$andDaysLabel}";
            }
            $lines[] = "📅 ≈ *{$weeksStr}*";
        }

        // Months + days
        if ($months > 0) {
            $monthsLabel = match ($lang) {
                'en' => 'months', 'es' => 'meses', 'de' => 'Monate', default => 'mois',
            };
            $andDaysLabel2 = match ($lang) {
                'en' => 'days', 'es' => 'días', 'de' => 'Tage', default => 'jours',
            };
            $monthStr = "{$months} {$monthsLabel}";
            if ($monthDays > 0) {
                $monthStr .= " + {$monthDays} {$andDaysLabel2}";
            }
            $lines[] = "🗓 ≈ *{$monthStr}*";
        }

        // Weekday/weekend breakdown
        $lines[] = "";
        $wdLabel = match ($lang) { 'en' => 'Weekdays', 'es' => 'Laborables', 'de' => 'Werktage', default => 'Jours ouvrés' };
        $weLabel = match ($lang) { 'en' => 'Weekend days', 'es' => 'Fines de semana', 'de' => 'Wochenendtage', default => 'Jours de week-end' };
        $lines[] = "💼 {$wdLabel} : *{$weekdays}*";
        $lines[] = "🏖 {$weLabel} : *{$weekends}*";

        $lines[] = "";
        $lines[] = "────────────────";
        $tipLabel = match ($lang) {
            'en' => "Try: _days between jan 1 and dec 31_, _date diff 2025-06-01 2026-01-01_",
            'es' => "Prueba: _días entre 1 ene y 31 dic_, _diferencia fechas_",
            default => "Essaie : _jours entre 1er janvier et 31 décembre_, _différence dates_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'       => 'date_diff',
            'total_days'   => $totalDays,
            'weeks'        => $weeks,
            'months'       => $months,
            'weekdays'     => $weekdays,
            'weekend_days' => $weekends,
            'swapped'      => $swapped,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.39.0 — relative_date
    // -------------------------------------------------------------------------

    private function handleRelativeDate(array $parsed, array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $userTz     = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
        $now        = new DateTimeImmutable('now', $userTz);
        $today      = new DateTimeImmutable($now->format('Y-m-d'), $userTz);

        $dateStr = trim($parsed['date'] ?? '');
        if ($dateStr === '') {
            $errMsg = match ($lang) {
                'en' => "⚠️ Please provide a date.\n\n_Example: how long ago was January 1st_",
                'es' => "⚠️ Proporciona una fecha.\n\n_Ejemplo: hace cuántos días fue el 1 de enero_",
                default => "⚠️ Indique une date.\n\n_Exemple : il y a combien de jours le 1er janvier_",
            };
            return AgentResult::reply($errMsg, ['action' => 'relative_date', 'error' => 'missing_date']);
        }

        try {
            $target = new DateTimeImmutable($dateStr, $userTz);
        } catch (\Throwable) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Invalid date: *{$dateStr}*\n\n_Use format YYYY-MM-DD_",
                'es' => "⚠️ Fecha inválida: *{$dateStr}*\n\n_Usa formato AAAA-MM-DD_",
                default => "⚠️ Date invalide : *{$dateStr}*\n\n_Utilise le format AAAA-MM-JJ_",
            };
            return AgentResult::reply($errMsg, ['action' => 'relative_date', 'error' => 'invalid_date']);
        }

        $targetDay = new DateTimeImmutable($target->format('Y-m-d'), $userTz);
        $diff      = $today->diff($targetDay);
        $totalDays = (int) $diff->days;
        $isPast    = $targetDay < $today;
        $isToday   = $totalDays === 0;
        $weeks     = intdiv($totalDays, 7);
        $extraDays = $totalDays % 7;
        $months    = $diff->m + ($diff->y * 12);
        $monthDays = $diff->d;

        $dayName   = $this->getDayName((int) $target->format('w'), $lang);

        // Build output
        $titleLabel = match ($lang) {
            'en' => 'RELATIVE DATE', 'es' => 'FECHA RELATIVA', 'de' => 'RELATIVES DATUM',
            default => 'DATE RELATIVE',
        };

        $lines = [
            "🕐 *{$titleLabel}*",
            "────────────────",
            "",
        ];

        $dateLabel = match ($lang) { 'en' => 'Date', 'es' => 'Fecha', 'de' => 'Datum', default => 'Date' };
        $todayLabel = match ($lang) { 'en' => 'Today', 'es' => 'Hoy', 'de' => 'Heute', default => "Aujourd'hui" };

        $lines[] = "📅 {$dateLabel} : *{$target->format($dateFormat)}* ({$dayName})";
        $lines[] = "📍 {$todayLabel} : *{$today->format($dateFormat)}*";
        $lines[] = "";

        if ($isToday) {
            $todayMsg = match ($lang) {
                'en' => "That's today!", 'es' => '¡Es hoy!', 'de' => 'Das ist heute!',
                default => "C'est aujourd'hui !",
            };
            $lines[] = "✨ *{$todayMsg}*";
        } elseif ($isPast) {
            $agoLabel = match ($lang) {
                'en' => 'ago', 'es' => 'atrás', 'de' => 'her', default => '',
            };
            $prefix = match ($lang) {
                'en' => '', 'es' => 'Hace', 'de' => 'Vor',
                default => 'Il y a',
            };
            $daysWord = match ($lang) {
                'en' => $totalDays === 1 ? 'day' : 'days',
                'es' => $totalDays === 1 ? 'día' : 'días',
                'de' => $totalDays === 1 ? 'Tag' : 'Tagen',
                default => $totalDays === 1 ? 'jour' : 'jours',
            };

            if ($lang === 'fr') {
                $lines[] = "⏪ {$prefix} *{$totalDays} {$daysWord}*";
            } elseif ($lang === 'en') {
                $lines[] = "⏪ *{$totalDays} {$daysWord}* {$agoLabel}";
            } else {
                $lines[] = "⏪ {$prefix} *{$totalDays} {$daysWord}* {$agoLabel}";
            }
        } else {
            $inLabel = match ($lang) {
                'en' => 'In', 'es' => 'En', 'de' => 'In', default => 'Dans',
            };
            $daysWord = match ($lang) {
                'en' => $totalDays === 1 ? 'day' : 'days',
                'es' => $totalDays === 1 ? 'día' : 'días',
                'de' => $totalDays === 1 ? 'Tag' : 'Tagen',
                default => $totalDays === 1 ? 'jour' : 'jours',
            };
            $lines[] = "⏩ {$inLabel} *{$totalDays} {$daysWord}*";
        }

        // Breakdown
        if ($totalDays > 7 && !$isToday) {
            $lines[] = "";
            if ($weeks > 0) {
                $weeksWord = match ($lang) {
                    'en' => $weeks === 1 ? 'week' : 'weeks',
                    'es' => $weeks === 1 ? 'semana' : 'semanas',
                    'de' => $weeks === 1 ? 'Woche' : 'Wochen',
                    default => $weeks === 1 ? 'semaine' : 'semaines',
                };
                $extraWord = match ($lang) {
                    'en' => $extraDays === 1 ? 'day' : 'days',
                    'es' => $extraDays === 1 ? 'día' : 'días',
                    'de' => $extraDays === 1 ? 'Tag' : 'Tage',
                    default => $extraDays === 1 ? 'jour' : 'jours',
                };
                $weeksStr = "{$weeks} {$weeksWord}";
                if ($extraDays > 0) {
                    $weeksStr .= " + {$extraDays} {$extraWord}";
                }
                $lines[] = "📅 ≈ *{$weeksStr}*";
            }
            if ($months > 0) {
                $monthsWord = match ($lang) {
                    'en' => $months === 1 ? 'month' : 'months',
                    'es' => $months === 1 ? 'mes' : 'meses',
                    'de' => $months === 1 ? 'Monat' : 'Monate',
                    default => 'mois',
                };
                $daysWord2 = match ($lang) {
                    'en' => 'days', 'es' => 'días', 'de' => 'Tage', default => 'jours',
                };
                $monthStr = "{$months} {$monthsWord}";
                if ($monthDays > 0) {
                    $monthStr .= " + {$monthDays} {$daysWord2}";
                }
                $lines[] = "🗓 ≈ *{$monthStr}*";
            }
        }

        // Visual timeline
        if (!$isToday) {
            $lines[] = "";
            if ($isPast) {
                $lines[] = "📍 {$target->format('d/m')} ━━━ {$totalDays}j ━━━▶ {$today->format('d/m')} _(today)_";
            } else {
                $lines[] = "📍 {$today->format('d/m')} _(today)_ ━━━ {$totalDays}j ━━━▶ {$target->format('d/m')}";
            }
        }

        $lines[] = "";
        $lines[] = "────────────────";
        $tipLabel = match ($lang) {
            'en' => "Try: _how long ago was jan 1_, _days until dec 25_, _date diff_",
            'es' => "Prueba: _hace cuántos días el 1 ene_, _días hasta dic 25_",
            default => "Essaie : _il y a combien de jours le 1er jan_, _dans combien de jours le 25 déc_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'     => 'relative_date',
            'date'       => $target->format('Y-m-d'),
            'total_days' => $totalDays,
            'is_past'    => $isPast,
            'is_today'   => $isToday,
            'weeks'      => $weeks,
            'months'     => $months,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.40.0 — timezone_cheatsheet
    // -------------------------------------------------------------------------

    private function handleTimezoneCheatsheet(array $parsed, array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $userTzStr  = $prefs['timezone'] ?? 'UTC';
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';

        $targetStr = trim($parsed['target'] ?? '');
        if ($targetStr === '') {
            $errMsg = match ($lang) {
                'en' => "⚠️ Please specify a city or timezone.\n\n_Example: cheatsheet Tokyo_",
                'es' => "⚠️ Especifica una ciudad o zona horaria.\n\n_Ejemplo: cheatsheet Tokyo_",
                'de' => "⚠️ Bitte gib eine Stadt oder Zeitzone an.\n\n_Beispiel: cheatsheet Tokyo_",
                default => "⚠️ Indique une ville ou un fuseau.\n\n_Exemple : cheatsheet Tokyo_",
            };
            return AgentResult::reply($errMsg, ['action' => 'timezone_cheatsheet', 'error' => 'missing_target']);
        }

        $resolvedTz = $this->resolveTimezoneString($targetStr);
        if (!$resolvedTz) {
            $suggestion = $this->suggestTimezone($targetStr);
            $errMsg = match ($lang) {
                'en' => "⚠️ Unknown timezone: *{$targetStr}*",
                'es' => "⚠️ Zona horaria desconocida: *{$targetStr}*",
                'de' => "⚠️ Unbekannte Zeitzone: *{$targetStr}*",
                default => "⚠️ Fuseau inconnu : *{$targetStr}*",
            };
            if ($suggestion) {
                $didYouMean = match ($lang) {
                    'en' => 'Did you mean', 'es' => 'Quisiste decir', 'de' => 'Meintest du',
                    default => 'Tu voulais dire',
                };
                $errMsg .= "\n_{$didYouMean} *{$suggestion}* ?_";
            }
            return AgentResult::reply($errMsg, ['action' => 'timezone_cheatsheet', 'error' => 'invalid_timezone']);
        }

        try {
            $userTz   = new DateTimeZone($userTzStr);
            $targetTz = new DateTimeZone($resolvedTz);
        } catch (\Throwable) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Error with timezone configuration. Check your timezone setting.",
                default => "⚠️ Erreur de configuration fuseau. Vérifie ton fuseau dans tes préférences.",
            };
            return AgentResult::reply($errMsg, ['action' => 'timezone_cheatsheet', 'error' => 'tz_error']);
        }

        $now       = new DateTimeImmutable('now', $userTz);
        $nowTarget = $now->setTimezone($targetTz);

        // Calculate offset difference
        $userOffset   = $userTz->getOffset($now);
        $targetOffset = $targetTz->getOffset($now);
        $diffSeconds  = $targetOffset - $userOffset;
        $diffHours    = $diffSeconds / 3600;
        $diffSign     = $diffHours >= 0 ? '+' : '';
        $diffDisplay  = $diffHours == intval($diffHours)
            ? $diffSign . intval($diffHours) . 'h'
            : $diffSign . sprintf('%.1f', $diffHours) . 'h';

        // DST status
        $isDstUser   = (bool) $now->format('I');
        $isDstTarget = (bool) $nowTarget->format('I');

        $dstLabelTarget = match ($lang) {
            'en' => $isDstTarget ? 'Summer time (DST active)' : 'Standard time (no DST)',
            'es' => $isDstTarget ? 'Horario de verano (DST activo)' : 'Horario estándar (sin DST)',
            'de' => $isDstTarget ? 'Sommerzeit (DST aktiv)' : 'Normalzeit (kein DST)',
            default => $isDstTarget ? 'Heure d\'été (DST actif)' : 'Heure standard (pas de DST)',
        };

        // Business hours overlap
        $overlapStart = max(9, 9 + intval($diffHours));
        $overlapEnd   = min(18, 18 + intval($diffHours));
        $overlapHours = max(0, $overlapEnd - $overlapStart);

        // Best meeting window
        $bestStart = max(9, max(9, 9 - intval($diffHours)));
        $bestEnd   = min(18, min(18, 18 - intval($diffHours)));
        $bestWindow = ($bestEnd > $bestStart) ? sprintf('%02d:00–%02d:00', $bestStart, $bestEnd) : null;

        // Target hour for status
        $targetHour = (int) $nowTarget->format('G');
        $targetIsoDay = (int) $nowTarget->format('N');
        $statusInfo = $this->getTimeStatus($targetHour, $targetIsoDay, $lang);

        // Build the card
        $titleLabel = match ($lang) {
            'en' => 'TIMEZONE CHEATSHEET', 'es' => 'FICHA HORARIA',
            'de' => 'ZEITZONEN-REFERENZ',
            default => 'FICHE PRATIQUE FUSEAU',
        };

        $shortTzTarget = $this->getShortTzName($resolvedTz);
        $shortTzUser   = $this->getShortTzName($userTzStr);

        $lines = [
            "📇 *{$titleLabel}*",
            "════════════════",
            "",
            "{$statusInfo['icon']} *{$targetStr}* — {$statusInfo['label']}",
            "",
        ];

        // Section 1: Current times
        $nowLabel = match ($lang) { 'en' => 'Now', 'es' => 'Ahora', 'de' => 'Jetzt', default => 'Maintenant' };
        $youLabel = match ($lang) { 'en' => 'You', 'es' => 'Tú', 'de' => 'Du', default => 'Toi' };
        $lines[] = "🕐 *{$nowLabel}* :";
        $lines[] = "  📍 {$youLabel} : *{$now->format('H:i')}* _{$shortTzUser}_ — {$now->format($dateFormat)}";
        $lines[] = "  🎯 {$targetStr} : *{$nowTarget->format('H:i')}* _{$shortTzTarget}_ — {$nowTarget->format($dateFormat)}";
        $lines[] = "";

        // Section 2: Offset
        $offsetLabel = match ($lang) {
            'en' => 'Time difference', 'es' => 'Diferencia horaria',
            'de' => 'Zeitunterschied', default => 'Décalage horaire',
        };
        $aheadBehind = match (true) {
            $diffHours > 0 => match ($lang) {
                'en' => 'ahead of you', 'es' => 'adelante de ti', 'de' => 'vor dir',
                default => 'en avance sur toi',
            },
            $diffHours < 0 => match ($lang) {
                'en' => 'behind you', 'es' => 'detrás de ti', 'de' => 'hinter dir',
                default => 'en retard sur toi',
            },
            default => match ($lang) {
                'en' => 'same time', 'es' => 'misma hora', 'de' => 'gleiche Zeit',
                default => 'même heure',
            },
        };
        $lines[] = "📐 *{$offsetLabel}* : *{$diffDisplay}* ({$aheadBehind})";
        $lines[] = "";

        // Section 3: DST
        $dstTitle = match ($lang) { 'en' => 'DST Status', 'es' => 'Estado DST', 'de' => 'DST-Status', default => 'Statut DST' };
        $dstIcon = $isDstTarget ? '☀️' : '❄️';
        $lines[] = "{$dstIcon} *{$dstTitle}* : {$dstLabelTarget}";
        $lines[] = "";

        // Section 4: Business hours overlap
        $overlapTitle = match ($lang) {
            'en' => 'Business hours overlap (9h–18h)',
            'es' => 'Overlap horario de oficina (9h–18h)',
            'de' => 'Überlappung Bürozeiten (9–18h)',
            default => 'Overlap heures de bureau (9h–18h)',
        };
        if ($overlapHours > 0) {
            $overlapBar = str_repeat('█', $overlapHours) . str_repeat('░', 9 - $overlapHours);
            $lines[] = "🏢 *{$overlapTitle}*";
            $lines[] = "  {$overlapBar} *{$overlapHours}h* / 9h";
        } else {
            $noOverlap = match ($lang) {
                'en' => 'No overlap — different work schedules',
                'es' => 'Sin overlap — horarios diferentes',
                'de' => 'Keine Überlappung — verschiedene Arbeitszeiten',
                default => 'Aucun overlap — horaires décalés',
            };
            $lines[] = "🏢 *{$overlapTitle}*";
            $lines[] = "  ⚠️ {$noOverlap}";
        }
        $lines[] = "";

        // Section 5: Best meeting window
        $meetingTitle = match ($lang) {
            'en' => 'Best meeting window', 'es' => 'Mejor horario de reunión',
            'de' => 'Bestes Meeting-Fenster', default => 'Meilleur créneau réunion',
        };
        if ($bestWindow) {
            $localLabel = match ($lang) { 'en' => 'your time', 'es' => 'tu hora', 'de' => 'deine Zeit', default => 'ton heure' };
            $lines[] = "📅 *{$meetingTitle}* : *{$bestWindow}* _({$localLabel})_";
        } else {
            $noWindow = match ($lang) {
                'en' => 'No ideal window — consider async communication',
                'es' => 'Sin ventana ideal — considerar comunicación asíncrona',
                'de' => 'Kein ideales Fenster — asynchrone Kommunikation empfohlen',
                default => 'Pas de créneau idéal — privilégie la communication asynchrone',
            };
            $lines[] = "📅 *{$meetingTitle}* : _{$noWindow}_";
        }

        $lines[] = "";
        $lines[] = "════════════════";
        $tipLabel = match ($lang) {
            'en' => "Try: _overlap {$targetStr}_, _brief {$targetStr}_, _convert time 14h to {$targetStr}_",
            'es' => "Prueba: _overlap {$targetStr}_, _brief {$targetStr}_, _convertir hora a {$targetStr}_",
            default => "Essaie : _overlap {$targetStr}_, _brief {$targetStr}_, _convertir 14h vers {$targetStr}_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'         => 'timezone_cheatsheet',
            'target'         => $resolvedTz,
            'diff_hours'     => $diffHours,
            'overlap_hours'  => $overlapHours,
            'dst_active'     => $isDstTarget,
            'best_window'    => $bestWindow,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.40.0 — project_progress
    // -------------------------------------------------------------------------

    private function handleProjectProgress(array $parsed, array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $userTz     = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
        $now        = new DateTimeImmutable('now', $userTz);
        $today      = new DateTimeImmutable($now->format('Y-m-d'), $userTz);

        $startStr = trim($parsed['start_date'] ?? '');
        $endStr   = trim($parsed['end_date'] ?? '');
        $label    = trim($parsed['label'] ?? '');

        if ($startStr === '' || $endStr === '') {
            $errMsg = match ($lang) {
                'en' => "⚠️ Please provide a start and end date.\n\n_Example: project progress 2026-01-15 to 2026-06-30 Project Alpha_",
                'es' => "⚠️ Proporciona fecha de inicio y fin.\n\n_Ejemplo: project progress 2026-01-15 a 2026-06-30 Proyecto Alpha_",
                'de' => "⚠️ Bitte gib ein Start- und Enddatum an.\n\n_Beispiel: project progress 2026-01-15 bis 2026-06-30_",
                default => "⚠️ Indique une date de début et de fin.\n\n_Exemple : progression projet 2026-01-15 2026-06-30 Projet Alpha_",
            };
            return AgentResult::reply($errMsg, ['action' => 'project_progress', 'error' => 'missing_dates']);
        }

        try {
            $startDate = new DateTimeImmutable($startStr === 'today' ? $today->format('Y-m-d') : $startStr, $userTz);
            $endDate   = new DateTimeImmutable($endStr === 'today' ? $today->format('Y-m-d') : $endStr, $userTz);
        } catch (\Throwable) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Invalid date format. Use YYYY-MM-DD.\n\n_Example: project progress 2026-01-15 2026-06-30_",
                'es' => "⚠️ Formato de fecha inválido. Usa AAAA-MM-DD.",
                default => "⚠️ Format de date invalide. Utilise AAAA-MM-JJ.\n\n_Exemple : progression projet 2026-01-15 2026-06-30_",
            };
            return AgentResult::reply($errMsg, ['action' => 'project_progress', 'error' => 'invalid_date']);
        }

        if ($endDate <= $startDate) {
            $errMsg = match ($lang) {
                'en' => "⚠️ End date must be after start date.",
                'es' => "⚠️ La fecha de fin debe ser posterior a la de inicio.",
                'de' => "⚠️ Enddatum muss nach Startdatum liegen.",
                default => "⚠️ La date de fin doit être après la date de début.",
            };
            return AgentResult::reply($errMsg, ['action' => 'project_progress', 'error' => 'invalid_range']);
        }

        $totalDiff   = $startDate->diff($endDate);
        $totalDays   = (int) $totalDiff->days;
        $elapsedDiff = $startDate->diff($today);
        $elapsedDays = (int) $elapsedDiff->days;

        // Clamp if today is before start or after end
        $notStarted = $today < $startDate;
        $completed  = $today >= $endDate;

        if ($notStarted) {
            $elapsedDays = 0;
        } elseif ($completed) {
            $elapsedDays = $totalDays;
        }

        $remainingDays = $totalDays - $elapsedDays;
        $pct = $totalDays > 0 ? (int) round($elapsedDays / $totalDays * 100) : 0;
        $pct = min(100, max(0, $pct));

        // Working days
        $totalWorkDays   = 0;
        $elapsedWorkDays = 0;
        $cursor = $startDate;
        for ($i = 0; $i < $totalDays; $i++) {
            $dow = (int) $cursor->format('N');
            if ($dow <= 5) {
                $totalWorkDays++;
                if (!$notStarted && $cursor < $today && !$completed || ($completed && $dow <= 5)) {
                    $elapsedWorkDays++;
                }
            }
            $cursor = $cursor->modify('+1 day');
        }
        // Recalculate elapsed work days properly
        if (!$notStarted && !$completed) {
            $elapsedWorkDays = 0;
            $cursor = $startDate;
            for ($i = 0; $i < $elapsedDays; $i++) {
                if ((int) $cursor->format('N') <= 5) {
                    $elapsedWorkDays++;
                }
                $cursor = $cursor->modify('+1 day');
            }
        } elseif ($completed) {
            $elapsedWorkDays = $totalWorkDays;
        } else {
            $elapsedWorkDays = 0;
        }
        $remainingWorkDays = $totalWorkDays - $elapsedWorkDays;

        // Midpoint date
        $midDays  = intdiv($totalDays, 2);
        $midDate  = $startDate->modify("+{$midDays} days");
        $midPassed = $today >= $midDate;

        // Title
        $titleLabel = match ($lang) {
            'en' => 'PROJECT PROGRESS', 'es' => 'PROGRESO DEL PROYECTO',
            'de' => 'PROJEKTFORTSCHRITT',
            default => 'PROGRESSION PROJET',
        };

        $lines = ["📊 *{$titleLabel}*"];
        if ($label !== '') {
            $lines[0] .= " — {$label}";
        }
        $lines[] = "════════════════";
        $lines[] = "";

        // Date range
        $startLabel = match ($lang) { 'en' => 'Start', 'es' => 'Inicio', 'de' => 'Start', default => 'Début' };
        $endLabel   = match ($lang) { 'en' => 'End', 'es' => 'Fin', 'de' => 'Ende', default => 'Fin' };
        $todayLbl   = match ($lang) { 'en' => 'Today', 'es' => 'Hoy', 'de' => 'Heute', default => "Aujourd'hui" };

        $lines[] = "📅 {$startLabel} : *{$startDate->format($dateFormat)}* ({$this->getDayName((int)$startDate->format('w'), $lang)})";
        $lines[] = "🏁 {$endLabel} : *{$endDate->format($dateFormat)}* ({$this->getDayName((int)$endDate->format('w'), $lang)})";
        $lines[] = "📍 {$todayLbl} : *{$today->format($dateFormat)}*";
        $lines[] = "";

        // Status
        if ($notStarted) {
            $daysUntilStart = (int) $today->diff($startDate)->days;
            $statusMsg = match ($lang) {
                'en' => "Not started yet — begins in {$daysUntilStart} day(s)",
                'es' => "Aún no iniciado — comienza en {$daysUntilStart} día(s)",
                'de' => "Noch nicht gestartet — beginnt in {$daysUntilStart} Tag(en)",
                default => "Pas encore commencé — début dans {$daysUntilStart} jour(s)",
            };
            $lines[] = "⏳ {$statusMsg}";
        } elseif ($completed) {
            $statusMsg = match ($lang) {
                'en' => 'Completed!', 'es' => '¡Completado!', 'de' => 'Abgeschlossen!',
                default => 'Terminé !',
            };
            $lines[] = "✅ *{$statusMsg}*";
        } else {
            $statusMsg = match ($lang) {
                'en' => 'In progress', 'es' => 'En progreso', 'de' => 'In Bearbeitung',
                default => 'En cours',
            };
            $lines[] = "🔄 *{$statusMsg}*";
        }
        $lines[] = "";

        // Progress bar
        $progressLabel = match ($lang) {
            'en' => 'Progress', 'es' => 'Progreso', 'de' => 'Fortschritt', default => 'Progression',
        };
        $lines[] = "📊 {$progressLabel} : {$this->progressBar($pct)} *{$pct}%*";
        $lines[] = "";

        // Stats
        $totalLabel = match ($lang) { 'en' => 'Total duration', 'es' => 'Duración total', 'de' => 'Gesamtdauer', default => 'Durée totale' };
        $elapsedLabel = match ($lang) { 'en' => 'Elapsed', 'es' => 'Transcurrido', 'de' => 'Vergangen', default => 'Écoulé' };
        $remainLabel = match ($lang) { 'en' => 'Remaining', 'es' => 'Restante', 'de' => 'Verbleibend', default => 'Restant' };
        $daysW = match ($lang) { 'en' => 'days', 'es' => 'días', 'de' => 'Tage', default => 'jours' };
        $workDaysW = match ($lang) { 'en' => 'work days', 'es' => 'días laborables', 'de' => 'Arbeitstage', default => 'jours ouvrés' };

        $lines[] = "📅 {$totalLabel} : *{$totalDays} {$daysW}* ({$totalWorkDays} {$workDaysW})";
        $lines[] = "✅ {$elapsedLabel} : *{$elapsedDays} {$daysW}* ({$elapsedWorkDays} {$workDaysW})";
        $lines[] = "⏳ {$remainLabel} : *{$remainingDays} {$daysW}* ({$remainingWorkDays} {$workDaysW})";
        $lines[] = "";

        // Midpoint
        $midLabel = match ($lang) { 'en' => 'Midpoint', 'es' => 'Punto medio', 'de' => 'Halbzeit', default => 'Mi-parcours' };
        $midIcon = $midPassed ? '✅' : '⬜';
        $midStatus = match (true) {
            $midPassed && !$completed => match ($lang) {
                'en' => 'passed', 'es' => 'pasado', 'de' => 'überschritten', default => 'dépassé',
            },
            !$midPassed => match ($lang) {
                'en' => 'upcoming', 'es' => 'próximo', 'de' => 'bevorstehend', default => 'à venir',
            },
            default => match ($lang) {
                'en' => 'passed', 'es' => 'pasado', 'de' => 'überschritten', default => 'dépassé',
            },
        };
        $lines[] = "{$midIcon} {$midLabel} : *{$midDate->format($dateFormat)}* _({$midStatus})_";

        // Visual timeline
        if (!$notStarted && !$completed) {
            $lines[] = "";
            $barLen = 20;
            $filled = (int) round($pct / 100 * $barLen);
            $filled = min($barLen, max(0, $filled));
            $bar = str_repeat('━', $filled) . '📍' . str_repeat('╌', $barLen - $filled);
            $lines[] = "{$startDate->format('d/m')} {$bar} {$endDate->format('d/m')}";
        }

        $lines[] = "";
        $lines[] = "════════════════";
        $tipLabel = match ($lang) {
            'en' => "Try: _countdown {$endDate->format('Y-m-d')}_, _working days {$startDate->format('Y-m-d')} {$endDate->format('Y-m-d')}_",
            'es' => "Prueba: _countdown {$endDate->format('Y-m-d')}_, _días laborables_",
            default => "Essaie : _countdown {$endDate->format('Y-m-d')}_, _jours ouvrés {$startDate->format('Y-m-d')} {$endDate->format('Y-m-d')}_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'            => 'project_progress',
            'label'             => $label,
            'total_days'        => $totalDays,
            'elapsed_days'      => $elapsedDays,
            'remaining_days'    => $remainingDays,
            'pct'               => $pct,
            'total_work_days'   => $totalWorkDays,
            'elapsed_work_days' => $elapsedWorkDays,
            'not_started'       => $notStarted,
            'completed'         => $completed,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.41.0 — time_capsule
    // -------------------------------------------------------------------------

    private function handleTimeCapsule(array $parsed, array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $userTz     = new DateTimeZone($prefs['timezone'] ?? 'UTC');
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
        $now        = new DateTimeImmutable('now', $userTz);

        $amount = max(1, min(100, (int) ($parsed['amount'] ?? 1)));
        $unit   = strtolower(trim($parsed['unit'] ?? 'year'));

        $validUnits = ['day', 'days', 'week', 'weeks', 'month', 'months', 'year', 'years',
                       'jour', 'jours', 'semaine', 'semaines', 'mois', 'an', 'ans', 'année', 'années'];
        if (!in_array($unit, $validUnits, true)) {
            $unit = 'year';
        }

        // Normalize unit to English singular
        $unitNorm = match ($unit) {
            'jour', 'jours', 'day', 'days'                        => 'day',
            'semaine', 'semaines', 'week', 'weeks'                 => 'week',
            'mois', 'month', 'months'                              => 'month',
            'an', 'ans', 'année', 'années', 'year', 'years'       => 'year',
            default                                                 => 'year',
        };

        $modifier = "-{$amount} {$unitNorm}";
        try {
            $past = $now->modify($modifier);
        } catch (\Throwable) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Could not compute the date. Try a smaller value.",
                'es' => "⚠️ No se pudo calcular la fecha. Prueba un valor menor.",
                default => "⚠️ Impossible de calculer la date. Essaie une valeur plus petite.",
            };
            return AgentResult::reply($errMsg, ['action' => 'time_capsule', 'error' => 'compute_failed']);
        }

        $diff      = $past->diff($now);
        $totalDays = (int) $diff->days;

        $unitLabel = match ($unitNorm) {
            'day'   => match ($lang) { 'en' => $amount === 1 ? 'day' : 'days', 'es' => $amount === 1 ? 'día' : 'días', default => $amount === 1 ? 'jour' : 'jours' },
            'week'  => match ($lang) { 'en' => $amount === 1 ? 'week' : 'weeks', 'es' => $amount === 1 ? 'semana' : 'semanas', default => $amount === 1 ? 'semaine' : 'semaines' },
            'month' => match ($lang) { 'en' => $amount === 1 ? 'month' : 'months', 'es' => $amount === 1 ? 'mes' : 'meses', default => 'mois' },
            'year'  => match ($lang) { 'en' => $amount === 1 ? 'year' : 'years', 'es' => $amount === 1 ? 'año' : 'años', default => $amount === 1 ? 'an' : 'ans' },
            default => $unitNorm,
        };

        $titleLabel = match ($lang) {
            'en' => 'TIME CAPSULE',
            'es' => 'CÁPSULA DEL TIEMPO',
            'de' => 'ZEITKAPSEL',
            default => 'CAPSULE TEMPORELLE',
        };

        $agoLabel = match ($lang) {
            'en' => "{$amount} {$unitLabel} ago",
            'es' => "Hace {$amount} {$unitLabel}",
            'de' => "Vor {$amount} {$unitLabel}",
            default => "Il y a {$amount} {$unitLabel}",
        };

        $pastDayName  = $this->getDayName((int) $past->format('w'), $lang);
        $nowDayName   = $this->getDayName((int) $now->format('w'), $lang);

        $lines = [
            "⏳ *{$titleLabel}*",
            "════════════════",
            "",
            "🔙 {$agoLabel} :",
            "",
        ];

        $dateLabel = match ($lang) { 'en' => 'Date', 'es' => 'Fecha', 'de' => 'Datum', default => 'Date' };
        $timeLabel = match ($lang) { 'en' => 'Time', 'es' => 'Hora', 'de' => 'Uhrzeit', default => 'Heure' };
        $dayLabel  = match ($lang) { 'en' => 'Day', 'es' => 'Día', 'de' => 'Tag', default => 'Jour' };

        $lines[] = "📅 {$dateLabel} : *{$past->format($dateFormat)}*";
        $lines[] = "📆 {$dayLabel} : *{$pastDayName}*";
        $lines[] = "🕐 {$timeLabel} : *{$past->format('H:i:s')}*";

        // Week number
        $pastWeek = (int) $past->format('W');
        $weekLabel = match ($lang) { 'en' => 'ISO week', 'es' => 'Semana ISO', default => 'Semaine ISO' };
        $lines[] = "📊 {$weekLabel} : W{$pastWeek}";

        $lines[] = "";
        $lines[] = "────────────────";

        $todayLabel = match ($lang) { 'en' => 'Today', 'es' => 'Hoy', 'de' => 'Heute', default => "Aujourd'hui" };
        $lines[] = "📍 {$todayLabel} : *{$now->format($dateFormat)}* ({$nowDayName})";

        $daysLabel = match ($lang) { 'en' => 'days', 'es' => 'días', 'de' => 'Tage', default => 'jours' };
        $separationLabel = match ($lang) {
            'en' => 'Separation', 'es' => 'Separación', 'de' => 'Abstand', default => 'Écart',
        };
        $lines[] = "📐 {$separationLabel} : *{$totalDays} {$daysLabel}*";

        // Same day of week?
        if ((int) $past->format('N') === (int) $now->format('N')) {
            $sameDay = match ($lang) {
                'en' => "Same day of the week! ({$pastDayName})",
                'es' => "Mismo día de la semana! ({$pastDayName})",
                default => "Même jour de la semaine ! ({$pastDayName})",
            };
            $lines[] = "🎯 {$sameDay}";
        }

        $lines[] = "";
        $lines[] = "════════════════";
        $tipLabel = match ($lang) {
            'en' => "Try: _time capsule 5 years_, _capsule 6 months_, _capsule 100 days_",
            'es' => "Prueba: _cápsula 5 años_, _cápsula 6 meses_, _cápsula 100 días_",
            default => "Essaie : _capsule 5 ans_, _capsule 6 mois_, _capsule 100 jours_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'     => 'time_capsule',
            'amount'     => $amount,
            'unit'       => $unitNorm,
            'past_date'  => $past->format('Y-m-d H:i:s'),
            'total_days' => $totalDays,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.41.0 — smart_greeting
    // -------------------------------------------------------------------------

    private function handleSmartGreeting(array $parsed, array $prefs): AgentResult
    {
        $lang       = $prefs['language'] ?? 'fr';
        $userTzStr  = $prefs['timezone'] ?? 'UTC';

        $targetStr = trim($parsed['target'] ?? '');
        $resolvedTz = $targetStr !== '' ? $this->resolveTimezoneString($targetStr) : $userTzStr;

        if (!$resolvedTz) {
            $suggestion = $this->suggestTimezone($targetStr);
            $errMsg = match ($lang) {
                'en' => "⚠️ Unknown city/timezone: *{$targetStr}*",
                'es' => "⚠️ Ciudad/zona horaria desconocida: *{$targetStr}*",
                default => "⚠️ Ville/fuseau inconnu : *{$targetStr}*",
            };
            if ($suggestion) {
                $didYouMean = match ($lang) {
                    'en' => 'Did you mean', 'es' => 'Quisiste decir', default => 'Tu voulais dire',
                };
                $errMsg .= "\n\n💡 {$didYouMean} *{$suggestion}* ?";
            }
            return AgentResult::reply($errMsg, ['action' => 'smart_greeting', 'error' => 'unknown_target']);
        }

        $targetTz  = new DateTimeZone($resolvedTz);
        $targetNow = new DateTimeImmutable('now', $targetTz);
        $hour      = (int) $targetNow->format('G');
        $isoDay    = (int) $targetNow->format('N');
        $isWeekend = $isoDay >= 6;

        // Determine greeting period and emoji
        [$period, $emoji] = match (true) {
            $hour >= 5 && $hour < 12   => ['morning',   '🌅'],
            $hour >= 12 && $hour < 14  => ['midday',    '☀️'],
            $hour >= 14 && $hour < 18  => ['afternoon', '🌤️'],
            $hour >= 18 && $hour < 22  => ['evening',   '🌆'],
            default                     => ['night',     '🌙'],
        };

        // i18n greetings
        $greetings = [
            'fr' => ['morning' => 'Bonjour', 'midday' => 'Bon appétit', 'afternoon' => 'Bon après-midi', 'evening' => 'Bonsoir', 'night' => 'Bonne nuit'],
            'en' => ['morning' => 'Good morning', 'midday' => 'Good afternoon', 'afternoon' => 'Good afternoon', 'evening' => 'Good evening', 'night' => 'Good night'],
            'es' => ['morning' => 'Buenos días', 'midday' => 'Buen provecho', 'afternoon' => 'Buenas tardes', 'evening' => 'Buenas tardes', 'night' => 'Buenas noches'],
            'de' => ['morning' => 'Guten Morgen', 'midday' => 'Mahlzeit', 'afternoon' => 'Guten Tag', 'evening' => 'Guten Abend', 'night' => 'Gute Nacht'],
            'it' => ['morning' => 'Buongiorno', 'midday' => 'Buon appetito', 'afternoon' => 'Buon pomeriggio', 'evening' => 'Buonasera', 'night' => 'Buonanotte'],
            'pt' => ['morning' => 'Bom dia', 'midday' => 'Bom almoço', 'afternoon' => 'Boa tarde', 'evening' => 'Boa noite', 'night' => 'Boa noite'],
            'ja' => ['morning' => 'おはようございます', 'midday' => 'こんにちは', 'afternoon' => 'こんにちは', 'evening' => 'こんばんは', 'night' => 'おやすみなさい'],
            'zh' => ['morning' => '早上好', 'midday' => '中午好', 'afternoon' => '下午好', 'evening' => '晚上好', 'night' => '晚安'],
            'ar' => ['morning' => 'صباح الخير', 'midday' => 'مساء الخير', 'afternoon' => 'مساء الخير', 'evening' => 'مساء الخير', 'night' => 'تصبح على خير'],
            'ko' => ['morning' => '좋은 아침입니다', 'midday' => '안녕하세요', 'afternoon' => '안녕하세요', 'evening' => '안녕하세요', 'night' => '안녕히 주무세요'],
            'nl' => ['morning' => 'Goedemorgen', 'midday' => 'Smakelijk eten', 'afternoon' => 'Goedemiddag', 'evening' => 'Goedenavond', 'night' => 'Goedenacht'],
            'ru' => ['morning' => 'Доброе утро', 'midday' => 'Добрый день', 'afternoon' => 'Добрый день', 'evening' => 'Добрый вечер', 'night' => 'Спокойной ночи'],
        ];

        $greetingText = $greetings[$lang][$period] ?? $greetings['fr'][$period];

        // Also show greeting in the TARGET timezone's likely language
        $targetLang = $this->guessLanguageForTimezone($resolvedTz);
        $localGreeting = '';
        if ($targetLang !== $lang && isset($greetings[$targetLang][$period])) {
            $localGreeting = $greetings[$targetLang][$period];
        }

        $cityLabel = $this->getShortTzName($resolvedTz);
        $titleLabel = match ($lang) {
            'en' => 'SMART GREETING', 'es' => 'SALUDO INTELIGENTE',
            'de' => 'SMARTE BEGRÜSSUNG', default => 'SALUT INTELLIGENT',
        };

        $lines = [
            "{$emoji} *{$titleLabel}*",
            "════════════════",
            "",
        ];

        $cityDisplay = $targetStr !== '' ? ucfirst($targetStr) : $cityLabel;
        $atLabel = match ($lang) { 'en' => 'In', 'es' => 'En', 'de' => 'In', default => 'À' };
        $timeLabel = match ($lang) { 'en' => 'Local time', 'es' => 'Hora local', default => 'Heure locale' };

        $lines[] = "📍 {$atLabel} *{$cityDisplay}* :";
        $lines[] = "🕐 {$timeLabel} : *{$targetNow->format('H:i')}*";
        $dayName = $this->getDayName((int) $targetNow->format('w'), $lang);
        $lines[] = "📅 {$dayName}" . ($isWeekend ? " 🏖" : "");
        $lines[] = "";

        $sayLabel = match ($lang) { 'en' => 'Say', 'es' => 'Di', 'de' => 'Sag', default => 'Dis' };
        $lines[] = "💬 {$sayLabel} : *\"{$greetingText}\"*";

        if ($localGreeting !== '') {
            $localLabel = match ($lang) {
                'en' => 'In local language', 'es' => 'En idioma local',
                'de' => 'In Landessprache', default => 'En langue locale',
            };
            $lines[] = "🌍 {$localLabel} : *\"{$localGreeting}\"*";
        }

        // Context advice
        $advice = match (true) {
            $hour < 5    => match ($lang) { 'en' => 'Very late — avoid calling unless urgent', 'es' => 'Muy tarde — evita llamar salvo urgencia', default => 'Très tard — évite d\'appeler sauf urgence' },
            $hour < 8    => match ($lang) { 'en' => 'Early morning — prefer email or message', 'es' => 'Muy temprano — mejor email o mensaje', default => 'Tôt le matin — préfère un email ou message' },
            $hour < 9    => match ($lang) { 'en' => 'People are getting ready — OK for messages', 'es' => 'Se están preparando — OK para mensajes', default => 'Les gens se préparent — OK pour un message' },
            $hour < 12   => match ($lang) { 'en' => 'Great time to call or message!', 'es' => '¡Buen momento para llamar o enviar mensaje!', default => 'Bon moment pour appeler ou écrire !' },
            $hour < 14   => match ($lang) { 'en' => 'Lunchtime — prefer a quick message', 'es' => 'Hora del almuerzo — mejor un mensaje rápido', default => 'Heure du déjeuner — préfère un message rapide' },
            $hour < 18   => match ($lang) { 'en' => 'Good time for a call or meeting', 'es' => 'Buen momento para una llamada o reunión', default => 'Bon moment pour un appel ou une réunion' },
            $hour < 20   => match ($lang) { 'en' => 'After work — keep it casual', 'es' => 'Después del trabajo — mantenlo informal', default => 'Après le travail — reste informel' },
            $hour < 22   => match ($lang) { 'en' => 'Evening — only for close contacts', 'es' => 'Noche — solo para contactos cercanos', default => 'Soirée — seulement pour les proches' },
            default       => match ($lang) { 'en' => 'Late night — avoid disturbing', 'es' => 'Noche cerrada — evita molestar', default => 'Tard le soir — évite de déranger' },
        };

        $lines[] = "";
        $adviceLabel = match ($lang) { 'en' => 'Tip', 'es' => 'Consejo', 'de' => 'Tipp', default => 'Conseil' };
        $lines[] = "💡 {$adviceLabel} : _{$advice}_";

        $lines[] = "";
        $lines[] = "════════════════";
        $tipLabel = match ($lang) {
            'en' => "Try: _greeting Tokyo_, _greeting New York_, _greeting Dubai_",
            'es' => "Prueba: _saludo Tokyo_, _saludo Nueva York_, _saludo Dubai_",
            default => "Essaie : _salut Tokyo_, _salut New York_, _salut Dubai_",
        };
        $lines[] = "💡 {$tipLabel}";

        return AgentResult::reply(implode("\n", $lines), [
            'action'    => 'smart_greeting',
            'target'    => $cityDisplay,
            'period'    => $period,
            'hour'      => $hour,
        ]);
    }

    // -------------------------------------------------------------------------
    // guessLanguageForTimezone — guess likely language for a timezone
    // -------------------------------------------------------------------------

    private function guessLanguageForTimezone(string $tz): string
    {
        $tzLower = mb_strtolower($tz);
        return match (true) {
            str_contains($tzLower, 'europe/paris') || str_contains($tzLower, 'europe/brussels') || str_contains($tzLower, 'africa/dakar') || str_contains($tzLower, 'africa/abidjan') => 'fr',
            str_contains($tzLower, 'america/new_york') || str_contains($tzLower, 'america/chicago') || str_contains($tzLower, 'america/los_angeles') || str_contains($tzLower, 'europe/london') || str_contains($tzLower, 'australia/') => 'en',
            str_contains($tzLower, 'europe/madrid') || str_contains($tzLower, 'america/mexico') || str_contains($tzLower, 'america/bogota') || str_contains($tzLower, 'america/buenos_aires') || str_contains($tzLower, 'america/lima') => 'es',
            str_contains($tzLower, 'europe/berlin') || str_contains($tzLower, 'europe/vienna') || str_contains($tzLower, 'europe/zurich') => 'de',
            str_contains($tzLower, 'europe/rome') => 'it',
            str_contains($tzLower, 'america/sao_paulo') || str_contains($tzLower, 'europe/lisbon') => 'pt',
            str_contains($tzLower, 'asia/tokyo') => 'ja',
            str_contains($tzLower, 'asia/shanghai') || str_contains($tzLower, 'asia/hong_kong') => 'zh',
            str_contains($tzLower, 'asia/seoul') => 'ko',
            str_contains($tzLower, 'europe/moscow') || str_contains($tzLower, 'asia/yekaterinburg') => 'ru',
            str_contains($tzLower, 'europe/amsterdam') => 'nl',
            str_contains($tzLower, 'asia/riyadh') || str_contains($tzLower, 'asia/dubai') || str_contains($tzLower, 'africa/cairo') => 'ar',
            default => 'en',
        };
    }

    // -------------------------------------------------------------------------
    // detectFastPath — bypass LLM for common unambiguous commands
    // -------------------------------------------------------------------------

    private function detectFastPath(string $bodyLower): ?array
    {
        $bodyLower = trim($bodyLower);

        // Show preferences
        if (in_array($bodyLower, ['mon profil', 'my profile', 'mes préférences', 'mes preferences', 'show preferences', 'show profile', 'profil', 'mi perfil', 'mein profil'], true)) {
            return ['action' => 'show'];
        }

        // Help
        if (in_array($bodyLower, ['aide preferences', 'aide préférences', 'help preferences', 'help', 'aide', 'ayuda preferencias', 'hilfe einstellungen', 'aiuto preferenze', 'ajuda preferências'], true)) {
            return ['action' => 'help'];
        }

        // Current time
        if (in_array($bodyLower, ['quelle heure', 'quelle heure est-il', 'heure actuelle', 'heure locale', 'current time', 'what time', 'what time is it', 'qué hora es', 'wie spät ist es', 'che ora è', 'que horas são'], true)) {
            return ['action' => 'current_time'];
        }

        // Export
        if (in_array($bodyLower, ['exporter', 'export', 'export preferences', 'exporter préférences', 'exporter preferences', 'backup settings'], true)) {
            return ['action' => 'export'];
        }

        // Show diff
        if (in_array($bodyLower, ['diff', 'diff preferences', 'mes personnalisations', 'mes modifications', 'my changes', 'show diff'], true)) {
            return ['action' => 'show_diff'];
        }

        // Worldclock
        if (in_array($bodyLower, ['horloge mondiale', 'world clock', 'worldclock', 'toutes les heures', 'reloj mundial', 'weltzeit'], true)) {
            return ['action' => 'worldclock'];
        }

        // Year progress
        if (in_array($bodyLower, ['progression année', 'progression annee', 'year progress', 'avancement année', 'avancement annee'], true)) {
            return ['action' => 'year_progress'];
        }

        // Week progress
        if (in_array($bodyLower, ['progression semaine', 'week progress', 'avancement semaine'], true)) {
            return ['action' => 'week_progress'];
        }

        // Month progress
        if (in_array($bodyLower, ['progression mois', 'month progress', 'avancement mois'], true)) {
            return ['action' => 'month_progress'];
        }

        // Quarter progress
        if (in_array($bodyLower, ['progression trimestre', 'quarter progress', 'avancement trimestre'], true)) {
            return ['action' => 'quarter_progress'];
        }

        // Daily summary
        if (in_array($bodyLower, ['résumé journée', 'resume journee', 'daily summary', 'bilan du jour', 'daily brief', 'briefing quotidien'], true)) {
            return ['action' => 'daily_summary'];
        }

        // Calendar week
        if (in_array($bodyLower, ['calendrier semaine', 'calendar week', 'cette semaine', 'ma semaine', 'planning semaine'], true)) {
            return ['action' => 'calendar_week'];
        }

        // Calendar month
        if (in_array($bodyLower, ['calendrier mois', 'calendar month', 'ce mois', 'vue mensuelle', 'planning mensuel'], true)) {
            return ['action' => 'calendar_month'];
        }

        // Preview date
        if (in_array($bodyLower, ['aperçu date', 'apercu date', 'preview date', 'formats de date', 'aperçu formats date', 'apercu formats date'], true)) {
            return ['action' => 'preview_date'];
        }

        // Productivity score
        if (in_array($bodyLower, ['score productivité', 'score productivite', 'productivity score', 'ma productivité', 'ma productivite'], true)) {
            return ['action' => 'productivity_score'];
        }

        // Week planner
        if (in_array($bodyLower, ['planning semaine', 'week planner', 'planificateur semaine'], true)) {
            return ['action' => 'week_planner'];
        }

        // Status dashboard
        if (in_array($bodyLower, ['dashboard', 'tableau de bord', 'status dashboard', 'mon dashboard', 'status'], true)) {
            return ['action' => 'status_dashboard'];
        }

        // Profile completeness
        if (in_array($bodyLower, ['complétude profil', 'completude profil', 'profile completeness', 'score profil', 'taux de remplissage'], true)) {
            return ['action' => 'profile_completeness'];
        }

        // Weekend countdown
        if (in_array($bodyLower, ['weekend', 'week-end', 'countdown weekend', 'vivement le weekend', 'combien avant le weekend'], true)) {
            return ['action' => 'weekend_countdown'];
        }

        // Focus score
        if (in_array($bodyLower, ['focus score', 'score focus', 'score concentration', 'niveau focus', 'focus level'], true)) {
            return ['action' => 'focus_score'];
        }

        // Standup helper
        if (in_array($bodyLower, ['standup', 'stand-up', 'standup helper', 'daily standup', 'point quotidien', 'daily'], true)) {
            return ['action' => 'standup_helper'];
        }

        // Morning routine
        if (in_array($bodyLower, ['routine matinale', 'morning routine', 'bonjour routine', 'routine matin', 'morning brief'], true)) {
            return ['action' => 'morning_routine'];
        }

        // DST info
        if (in_array($bodyLower, ['heure été', 'heure ete', 'dst', 'dst info', 'changement heure', 'heure hiver'], true)) {
            return ['action' => 'dst_info'];
        }

        // Locale details
        if (in_array($bodyLower, ['locale details', 'détails locale', 'details locale', 'ma locale', 'my locale'], true)) {
            return ['action' => 'locale_details'];
        }

        // Reset all
        if (in_array($bodyLower, ['reset all', 'réinitialiser tout', 'reinitialiser tout', 'reset tout'], true)) {
            return ['action' => 'reset', 'key' => 'all'];
        }

        // Market hours
        if (in_array($bodyLower, ['marchés', 'marches', 'market hours', 'bourse', 'marchés financiers', 'marches financiers', 'stock market'], true)) {
            return ['action' => 'market_hours'];
        }

        // Pomodoro
        if (in_array($bodyLower, ['pomodoro', 'pomodoro timer', 'timer pomodoro', 'technique pomodoro'], true)) {
            return ['action' => 'pomodoro'];
        }

        // Weekly review
        if (in_array($bodyLower, ['weekly review', 'bilan semaine', 'revue semaine', 'review semaine', 'bilan hebdomadaire'], true)) {
            return ['action' => 'weekly_review'];
        }

        // Week summary
        if (in_array($bodyLower, ['résumé semaine', 'resume semaine', 'week summary', 'semaine résumé', 'semaine resume'], true)) {
            return ['action' => 'week_summary'];
        }

        // Season info
        if (in_array($bodyLower, ['saison', 'season', 'quelle saison', 'season info', 'info saison'], true)) {
            return ['action' => 'season_info'];
        }

        // Habit tracker
        if (in_array($bodyLower, ['habitudes', 'habits', 'habit tracker', 'suivi habitudes', 'mes habitudes'], true)) {
            return ['action' => 'habit_tracker'];
        }

        // Break reminder
        if (in_array($bodyLower, ['pause', 'break', 'break reminder', 'rappel pause', 'temps de pause', 'pause café', 'pause cafe', 'stretch break', 'quand faire une pause', 'when to take a break'], true)) {
            return ['action' => 'break_reminder'];
        }

        // Water reminder
        if (in_array($bodyLower, ['eau', 'water', 'hydratation', 'hydration', 'rappel eau', 'water reminder', 'drink water', 'boire eau', 'suivi eau', 'water tracker'], true)) {
            return ['action' => 'water_reminder'];
        }

        // Energy level
        if (in_array($bodyLower, ['énergie', 'energie', 'energy', 'energy level', 'niveau énergie', 'niveau energie', 'mon énergie', 'mon energie', 'my energy'], true)) {
            return ['action' => 'energy_level'];
        }

        // Quick setup
        if (in_array($bodyLower, ['setup rapide', 'quick setup', 'configuration rapide', 'setup', 'configurer vite'], true)) {
            return ['action' => 'quick_setup'];
        }

        // Meeting countdown
        if (preg_match('/^(?:meeting|réunion|reunion|rdv)\s+(?:à|a|at)\s+(\d{1,2}[h:]\d{0,2})/ui', $bodyLower, $m)) {
            return ['action' => 'meeting_countdown', 'time' => $m[1]];
        }
        if (in_array($bodyLower, ['meeting countdown', 'countdown réunion', 'countdown reunion', 'countdown meeting'], true)) {
            return ['action' => 'meeting_countdown'];
        }

        // Daily planner
        if (in_array($bodyLower, ['daily planner', 'planning journée', 'planning journee', 'plan du jour', 'mon planning', 'my daily plan', 'plan journalier', 'plan de la journée', 'plan de la journee'], true)) {
            return ['action' => 'daily_planner'];
        }

        // Time budget
        if (in_array($bodyLower, ['budget temps', 'time budget', 'heures restantes', 'remaining hours', 'hours left', 'working hours left', 'temps de travail restant', 'budget horaire'], true)) {
            return ['action' => 'time_budget'];
        }

        // v1.54.0 — Time report
        if (in_array($bodyLower, ['time report', 'rapport quotidien', 'daily report', 'rapport temps', 'daily time report', 'bilan journée', 'bilan journee', 'état du monde', 'etat du monde', 'world status', 'résumé du jour', 'resume du jour'], true)) {
            return ['action' => 'time_report'];
        }

        // v1.55.0 — Birthday countdown
        if (in_array($bodyLower, ['countdown anniversaire', 'birthday countdown', 'prochain anniversaire', 'mon anniversaire', 'my birthday', 'next birthday', 'cumpleaños', 'geburtstag countdown'], true)) {
            return ['action' => 'birthday_countdown'];
        }

        // v1.55.0 — Quick setup
        if (in_array($bodyLower, ['quick setup', 'setup rapide', 'configuration rapide', 'configurer', 'setup', 'config rapide', 'configurar', 'einrichten', 'configurazione rapida', 'quick start'], true)) {
            return ['action' => 'quick_setup'];
        }

        // v1.57.0 — Preferences suggestions
        if (in_array($bodyLower, ['suggestions preferences', 'suggestions préférences', 'preferences suggestions', 'preferences tips', 'conseils preferences', 'conseils préférences', 'améliorer préférences', 'ameliorer preferences', 'improve preferences', 'optimiser preferences', 'suggestions profil', 'profile tips', 'analyse preferences'], true)) {
            return ['action' => 'preferences_suggestions'];
        }

        // v1.57.0 — Availability now
        if (in_array($bodyLower, ['disponibilité', 'disponibilite', 'availability now', 'available now', 'qui est disponible', 'villes disponibles', 'available cities', 'open for calls', 'qui peut appeler', 'appeler maintenant', 'call now', 'who is available', 'cities available'], true)) {
            return ['action' => 'availability_now'];
        }

        // v1.56.0 — Work-life balance
        if (in_array($bodyLower, ['work-life balance', 'work life balance', 'équilibre vie-travail', 'equilibre vie-travail', 'balance travail', 'balance score', 'work life', 'equilibrio trabajo', 'gleichgewicht'], true)) {
            return ['action' => 'work_life_balance'];
        }

        // v1.56.0 — Timezone quiz
        if (in_array($bodyLower, ['quiz timezone', 'quiz fuseau', 'timezone quiz', 'timezone game', 'quiz fuseaux', 'quiz horaires', 'question fuseau', 'devinette horaire', 'quiz géo', 'quiz geo', 'quiz tz'], true)) {
            return ['action' => 'timezone_quiz'];
        }

        // v1.54.0 — City compare (regex for "X vs Y" or "comparer X et Y")
        if (preg_match('/^(?:comparer?|compare)\s+(.+?)\s+(?:et|and|vs\.?)\s+(.+)$/iu', $bodyLower, $m)) {
            return ['action' => 'city_compare', 'city1' => trim($m[1]), 'city2' => trim($m[2])];
        }
        if (preg_match('/^(.+?)\s+vs\.?\s+(.+)$/iu', $bodyLower, $m)) {
            return ['action' => 'city_compare', 'city1' => trim($m[1]), 'city2' => trim($m[2])];
        }

        // v1.62.0 — Regex fast-path: "set language X" / "changer langue X" / "cambiar idioma X"
        if (preg_match('/^(?:set\s+language|changer?\s+(?:la\s+)?langue|cambiar\s+idioma|lingua|sprache|idioma)\s+(.+)$/iu', $bodyLower, $m)) {
            $val = trim($m[1]);
            $langMap = [
                'français' => 'fr', 'francais' => 'fr', 'french' => 'fr', 'fr' => 'fr',
                'english' => 'en', 'anglais' => 'en', 'en' => 'en', 'inglés' => 'en', 'ingles' => 'en',
                'español' => 'es', 'espagnol' => 'es', 'spanish' => 'es', 'es' => 'es',
                'deutsch' => 'de', 'allemand' => 'de', 'german' => 'de', 'de' => 'de',
                'italiano' => 'it', 'italien' => 'it', 'italian' => 'it', 'it' => 'it',
                'português' => 'pt', 'portugais' => 'pt', 'portuguese' => 'pt', 'pt' => 'pt',
                'arabe' => 'ar', 'arabic' => 'ar', 'ar' => 'ar', 'العربية' => 'ar',
                'chinois' => 'zh', 'chinese' => 'zh', 'zh' => 'zh', '中文' => 'zh',
                'japonais' => 'ja', 'japanese' => 'ja', 'ja' => 'ja', '日本語' => 'ja',
                'coréen' => 'ko', 'coreen' => 'ko', 'korean' => 'ko', 'ko' => 'ko', '한국어' => 'ko',
                'russe' => 'ru', 'russian' => 'ru', 'ru' => 'ru', 'русский' => 'ru',
                'néerlandais' => 'nl', 'neerlandais' => 'nl', 'dutch' => 'nl', 'nl' => 'nl', 'nederlands' => 'nl',
            ];
            $resolved = $langMap[mb_strtolower($val)] ?? null;
            if ($resolved) {
                return ['action' => 'set', 'key' => 'language', 'value' => $resolved];
            }
        }

        // v1.62.0 — Regex fast-path: "set timezone X" / "changer fuseau X"
        if (preg_match('/^(?:set\s+timezone|changer?\s+(?:le\s+)?(?:fuseau\s+(?:horaire)?|timezone)|cambiar\s+(?:zona\s+horaria|timezone)|timezone\s+set)\s+(.+)$/iu', $bodyLower, $m)) {
            $val = trim($m[1]);
            return ['action' => 'set', 'key' => 'timezone', 'value' => $val];
        }

        // v1.62.0 — Regex fast-path: "set style X" / "changer style X"
        if (preg_match('/^(?:set\s+(?:communication\s+)?style|changer?\s+(?:le\s+)?style|style)\s+(friendly|formal|concise|detailed|casual|amical|formel|concis|détaillé|detaille|décontracté|decontracte)$/iu', $bodyLower, $m)) {
            $styleMap = [
                'friendly' => 'friendly', 'amical' => 'friendly',
                'formal' => 'formal', 'formel' => 'formal',
                'concise' => 'concise', 'concis' => 'concise',
                'detailed' => 'detailed', 'détaillé' => 'detailed', 'detaille' => 'detailed',
                'casual' => 'casual', 'décontracté' => 'casual', 'decontracte' => 'casual',
            ];
            $resolved = $styleMap[mb_strtolower($m[1])] ?? mb_strtolower($m[1]);
            return ['action' => 'set', 'key' => 'communication_style', 'value' => $resolved];
        }

        // v1.62.0 — Fast-path: "timezone matrix" / "matrice fuseaux"
        if (in_array($bodyLower, ['timezone matrix', 'matrice fuseaux', 'matrice fuseau', 'time matrix', 'grille horaire', 'grille fuseaux', 'timezone grid', 'planning grid'], true)) {
            return ['action' => 'timezone_matrix'];
        }
        if (preg_match('/^(?:timezone\s+matrix|matrice\s+fuseaux?|time\s+matrix|grille\s+(?:horaire|fuseaux?))\s+(.+)$/iu', $bodyLower, $m)) {
            $cities = preg_split('/[\s,]+(?:et|and|,)\s*|[\s,]+/iu', trim($m[1]));
            $cities = array_values(array_filter(array_map('trim', $cities)));
            return ['action' => 'timezone_matrix', 'cities' => $cities];
        }

        // v1.58.0 — Regex fast-path: "heure à [ville]" / "time in [city]" / "hora en [ciudad]"
        if (preg_match('/^(?:quelle\s+)?heure\s+(?:à|a|en|au)\s+(.+)$/iu', $bodyLower, $m)) {
            return ['action' => 'compare_timezone', 'target' => trim($m[1])];
        }
        if (preg_match('/^(?:what\s+)?time\s+in\s+(.+)$/iu', $bodyLower, $m)) {
            return ['action' => 'compare_timezone', 'target' => trim($m[1])];
        }
        if (preg_match('/^(?:qué\s+)?hora\s+en\s+(.+)$/iu', $bodyLower, $m)) {
            return ['action' => 'compare_timezone', 'target' => trim($m[1])];
        }

        // v1.58.0 — Regex fast-path: "dans [N] jours/semaines/mois" → date_add
        if (preg_match('/^dans\s+(\d+)\s+(jours?|semaines?|mois)$/iu', $bodyLower, $m)) {
            $n = (int) $m[1];
            $unit = mb_strtolower($m[2]);
            if (str_starts_with($unit, 'jour')) return ['action' => 'date_add', 'base_date' => 'today', 'days' => $n];
            if (str_starts_with($unit, 'semaine')) return ['action' => 'date_add', 'base_date' => 'today', 'weeks' => $n];
            if ($unit === 'mois') return ['action' => 'date_add', 'base_date' => 'today', 'months' => $n];
        }
        if (preg_match('/^in\s+(\d+)\s+(days?|weeks?|months?)$/iu', $bodyLower, $m)) {
            $n = (int) $m[1];
            $unit = mb_strtolower($m[2]);
            if (str_starts_with($unit, 'day')) return ['action' => 'date_add', 'base_date' => 'today', 'days' => $n];
            if (str_starts_with($unit, 'week')) return ['action' => 'date_add', 'base_date' => 'today', 'weeks' => $n];
            if (str_starts_with($unit, 'month')) return ['action' => 'date_add', 'base_date' => 'today', 'months' => $n];
        }

        // v1.58.0 — Regex fast-path: "productivity planner" / "plan productivité"
        if (preg_match('/^(?:productivity\s+planner|plan\s+productivit[eé]|planificateur\s+productivit[eé]|daily\s+plan|mon\s+plan|my\s+plan)$/iu', $bodyLower)) {
            return ['action' => 'productivity_planner'];
        }

        // v1.58.0 — Regex fast-path: "timezone friendship [city]" / "amitié fuseau [ville]"
        if (preg_match('/^(?:timezone\s+friendship|amiti[eé]\s+fuseau|tz\s+friend(?:ship)?)\s+(.+)$/iu', $bodyLower, $m)) {
            return ['action' => 'timezone_friendship', 'target' => trim($m[1])];
        }

        // v1.59.0 — Timezone roulette
        if (in_array($bodyLower, ['timezone roulette', 'roulette fuseau', 'ville aléatoire', 'ville aleatoire', 'random city', 'random timezone', 'surprise fuseau', 'discover city', 'explore timezone'], true)) {
            return ['action' => 'timezone_roulette'];
        }

        // v1.60.0 — Currency info
        if (in_array($bodyLower, ['devise', 'currency', 'currency info', 'monnaie', 'quelle devise', 'quelle monnaie', 'local currency', 'devise locale', 'money info', 'taux de change', 'exchange rate'], true)) {
            return ['action' => 'currency_info'];
        }
        if (preg_match('/^(?:devise|currency|monnaie|quelle\s+devise|what\s+currency)\s+(?:à|a|en|in|de|for)\s+(.+)$/iu', $bodyLower, $m)) {
            return ['action' => 'currency_info', 'target' => trim($m[1])];
        }

        // v1.60.0 — Water reminder
        if (in_array($bodyLower, ['eau', 'water', 'water reminder', 'rappel eau', 'hydratation', 'hydration', 'boire de l\'eau', 'drink water', 'rappel hydratation', 'water tracker', 'suivi eau', 'hydration tracker', 'combien boire', 'how much water', 'objectif eau', 'water goal'], true)) {
            return ['action' => 'water_reminder'];
        }

        // v1.59.0 — Regex fast-path: "meeting cost [cities]" / "coût réunion [villes]"
        if (preg_match('/^(?:meeting\s+cost|co[uû]t\s+r[eé]union|timezone\s+tax)\s+(.+)$/iu', $bodyLower, $m)) {
            $cities = preg_split('/[\s,]+(?:et|and|,)\s*|[\s,]+/iu', trim($m[1]));
            $cities = array_values(array_filter(array_map('trim', $cities)));
            if (count($cities) >= 2) {
                return ['action' => 'meeting_cost', 'cities' => $cities];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // dispatchParsedAction — route a parsed action to its handler
    // -------------------------------------------------------------------------

    private function dispatchParsedAction(AgentContext $context, string $userId, array $parsed, array $prefs): AgentResult
    {
        $action = $parsed['action'] ?? '';
        $lang   = $prefs['language'] ?? 'fr';

        try {
            return match ($action) {
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
                'timezone_abbrev'  => $this->handleTimezoneAbbrev($parsed, $prefs),
                'pomodoro'         => $this->handlePomodoro($parsed, $prefs),
                'elapsed_time'     => $this->handleElapsedTime($parsed, $prefs),
                'focus_window'     => $this->handleFocusWindow($parsed, $prefs),
                'time_add'         => $this->handleTimeAdd($parsed, $prefs),
                'meeting_suggest'  => $this->handleMeetingSuggest($parsed, $prefs),
                'timezone_summary' => $this->handleTimezoneSummary($parsed, $prefs),
                'date_range'          => $this->handleDateRange($parsed, $prefs),
                'profile_completeness'=> $this->handleProfileCompleteness($prefs),
                'preference_snapshot' => $this->handlePreferenceSnapshot($prefs),
                'favorite_cities'     => $this->handleFavoriteCities($context, $userId, $parsed, $prefs),
                'time_diff'           => $this->handleTimeDiff($parsed, $prefs),
                'locale_preset'       => $this->handleLocalePreset($context, $userId, $parsed, $prefs),
                'workday_progress'    => $this->handleWorkdayProgress($prefs),
                'timezone_overlap'    => $this->handleTimezoneOverlap($parsed, $prefs),
                'sleep_schedule'      => $this->handleSleepSchedule($parsed, $prefs),
                'flight_time'         => $this->handleFlightTime($parsed, $prefs),
                'deadline_check'      => $this->handleDeadlineCheck($parsed, $prefs),
                'month_progress'      => $this->handleMonthProgress($prefs),
                'quarter_progress'    => $this->handleQuarterProgress($prefs),
                'next_weekday'        => $this->handleNextWeekday($parsed, $prefs),
                'alarm_time'          => $this->handleAlarmTime($parsed, $prefs),
                'week_progress'       => $this->handleWeekProgress($prefs),
                'batch_countdown'     => $this->handleBatchCountdown($parsed, $prefs),
                'nth_weekday'         => $this->handleNthWeekday($parsed, $prefs),
                'daily_summary'       => $this->handleDailySummary($prefs),
                'timezone_roster'     => $this->handleTimezoneRoster($parsed, $prefs),
                'productivity_score'  => $this->handleProductivityScore($prefs),
                'timezone_history'    => $this->handleTimezoneHistory($parsed, $prefs),
                'unit_convert'        => $this->handleUnitConvert($parsed, $prefs),
                'week_planner'        => $this->handleWeekPlanner($prefs),
                'season_info'         => $this->handleSeasonInfo($parsed, $prefs),
                'quick_timer'         => $this->handleQuickTimer($parsed, $prefs),
                'market_hours'        => $this->handleMarketHours($parsed, $prefs),
                'week_summary'        => $this->handleWeekSummary($prefs),
                'date_diff'             => $this->handleDateDiff($parsed, $prefs),
                'relative_date'         => $this->handleRelativeDate($parsed, $prefs),
                'timezone_cheatsheet'   => $this->handleTimezoneCheatsheet($parsed, $prefs),
                'project_progress'      => $this->handleProjectProgress($parsed, $prefs),
                'time_capsule'          => $this->handleTimeCapsule($parsed, $prefs),
                'smart_greeting'        => $this->handleSmartGreeting($parsed, $prefs),
                'energy_level'          => $this->handleEnergyLevel($parsed, $prefs),
                'international_dialing' => $this->handleInternationalDialing($parsed, $prefs),
                'preferences_search'    => $this->handlePreferencesSearch($parsed, $prefs),
                'locale_details'        => $this->handleLocaleDetails($prefs),
                'status_dashboard'      => $this->handleStatusDashboard($prefs),
                'time_ago'              => $this->handleTimeAgo($parsed, $prefs),
                'focus_score'           => $this->handleFocusScore($prefs),
                'standup_helper'        => $this->handleStandupHelper($prefs),
                'morning_routine'       => $this->handleMorningRoutine($prefs),
                'preferences_compare'   => $this->handlePreferencesCompare($parsed, $prefs),
                'weekend_countdown'     => $this->handleWeekendCountdown($prefs),
                'time_swap'             => $this->handleTimeSwap($parsed, $prefs),
                'weekly_review'         => $this->handleWeeklyReview($prefs),
                'habit_tracker'         => $this->handleHabitTracker($prefs),
                'goal_tracker'          => $this->handleGoalTracker($parsed, $prefs),
                'timezone_buddy'        => $this->handleTimezoneBuddy($parsed, $prefs),
                'duration_calc'         => $this->handleDurationCalc($parsed, $prefs),
                'smart_schedule'        => $this->handleSmartSchedule($parsed, $prefs),
                'break_reminder'        => $this->handleBreakReminder($parsed, $prefs),
                'time_budget'           => $this->handleTimeBudget($parsed, $prefs),
                'time_report'           => $this->handleTimeReport($parsed, $prefs),
                'city_compare'          => $this->handleCityCompare($parsed, $prefs),
                'birthday_countdown'    => $this->handleBirthdayCountdown($parsed, $prefs),
                'quick_setup'           => $this->handleQuickSetup($prefs),
                'work_life_balance'     => $this->handleWorkLifeBalance($parsed, $prefs),
                'timezone_quiz'         => $this->handleTimezoneQuiz($parsed, $prefs),
                'preferences_suggestions' => $this->handlePreferencesSuggestions($prefs),
                'availability_now'      => $this->handleAvailabilityNow($parsed, $prefs),
                'productivity_planner'  => $this->handleProductivityPlanner($prefs),
                'timezone_friendship'   => $this->handleTimezoneFriendship($parsed, $prefs),
                'timezone_roulette'     => $this->handleTimezoneRoulette($prefs),
                'meeting_cost'          => $this->handleMeetingCost($parsed, $prefs),
                'currency_info'         => $this->handleCurrencyInfo($parsed, $prefs),
                'water_reminder'        => $this->handleWaterReminder($prefs),
                'meeting_countdown'     => $this->handleMeetingCountdown($parsed, $prefs),
                'daily_planner'         => $this->handleDailyPlanner($prefs),
                'timezone_matrix'       => $this->handleTimezoneMatrix($parsed, $prefs),
                default                 => AgentResult::reply(
                    $this->formatUnknownAction($action, $lang),
                    ['action' => 'unknown_action', 'received' => mb_substr($action, 0, 50)]
                ),
            };
        } catch (\Throwable $e) {
            Log::warning("UserPreferencesAgent: handler '{$action}' threw exception", [
                'error'  => $e->getMessage(),
                'action' => $action,
            ]);
            $safeAction = mb_substr(preg_replace('/[*_~`]/', '', $action), 0, 50);
            $msg = match ($lang) {
                'en' => "⚠️ An error occurred while processing *{$safeAction}*. Try again or type *help preferences*.",
                'es' => "⚠️ Ocurrió un error al procesar *{$safeAction}*. Inténtalo de nuevo o escribe *ayuda preferencias*.",
                'de' => "⚠️ Fehler bei der Verarbeitung von *{$safeAction}*. Versuche es erneut oder tippe *Hilfe Einstellungen*.",
                'it' => "⚠️ Errore durante l'elaborazione di *{$safeAction}*. Riprova o scrivi *aiuto preferenze*.",
                'pt' => "⚠️ Ocorreu um erro ao processar *{$safeAction}*. Tente novamente ou digite *ajuda preferências*.",
                'ar' => "⚠️ حدث خطأ أثناء معالجة *{$safeAction}*. حاول مرة أخرى أو اكتب *مساعدة التفضيلات*.",
                'zh' => "⚠️ 处理 *{$safeAction}* 时发生错误。请重试或输入 *帮助 偏好设置*。",
                'ja' => "⚠️ *{$safeAction}* の処理中にエラーが発生しました。再試行するか *help preferences* と入力してください。",
                'ko' => "⚠️ *{$safeAction}* 처리 중 오류가 발생했습니다. 다시 시도하거나 *help preferences* 를 입력하세요.",
                'ru' => "⚠️ Ошибка при обработке *{$safeAction}*. Попробуйте снова или введите *help preferences*.",
                'nl' => "⚠️ Er is een fout opgetreden bij *{$safeAction}*. Probeer opnieuw of typ *help preferences*.",
                default => "⚠️ Une erreur est survenue pour *{$safeAction}*. Réessaie ou tape *aide preferences*.",
            };
            return AgentResult::reply($msg, ['action' => 'handler_error', 'failed_action' => $action, 'error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // NEW HANDLERS v1.51.0 — Missing method implementations
    // =========================================================================

    private function handleEnergyLevel(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $hour   = (int) $now->format('G');
        $isoDay = (int) $now->format('N');

        // Energy curve based on circadian rhythm
        [$level, $emoji, $pct] = match (true) {
            $hour >= 6 && $hour < 8   => ['waking_up',  '🌅', 40],
            $hour >= 8 && $hour < 10  => ['peak',       '⚡', 90],
            $hour >= 10 && $hour < 12 => ['high',       '🔥', 85],
            $hour >= 12 && $hour < 14 => ['post_lunch', '😴', 50],
            $hour >= 14 && $hour < 16 => ['recovering', '☕', 65],
            $hour >= 16 && $hour < 18 => ['second_wind','💪', 75],
            $hour >= 18 && $hour < 20 => ['winding',    '🌆', 55],
            $hour >= 20 && $hour < 22 => ['low',        '🛋️', 35],
            $hour >= 22 || $hour < 2  => ['sleep',      '😴', 15],
            default                    => ['rest',       '🌙', 20],
        };

        $bar = $this->progressBar($pct);

        $title = match ($lang) {
            'en' => 'ENERGY LEVEL', 'es' => 'NIVEL DE ENERGÍA', default => 'NIVEAU D\'ÉNERGIE',
        };

        $recommendation = match ($level) {
            'peak'       => match ($lang) { 'en' => 'Best time for deep work and complex tasks', 'es' => 'Mejor momento para trabajo profundo', default => 'Meilleur moment pour le travail profond et les tâches complexes' },
            'high'       => match ($lang) { 'en' => 'Great for meetings and collaboration', 'es' => 'Ideal para reuniones y colaboración', default => 'Idéal pour les réunions et la collaboration' },
            'post_lunch' => match ($lang) { 'en' => 'Light tasks, emails, or a short walk', 'es' => 'Tareas ligeras, emails o un paseo corto', default => 'Tâches légères, emails ou une courte marche' },
            'recovering' => match ($lang) { 'en' => 'Good for routine tasks and follow-ups', 'es' => 'Bueno para tareas rutinarias', default => 'Bon pour les tâches de routine et les suivis' },
            'second_wind'=> match ($lang) { 'en' => 'Creative work and brainstorming', 'es' => 'Trabajo creativo y lluvia de ideas', default => 'Travail créatif et brainstorming' },
            'winding'    => match ($lang) { 'en' => 'Planning tomorrow, light reading', 'es' => 'Planificar mañana, lectura ligera', default => 'Planifier demain, lecture légère' },
            default      => match ($lang) { 'en' => 'Rest and recharge', 'es' => 'Descansa y recarga', default => 'Repos et rechargement' },
        };

        $levelLabel = match ($lang) {
            'en' => 'Energy', 'es' => 'Energía', default => 'Énergie',
        };
        $recLabel = match ($lang) {
            'en' => 'Recommendation', 'es' => 'Recomendación', default => 'Recommandation',
        };
        $dayLabel = $this->getDayName((int) $now->format('w'), $lang);

        $lines = [
            "{$emoji} *{$title}*",
            "════════════════",
            "",
            "🕐 *{$now->format('H:i')}* — {$dayLabel}",
            "",
            "{$levelLabel} : {$bar} *{$pct}%*",
            "",
            "💡 {$recLabel} : _{$recommendation}_",
        ];

        if ($isoDay >= 6) {
            $weekendTip = match ($lang) {
                'en' => 'Weekend — take it easy!', 'es' => '¡Fin de semana — tómatelo con calma!', default => 'Weekend — profites-en pour te reposer !',
            };
            $lines[] = "";
            $lines[] = "🏖 _{$weekendTip}_";
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'energy_level', 'level' => $level, 'pct' => $pct]);
    }

    private function handleInternationalDialing(array $parsed, array $prefs): AgentResult
    {
        $lang   = $prefs['language'] ?? 'fr';
        $target = trim($parsed['target'] ?? $parsed['country'] ?? '');

        $dialingCodes = [
            'france' => ['+33', '🇫🇷'], 'usa' => ['+1', '🇺🇸'], 'united states' => ['+1', '🇺🇸'],
            'uk' => ['+44', '🇬🇧'], 'united kingdom' => ['+44', '🇬🇧'], 'england' => ['+44', '🇬🇧'],
            'germany' => ['+49', '🇩🇪'], 'allemagne' => ['+49', '🇩🇪'],
            'spain' => ['+34', '🇪🇸'], 'espagne' => ['+34', '🇪🇸'],
            'italy' => ['+39', '🇮🇹'], 'italie' => ['+39', '🇮🇹'],
            'japan' => ['+81', '🇯🇵'], 'japon' => ['+81', '🇯🇵'],
            'china' => ['+86', '🇨🇳'], 'chine' => ['+86', '🇨🇳'],
            'brazil' => ['+55', '🇧🇷'], 'brésil' => ['+55', '🇧🇷'], 'bresil' => ['+55', '🇧🇷'],
            'australia' => ['+61', '🇦🇺'], 'australie' => ['+61', '🇦🇺'],
            'canada' => ['+1', '🇨🇦'], 'mexico' => ['+52', '🇲🇽'], 'mexique' => ['+52', '🇲🇽'],
            'india' => ['+91', '🇮🇳'], 'inde' => ['+91', '🇮🇳'],
            'south korea' => ['+82', '🇰🇷'], 'corée du sud' => ['+82', '🇰🇷'], 'coree du sud' => ['+82', '🇰🇷'],
            'russia' => ['+7', '🇷🇺'], 'russie' => ['+7', '🇷🇺'],
            'netherlands' => ['+31', '🇳🇱'], 'pays-bas' => ['+31', '🇳🇱'],
            'belgium' => ['+32', '🇧🇪'], 'belgique' => ['+32', '🇧🇪'],
            'switzerland' => ['+41', '🇨🇭'], 'suisse' => ['+41', '🇨🇭'],
            'portugal' => ['+351', '🇵🇹'], 'morocco' => ['+212', '🇲🇦'], 'maroc' => ['+212', '🇲🇦'],
            'tunisia' => ['+216', '🇹🇳'], 'tunisie' => ['+216', '🇹🇳'],
            'algeria' => ['+213', '🇩🇿'], 'algérie' => ['+213', '🇩🇿'], 'algerie' => ['+213', '🇩🇿'],
            'saudi arabia' => ['+966', '🇸🇦'], 'arabie saoudite' => ['+966', '🇸🇦'],
            'uae' => ['+971', '🇦🇪'], 'emirates' => ['+971', '🇦🇪'], 'émirats' => ['+971', '🇦🇪'], 'emirats' => ['+971', '🇦🇪'],
            'turkey' => ['+90', '🇹🇷'], 'turquie' => ['+90', '🇹🇷'],
            'singapore' => ['+65', '🇸🇬'], 'singapour' => ['+65', '🇸🇬'],
            'hong kong' => ['+852', '🇭🇰'], 'taiwan' => ['+886', '🇹🇼'],
            'thailand' => ['+66', '🇹🇭'], 'thaïlande' => ['+66', '🇹🇭'], 'thailande' => ['+66', '🇹🇭'],
            'vietnam' => ['+84', '🇻🇳'], 'indonesia' => ['+62', '🇮🇩'], 'indonésie' => ['+62', '🇮🇩'],
            'philippines' => ['+63', '🇵🇭'], 'egypt' => ['+20', '🇪🇬'], 'égypte' => ['+20', '🇪🇬'], 'egypte' => ['+20', '🇪🇬'],
            'south africa' => ['+27', '🇿🇦'], 'afrique du sud' => ['+27', '🇿🇦'],
            'nigeria' => ['+234', '🇳🇬'], 'senegal' => ['+221', '🇸🇳'], 'sénégal' => ['+221', '🇸🇳'],
            'ivory coast' => ['+225', '🇨🇮'], 'côte d\'ivoire' => ['+225', '🇨🇮'], 'cote d\'ivoire' => ['+225', '🇨🇮'],
        ];

        if ($target === '') {
            // Show a list of common codes
            $title = match ($lang) {
                'en' => 'INTERNATIONAL DIALING CODES', 'es' => 'CÓDIGOS DE MARCACIÓN INTERNACIONAL', default => 'INDICATIFS TÉLÉPHONIQUES INTERNATIONAUX',
            };
            $lines = ["{$title}", "════════════════", ""];
            $shown = [];
            foreach ($dialingCodes as $country => [$code, $flag]) {
                $key = $code . $flag;
                if (isset($shown[$key])) continue;
                $shown[$key] = true;
                $lines[] = "{$flag} *" . ucfirst($country) . "* : `{$code}`";
            }
            $lines[] = "";
            $tip = match ($lang) {
                'en' => "Type _dialing <country>_ for a specific country",
                'es' => "Escribe _indicativo <país>_ para un país específico",
                default => "Tape _indicatif <pays>_ pour un pays spécifique",
            };
            $lines[] = "💡 _{$tip}_";
            return AgentResult::reply(implode("\n", $lines), ['action' => 'international_dialing', 'mode' => 'list']);
        }

        $targetLower = mb_strtolower($target);
        if (isset($dialingCodes[$targetLower])) {
            [$code, $flag] = $dialingCodes[$targetLower];
            $countryName = ucfirst($target);
            $title = match ($lang) { 'en' => 'DIALING CODE', 'es' => 'CÓDIGO DE MARCACIÓN', default => 'INDICATIF TÉLÉPHONIQUE' };
            $howTo = match ($lang) {
                'en' => "To call {$countryName}, dial: `{$code}` followed by the local number",
                'es' => "Para llamar a {$countryName}, marca: `{$code}` seguido del número local",
                default => "Pour appeler {$countryName}, compose : `{$code}` suivi du numéro local",
            };
            $lines = [
                "📞 *{$title}*",
                "════════════════",
                "",
                "{$flag} *{$countryName}* : `{$code}`",
                "",
                "💡 _{$howTo}_",
            ];
            return AgentResult::reply(implode("\n", $lines), ['action' => 'international_dialing', 'country' => $countryName, 'code' => $code]);
        }

        $errMsg = match ($lang) {
            'en' => "⚠️ Country not found: *{$target}*\n\n_Type *dialing codes* for the full list._",
            'es' => "⚠️ País no encontrado: *{$target}*\n\n_Escribe *códigos de marcación* para la lista completa._",
            default => "⚠️ Pays non trouvé : *{$target}*\n\n_Tape *indicatifs* pour la liste complète._",
        };
        return AgentResult::reply($errMsg, ['action' => 'international_dialing', 'error' => 'not_found']);
    }

    private function handleStatusDashboard(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $hour      = (int) $now->format('G');
        $dayOfYear = (int) $now->format('z') + 1;
        $daysInYear= (int) $now->format('L') ? 366 : 365;
        $yearPct   = (int) round($dayOfYear / $daysInYear * 100);
        $isoWeek   = (int) $now->format('W');
        $isoDay    = (int) $now->format('N');
        $dayName   = $this->getDayName((int) $now->format('w'), $lang);

        // Work progress (9h-18h)
        $workPct = 0;
        if ($hour >= 9 && $hour < 18) {
            $workPct = (int) round(($hour - 9 + (int)$now->format('i') / 60) / 9 * 100);
        } elseif ($hour >= 18) {
            $workPct = 100;
        }

        // Week progress
        $weekPct = (int) round(($isoDay - 1 + ($hour / 24)) / 7 * 100);

        // Month progress
        $dayOfMonth  = (int) $now->format('j');
        $daysInMonth = (int) $now->format('t');
        $monthPct    = (int) round($dayOfMonth / $daysInMonth * 100);

        $title = match ($lang) { 'en' => 'STATUS DASHBOARD', 'es' => 'PANEL DE ESTADO', default => 'TABLEAU DE BORD' };

        $lines = [
            "📊 *{$title}*",
            "════════════════",
            "",
            "🕐 *{$now->format('H:i')}* — {$dayName} {$now->format('d/m/Y')}",
            "📅 " . match ($lang) { 'en' => "Week", 'es' => "Semana", default => "Semaine" } . " {$isoWeek} | " . match ($lang) { 'en' => "Day", 'es' => "Día", default => "Jour" } . " {$dayOfYear}/{$daysInYear}",
            "",
            "📈 " . match ($lang) { 'en' => "Day", 'es' => "Día", default => "Journée" } . " : {$this->progressBar($workPct)} *{$workPct}%*",
            "📈 " . match ($lang) { 'en' => "Week", 'es' => "Semana", default => "Semaine" } . " : {$this->progressBar($weekPct)} *{$weekPct}%*",
            "📈 " . match ($lang) { 'en' => "Month", 'es' => "Mes", default => "Mois" } . " : {$this->progressBar($monthPct)} *{$monthPct}%*",
            "📈 " . match ($lang) { 'en' => "Year", 'es' => "Año", default => "Année" } . " : {$this->progressBar($yearPct)} *{$yearPct}%*",
        ];

        // Energy
        $energyPct = match (true) {
            $hour >= 8 && $hour < 10  => 90,
            $hour >= 10 && $hour < 12 => 85,
            $hour >= 12 && $hour < 14 => 50,
            $hour >= 14 && $hour < 16 => 65,
            $hour >= 16 && $hour < 18 => 75,
            default                    => 30,
        };
        $lines[] = "";
        $lines[] = "⚡ " . match ($lang) { 'en' => "Energy", 'es' => "Energía", default => "Énergie" } . " : {$this->progressBar($energyPct)} *{$energyPct}%*";

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'status_dashboard']);
    }

    private function handleTimeAgo(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';
        $dateStr   = trim($parsed['date'] ?? $parsed['target'] ?? '');

        if ($dateStr === '') {
            $errMsg = match ($lang) {
                'en' => "⚠️ Please specify a date.\n\n_Example: time ago 2024-01-15_",
                'es' => "⚠️ Indica una fecha.\n\n_Ejemplo: hace cuánto 2024-01-15_",
                default => "⚠️ Indique une date.\n\n_Exemple : il y a combien 2024-01-15_",
            };
            return AgentResult::reply($errMsg, ['action' => 'time_ago', 'error' => 'missing_date']);
        }

        try {
            $tz   = new DateTimeZone($userTzStr);
            $now  = new DateTimeImmutable('now', $tz);
            $past = new DateTimeImmutable($dateStr, $tz);
        } catch (\Exception) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Invalid date: *{$dateStr}*\n\n_Use format: YYYY-MM-DD_",
                'es' => "⚠️ Fecha inválida: *{$dateStr}*\n\n_Usa formato: AAAA-MM-DD_",
                default => "⚠️ Date invalide : *{$dateStr}*\n\n_Utilise le format : AAAA-MM-JJ_",
            };
            return AgentResult::reply($errMsg, ['action' => 'time_ago', 'error' => 'invalid_date']);
        }

        $diff    = $past->diff($now);
        $title   = match ($lang) { 'en' => 'TIME AGO', 'es' => 'TIEMPO TRANSCURRIDO', default => 'TEMPS ÉCOULÉ' };
        $totalDays = (int) $diff->format('%a');

        $lines = [
            "⏳ *{$title}*",
            "════════════════",
            "",
            "📅 " . match ($lang) { 'en' => "From", 'es' => "Desde", default => "Depuis" } . " : *{$past->format('d/m/Y')}*",
            "📅 " . match ($lang) { 'en' => "To", 'es' => "Hasta", default => "Jusqu'à" } . " : *{$now->format('d/m/Y')}*",
            "",
            "📊 *{$diff->y}* " . match ($lang) { 'en' => "years", 'es' => "años", default => "ans" }
            . ", *{$diff->m}* " . match ($lang) { 'en' => "months", 'es' => "meses", default => "mois" }
            . ", *{$diff->d}* " . match ($lang) { 'en' => "days", 'es' => "días", default => "jours" },
            "📊 " . match ($lang) { 'en' => "Total", 'es' => "Total", default => "Total" } . " : *{$totalDays}* " . match ($lang) { 'en' => "days", 'es' => "días", default => "jours" }
            . " (" . number_format($totalDays / 7, 1) . " " . match ($lang) { 'en' => "weeks", 'es' => "semanas", default => "semaines" } . ")",
        ];

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'time_ago', 'total_days' => $totalDays]);
    }

    private function handleFocusScore(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $hour   = (int) $now->format('G');
        $isoDay = (int) $now->format('N');

        // Focus type recommendation
        [$taskType, $emoji, $score] = match (true) {
            $isoDay >= 6 => ['rest', '🏖', 20],
            $hour >= 8 && $hour < 10  => ['deep_work', '🧠', 95],
            $hour >= 10 && $hour < 12 => ['meetings', '🤝', 80],
            $hour >= 12 && $hour < 14 => ['admin', '📋', 40],
            $hour >= 14 && $hour < 16 => ['creative', '🎨', 70],
            $hour >= 16 && $hour < 18 => ['deep_work', '🧠', 75],
            default                    => ['rest', '🛋️', 15],
        };

        $taskLabel = match ($taskType) {
            'deep_work' => match ($lang) { 'en' => 'Deep work', 'es' => 'Trabajo profundo', default => 'Travail profond' },
            'meetings'  => match ($lang) { 'en' => 'Meetings & collaboration', 'es' => 'Reuniones y colaboración', default => 'Réunions et collaboration' },
            'creative'  => match ($lang) { 'en' => 'Creative work', 'es' => 'Trabajo creativo', default => 'Travail créatif' },
            'admin'     => match ($lang) { 'en' => 'Admin & emails', 'es' => 'Admin y emails', default => 'Admin et emails' },
            default     => match ($lang) { 'en' => 'Rest', 'es' => 'Descanso', default => 'Repos' },
        };

        $title = match ($lang) { 'en' => 'FOCUS SCORE', 'es' => 'PUNTUACIÓN DE ENFOQUE', default => 'SCORE DE FOCUS' };

        $lines = [
            "{$emoji} *{$title}*",
            "════════════════",
            "",
            "🕐 *{$now->format('H:i')}* — {$this->getDayName((int) $now->format('w'), $lang)}",
            "",
            "🎯 " . match ($lang) { 'en' => "Focus", 'es' => "Enfoque", default => "Focus" } . " : {$this->progressBar($score)} *{$score}%*",
            "",
            "💡 " . match ($lang) { 'en' => "Best for", 'es' => "Ideal para", default => "Idéal pour" } . " : *{$taskLabel}*",
        ];

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'focus_score', 'task_type' => $taskType, 'score' => $score]);
    }

    private function handleStandupHelper(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $dayName   = $this->getDayName((int) $now->format('w'), $lang);
        $isoWeek   = (int) $now->format('W');
        $isoDay    = (int) $now->format('N');
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';

        // Work days left in week
        $workDaysLeft = max(0, 5 - $isoDay);

        $title = match ($lang) { 'en' => 'DAILY STANDUP', 'es' => 'STANDUP DIARIO', default => 'STANDUP QUOTIDIEN' };

        $lines = [
            "📋 *{$title}*",
            "════════════════",
            "",
            "📅 *{$dayName}* — {$now->format($dateFormat)}",
            "📅 " . match ($lang) { 'en' => "Week", 'es' => "Semana", default => "Semaine" } . " {$isoWeek}" . ($isoDay <= 5 ? " | *{$workDaysLeft}* " . match ($lang) { 'en' => "work days left", 'es' => "días laborables restantes", default => "jours ouvrés restants" } : ''),
            "",
            match ($lang) {
                'en' => "✅ *Yesterday:*\n• _..._\n\n🎯 *Today:*\n• _..._\n\n🚧 *Blockers:*\n• _None_",
                'es' => "✅ *Ayer:*\n• _..._\n\n🎯 *Hoy:*\n• _..._\n\n🚧 *Bloqueos:*\n• _Ninguno_",
                default => "✅ *Hier :*\n• _..._\n\n🎯 *Aujourd'hui :*\n• _..._\n\n🚧 *Blockers :*\n• _Aucun_",
            },
        ];

        // Daily tip
        $tips = match ($lang) {
            'en' => ['Start with the hardest task', 'Block focus time in your calendar', 'Take breaks every 90 min', 'Drink water!', 'End the day by planning tomorrow'],
            'es' => ['Empieza con la tarea más difícil', 'Bloquea tiempo de enfoque', 'Toma descansos cada 90 min', '¡Bebe agua!', 'Termina el día planificando mañana'],
            default => ['Commence par la tâche la plus difficile', 'Bloque du temps de focus dans ton agenda', 'Fais une pause toutes les 90 min', 'Bois de l\'eau !', 'Termine la journée en planifiant demain'],
        };
        $tip = $tips[array_rand($tips)];
        $lines[] = "";
        $lines[] = "💡 _" . match ($lang) { 'en' => "Tip", 'es' => "Consejo", default => "Conseil" } . " : {$tip}_";

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'standup_helper']);
    }

    private function handleMorningRoutine(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $hour      = (int) $now->format('G');
        $dayName   = $this->getDayName((int) $now->format('w'), $lang);
        $isoDay    = (int) $now->format('N');
        $isWeekend = $isoDay >= 6;

        $greeting = match (true) {
            $hour < 6  => match ($lang) { 'en' => 'Early bird! 🐦', 'es' => '¡Madrugador! 🐦', default => 'Lève-tôt ! 🐦' },
            $hour < 9  => match ($lang) { 'en' => 'Good morning! ☀️', 'es' => '¡Buenos días! ☀️', default => 'Bonjour ! ☀️' },
            $hour < 12 => match ($lang) { 'en' => 'Late start? No worries! 😊', 'es' => '¿Empezando tarde? ¡No pasa nada! 😊', default => 'Début tardif ? Pas de souci ! 😊' },
            default    => match ($lang) { 'en' => 'Planning for tomorrow? 🌙', 'es' => '¿Planificando para mañana? 🌙', default => 'Tu prépares demain ? 🌙' },
        };

        $title = match ($lang) { 'en' => 'MORNING ROUTINE', 'es' => 'RUTINA MATINAL', default => 'ROUTINE MATINALE' };

        $lines = [
            "🌅 *{$title}*",
            "════════════════",
            "",
            $greeting,
            "",
            "🕐 *{$now->format('H:i')}* — {$dayName}",
            "",
        ];

        if ($isWeekend) {
            $lines[] = match ($lang) {
                'en' => "🏖 *Weekend mode* — Enjoy your day!",
                'es' => "🏖 *Modo fin de semana* — ¡Disfruta tu día!",
                default => "🏖 *Mode weekend* — Profite de ta journée !",
            };
        } else {
            $checklist = match ($lang) {
                'en' => "☐ Check email & messages\n☐ Review today's calendar\n☐ Set 3 priorities for the day\n☐ Block focus time\n☐ Prepare standup notes",
                'es' => "☐ Revisar email y mensajes\n☐ Ver el calendario de hoy\n☐ Definir 3 prioridades\n☐ Bloquear tiempo de enfoque\n☐ Preparar notas del standup",
                default => "☐ Vérifier emails et messages\n☐ Revoir l'agenda du jour\n☐ Définir 3 priorités\n☐ Bloquer du temps de focus\n☐ Préparer les notes de standup",
            };
            $lines[] = $checklist;
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'morning_routine']);
    }

    private function handlePreferencesCompare(array $parsed, array $prefs): AgentResult
    {
        $lang = $prefs['language'] ?? 'fr';
        $presetName = trim($parsed['preset'] ?? $parsed['target'] ?? '');

        if ($presetName === '' || !isset(self::LOCALE_PRESETS[mb_strtolower($presetName)])) {
            $available = implode(', ', array_keys(self::LOCALE_PRESETS));
            $errMsg = match ($lang) {
                'en' => "⚠️ Specify a locale to compare with.\n\n_Available: {$available}_",
                'es' => "⚠️ Indica un perfil regional para comparar.\n\n_Disponibles: {$available}_",
                default => "⚠️ Indique un profil régional à comparer.\n\n_Disponibles : {$available}_",
            };
            return AgentResult::reply($errMsg, ['action' => 'preferences_compare', 'error' => 'missing_preset']);
        }

        $preset = self::LOCALE_PRESETS[mb_strtolower($presetName)];
        $title  = match ($lang) { 'en' => 'COMPARE PREFERENCES', 'es' => 'COMPARAR PREFERENCIAS', default => 'COMPARER PRÉFÉRENCES' };

        $lines = [
            "🔄 *{$title}*",
            "════════════════",
            "",
            match ($lang) { 'en' => "Your profile vs", 'es' => "Tu perfil vs", default => "Ton profil vs" } . " *" . ucfirst($presetName) . "* :",
            "",
        ];

        $keys = ['language', 'timezone', 'date_format', 'unit_system'];
        foreach ($keys as $key) {
            $current = $prefs[$key] ?? '—';
            $target  = $preset[$key] ?? '—';
            $match   = $current === $target ? '✅' : '🔸';
            $label   = $this->formatKeyLabel($key, $lang);
            $lines[] = "{$match} *{$label}* : {$current} → {$target}";
        }

        $lines[] = "";
        $lines[] = "════════════════";
        $applyTip = match ($lang) {
            'en' => "To apply: _locale preset {$presetName}_",
            'es' => "Para aplicar: _perfil regional {$presetName}_",
            default => "Pour appliquer : _profil régional {$presetName}_",
        };
        $lines[] = "💡 _{$applyTip}_";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'preferences_compare', 'preset' => $presetName]);
    }

    private function handleWeekendCountdown(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $isoDay = (int) $now->format('N');
        $hour   = (int) $now->format('G');

        $title = match ($lang) { 'en' => 'WEEKEND COUNTDOWN', 'es' => 'CUENTA ATRÁS FIN DE SEMANA', default => 'COUNTDOWN WEEK-END' };

        if ($isoDay >= 6) {
            $emoji = '🎉';
            $msg = match ($lang) {
                'en' => "It's the weekend! Enjoy!",
                'es' => "¡Es fin de semana! ¡Disfruta!",
                default => "C'est le week-end ! Profites-en !",
            };
            $hoursLeft = $isoDay === 6 ? (24 - $hour) + 24 : (24 - $hour);
            $endMsg = match ($lang) {
                'en' => "Weekend ends in ~{$hoursLeft}h",
                'es' => "El fin de semana termina en ~{$hoursLeft}h",
                default => "Le week-end se termine dans ~{$hoursLeft}h",
            };
            $lines = [
                "{$emoji} *{$title}*",
                "════════════════",
                "",
                $msg,
                "",
                "⏳ _{$endMsg}_",
                "",
                "════════════════",
            ];
        } else {
            // Calculate hours until Friday 18:00
            $daysUntilFri = 5 - $isoDay;
            $hoursUntil = $daysUntilFri * 24 + (18 - $hour);
            if ($hour >= 18) {
                $hoursUntil = ($daysUntilFri - 1) * 24 + (18 + 24 - $hour);
            }
            $daysDisplay = (int) floor($hoursUntil / 24);
            $hrsDisplay  = $hoursUntil % 24;

            $lines = [
                "⏳ *{$title}*",
                "════════════════",
                "",
                "📅 {$this->getDayName((int) $now->format('w'), $lang)} *{$now->format('H:i')}*",
                "",
            ];

            if ($daysDisplay > 0) {
                $lines[] = "⏱ *{$daysDisplay}* " . match ($lang) { 'en' => "days", 'es' => "días", default => "jours" } . " *{$hrsDisplay}h* " . match ($lang) { 'en' => "until weekend", 'es' => "para el fin de semana", default => "avant le week-end" };
            } else {
                $lines[] = "⏱ *{$hrsDisplay}h* " . match ($lang) { 'en' => "until weekend!", 'es' => "¡para el fin de semana!", default => "avant le week-end !" };
            }

            $pct = (int) round(($isoDay - 1 + $hour / 24) / 5 * 100);
            $lines[] = "";
            $lines[] = match ($lang) { 'en' => "Week progress", 'es' => "Progreso semana", default => "Progression semaine" } . " : {$this->progressBar($pct)} *{$pct}%*";

            $lines[] = "";
            $lines[] = "════════════════";
        }

        return AgentResult::reply(implode("\n", $lines), ['action' => 'weekend_countdown', 'is_weekend' => $isoDay >= 6]);
    }

    private function handleTimeSwap(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';
        $city1     = trim($parsed['city1'] ?? $parsed['from'] ?? '');
        $city2     = trim($parsed['city2'] ?? $parsed['to'] ?? '');

        if ($city1 === '' || $city2 === '') {
            $errMsg = match ($lang) {
                'en' => "⚠️ Specify two cities to swap.\n\n_Example: time swap Paris Tokyo_",
                'es' => "⚠️ Indica dos ciudades.\n\n_Ejemplo: intercambio horario París Tokio_",
                default => "⚠️ Indique deux villes.\n\n_Exemple : échange horaire Paris Tokyo_",
            };
            return AgentResult::reply($errMsg, ['action' => 'time_swap', 'error' => 'missing_cities']);
        }

        $tz1 = $this->resolveTimezoneString($city1);
        $tz2 = $this->resolveTimezoneString($city2);

        if (!$tz1 || !$tz2) {
            $bad = !$tz1 ? $city1 : $city2;
            $errMsg = match ($lang) {
                'en' => "⚠️ Unknown city/timezone: *{$bad}*",
                'es' => "⚠️ Ciudad/zona horaria desconocida: *{$bad}*",
                default => "⚠️ Ville/fuseau inconnu : *{$bad}*",
            };
            return AgentResult::reply($errMsg, ['action' => 'time_swap', 'error' => 'unknown_tz']);
        }

        $zone1 = new DateTimeZone($tz1);
        $zone2 = new DateTimeZone($tz2);
        $now1  = new DateTimeImmutable('now', $zone1);
        $now2  = new DateTimeImmutable('now', $zone2);

        $title = match ($lang) { 'en' => 'TIME SWAP', 'es' => 'INTERCAMBIO HORARIO', default => 'ÉCHANGE HORAIRE' };

        $lines = [
            "🔄 *{$title}*",
            "════════════════",
            "",
            "📍 *" . ucfirst($city1) . "* → 🕐 *{$now1->format('H:i')}* ({$this->getShortTzName($tz1)})",
            "📍 *" . ucfirst($city2) . "* → 🕐 *{$now2->format('H:i')}* ({$this->getShortTzName($tz2)})",
            "",
        ];

        $offset1 = $zone1->getOffset($now1);
        $offset2 = $zone2->getOffset($now2);
        $diffHrs = ($offset2 - $offset1) / 3600;
        $sign    = $diffHrs >= 0 ? '+' : '';
        $lines[] = "⏱ " . match ($lang) { 'en' => "Difference", 'es' => "Diferencia", default => "Décalage" } . " : *{$sign}{$diffHrs}h*";

        // Show swap table for key hours
        $lines[] = "";
        $swapLabel = match ($lang) { 'en' => 'Quick reference', 'es' => 'Referencia rápida', default => 'Tableau rapide' };
        $lines[] = "📋 *{$swapLabel}* :";
        foreach ([9, 12, 15, 18, 21] as $h) {
            $ref1 = $now1->setTime($h, 0);
            $ref2 = (new DateTimeImmutable($ref1->format('Y-m-d H:i:s'), $zone1))->setTimezone($zone2);
            $lines[] = "  {$h}:00 " . ucfirst($city1) . " → *{$ref2->format('H:i')}* " . ucfirst($city2);
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'time_swap']);
    }

    private function handleWeeklyReview(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $isoWeek   = (int) $now->format('W');
        $isoDay    = (int) $now->format('N');
        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';

        // Week boundaries
        $monday = $now->modify('monday this week');
        $friday = $now->modify('friday this week');
        $sunday = $now->modify('sunday this week');

        $title = match ($lang) { 'en' => 'WEEKLY REVIEW', 'es' => 'REVISIÓN SEMANAL', default => 'BILAN HEBDOMADAIRE' };

        $lines = [
            "📊 *{$title}*",
            "════════════════",
            "",
            "📅 " . match ($lang) { 'en' => "Week", 'es' => "Semana", default => "Semaine" } . " {$isoWeek} : {$monday->format($dateFormat)} → {$sunday->format($dateFormat)}",
            "",
            match ($lang) {
                'en' => "✅ *Wins this week:*\n• _..._\n\n🎯 *Goals achieved:*\n• _..._\n\n📝 *Learnings:*\n• _..._\n\n🔮 *Next week priorities:*\n• _..._",
                'es' => "✅ *Logros de esta semana:*\n• _..._\n\n🎯 *Objetivos cumplidos:*\n• _..._\n\n📝 *Aprendizajes:*\n• _..._\n\n🔮 *Prioridades próxima semana:*\n• _..._",
                default => "✅ *Victoires de la semaine :*\n• _..._\n\n🎯 *Objectifs atteints :*\n• _..._\n\n📝 *Apprentissages :*\n• _..._\n\n🔮 *Priorités semaine prochaine :*\n• _..._",
            },
        ];

        // Week stats
        $workDaysDone = min($isoDay, 5);
        $pct = (int) round($workDaysDone / 5 * 100);
        $lines[] = "";
        $lines[] = match ($lang) { 'en' => "Week completion", 'es' => "Avance semana", default => "Avancement semaine" } . " : {$this->progressBar($pct)} *{$pct}%*";

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'weekly_review', 'week' => $isoWeek]);
    }

    private function handleHabitTracker(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $dayName   = $this->getDayName((int) $now->format('w'), $lang);
        $isoDay    = (int) $now->format('N');
        $isWeekend = $isoDay >= 6;

        $title = match ($lang) { 'en' => 'HABIT TRACKER', 'es' => 'SEGUIMIENTO DE HÁBITOS', default => 'SUIVI D\'HABITUDES' };

        $habits = match ($lang) {
            'en' => [
                '💧 Drink 8 glasses of water',
                '🏃 30 min exercise',
                '📖 Read 20 min',
                '🧘 5 min meditation',
                '📝 Journal / reflection',
                '😴 Sleep 7-8 hours',
            ],
            'es' => [
                '💧 Beber 8 vasos de agua',
                '🏃 30 min de ejercicio',
                '📖 Leer 20 min',
                '🧘 5 min de meditación',
                '📝 Diario / reflexión',
                '😴 Dormir 7-8 horas',
            ],
            default => [
                '💧 Boire 8 verres d\'eau',
                '🏃 30 min d\'exercice',
                '📖 Lire 20 min',
                '🧘 5 min de méditation',
                '📝 Journal / réflexion',
                '😴 Dormir 7-8 heures',
            ],
        };

        $lines = [
            "🎯 *{$title}*",
            "════════════════",
            "",
            "📅 *{$dayName}* — {$now->format('d/m/Y')}",
            "",
        ];

        foreach ($habits as $habit) {
            $lines[] = "☐ {$habit}";
        }

        $lines[] = "";
        $motivLabel = match ($lang) {
            'en' => 'Consistency is the key to success!',
            'es' => '¡La consistencia es la clave del éxito!',
            default => 'La régularité est la clé du succès !',
        };
        $lines[] = "💪 _{$motivLabel}_";

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'habit_tracker']);
    }

    private function handleGoalTracker(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';
        $goalName  = trim($parsed['goal'] ?? $parsed['label'] ?? '');
        $deadline  = trim($parsed['deadline'] ?? $parsed['target_date'] ?? '');
        $pctDone   = (int) ($parsed['progress'] ?? $parsed['pct'] ?? 0);

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $title = match ($lang) { 'en' => 'GOAL TRACKER', 'es' => 'SEGUIMIENTO DE OBJETIVOS', default => 'SUIVI D\'OBJECTIFS' };

        $lines = [
            "🎯 *{$title}*",
            "════════════════",
            "",
        ];

        if ($goalName !== '' && $deadline !== '') {
            try {
                $deadlineDate = new DateTimeImmutable($deadline, $tz);
                $diff         = $now->diff($deadlineDate);
                $totalDays    = (int) $diff->format('%a');
                $isPast       = $deadlineDate < $now;

                $lines[] = "📌 " . match ($lang) { 'en' => "Goal", 'es' => "Objetivo", default => "Objectif" } . " : *{$goalName}*";
                $lines[] = "📅 " . match ($lang) { 'en' => "Deadline", 'es' => "Fecha límite", default => "Échéance" } . " : *{$deadlineDate->format('d/m/Y')}*";
                $lines[] = "";

                if ($pctDone > 0) {
                    $lines[] = "📊 " . match ($lang) { 'en' => "Progress", 'es' => "Progreso", default => "Progression" } . " : {$this->progressBar($pctDone)} *{$pctDone}%*";
                    $lines[] = "";
                }

                if ($isPast) {
                    $lines[] = "⚠️ " . match ($lang) { 'en' => "Deadline passed {$totalDays} days ago!", 'es' => "¡Fecha límite pasó hace {$totalDays} días!", default => "Échéance dépassée de {$totalDays} jours !" };
                } else {
                    $lines[] = "⏳ *{$totalDays}* " . match ($lang) { 'en' => "days remaining", 'es' => "días restantes", default => "jours restants" };
                    if ($pctDone > 0 && $pctDone < 100 && $totalDays > 0) {
                        $remaining = 100 - $pctDone;
                        $dailyRate = round($remaining / $totalDays, 1);
                        $lines[] = "📈 " . match ($lang) { 'en' => "Need ~{$dailyRate}%/day to finish on time", 'es' => "Necesitas ~{$dailyRate}%/día para terminar a tiempo", default => "Il faut ~{$dailyRate}%/jour pour finir à temps" };
                    }
                }
            } catch (\Exception) {
                $lines[] = "⚠️ " . match ($lang) { 'en' => "Invalid deadline date", 'es' => "Fecha límite inválida", default => "Date d'échéance invalide" };
            }
        } else {
            $lines[] = match ($lang) {
                'en' => "_Set a goal with name and deadline._\n\n_Example: goal \"Launch v2\" deadline 2026-06-30 progress 45_",
                'es' => "_Define un objetivo con nombre y fecha._\n\n_Ejemplo: objetivo \"Lanzar v2\" fecha 2026-06-30 progreso 45_",
                default => "_Définis un objectif avec nom et échéance._\n\n_Exemple : objectif \"Lancer v2\" échéance 2026-06-30 progression 45_",
            };
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'goal_tracker']);
    }

    private function handleTimezoneBuddy(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';
        $cities    = $parsed['cities'] ?? [];

        if (!is_array($cities) || count($cities) < 2) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Specify at least 2 cities.\n\n_Example: timezone buddy Paris, Tokyo, New York_",
                'es' => "⚠️ Indica al menos 2 ciudades.\n\n_Ejemplo: buddy horario París, Tokio, Nueva York_",
                default => "⚠️ Indique au moins 2 villes.\n\n_Exemple : buddy fuseau Paris, Tokyo, New York_",
            };
            return AgentResult::reply($errMsg, ['action' => 'timezone_buddy', 'error' => 'missing_cities']);
        }

        $title = match ($lang) { 'en' => 'TIMEZONE BUDDY', 'es' => 'COMPAÑERO DE ZONA HORARIA', default => 'BUDDY FUSEAU HORAIRE' };

        $lines = [
            "🤝 *{$title}*",
            "════════════════",
            "",
        ];

        // Resolve all timezones and find overlap
        $resolved = [];
        foreach ($cities as $city) {
            $tz = $this->resolveTimezoneString(trim($city));
            if ($tz) {
                $resolved[] = ['city' => ucfirst(trim($city)), 'tz' => $tz];
            }
        }

        if (count($resolved) < 2) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Could not resolve enough timezones.",
                'es' => "⚠️ No se pudieron resolver suficientes zonas horarias.",
                default => "⚠️ Impossible de résoudre assez de fuseaux horaires.",
            };
            return AgentResult::reply($errMsg, ['action' => 'timezone_buddy', 'error' => 'resolve_failed']);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Show current time for each city
        foreach ($resolved as $r) {
            $zone    = new DateTimeZone($r['tz']);
            $cityNow = $now->setTimezone($zone);
            $lines[] = "📍 *{$r['city']}* : 🕐 *{$cityNow->format('H:i')}* ({$this->getShortTzName($r['tz'])})";
        }

        // Find overlap hours (9-18 business hours)
        $overlapHours = [];
        for ($utcH = 0; $utcH < 24; $utcH++) {
            $allBusiness = true;
            foreach ($resolved as $r) {
                $zone      = new DateTimeZone($r['tz']);
                $offsetSec = $zone->getOffset($now);
                $localH    = ($utcH + $offsetSec / 3600 + 24) % 24;
                if ($localH < 9 || $localH >= 18) {
                    $allBusiness = false;
                    break;
                }
            }
            if ($allBusiness) {
                $overlapHours[] = $utcH;
            }
        }

        $lines[] = "";
        if (count($overlapHours) > 0) {
            $overlapLabel = match ($lang) { 'en' => 'Common business hours (UTC)', 'es' => 'Horas laborales comunes (UTC)', default => 'Heures de bureau communes (UTC)' };
            $first = sprintf('%02d:00', min($overlapHours));
            $last  = sprintf('%02d:00', max($overlapHours) + 1);
            $lines[] = "✅ *{$overlapLabel}* :";
            $lines[] = "  🕐 {$first} — {$last} (*" . count($overlapHours) . "h*)";
        } else {
            $noOverlap = match ($lang) { 'en' => 'No common business hours found', 'es' => 'No hay horas laborales comunes', default => 'Aucune heure de bureau commune trouvée' };
            $lines[] = "⚠️ _{$noOverlap}_";
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'timezone_buddy', 'overlap_hours' => count($overlapHours)]);
    }

    private function handleDurationCalc(array $parsed, array $prefs): AgentResult
    {
        $lang = $prefs['language'] ?? 'fr';
        $op   = trim($parsed['operation'] ?? $parsed['op'] ?? 'add');

        // v1.53.0 — Support "durations" array format (sum of N durations)
        $durations = $parsed['durations'] ?? null;
        if (is_array($durations) && count($durations) > 0) {
            $totalMin     = 0;
            $validParts   = [];
            $invalidParts = [];
            foreach ($durations as $d) {
                $d = trim((string) $d);
                if ($d === '') continue;
                $mins = $this->parseDurationToMinutes($d);
                if ($mins === null) {
                    $invalidParts[] = $d;
                } else {
                    $totalMin += $mins;
                    $validParts[] = $d;
                }
            }
            if (empty($validParts)) {
                $errMsg = match ($lang) {
                    'en' => "⚠️ No valid durations found.\n\n_Example: 2h30 + 1h45 + 3h15_",
                    'es' => "⚠️ No se encontraron duraciones válidas.\n\n_Ejemplo: 2h30 + 1h45 + 3h15_",
                    default => "⚠️ Aucune durée valide trouvée.\n\n_Exemple : 2h30 + 1h45 + 3h15_",
                };
                return AgentResult::reply($errMsg, ['action' => 'duration_calc', 'error' => 'no_valid_durations']);
            }

            $h = (int) floor($totalMin / 60);
            $m = $totalMin % 60;
            $title = match ($lang) { 'en' => 'DURATION CALCULATOR', 'es' => 'CALCULADORA DE DURACIÓN', default => 'CALCULATEUR DE DURÉE' };

            $lines = [
                "⏱ *{$title}*",
                "════════════════",
                "",
                "📊 " . implode(' + ', array_map(fn($d) => "*{$d}*", $validParts)),
                "",
                "⏱ " . match ($lang) { 'en' => "Total", 'es' => "Total", default => "Total" } . " : *{$h}h" . ($m > 0 ? sprintf('%02d', $m) : '') . "*",
                "   = *{$totalMin}* min",
            ];

            if ($totalMin >= 60) {
                $decimal = round($totalMin / 60, 2);
                $lines[] = "   = *{$decimal}*h " . match ($lang) { 'en' => "(decimal)", 'es' => "(decimal)", default => "(décimal)" };
            }

            if ($totalMin >= 1440) {
                $days = round($totalMin / 1440, 1);
                $lines[] = "   = *{$days}* " . match ($lang) { 'en' => "days", 'es' => "días", default => "jours" };
            }

            if (count($validParts) >= 2) {
                $avgMin = (int) round($totalMin / count($validParts));
                $avgH   = (int) floor($avgMin / 60);
                $avgM   = $avgMin % 60;
                $avgLabel = match ($lang) { 'en' => "Average", 'es' => "Promedio", default => "Moyenne" };
                $lines[] = "";
                $lines[] = "📈 {$avgLabel} : *{$avgH}h" . ($avgM > 0 ? sprintf('%02d', $avgM) : '') . "* ({$avgMin} min)";
                $lines[] = "   " . match ($lang) { 'en' => "over", 'es' => "en", default => "sur" } . " *" . count($validParts) . "* " . match ($lang) { 'en' => "entries", 'es' => "entradas", default => "entrées" };
            }

            if (!empty($invalidParts)) {
                $warnLabel = match ($lang) { 'en' => "Ignored (invalid format)", 'es' => "Ignoradas (formato inválido)", default => "Ignorées (format invalide)" };
                $lines[] = "";
                $lines[] = "⚠️ _{$warnLabel} : " . implode(', ', $invalidParts) . "_";
            }

            $lines[] = "";
            $lines[] = "════════════════";

            return AgentResult::reply(implode("\n", $lines), ['action' => 'duration_calc', 'result_minutes' => $totalMin, 'count' => count($validParts)]);
        }

        // Legacy fallback: duration1/duration2
        $duration1 = trim($parsed['duration1'] ?? $parsed['from'] ?? '');
        $duration2 = trim($parsed['duration2'] ?? $parsed['to'] ?? '');

        if ($duration1 === '') {
            $errMsg = match ($lang) {
                'en' => "⚠️ Specify at least one duration.\n\n_Example: 2h30 + 1h45 + 3h15_",
                'es' => "⚠️ Indica al menos una duración.\n\n_Ejemplo: 2h30 + 1h45 + 3h15_",
                default => "⚠️ Indique au moins une durée.\n\n_Exemple : 2h30 + 1h45 + 3h15_",
            };
            return AgentResult::reply($errMsg, ['action' => 'duration_calc', 'error' => 'missing_duration']);
        }

        $min1 = $this->parseDurationToMinutes($duration1);
        $min2 = $duration2 !== '' ? $this->parseDurationToMinutes($duration2) : 0;

        if ($min1 === null) {
            $errMsg = match ($lang) {
                'en' => "⚠️ Invalid duration format: *{$duration1}*",
                'es' => "⚠️ Formato de duración inválido: *{$duration1}*",
                default => "⚠️ Format de durée invalide : *{$duration1}*",
            };
            return AgentResult::reply($errMsg, ['action' => 'duration_calc', 'error' => 'invalid_format']);
        }

        $resultMin = $op === 'subtract' ? $min1 - ($min2 ?? 0) : $min1 + ($min2 ?? 0);
        $resultMin = max(0, $resultMin);

        $h = (int) floor($resultMin / 60);
        $m = $resultMin % 60;

        $title = match ($lang) { 'en' => 'DURATION CALCULATOR', 'es' => 'CALCULADORA DE DURACIÓN', default => 'CALCULATEUR DE DURÉE' };
        $opSymbol = $op === 'subtract' ? '−' : '+';

        $lines = [
            "⏱ *{$title}*",
            "════════════════",
            "",
        ];

        if ($duration2 !== '') {
            $lines[] = "📊 *{$duration1}* {$opSymbol} *{$duration2}*";
        } else {
            $lines[] = "📊 *{$duration1}*";
        }

        $lines[] = "";
        $lines[] = "⏱ " . match ($lang) { 'en' => "Result", 'es' => "Resultado", default => "Résultat" } . " : *{$h}h" . ($m > 0 ? sprintf('%02d', $m) : '') . "*";
        $lines[] = "   = *{$resultMin}* min";

        if ($resultMin >= 1440) {
            $days = round($resultMin / 1440, 1);
            $lines[] = "   = *{$days}* " . match ($lang) { 'en' => "days", 'es' => "días", default => "jours" };
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'duration_calc', 'result_minutes' => $resultMin]);
    }

    private function handleSmartSchedule(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';
        $activity  = trim($parsed['activity'] ?? $parsed['task'] ?? '');

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $hour   = (int) $now->format('G');
        $isoDay = (int) $now->format('N');

        $title = match ($lang) { 'en' => 'SMART SCHEDULE', 'es' => 'HORARIO INTELIGENTE', default => 'PLANNING INTELLIGENT' };

        $lines = [
            "🧠 *{$title}*",
            "════════════════",
            "",
            "🕐 *{$now->format('H:i')}* — {$this->getDayName((int) $now->format('w'), $lang)}",
            "",
        ];

        // Suggest optimal time slots for the rest of the day
        $slots = [];
        if ($hour < 10) {
            $slots[] = match ($lang) {
                'en' => "🧠 *08:00–10:00* — Deep work (highest focus)",
                'es' => "🧠 *08:00–10:00* — Trabajo profundo (máximo enfoque)",
                default => "🧠 *08:00–10:00* — Travail profond (focus max)",
            };
        }
        if ($hour < 12) {
            $slots[] = match ($lang) {
                'en' => "🤝 *10:00–12:00* — Meetings & collaboration",
                'es' => "🤝 *10:00–12:00* — Reuniones y colaboración",
                default => "🤝 *10:00–12:00* — Réunions et collaboration",
            };
        }
        if ($hour < 14) {
            $slots[] = match ($lang) {
                'en' => "🍽️ *12:00–13:30* — Lunch break",
                'es' => "🍽️ *12:00–13:30* — Pausa para almorzar",
                default => "🍽️ *12:00–13:30* — Pause déjeuner",
            };
        }
        if ($hour < 16) {
            $slots[] = match ($lang) {
                'en' => "📋 *14:00–16:00* — Admin, emails, follow-ups",
                'es' => "📋 *14:00–16:00* — Admin, emails, seguimientos",
                default => "📋 *14:00–16:00* — Admin, emails, suivis",
            };
        }
        if ($hour < 18) {
            $slots[] = match ($lang) {
                'en' => "🎨 *16:00–18:00* — Creative work & planning",
                'es' => "🎨 *16:00–18:00* — Trabajo creativo y planificación",
                default => "🎨 *16:00–18:00* — Travail créatif et planification",
            };
        }

        if (empty($slots)) {
            $slots[] = match ($lang) {
                'en' => "🛋️ After work — rest and recharge for tomorrow",
                'es' => "🛋️ Después del trabajo — descansa y recarga para mañana",
                default => "🛋️ Après le travail — repos et rechargement pour demain",
            };
        }

        if ($activity !== '') {
            $actLabel = match ($lang) { 'en' => "For", 'es' => "Para", default => "Pour" };
            $lines[] = "📌 {$actLabel} : *{$activity}*";
            $lines[] = "";
        }

        $suggestLabel = match ($lang) { 'en' => "Suggested schedule", 'es' => "Horario sugerido", default => "Planning suggéré" };
        $lines[] = "📋 *{$suggestLabel}* :";
        foreach ($slots as $slot) {
            $lines[] = $slot;
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'smart_schedule']);
    }

    // -------------------------------------------------------------------------
    // v1.52.0 — break_reminder: suggest breaks based on ultradian rhythm
    // -------------------------------------------------------------------------

    private function handleBreakReminder(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $hour   = (int) $now->format('G');
        $minute = (int) $now->format('i');

        $workStartHour = 9;
        if (preg_match('/^(\d{1,2})[h:]?\d{0,2}$/', trim($parsed['work_start'] ?? ''), $m)) {
            $workStartHour = (int) $m[1];
        }

        $workedMinutes = max(0, ($hour * 60 + $minute) - ($workStartHour * 60));
        $workedH = (int) floor($workedMinutes / 60);
        $workedM = $workedMinutes % 60;

        $title = match ($lang) {
            'en' => 'BREAK REMINDER', 'es' => 'RECORDATORIO DE DESCANSO',
            'de' => 'PAUSENERINNERUNG', 'it' => 'PROMEMORIA PAUSA',
            'pt' => 'LEMBRETE DE PAUSA', default => 'RAPPEL DE PAUSE',
        };

        $lines = [
            "☕ *{$title}*",
            "════════════════",
            "",
            "🕐 " . match ($lang) { 'en' => "Current time", 'es' => "Hora actual", default => "Heure actuelle" } . " : *{$now->format('H:i')}*",
            "⏱ " . match ($lang) { 'en' => "Working since", 'es' => "Trabajando desde", default => "Travail depuis" }
                . " : *{$workStartHour}h00* ({$workedH}h" . ($workedM > 0 ? sprintf('%02d', $workedM) : '') . " "
                . match ($lang) { 'en' => "elapsed", 'es' => "transcurrido", default => "écoulées" } . ")",
            "",
        ];

        // 90-minute ultradian rhythm cycles
        $breakTypes = [
            90  => match ($lang) { 'en' => "☕ Micro-break (5 min) — water, stretch", 'es' => "☕ Micro-descanso (5 min) — agua, estiramiento", default => "☕ Micro-pause (5 min) — eau, étirements" },
            180 => match ($lang) { 'en' => "🍽️ Meal break (30-45 min)", 'es' => "🍽️ Pausa comida (30-45 min)", default => "🍽️ Pause repas (30-45 min)" },
            270 => match ($lang) { 'en' => "🚶 Walk break (10-15 min) — fresh air", 'es' => "🚶 Paseo (10-15 min) — aire fresco", default => "🚶 Pause marche (10-15 min) — air frais" },
            360 => match ($lang) { 'en' => "☕ Coffee break (10 min) — social time", 'es' => "☕ Pausa café (10 min) — tiempo social", default => "☕ Pause café (10 min) — moment social" },
            450 => match ($lang) { 'en' => "🧘 Wind-down (15 min) — plan tomorrow", 'es' => "🧘 Relajación (15 min) — planificar mañana", default => "🧘 Décompression (15 min) — planifier demain" },
        ];

        $breakLabel = match ($lang) { 'en' => "Break schedule", 'es' => "Calendario de pausas", default => "Planning des pauses" };
        $lines[] = "📋 *{$breakLabel}* :";

        $nextBreakMin = null;
        foreach ($breakTypes as $atMin => $desc) {
            $breakHour = $workStartHour + (int) floor($atMin / 60);
            $breakMin  = $atMin % 60;
            $timeStr   = sprintf('%02d:%02d', $breakHour, $breakMin);
            $status    = $atMin <= $workedMinutes ? '✅' : '⏳';
            $lines[]   = "{$status} *{$timeStr}* — {$desc}";

            if ($nextBreakMin === null && $atMin > $workedMinutes) {
                $nextBreakMin = $atMin;
            }
        }

        $lines[] = "";

        if ($nextBreakMin !== null) {
            $minutesUntil = $nextBreakMin - $workedMinutes;
            $lines[] = "⏰ " . match ($lang) {
                'en' => "Next break in *{$minutesUntil} min*",
                'es' => "Próxima pausa en *{$minutesUntil} min*",
                default => "Prochaine pause dans *{$minutesUntil} min*",
            };
        } else {
            $lines[] = "🏁 " . match ($lang) {
                'en' => "All break cycles complete — time to wrap up!",
                'es' => "Todos los ciclos completados — ¡hora de terminar!",
                default => "Tous les cycles terminés — c'est l'heure de décrocher !",
            };
        }

        $glasses = max(1, (int) floor($workedMinutes / 60));
        $lines[] = "💧 " . match ($lang) {
            'en' => "You should have drunk ~{$glasses} glasses of water so far",
            'es' => "Deberías haber bebido ~{$glasses} vasos de agua hasta ahora",
            default => "Tu devrais avoir bu ~{$glasses} verres d'eau jusqu'ici",
        };

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'break_reminder', 'worked_minutes' => $workedMinutes]);
    }

    // -------------------------------------------------------------------------
    // v1.52.0 — time_budget: remaining working hours today and this week
    // -------------------------------------------------------------------------

    private function handleTimeBudget(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $hour   = (int) $now->format('G');
        $minute = (int) $now->format('i');
        $isoDay = (int) $now->format('N');

        $workStartHour = 9;
        $workEndHour   = 18;
        if (preg_match('/^(\d{1,2})[h:]?\d{0,2}$/', trim($parsed['work_start'] ?? ''), $m)) {
            $workStartHour = (int) $m[1];
        }
        if (preg_match('/^(\d{1,2})[h:]?\d{0,2}$/', trim($parsed['work_end'] ?? ''), $m)) {
            $workEndHour = (int) $m[1];
        }

        $dailyWorkMinutes = ($workEndHour - $workStartHour) * 60;
        $currentMinutes   = $hour * 60 + $minute;
        $startMinutes     = $workStartHour * 60;
        $endMinutes       = $workEndHour * 60;
        $isWorkday        = $isoDay <= 5;

        $todayRemaining = 0;
        $todayWorked    = 0;
        if ($isWorkday) {
            if ($currentMinutes < $startMinutes) {
                $todayRemaining = $dailyWorkMinutes;
            } elseif ($currentMinutes >= $endMinutes) {
                $todayWorked = $dailyWorkMinutes;
            } else {
                $todayWorked    = $currentMinutes - $startMinutes;
                $todayRemaining = $endMinutes - $currentMinutes;
            }
        }

        $remainingWorkdays = max(0, 5 - $isoDay);
        $weekRemaining     = $todayRemaining + ($remainingWorkdays * $dailyWorkMinutes);
        $weekTotal         = 5 * $dailyWorkMinutes;
        $weekWorked        = $weekTotal - $weekRemaining;
        $weekPct           = $weekTotal > 0 ? (int) round(($weekWorked / $weekTotal) * 100) : 0;

        $title = match ($lang) {
            'en' => 'TIME BUDGET', 'es' => 'PRESUPUESTO DE TIEMPO',
            'de' => 'ZEITBUDGET', 'it' => 'BUDGET TEMPO',
            'pt' => 'ORÇAMENTO DE TEMPO', default => 'BUDGET TEMPS',
        };

        $lines = [
            "⏳ *{$title}*",
            "════════════════",
            "",
            "🕐 *{$now->format('H:i')}* — {$this->getDayName((int) $now->format('w'), $lang)}",
            "🏢 " . match ($lang) { 'en' => "Work hours", 'es' => "Horario laboral", default => "Horaires de travail" }
                . " : *{$workStartHour}h00 – {$workEndHour}h00*",
            "",
        ];

        $todayLabel = match ($lang) { 'en' => "TODAY", 'es' => "HOY", default => "AUJOURD'HUI" };
        $lines[] = "📅 *{$todayLabel}* :";

        if (!$isWorkday) {
            $lines[] = "   🎉 " . match ($lang) {
                'en' => "It's the weekend! Enjoy your rest.",
                'es' => "¡Es fin de semana! Disfruta tu descanso.",
                default => "C'est le week-end ! Profite de ton repos.",
            };
        } elseif ($currentMinutes >= $endMinutes) {
            $h = (int) floor($todayWorked / 60);
            $m = $todayWorked % 60;
            $lines[] = "   ✅ " . match ($lang) {
                'en' => "Work day complete!", 'es' => "¡Día completado!", default => "Journée terminée !",
            } . " ({$h}h" . ($m > 0 ? sprintf('%02d', $m) : '') . ")";
        } elseif ($currentMinutes < $startMinutes) {
            $h = (int) floor($todayRemaining / 60);
            $lines[] = "   ⏳ " . match ($lang) {
                'en' => "Work hasn't started — full day ahead ({$h}h)",
                'es' => "Aún no empieza — día completo ({$h}h)",
                default => "Pas encore commencé — journée complète ({$h}h)",
            };
        } else {
            $wH = (int) floor($todayWorked / 60);
            $wM = $todayWorked % 60;
            $rH = (int) floor($todayRemaining / 60);
            $rM = $todayRemaining % 60;
            $pct = $dailyWorkMinutes > 0 ? (int) round(($todayWorked / $dailyWorkMinutes) * 100) : 0;

            $lines[] = "   ⏱ " . match ($lang) { 'en' => "Worked", 'es' => "Trabajado", default => "Travaillé" }
                . " : *{$wH}h" . ($wM > 0 ? sprintf('%02d', $wM) : '') . "*";
            $lines[] = "   ⏳ " . match ($lang) { 'en' => "Remaining", 'es' => "Restante", default => "Restant" }
                . " : *{$rH}h" . ($rM > 0 ? sprintf('%02d', $rM) : '') . "*";
            $lines[] = "   " . $this->progressBar($pct);
        }

        $lines[] = "";

        $weekLabel = match ($lang) { 'en' => "THIS WEEK", 'es' => "ESTA SEMANA", default => "CETTE SEMAINE" };
        $lines[] = "📊 *{$weekLabel}* :";

        $wwH = (int) floor($weekWorked / 60);
        $wwM = $weekWorked % 60;
        $wrH = (int) floor($weekRemaining / 60);
        $wrM = $weekRemaining % 60;
        $wtH = (int) floor($weekTotal / 60);

        $lines[] = "   ⏱ " . match ($lang) { 'en' => "Worked", 'es' => "Trabajado", default => "Travaillé" }
            . " : *{$wwH}h" . ($wwM > 0 ? sprintf('%02d', $wwM) : '') . "* / {$wtH}h";
        $lines[] = "   ⏳ " . match ($lang) { 'en' => "Remaining", 'es' => "Restante", default => "Restant" }
            . " : *{$wrH}h" . ($wrM > 0 ? sprintf('%02d', $wrM) : '') . "*";
        $lines[] = "   " . $this->progressBar($weekPct);

        if ($remainingWorkdays > 0) {
            $lines[] = "   📅 " . match ($lang) {
                'en' => "{$remainingWorkdays} workday(s) left this week",
                'es' => "{$remainingWorkdays} día(s) laborable(s) restante(s)",
                default => "{$remainingWorkdays} jour(s) ouvré(s) restant(s)",
            };
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), [
            'action'           => 'time_budget',
            'today_remaining'  => $todayRemaining,
            'week_remaining'   => $weekRemaining,
            'week_pct'         => $weekPct,
        ]);
    }

    // =========================================================================
    // v1.54.0 — New features: time_report, city_compare
    // =========================================================================

    private function handleTimeReport(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $hour   = (int) $now->format('G');
        $isoDay = (int) $now->format('N');

        $title = match ($lang) {
            'en' => 'DAILY TIME REPORT', 'es' => 'REPORTE DIARIO',
            'de' => 'TÄGLICHER ZEITBERICHT', 'it' => 'RAPPORTO GIORNALIERO',
            'pt' => 'RELATÓRIO DIÁRIO', default => 'RAPPORT QUOTIDIEN',
        };

        $dayLabel   = $this->getDayName((int) $now->format('w'), $lang);
        $monthLabel = $this->getMonthName((int) $now->format('n'), $lang);

        $lines = [
            "📊 *{$title}*",
            "════════════════",
            "",
            "📅 {$dayLabel} {$now->format('d')} {$monthLabel} {$now->format('Y')}",
            "🕐 *{$now->format('H:i')}* ({$userTzStr})",
            "",
        ];

        // Key world cities status
        $keyTimezones = [
            ['🇫🇷', 'Paris',      'Europe/Paris'],
            ['🇬🇧', 'London',     'Europe/London'],
            ['🇺🇸', 'New York',   'America/New_York'],
            ['🇺🇸', 'Los Angeles', 'America/Los_Angeles'],
            ['🇯🇵', 'Tokyo',      'Asia/Tokyo'],
            ['🇨🇳', 'Shanghai',   'Asia/Shanghai'],
            ['🇦🇪', 'Dubai',      'Asia/Dubai'],
            ['🇦🇺', 'Sydney',     'Australia/Sydney'],
        ];

        $worldLabel = match ($lang) { 'en' => 'World Status', 'es' => 'Estado Mundial', default => 'État du monde' };
        $lines[] = "🌍 *{$worldLabel}* :";

        foreach ($keyTimezones as [$flag, $city, $tzId]) {
            try {
                $cityTz   = new DateTimeZone($tzId);
                $cityNow  = new DateTimeImmutable('now', $cityTz);
                $cityHour = (int) $cityNow->format('G');
                $statusInfo = $this->getTimeStatus($cityHour, (int) $cityNow->format('N'), $lang);
                $lines[] = "{$flag} {$city} : *{$cityNow->format('H:i')}* {$statusInfo['emoji']}";
            } catch (\Exception) {
                continue;
            }
        }

        // Productivity metrics
        $lines[] = "";
        $productivityLabel = match ($lang) { 'en' => 'Your Day', 'es' => 'Tu Día', default => 'Ta Journée' };
        $lines[] = "📈 *{$productivityLabel}* :";

        // Day progress
        $dayPct = (int) round(($hour * 60 + (int) $now->format('i')) / 1440 * 100);
        $dayBar = $this->progressBar($dayPct);
        $dayPLabel = match ($lang) { 'en' => 'Day', 'es' => 'Día', default => 'Journée' };
        $lines[] = "🌅 {$dayPLabel} : {$dayBar} *{$dayPct}%*";

        // Week progress
        $weekPct = (int) round((($isoDay - 1) * 24 + $hour) / (7 * 24) * 100);
        $weekBar = $this->progressBar($weekPct);
        $weekPLabel = match ($lang) { 'en' => 'Week', 'es' => 'Semana', default => 'Semaine' };
        $lines[] = "📅 {$weekPLabel} : {$weekBar} *{$weekPct}%*";

        // Work hours remaining (if weekday)
        if ($isoDay <= 5) {
            $workEnd = 18;
            if ($hour >= 9 && $hour < $workEnd) {
                $remaining = ($workEnd * 60) - ($hour * 60 + (int) $now->format('i'));
                $remH = (int) floor($remaining / 60);
                $remM = $remaining % 60;
                $workLabel = match ($lang) { 'en' => 'Work remaining', 'es' => 'Trabajo restante', default => 'Travail restant' };
                $lines[] = "⏳ {$workLabel} : *{$remH}h{$remM}m*";
            }
        }

        // Energy level hint
        [$energyEmoji, $energyTip] = match (true) {
            $hour >= 8 && $hour < 10  => ['⚡', match ($lang) { 'en' => 'Peak focus — tackle hard tasks now', 'es' => 'Enfoque máximo — aborda tareas difíciles', default => 'Focus max — attaque les tâches difficiles' }],
            $hour >= 10 && $hour < 12 => ['🔥', match ($lang) { 'en' => 'High energy — great for meetings', 'es' => 'Alta energía — ideal para reuniones', default => 'Haute énergie — idéal pour les réunions' }],
            $hour >= 12 && $hour < 14 => ['😴', match ($lang) { 'en' => 'Post-lunch dip — take it easy', 'es' => 'Bajón post-almuerzo — tómalo con calma', default => 'Creux post-déjeuner — vas-y doucement' }],
            $hour >= 14 && $hour < 16 => ['☕', match ($lang) { 'en' => 'Recovering — routine tasks', 'es' => 'Recuperación — tareas rutinarias', default => 'Récupération — tâches de routine' }],
            $hour >= 16 && $hour < 18 => ['💪', match ($lang) { 'en' => 'Second wind — creative work', 'es' => 'Segundo aire — trabajo creativo', default => 'Second souffle — travail créatif' }],
            default                    => ['🌙', match ($lang) { 'en' => 'Rest time — recharge', 'es' => 'Hora de descanso — recarga', default => 'Repos — recharge tes batteries' }],
        };

        $lines[] = "";
        $lines[] = "{$energyEmoji} _{$energyTip}_";
        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), ['action' => 'time_report']);
    }

    private function handleCityCompare(array $parsed, array $prefs): AgentResult
    {
        $lang = $prefs['language'] ?? 'fr';

        $city1 = trim($parsed['city1'] ?? $parsed['from'] ?? '');
        $city2 = trim($parsed['city2'] ?? $parsed['to'] ?? $parsed['target'] ?? '');

        if ($city1 === '' || $city2 === '') {
            $msg = match ($lang) {
                'en' => "⚠️ Please specify two cities to compare.\n\n_Example: compare Paris and Tokyo_",
                'es' => "⚠️ Especifica dos ciudades para comparar.\n\n_Ejemplo: comparar París y Tokio_",
                'de' => "⚠️ Bitte gib zwei Städte zum Vergleichen an.\n\n_Beispiel: vergleiche Paris und Tokio_",
                default => "⚠️ Précise deux villes à comparer.\n\n_Exemple : comparer Paris et Tokyo_",
            };
            return AgentResult::reply($msg, ['action' => 'city_compare', 'error' => 'missing_cities']);
        }

        $tz1Str = $this->resolveTimezoneString($city1);
        $tz2Str = $this->resolveTimezoneString($city2);

        if ($tz1Str === null) {
            $msg = match ($lang) {
                'en' => "⚠️ Could not find timezone for *{$city1}*. Try an IANA name like Europe/Paris.",
                'es' => "⚠️ No encontré la zona horaria de *{$city1}*. Prueba un nombre IANA como Europe/Paris.",
                default => "⚠️ Impossible de trouver le fuseau de *{$city1}*. Essaie un nom IANA comme Europe/Paris.",
            };
            return AgentResult::reply($msg, ['action' => 'city_compare', 'error' => 'unknown_city1']);
        }
        if ($tz2Str === null) {
            $msg = match ($lang) {
                'en' => "⚠️ Could not find timezone for *{$city2}*. Try an IANA name like Asia/Tokyo.",
                'es' => "⚠️ No encontré la zona horaria de *{$city2}*. Prueba un nombre IANA como Asia/Tokyo.",
                default => "⚠️ Impossible de trouver le fuseau de *{$city2}*. Essaie un nom IANA comme Asia/Tokyo.",
            };
            return AgentResult::reply($msg, ['action' => 'city_compare', 'error' => 'unknown_city2']);
        }

        try {
            $tz1  = new DateTimeZone($tz1Str);
            $tz2  = new DateTimeZone($tz2Str);
            $now1 = new DateTimeImmutable('now', $tz1);
            $now2 = new DateTimeImmutable('now', $tz2);
        } catch (\Exception) {
            $msg = match ($lang) {
                'en' => "⚠️ Error resolving timezones.",
                'es' => "⚠️ Error al resolver zonas horarias.",
                default => "⚠️ Erreur de résolution des fuseaux horaires.",
            };
            return AgentResult::reply($msg, ['action' => 'city_compare', 'error' => 'tz_error']);
        }

        $title = match ($lang) {
            'en' => 'CITY COMPARISON', 'es' => 'COMPARACIÓN DE CIUDADES',
            'de' => 'STÄDTEVERGLEICH', 'it' => 'CONFRONTO CITTÀ',
            'pt' => 'COMPARAÇÃO DE CIDADES', default => 'COMPARAISON VILLES',
        };

        $hour1   = (int) $now1->format('G');
        $hour2   = (int) $now2->format('G');
        $isoDay1 = (int) $now1->format('N');
        $isoDay2 = (int) $now2->format('N');

        $status1 = $this->getTimeStatus($hour1, $isoDay1, $lang);
        $status2 = $this->getTimeStatus($hour2, $isoDay2, $lang);

        $diffSeconds = $tz1->getOffset($now1) - $tz2->getOffset($now2);
        $diffHours   = $diffSeconds / 3600;
        $diffSign    = $diffHours >= 0 ? '+' : '';

        $dayName1 = $this->getDayName((int) $now1->format('w'), $lang);
        $dayName2 = $this->getDayName((int) $now2->format('w'), $lang);

        $diffLabel = match ($lang) { 'en' => 'Time difference', 'es' => 'Diferencia horaria', default => 'Décalage horaire' };
        $busiLabel = match ($lang) { 'en' => 'Office (9-18)', 'es' => 'Oficina (9-18)', default => 'Bureau (9-18)' };

        $biz1 = ($hour1 >= 9 && $hour1 < 18 && $isoDay1 <= 5);
        $biz2 = ($hour2 >= 9 && $hour2 < 18 && $isoDay2 <= 5);
        $biz1Str = $biz1 ? '✅' : '❌';
        $biz2Str = $biz2 ? '✅' : '❌';

        // Calculate overlap window
        $overlapLabel = match ($lang) { 'en' => 'Overlap window', 'es' => 'Ventana común', default => 'Fenêtre commune' };
        $offset1 = $tz1->getOffset($now1) / 3600;
        $offset2 = $tz2->getOffset($now2) / 3600;
        $overlapStart = max(9 + $offset1, 9 + $offset2);
        $overlapEnd   = min(18 + $offset1, 18 + $offset2);
        $overlapHours = max(0, $overlapEnd - $overlapStart);

        if ($overlapHours > 0) {
            $localStart1 = (int) ($overlapStart - $offset1);
            $localEnd1   = (int) ($overlapEnd - $offset1);
            $localStart2 = (int) ($overlapStart - $offset2);
            $localEnd2   = (int) ($overlapEnd - $offset2);
            $overlapStr  = sprintf(
                '%dh (%02d:00–%02d:00 / %02d:00–%02d:00)',
                (int) $overlapHours, $localStart1, $localEnd1, $localStart2, $localEnd2
            );
        } else {
            $overlapStr = match ($lang) { 'en' => 'No overlap', 'es' => 'Sin coincidencia', default => 'Aucun chevauchement' };
        }

        $city1Label = ucfirst($city1);
        $city2Label = ucfirst($city2);

        $lines = [
            "🏙️ *{$title}*",
            "════════════════",
            "",
            "📍 *{$city1Label}* vs *{$city2Label}*",
            "",
            "┌─────────────────────",
            "│ 🕐 {$city1Label} : *{$now1->format('H:i')}* {$status1['emoji']}",
            "│    {$dayName1} {$now1->format('d/m/Y')}",
            "│    {$biz1Str} {$busiLabel}",
            "├─────────────────────",
            "│ 🕐 {$city2Label} : *{$now2->format('H:i')}* {$status2['emoji']}",
            "│    {$dayName2} {$now2->format('d/m/Y')}",
            "│    {$biz2Str} {$busiLabel}",
            "└─────────────────────",
            "",
            "⏱ {$diffLabel} : *{$diffSign}{$diffHours}h*",
            "🤝 {$overlapLabel} : *{$overlapStr}*",
            "",
            "════════════════",
        ];

        return AgentResult::reply(implode("\n", $lines), [
            'action'     => 'city_compare',
            'city1'      => $city1,
            'city2'      => $city2,
            'diff_h'     => $diffHours,
            'overlap_h'  => $overlapHours,
        ]);
    }

    // =========================================================================
    // NEW HANDLERS v1.55.0
    // =========================================================================

    /**
     * Birthday countdown — show countdown to the user's next birthday.
     * Accepts a birthdate param, or falls back to a previously used one in prefs.
     */
    private function handleBirthdayCountdown(array $parsed, array $prefs): AgentResult
    {
        $lang    = $prefs['language'] ?? 'fr';
        $tzName  = $prefs['timezone'] ?? 'UTC';
        $dateFmt = $prefs['date_format'] ?? 'd/m/Y';

        $birthdateStr = trim($parsed['birthdate'] ?? '');

        if ($birthdateStr === '') {
            $msg = match ($lang) {
                'en' => "🎂 Please provide your birthdate.\n\n_Example: birthday countdown 1990-05-15_\n_Format: YYYY-MM-DD_",
                'es' => "🎂 Indica tu fecha de nacimiento.\n\n_Ejemplo: cuenta regresiva cumpleaños 1990-05-15_\n_Formato: AAAA-MM-DD_",
                'de' => "🎂 Bitte gib dein Geburtsdatum an.\n\n_Beispiel: Geburtstags-Countdown 1990-05-15_\n_Format: JJJJ-MM-TT_",
                'it' => "🎂 Indica la tua data di nascita.\n\n_Esempio: countdown compleanno 1990-05-15_\n_Formato: AAAA-MM-GG_",
                'pt' => "🎂 Informe sua data de nascimento.\n\n_Exemplo: countdown aniversário 1990-05-15_\n_Formato: AAAA-MM-DD_",
                default => "🎂 Indique ta date de naissance.\n\n_Exemple : countdown anniversaire 1990-05-15_\n_Format : AAAA-MM-JJ_",
            };
            return AgentResult::reply($msg, ['action' => 'birthday_countdown', 'error' => 'missing_birthdate']);
        }

        try {
            $tz        = new DateTimeZone($tzName);
            $today     = new DateTimeImmutable('now', $tz);
            $birthDate = new DateTimeImmutable($birthdateStr . ' 00:00:00', $tz);
        } catch (\Exception) {
            $msg = match ($lang) {
                'en' => "⚠️ Invalid birthdate: *{$birthdateStr}*.\n_Use format YYYY-MM-DD, e.g. 1990-05-15_",
                'es' => "⚠️ Fecha inválida: *{$birthdateStr}*.\n_Usa formato AAAA-MM-DD, ej. 1990-05-15_",
                default => "⚠️ Date de naissance invalide : *{$birthdateStr}*.\n_Utilise le format AAAA-MM-JJ, ex : 1990-05-15_",
            };
            return AgentResult::reply($msg, ['action' => 'birthday_countdown', 'error' => 'invalid_date']);
        }

        if ($birthDate > $today) {
            $msg = match ($lang) {
                'en' => "⚠️ The date *{$birthdateStr}* is in the future.",
                'es' => "⚠️ La fecha *{$birthdateStr}* está en el futuro.",
                default => "⚠️ La date *{$birthdateStr}* est dans le futur.",
            };
            return AgentResult::reply($msg, ['action' => 'birthday_countdown', 'error' => 'future_date']);
        }

        $todayMidnight = new DateTimeImmutable($today->format('Y-m-d') . ' 00:00:00', $tz);
        $diff   = $today->diff($birthDate);
        $years  = (int) $diff->y;

        // Compute next birthday (handle Feb 29)
        $bdayMonthDay = $birthDate->format('m-d');
        $thisYear = (int) $today->format('Y');
        if ($bdayMonthDay === '02-29') {
            $bdayThisYear = checkdate(2, 29, $thisYear) ? "{$thisYear}-02-29" : "{$thisYear}-03-01";
            $nextYear     = $thisYear + 1;
            $bdayNextYear = checkdate(2, 29, $nextYear) ? "{$nextYear}-02-29" : "{$nextYear}-03-01";
        } else {
            $bdayThisYear = "{$thisYear}-{$bdayMonthDay}";
            $bdayNextYear = ($thisYear + 1) . "-{$bdayMonthDay}";
        }

        try {
            $thisYearBirthday = new DateTimeImmutable($bdayThisYear . ' 00:00:00', $tz);
        } catch (\Exception) {
            $thisYearBirthday = new DateTimeImmutable($bdayNextYear . ' 00:00:00', $tz);
        }

        $nextBirthday = ($thisYearBirthday <= $todayMidnight)
            ? new DateTimeImmutable($bdayNextYear . ' 00:00:00', $tz)
            : $thisYearBirthday;

        $daysUntil   = (int) $todayMidnight->diff($nextBirthday)->days;
        $turningAge  = $years + (($thisYearBirthday <= $todayMidnight) ? 2 : 1);
        // Correct: if birthday this year is past, next birthday = next year = years+1+1? No.
        // Actually: turningAge = year_of_next_birthday - year_of_birth
        $turningAge = (int) $nextBirthday->format('Y') - (int) $birthDate->format('Y');
        $bdayDay     = $this->getDayName((int) $nextBirthday->format('w'), $lang);
        $bdayFmt     = $nextBirthday->format($dateFmt);

        // Progress bar toward birthday (365-day cycle)
        $daysSinceLast = 365 - $daysUntil;
        if ($daysSinceLast < 0) $daysSinceLast = 0;
        $pct = ($daysSinceLast > 0) ? (int) round($daysSinceLast / 365 * 100) : 0;
        $bar = $this->progressBar($pct);

        // i18n titles and labels
        $title = match ($lang) { 'en' => 'BIRTHDAY COUNTDOWN', 'es' => 'CUENTA REGRESIVA CUMPLEAÑOS', 'de' => 'GEBURTSTAGS-COUNTDOWN', 'it' => 'CONTO ALLA ROVESCIA COMPLEANNO', 'pt' => 'CONTAGEM REGRESSIVA ANIVERSÁRIO', default => 'COMPTE À REBOURS ANNIVERSAIRE' };

        if ($daysUntil === 0) {
            $mainMsg = match ($lang) {
                'en' => "🎉 *HAPPY BIRTHDAY!* You're turning *{$turningAge}* today!",
                'es' => "🎉 *¡FELIZ CUMPLEAÑOS!* ¡Hoy cumples *{$turningAge}*!",
                'de' => "🎉 *ALLES GUTE ZUM GEBURTSTAG!* Du wirst heute *{$turningAge}*!",
                default => "🎉 *JOYEUX ANNIVERSAIRE !* Tu as *{$turningAge} ans* aujourd'hui !",
            };
        } elseif ($daysUntil <= 7) {
            $mainMsg = match ($lang) {
                'en' => "🎂 Your birthday is *very soon* — in *{$daysUntil} day(s)*!\nYou'll be turning *{$turningAge}*.",
                'es' => "🎂 Tu cumpleaños es *muy pronto* — en *{$daysUntil} día(s)*!\nCumplirás *{$turningAge}*.",
                default => "🎂 Ton anniversaire est *très bientôt* — dans *{$daysUntil} jour(s)* !\nTu auras *{$turningAge} ans*.",
            };
        } elseif ($daysUntil <= 30) {
            $mainMsg = match ($lang) {
                'en' => "🎂 *{$daysUntil} days* until your birthday!\nYou'll be turning *{$turningAge}*.",
                'es' => "🎂 *{$daysUntil} días* para tu cumpleaños!\nCumplirás *{$turningAge}*.",
                default => "🎂 *{$daysUntil} jours* avant ton anniversaire !\nTu auras *{$turningAge} ans*.",
            };
        } else {
            $months = (int) floor($daysUntil / 30);
            $remDays = $daysUntil - ($months * 30);
            $mainMsg = match ($lang) {
                'en' => "📅 *{$daysUntil} days* (~{$months}mo {$remDays}d) until your birthday.\nYou'll be turning *{$turningAge}*.",
                'es' => "📅 *{$daysUntil} días* (~{$months}m {$remDays}d) para tu cumpleaños.\nCumplirás *{$turningAge}*.",
                default => "📅 *{$daysUntil} jours* (~{$months}m {$remDays}j) avant ton anniversaire.\nTu auras *{$turningAge} ans*.",
            };
        }

        $nextLabel = match ($lang) { 'en' => 'Next birthday', 'es' => 'Próximo cumpleaños', 'de' => 'Nächster Geburtstag', default => 'Prochain anniversaire' };
        $currAge   = match ($lang) { 'en' => 'Current age', 'es' => 'Edad actual', 'de' => 'Aktuelles Alter', default => 'Âge actuel' };
        $progLabel = match ($lang) { 'en' => 'Progress', 'es' => 'Progreso', 'de' => 'Fortschritt', default => 'Progression' };

        $lines = [
            "🎂 *{$title}*",
            "════════════════",
            "",
            $mainMsg,
            "",
            "📅 {$nextLabel} : *{$bdayDay} {$bdayFmt}*",
            "🎯 {$currAge} : *{$years}* " . ($lang === 'en' ? 'years' : ($lang === 'es' ? 'años' : 'ans')),
            "",
            "{$progLabel} : {$bar} {$pct}%",
            "",
            "════════════════",
        ];

        return AgentResult::reply(implode("\n", $lines), [
            'action'              => 'birthday_countdown',
            'days_until'          => $daysUntil,
            'turning_age'         => $turningAge,
        ]);
    }

    /**
     * Quick setup — guided wizard to help new users configure essential preferences.
     * Shows what's configured and what's missing, with suggested commands.
     */
    private function handleQuickSetup(array $prefs): AgentResult
    {
        $lang   = $prefs['language'] ?? 'fr';
        $dateFmt = $prefs['date_format'] ?? 'd/m/Y';

        $title = match ($lang) { 'en' => 'QUICK SETUP', 'es' => 'CONFIGURACIÓN RÁPIDA', 'de' => 'SCHNELLEINRICHTUNG', 'it' => 'CONFIGURAZIONE RAPIDA', 'pt' => 'CONFIGURAÇÃO RÁPIDA', default => 'CONFIGURATION RAPIDE' };

        $defaults = [
            'language'   => 'fr',
            'timezone'   => 'UTC',
            'date_format' => 'd/m/Y',
            'unit_system' => 'metric',
            'communication_style' => 'friendly',
            'email'      => null,
            'phone'      => null,
        ];

        $steps = [];
        $done  = 0;
        $total = count($defaults);

        foreach ($defaults as $key => $defaultVal) {
            $currentVal  = $prefs[$key] ?? $defaultVal;
            $isSet       = ($currentVal !== $defaultVal && $currentVal !== null && $currentVal !== '');
            $labelKey    = $this->formatKeyLabel($key, $lang);

            if ($isSet) {
                $done++;
                $formatted = $this->formatValue($key, $currentVal, $lang);
                $steps[] = "✅ {$labelKey} : *{$formatted}*";
            } else {
                $suggestion = match ($key) {
                    'language'   => match ($lang) { 'en' => '_set language en_', 'es' => '_set language es_', default => '_set language fr_' },
                    'timezone'   => '_timezone Europe/Paris_',
                    'date_format' => match ($lang) { 'en' => '_format américain_ or _format ISO_', default => '_aperçu date_ pour voir les options' },
                    'unit_system' => '_métrique_ ou _imperial_',
                    'communication_style' => match ($lang) { 'en' => '_style formal_ or _style concise_', default => '_style formel_ ou _style concis_' },
                    'email'      => match ($lang) { 'en' => '_my email user@example.com_', default => '_mon email user@exemple.com_' },
                    'phone'      => match ($lang) { 'en' => '_my phone +33612345678_', default => '_mon numéro +33612345678_' },
                    default      => '',
                };
                $steps[] = "⬜ {$labelKey} : " . ($suggestion !== '' ? $suggestion : match ($lang) { 'en' => '_not set_', 'es' => '_no configurado_', default => '_non configuré_' });
            }
        }

        $pct = ($total > 0) ? (int) round($done / $total * 100) : 0;
        $bar = $this->progressBar($pct);

        $completionLabel = match ($lang) { 'en' => 'Setup progress', 'es' => 'Progreso', 'de' => 'Fortschritt', default => 'Progression' };
        $tipLabel = match ($lang) { 'en' => 'Tip', 'es' => 'Consejo', 'de' => 'Tipp', default => 'Astuce' };
        $tip = match ($lang) {
            'en' => 'Set multiple at once: _language en and timezone America/New_York_',
            'es' => 'Configura varios a la vez: _idioma es y timezone America/Mexico_City_',
            'de' => 'Mehrere gleichzeitig setzen: _language de und timezone Europe/Berlin_',
            default => 'Configure plusieurs d\'un coup : _langue anglais et timezone New York_',
        };

        $lines = [
            "⚡ *{$title}*",
            "════════════════",
            "",
            "{$completionLabel} : {$bar} {$pct}% ({$done}/{$total})",
            "",
            ...$steps,
            "",
            "💡 *{$tipLabel}* : {$tip}",
            "",
            "════════════════",
        ];

        if ($pct === 100) {
            $congratsMsg = match ($lang) {
                'en' => "🎉 *All set!* Your profile is fully configured.",
                'es' => "🎉 *¡Listo!* Tu perfil está completamente configurado.",
                'de' => "🎉 *Fertig!* Dein Profil ist vollständig konfiguriert.",
                default => "🎉 *Bravo !* Ton profil est entièrement configuré.",
            };
            // Insert congrats before the closing separator
            array_splice($lines, -1, 0, [$congratsMsg, ""]);
        }

        return AgentResult::reply(implode("\n", $lines), [
            'action'      => 'quick_setup',
            'completion'  => $pct,
            'done'        => $done,
            'total'       => $total,
        ]);
    }

    // =========================================================================
    // NEW HANDLERS v1.56.0
    // =========================================================================

    /**
     * Work-Life Balance — score and recommendations based on current time,
     * work hours, and day of week.
     */
    private function handleWorkLifeBalance(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $hour    = (int) $now->format('G');
        $minute  = (int) $now->format('i');
        $isoDay  = (int) $now->format('N'); // 1=Mon, 7=Sun
        $decimal = $hour + ($minute / 60);

        // Work hours from parsed or default 9-18
        $workStart = 9;
        $workEnd   = 18;
        if (!empty($parsed['work_start'])) {
            $ws = $this->parseTimeString($parsed['work_start']);
            if ($ws) { $parts = explode(':', $ws); $workStart = (int) $parts[0]; }
        }
        if (!empty($parsed['work_end'])) {
            $we = $this->parseTimeString($parsed['work_end']);
            if ($we) { $parts = explode(':', $we); $workEnd = (int) $parts[0]; }
        }

        $workDuration = $workEnd - $workStart;
        $isWeekend    = $isoDay >= 6;
        $isWorkHours  = !$isWeekend && $decimal >= $workStart && $decimal < $workEnd;

        // Calculate balance score (0-100)
        $score = 50; // baseline
        if ($isWeekend) {
            $score += 30; // weekend bonus
            if ($hour >= 8 && $hour <= 22) $score += 10; // healthy waking hours
            if ($hour >= 0 && $hour < 7) $score += 10; // sleeping = good
        } else {
            // Weekday scoring
            if ($decimal < $workStart && $hour >= 6) $score += 15; // morning personal time
            if ($decimal >= $workEnd && $hour <= 22) $score += 20; // evening personal time
            if ($hour >= 23 || $hour < 6) $score -= 10; // late night penalty
            if ($workDuration <= 8) $score += 10; // reasonable work hours
            if ($workDuration > 10) $score -= 15; // overwork penalty
            if ($isWorkHours) $score += 5; // in normal work hours = structured
        }
        $score = max(0, min(100, $score));
        $bar   = $this->progressBar($score);

        // Status emoji and label
        [$statusEmoji, $statusLabel] = match (true) {
            $score >= 80 => ['🟢', match ($lang) { 'en' => 'Excellent', 'es' => 'Excelente', 'de' => 'Ausgezeichnet', default => 'Excellent' }],
            $score >= 60 => ['🟡', match ($lang) { 'en' => 'Good', 'es' => 'Bueno', 'de' => 'Gut', default => 'Bon' }],
            $score >= 40 => ['🟠', match ($lang) { 'en' => 'Fair', 'es' => 'Regular', 'de' => 'Mittel', default => 'Moyen' }],
            default      => ['🔴', match ($lang) { 'en' => 'Needs attention', 'es' => 'Necesita atención', 'de' => 'Achtung', default => 'À surveiller' }],
        };

        // Current activity
        $activity = match (true) {
            $isWeekend && $hour >= 6 && $hour < 12  => match ($lang) { 'en' => '☀️ Weekend morning — enjoy!', 'es' => '☀️ Mañana de fin de semana', default => '☀️ Matinée de weekend — profite !' },
            $isWeekend && $hour >= 12 && $hour < 18 => match ($lang) { 'en' => '🌤️ Weekend afternoon', 'es' => '🌤️ Tarde de fin de semana', default => '🌤️ Après-midi de weekend' },
            $isWeekend                               => match ($lang) { 'en' => '🌙 Weekend evening — time to relax', 'es' => '🌙 Noche de fin de semana', default => '🌙 Soirée de weekend — détends-toi' },
            $isWorkHours                             => match ($lang) { 'en' => '💼 Work hours — stay focused', 'es' => '💼 Horas de trabajo', default => '💼 Heures de travail — reste concentré' },
            $decimal < $workStart && $hour >= 5      => match ($lang) { 'en' => '🌅 Pre-work — personal time', 'es' => '🌅 Antes del trabajo', default => '🌅 Avant le travail — temps perso' },
            $decimal >= $workEnd && $hour <= 22      => match ($lang) { 'en' => '🏠 After work — unwind', 'es' => '🏠 Después del trabajo', default => '🏠 Après le travail — décompresse' },
            default                                  => match ($lang) { 'en' => '🌙 Late night — consider resting', 'es' => '🌙 Noche — descansa', default => '🌙 Nuit — pense à te reposer' },
        };

        // Recommendations
        $tips = [];
        if ($hour >= 23 || $hour < 5) {
            $tips[] = match ($lang) { 'en' => '😴 Get some sleep — rest improves productivity tomorrow', 'es' => '😴 Duerme — el descanso mejora la productividad', default => '😴 Va dormir — le repos améliore ta productivité demain' };
        }
        if ($isWorkHours && $decimal > $workStart + 2 && ($minute % 60) < 30) {
            $tips[] = match ($lang) { 'en' => '☕ Good time for a short break', 'es' => '☕ Buen momento para un descanso', default => '☕ Bon moment pour une courte pause' };
        }
        if (!$isWeekend && $decimal >= $workEnd && $hour <= 20) {
            $tips[] = match ($lang) { 'en' => '🏃 Great time for exercise or a walk', 'es' => '🏃 Buen momento para ejercicio', default => '🏃 Bon moment pour du sport ou une balade' };
        }
        if ($workDuration > 9) {
            $tips[] = match ($lang) { 'en' => '⚠️ Long work hours — consider shorter days', 'es' => '⚠️ Jornada larga — considera reducir', default => '⚠️ Longue journée de travail — envisage de réduire' };
        }
        if (empty($tips)) {
            $tips[] = match ($lang) { 'en' => '✨ Keep up the balance!', 'es' => '✨ ¡Sigue así!', default => '✨ Continue comme ça !' };
        }

        $title = match ($lang) { 'en' => 'WORK-LIFE BALANCE', 'es' => 'EQUILIBRIO VIDA-TRABAJO', 'de' => 'WORK-LIFE-BALANCE', default => 'ÉQUILIBRE VIE-TRAVAIL' };
        $scoreLabel = match ($lang) { 'en' => 'Balance score', 'es' => 'Puntuación', 'de' => 'Punktzahl', default => 'Score d\'équilibre' };
        $nowLabel = match ($lang) { 'en' => 'Right now', 'es' => 'Ahora', 'de' => 'Jetzt', default => 'En ce moment' };
        $tipsLabel = match ($lang) { 'en' => 'Recommendations', 'es' => 'Recomendaciones', 'de' => 'Empfehlungen', default => 'Recommandations' };
        $hoursLabel = match ($lang) { 'en' => 'Work hours', 'es' => 'Horario', 'de' => 'Arbeitszeit', default => 'Horaires de travail' };

        $tipsFormatted = implode("\n", array_map(fn ($t) => "  - {$t}", $tips));
        $dayLabel = $this->getDayName($isoDay % 7, $lang);
        $timeStr  = $now->format('H:i');

        $lines = [
            "⚖️ *{$title}*",
            "════════════════",
            "",
            "{$statusEmoji} {$scoreLabel} : {$bar} *{$score}%* — {$statusLabel}",
            "",
            "🕐 {$dayLabel} {$timeStr} ({$userTzStr})",
            "📋 {$hoursLabel} : {$workStart}h — {$workEnd}h",
            "",
            "📍 {$nowLabel} : {$activity}",
            "",
            "💡 *{$tipsLabel}* :",
            $tipsFormatted,
            "",
            "════════════════",
        ];

        return AgentResult::reply(implode("\n", $lines), [
            'action' => 'work_life_balance',
            'score'  => $score,
            'status' => $statusLabel,
        ]);
    }

    /**
     * Timezone Quiz — generates a random timezone question for the user.
     */
    private function handleTimezoneQuiz(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $userTz = new DateTimeZone($userTzStr);
            $now    = new DateTimeImmutable('now', $userTz);
        } catch (\Exception) {
            $userTz = new DateTimeZone('UTC');
            $now    = new DateTimeImmutable('now', $userTz);
        }

        // Pick random cities for questions
        $quizCities = [
            'Tokyo'     => 'Asia/Tokyo',
            'New York'  => 'America/New_York',
            'London'    => 'Europe/London',
            'Dubai'     => 'Asia/Dubai',
            'Sydney'    => 'Australia/Sydney',
            'São Paulo' => 'America/Sao_Paulo',
            'Mumbai'    => 'Asia/Kolkata',
            'Berlin'    => 'Europe/Berlin',
            'Seoul'     => 'Asia/Seoul',
            'Cairo'     => 'Africa/Cairo',
            'Mexico'    => 'America/Mexico_City',
            'Bangkok'   => 'Asia/Bangkok',
        ];

        $cityNames = array_keys($quizCities);
        shuffle($cityNames);
        $city1 = $cityNames[0];
        $city2 = $cityNames[1];
        $city3 = $cityNames[2];
        $tz1   = new DateTimeZone($quizCities[$city1]);
        $tz2   = new DateTimeZone($quizCities[$city2]);
        $tz3   = new DateTimeZone($quizCities[$city3]);

        $time1 = new DateTimeImmutable('now', $tz1);
        $time2 = new DateTimeImmutable('now', $tz2);
        $time3 = new DateTimeImmutable('now', $tz3);

        // Generate 3 different question types
        $questionType = random_int(1, 4);

        switch ($questionType) {
            case 1:
                // "If it's Xh in City1, what time is it in City2?"
                $refTime = $time1->format('H:i');
                $answer  = $time2->format('H:i');
                $question = match ($lang) {
                    'en' => "If it's *{$refTime}* in *{$city1}*, what time is it in *{$city2}*?",
                    'es' => "Si son las *{$refTime}* en *{$city1}*, ¿qué hora es en *{$city2}*?",
                    'de' => "Wenn es *{$refTime}* in *{$city1}* ist, wie spät ist es in *{$city2}*?",
                    default => "S'il est *{$refTime}* à *{$city1}*, quelle heure est-il à *{$city2}* ?",
                };
                $answerText = match ($lang) {
                    'en' => "The answer is *{$answer}* in {$city2}",
                    'es' => "La respuesta es *{$answer}* en {$city2}",
                    default => "La réponse est *{$answer}* à {$city2}",
                };
                break;

            case 2:
                // "What's the time difference between City1 and City2?"
                $offset1 = $tz1->getOffset($now) / 3600;
                $offset2 = $tz2->getOffset($now) / 3600;
                $diff    = abs($offset1 - $offset2);
                $diffH   = (int) $diff;
                $diffM   = (int) (($diff - $diffH) * 60);
                $diffStr = $diffM > 0 ? "{$diffH}h{$diffM}m" : "{$diffH}h";
                $ahead   = $offset1 > $offset2 ? $city1 : $city2;
                $question = match ($lang) {
                    'en' => "What is the time difference between *{$city1}* and *{$city2}*?",
                    'es' => "¿Cuál es la diferencia horaria entre *{$city1}* y *{$city2}*?",
                    'de' => "Wie groß ist der Zeitunterschied zwischen *{$city1}* und *{$city2}*?",
                    default => "Quel est le décalage horaire entre *{$city1}* et *{$city2}* ?",
                };
                $answerText = match ($lang) {
                    'en' => "The difference is *{$diffStr}* — {$ahead} is ahead",
                    'es' => "La diferencia es *{$diffStr}* — {$ahead} va adelante",
                    default => "Le décalage est de *{$diffStr}* — {$ahead} est en avance",
                };
                break;

            case 3:
                // "Which of these 3 cities is earliest right now?"
                $times = [
                    $city1 => (int) $time1->format('G') * 60 + (int) $time1->format('i'),
                    $city2 => (int) $time2->format('G') * 60 + (int) $time2->format('i'),
                    $city3 => (int) $time3->format('G') * 60 + (int) $time3->format('i'),
                ];
                // Earliest = smallest time value (but handle day boundaries)
                asort($times);
                $earliest = array_key_first($times);
                $earliestTime = (new DateTimeImmutable('now', new DateTimeZone($quizCities[$earliest])))->format('H:i');
                $question = match ($lang) {
                    'en' => "Which city has the earliest time right now: *{$city1}*, *{$city2}*, or *{$city3}*?",
                    'es' => "¿Qué ciudad tiene la hora más temprana ahora: *{$city1}*, *{$city2}* o *{$city3}*?",
                    'de' => "Welche Stadt hat gerade die früheste Uhrzeit: *{$city1}*, *{$city2}* oder *{$city3}*?",
                    default => "Quelle ville a l'heure la plus matinale en ce moment : *{$city1}*, *{$city2}* ou *{$city3}* ?",
                };
                $answerText = match ($lang) {
                    'en' => "It's *{$earliest}* with *{$earliestTime}*",
                    'es' => "Es *{$earliest}* con *{$earliestTime}*",
                    default => "C'est *{$earliest}* avec *{$earliestTime}*",
                };
                break;

            default:
                // "Is it day or night in City1 right now?"
                $hour1 = (int) $time1->format('G');
                $isDayTime = $hour1 >= 6 && $hour1 < 20;
                $question = match ($lang) {
                    'en' => "Is it currently day ☀️ or night 🌙 in *{$city1}*? (current time there: guess!)",
                    'es' => "¿Es de día ☀️ o de noche 🌙 en *{$city1}*? (hora actual: ¡adivina!)",
                    'de' => "Ist es gerade Tag ☀️ oder Nacht 🌙 in *{$city1}*?",
                    default => "Est-il actuellement jour ☀️ ou nuit 🌙 à *{$city1}* ? (devine !)",
                };
                $dayNight = $isDayTime
                    ? match ($lang) { 'en' => '☀️ Day', 'es' => '☀️ Día', default => '☀️ Jour' }
                    : match ($lang) { 'en' => '🌙 Night', 'es' => '🌙 Noche', default => '🌙 Nuit' };
                $answerText = match ($lang) {
                    'en' => "It's *{$dayNight}* in {$city1} — it's {$time1->format('H:i')} there",
                    'es' => "Es *{$dayNight}* en {$city1} — son las {$time1->format('H:i')}",
                    default => "Il fait *{$dayNight}* à {$city1} — il y est {$time1->format('H:i')}",
                };
                break;
        }

        $title = match ($lang) { 'en' => 'TIMEZONE QUIZ', 'es' => 'QUIZ DE HUSOS HORARIOS', 'de' => 'ZEITZONEN-QUIZ', default => 'QUIZ FUSEAUX HORAIRES' };
        $answerLabel = match ($lang) { 'en' => 'Answer (tap to reveal)', 'es' => 'Respuesta', 'de' => 'Antwort', default => 'Réponse' };
        $funFact = match ($lang) {
            'en' => '_Did you know? There are 38 different UTC offsets in use worldwide, including half-hour and 45-minute offsets!_',
            'es' => '_¿Sabías? Hay 38 offsets UTC diferentes en uso, incluyendo de media hora y 45 minutos._',
            'de' => '_Wusstest du? Es gibt 38 verschiedene UTC-Offsets weltweit, einschließlich Halb- und Dreiviertelstunden!_',
            default => '_Le savais-tu ? Il existe 38 décalages UTC différents dans le monde, dont des décalages de 30 et 45 minutes !_',
        };

        $lines = [
            "🧠 *{$title}*",
            "════════════════",
            "",
            "❓ {$question}",
            "",
            "🤔 ...",
            "",
            "✅ *{$answerLabel}* :",
            $answerText,
            "",
            $funFact,
            "",
            "════════════════",
        ];

        return AgentResult::reply(implode("\n", $lines), [
            'action'        => 'timezone_quiz',
            'question_type' => $questionType,
        ]);
    }

    // =========================================================================
    // v1.57.0 — Preferences Suggestions
    // =========================================================================

    private function handlePreferencesSuggestions(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $suggestions = [];
        $score       = 0;
        $maxScore    = 0;

        // Check timezone is set (not UTC default)
        $maxScore += 20;
        if ($userTzStr !== 'UTC') {
            $score += 20;
        } else {
            $suggestions[] = match ($lang) {
                'en' => '🌍 Set your timezone for accurate local times → _timezone Europe/Paris_',
                'es' => '🌍 Configura tu zona horaria para horas precisas → _timezone Europe/Paris_',
                'de' => '🌍 Setze deine Zeitzone für genaue Uhrzeiten → _timezone Europe/Paris_',
                default => '🌍 Définis ton fuseau horaire pour des heures précises → _timezone Europe/Paris_',
            };
        }

        // Check language is explicitly set
        $maxScore += 15;
        if (($prefs['language'] ?? 'fr') !== 'fr' || isset($prefs['language'])) {
            $score += 15;
        } else {
            $suggestions[] = match ($lang) {
                'en' => '🗣️ Set your preferred language → _set language en_',
                'es' => '🗣️ Configura tu idioma → _set language es_',
                default => '🗣️ Confirme ta langue préférée → _set language fr_',
            };
        }

        // Check date format
        $maxScore += 15;
        if (!empty($prefs['date_format']) && $prefs['date_format'] !== 'd/m/Y') {
            $score += 15;
        } elseif (!empty($prefs['date_format'])) {
            $score += 10;
        } else {
            $suggestions[] = match ($lang) {
                'en' => '📅 Choose your date format → _preview date_ to compare options',
                'es' => '📅 Elige tu formato de fecha → _preview date_ para comparar',
                default => '📅 Choisis ton format de date → _apercu date_ pour comparer',
            };
        }

        // Check communication style
        $maxScore += 15;
        if (!empty($prefs['communication_style']) && $prefs['communication_style'] !== 'friendly') {
            $score += 15;
        } elseif (!empty($prefs['communication_style'])) {
            $score += 10;
        } else {
            $suggestions[] = match ($lang) {
                'en' => '💬 Set your communication style → _style concise_ or _style formal_',
                'es' => '💬 Define tu estilo de comunicación → _style concise_ o _style formal_',
                default => '💬 Définis ton style de communication → _style concis_ ou _style formel_',
            };
        }

        // Check email
        $maxScore += 10;
        if (!empty($prefs['email'])) {
            $score += 10;
        } else {
            $suggestions[] = match ($lang) {
                'en' => '📧 Add your email for a complete profile → _set email your@email.com_',
                'es' => '📧 Agrega tu correo → _set email tu@correo.com_',
                default => '📧 Ajoute ton email pour compléter ton profil → _set email ton@email.com_',
            };
        }

        // Check phone
        $maxScore += 10;
        if (!empty($prefs['phone'])) {
            $score += 10;
        } else {
            $suggestions[] = match ($lang) {
                'en' => '📱 Add your phone number → _set phone +33612345678_',
                'es' => '📱 Agrega tu teléfono → _set phone +34612345678_',
                default => '📱 Ajoute ton numéro → _set phone +33612345678_',
            };
        }

        // Check unit system
        $maxScore += 10;
        if (!empty($prefs['unit_system'])) {
            $score += 10;
        } else {
            $suggestions[] = match ($lang) {
                'en' => '📏 Set your unit system → _metric_ or _imperial_',
                'es' => '📏 Configura tu sistema de unidades → _metric_ o _imperial_',
                default => '📏 Choisis ton système d\'unités → _metric_ ou _imperial_',
            };
        }

        // Contextual suggestions based on time
        $hour = (int) $now->format('G');
        if ($hour >= 22 || $hour < 6) {
            $suggestions[] = match ($lang) {
                'en' => '🌙 _Tip: Try the *morning routine* command tomorrow to start your day right!_',
                'es' => '🌙 _Consejo: Prueba *morning routine* mañana para empezar bien el día._',
                default => '🌙 _Astuce : Essaie la commande *routine matinale* demain pour bien démarrer ta journée !_',
            };
        } elseif ($hour >= 8 && $hour < 10) {
            $suggestions[] = match ($lang) {
                'en' => '☀️ _Tip: Use *daily summary* for a complete morning briefing!_',
                'es' => '☀️ _Consejo: Usa *daily summary* para un resumen matutino._',
                default => '☀️ _Astuce : Utilise *résumé journée* pour un briefing matinal complet !_',
            };
        }

        $pct = $maxScore > 0 ? (int) round(($score / $maxScore) * 100) : 0;
        $bar = $this->progressBar($pct);

        [$statusEmoji, $statusLabel] = match (true) {
            $pct >= 90 => ['🏆', match ($lang) { 'en' => 'Complete!', 'es' => '¡Completo!', default => 'Complet !' }],
            $pct >= 70 => ['🟢', match ($lang) { 'en' => 'Well configured', 'es' => 'Bien configurado', default => 'Bien configuré' }],
            $pct >= 40 => ['🟡', match ($lang) { 'en' => 'Partially configured', 'es' => 'Parcialmente configurado', default => 'Partiellement configuré' }],
            default    => ['🔴', match ($lang) { 'en' => 'Needs setup', 'es' => 'Necesita configuración', default => 'À configurer' }],
        };

        $title = match ($lang) {
            'en' => 'PROFILE SUGGESTIONS', 'es' => 'SUGERENCIAS DE PERFIL', 'de' => 'PROFILVORSCHLÄGE', default => 'SUGGESTIONS DE PROFIL',
        };
        $scoreLabel = match ($lang) {
            'en' => 'Profile score', 'es' => 'Puntuación', default => 'Score profil',
        };

        $lines = [
            "💡 *{$title}*",
            "════════════════",
            "",
            "{$statusEmoji} {$scoreLabel} : {$bar} *{$pct}%* — {$statusLabel}",
            "",
        ];

        if (empty($suggestions)) {
            $perfect = match ($lang) {
                'en' => '✅ Your profile is fully optimized! Nothing to suggest.',
                'es' => '✅ ¡Tu perfil está completamente optimizado!',
                default => '✅ Ton profil est entièrement optimisé ! Rien à suggérer.',
            };
            $lines[] = $perfect;
        } else {
            $sugLabel = match ($lang) {
                'en' => 'Suggestions', 'es' => 'Sugerencias', default => 'Suggestions',
            };
            $lines[] = "📋 *{$sugLabel}* :";
            foreach ($suggestions as $s) {
                $lines[] = "  • {$s}";
            }
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), [
            'action' => 'preferences_suggestions',
            'score'  => $pct,
            'suggestions_count' => count($suggestions),
        ]);
    }

    // =========================================================================
    // v1.57.0 — Availability Now
    // =========================================================================

    private function handleAvailabilityNow(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $userTz = new DateTimeZone($userTzStr);
            $now    = new DateTimeImmutable('now', $userTz);
        } catch (\Exception) {
            $userTz = new DateTimeZone('UTC');
            $now    = new DateTimeImmutable('now', $userTz);
        }

        $businessCities = [
            'London'      => 'Europe/London',
            'Paris'       => 'Europe/Paris',
            'Berlin'      => 'Europe/Berlin',
            'Moscow'      => 'Europe/Moscow',
            'Dubai'       => 'Asia/Dubai',
            'Mumbai'      => 'Asia/Kolkata',
            'Singapore'   => 'Asia/Singapore',
            'Tokyo'       => 'Asia/Tokyo',
            'Sydney'      => 'Australia/Sydney',
            'New York'    => 'America/New_York',
            'Chicago'     => 'America/Chicago',
            'Los Angeles' => 'America/Los_Angeles',
            'São Paulo'   => 'America/Sao_Paulo',
        ];

        $available   = [];
        $closingSoon = [];
        $closed      = [];

        foreach ($businessCities as $city => $tzId) {
            try {
                $cityTz   = new DateTimeZone($tzId);
                $cityNow  = new DateTimeImmutable('now', $cityTz);
                $cityHour = (int) $cityNow->format('G');
                $cityMin  = (int) $cityNow->format('i');
                $decimal  = $cityHour + ($cityMin / 60);
                $isoDay   = (int) $cityNow->format('N');
                $timeStr  = $cityNow->format('H:i');

                if ($isoDay >= 6) {
                    // Weekend
                    $closed[] = "  🔴 *{$city}* — {$timeStr} " . match ($lang) {
                        'en' => '(weekend)', 'es' => '(fin de semana)', 'de' => '(Wochenende)', default => '(weekend)',
                    };
                } elseif ($decimal >= 9 && $decimal < 17) {
                    // Open — within 9-17
                    $remaining = 17 - $decimal;
                    $remH = (int) $remaining;
                    $remM = (int) (($remaining - $remH) * 60);
                    $remStr = $remM > 0 ? "{$remH}h{$remM}m" : "{$remH}h";

                    if ($remaining <= 1.5) {
                        $closingSoon[] = "  🟡 *{$city}* — {$timeStr} ⏳ " . match ($lang) {
                            'en' => "closing in {$remStr}",
                            'es' => "cierra en {$remStr}",
                            'de' => "schließt in {$remStr}",
                            default => "ferme dans {$remStr}",
                        };
                    } else {
                        $available[] = "  🟢 *{$city}* — {$timeStr} ✓ " . match ($lang) {
                            'en' => "{$remStr} left",
                            'es' => "{$remStr} restantes",
                            'de' => "noch {$remStr}",
                            default => "{$remStr} restant",
                        };
                    }
                } elseif ($decimal >= 17 && $decimal < 18) {
                    $closingSoon[] = "  🟡 *{$city}* — {$timeStr} " . match ($lang) {
                        'en' => '(closing time)', 'es' => '(hora de cierre)', default => '(fin de journée)',
                    };
                } else {
                    $closed[] = "  🔴 *{$city}* — {$timeStr} " . match ($lang) {
                        'en' => '(closed)', 'es' => '(cerrado)', 'de' => '(geschlossen)', default => '(fermé)',
                    };
                }
            } catch (\Exception) {
                continue;
            }
        }

        $title = match ($lang) {
            'en' => 'GLOBAL AVAILABILITY', 'es' => 'DISPONIBILIDAD GLOBAL', 'de' => 'GLOBALE VERFÜGBARKEIT', default => 'DISPONIBILITÉ MONDIALE',
        };
        $yourTime = match ($lang) {
            'en' => 'Your time', 'es' => 'Tu hora', 'de' => 'Deine Zeit', default => 'Ton heure',
        };

        $lines = [
            "🌐 *{$title}*",
            "════════════════",
            "",
            "🕐 {$yourTime} : *{$now->format('H:i')}* ({$userTzStr})",
            "",
        ];

        if (!empty($available)) {
            $openLabel = match ($lang) { 'en' => 'Open for business', 'es' => 'Abiertos', 'de' => 'Geöffnet', default => 'Ouvert' };
            $lines[] = "✅ *{$openLabel}* :";
            array_push($lines, ...$available);
            $lines[] = "";
        }

        if (!empty($closingSoon)) {
            $soonLabel = match ($lang) { 'en' => 'Closing soon', 'es' => 'Cierra pronto', 'de' => 'Schließt bald', default => 'Ferme bientôt' };
            $lines[] = "⏳ *{$soonLabel}* :";
            array_push($lines, ...$closingSoon);
            $lines[] = "";
        }

        if (!empty($closed)) {
            $closedLabel = match ($lang) { 'en' => 'Closed', 'es' => 'Cerrados', 'de' => 'Geschlossen', default => 'Fermé' };
            $lines[] = "💤 *{$closedLabel}* :";
            array_push($lines, ...$closed);
            $lines[] = "";
        }

        $totalOpen = count($available) + count($closingSoon);
        $summary = match ($lang) {
            'en' => "_{$totalOpen} of " . count($businessCities) . " major cities currently available for calls_",
            'es' => "_{$totalOpen} de " . count($businessCities) . " ciudades principales disponibles para llamadas_",
            'de' => "_{$totalOpen} von " . count($businessCities) . " Großstädten erreichbar_",
            default => "_{$totalOpen} sur " . count($businessCities) . " grandes villes actuellement joignables_",
        };
        $lines[] = $summary;
        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), [
            'action'         => 'availability_now',
            'available'      => count($available),
            'closing_soon'   => count($closingSoon),
            'closed'         => count($closed),
        ]);
    }

    // =========================================================================
    // NEW HANDLERS v1.58.0
    // =========================================================================

    /**
     * Productivity Planner — actionable rest-of-day plan based on current time,
     * energy level, day of week, and workday progress.
     */
    private function handleProductivityPlanner(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $tz  = new DateTimeZone($userTzStr);
            $now = new DateTimeImmutable('now', $tz);
        } catch (\Exception) {
            $tz  = new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        }

        $hour    = (int) $now->format('G');
        $minute  = (int) $now->format('i');
        $isoDay  = (int) $now->format('N');
        $decimal = $hour + ($minute / 60);
        $dayName = $this->getDayName((int) $now->format('w'), $lang);

        $isWeekend  = $isoDay >= 6;
        $workStart  = 9;
        $workEnd    = 18;
        $workHoursLeft = max(0, $workEnd - $decimal);

        // Build time slots for the rest of the day
        $slots = [];
        $slotStart = max($hour + 1, (int) ceil($decimal));

        for ($h = $slotStart; $h <= 22 && count($slots) < 6; $h++) {
            [$task, $emoji, $intensity] = match (true) {
                $h >= 6 && $h < 8   => [
                    match ($lang) { 'en' => 'Morning routine, planning', 'es' => 'Rutina matinal, planificación', default => 'Routine matinale, planification' },
                    '🌅', 'low',
                ],
                $h >= 8 && $h < 10  => [
                    match ($lang) { 'en' => 'Deep work — complex tasks', 'es' => 'Trabajo profundo — tareas complejas', default => 'Travail profond — tâches complexes' },
                    '⚡', 'high',
                ],
                $h >= 10 && $h < 12 => [
                    match ($lang) { 'en' => 'Meetings & collaboration', 'es' => 'Reuniones y colaboración', default => 'Réunions et collaboration' },
                    '🤝', 'high',
                ],
                $h >= 12 && $h < 13 => [
                    match ($lang) { 'en' => 'Lunch break', 'es' => 'Pausa para comer', default => 'Pause déjeuner' },
                    '🍽️', 'break',
                ],
                $h >= 13 && $h < 14 => [
                    match ($lang) { 'en' => 'Light tasks, emails', 'es' => 'Tareas ligeras, emails', default => 'Tâches légères, emails' },
                    '📧', 'low',
                ],
                $h >= 14 && $h < 16 => [
                    match ($lang) { 'en' => 'Focused work — follow-ups', 'es' => 'Trabajo enfocado — seguimientos', default => 'Travail ciblé — suivis' },
                    '🎯', 'medium',
                ],
                $h >= 16 && $h < 18 => [
                    match ($lang) { 'en' => 'Creative work, brainstorming', 'es' => 'Trabajo creativo, lluvia de ideas', default => 'Travail créatif, brainstorming' },
                    '💡', 'medium',
                ],
                $h >= 18 && $h < 19 => [
                    match ($lang) { 'en' => 'Wrap up, plan tomorrow', 'es' => 'Cierre, planificar mañana', default => 'Clôture, planifier demain' },
                    '📋', 'low',
                ],
                $h >= 19 && $h < 21 => [
                    match ($lang) { 'en' => 'Personal time, exercise', 'es' => 'Tiempo personal, ejercicio', default => 'Temps personnel, sport' },
                    '🏃', 'personal',
                ],
                default => [
                    match ($lang) { 'en' => 'Wind down, relax', 'es' => 'Relajarse', default => 'Décompresser, détente' },
                    '🌙', 'rest',
                ],
            };

            if ($isWeekend) {
                [$task, $emoji] = match (true) {
                    $h >= 8 && $h < 10  => [match ($lang) { 'en' => 'Morning — personal projects', 'es' => 'Mañana — proyectos personales', default => 'Matin — projets personnels' }, '☀️'],
                    $h >= 10 && $h < 12 => [match ($lang) { 'en' => 'Exercise, hobbies', 'es' => 'Ejercicio, hobbies', default => 'Sport, loisirs' }, '🏃'],
                    $h >= 12 && $h < 14 => [match ($lang) { 'en' => 'Lunch, socializing', 'es' => 'Comida, socialización', default => 'Déjeuner, social' }, '🍽️'],
                    $h >= 14 && $h < 17 => [match ($lang) { 'en' => 'Learning, side projects', 'es' => 'Aprendizaje, proyectos', default => 'Apprentissage, side projects' }, '📚'],
                    $h >= 17 && $h < 20 => [match ($lang) { 'en' => 'Socializing, entertainment', 'es' => 'Socialización, entretenimiento', default => 'Social, divertissement' }, '🎭'],
                    default             => [match ($lang) { 'en' => 'Relax, rest', 'es' => 'Descansar', default => 'Repos, détente' }, '🌙'],
                };
                $intensity = 'personal';
            }

            $slots[] = "  {$emoji} *{$h}h-" . ($h + 1) . "h* : {$task}";
        }

        // Energy level
        [$energyEmoji, $energyPct] = match (true) {
            $hour >= 8 && $hour < 10  => ['⚡', 90],
            $hour >= 10 && $hour < 12 => ['🔥', 85],
            $hour >= 12 && $hour < 14 => ['😴', 50],
            $hour >= 14 && $hour < 16 => ['☕', 65],
            $hour >= 16 && $hour < 18 => ['💪', 75],
            $hour >= 18 && $hour < 20 => ['🌆', 55],
            default                    => ['🌙', 30],
        };
        $energyBar = $this->progressBar($energyPct);

        // Workday progress
        $workPct = 0;
        if (!$isWeekend && $decimal >= $workStart) {
            $workPct = min(100, (int) round(($decimal - $workStart) / ($workEnd - $workStart) * 100));
        }
        $workBar = $this->progressBar($workPct);

        $title = match ($lang) { 'en' => 'PRODUCTIVITY PLANNER', 'es' => 'PLANIFICADOR DE PRODUCTIVIDAD', 'de' => 'PRODUKTIVITÄTSPLANER', default => 'PLANIFICATEUR PRODUCTIVITÉ' };
        $energyLabel = match ($lang) { 'en' => 'Energy', 'es' => 'Energía', 'de' => 'Energie', default => 'Énergie' };
        $workLabel = match ($lang) { 'en' => 'Workday', 'es' => 'Jornada', 'de' => 'Arbeitstag', default => 'Journée' };
        $planLabel = match ($lang) { 'en' => 'Recommended plan', 'es' => 'Plan recomendado', 'de' => 'Empfohlener Plan', default => 'Plan recommandé' };

        $lines = [
            "📋 *{$title}*",
            "════════════════",
            "",
            "📅 *{$dayName}* — {$now->format('H:i')} ({$userTzStr})",
            "",
            "{$energyEmoji} {$energyLabel} : {$energyBar} {$energyPct}%",
        ];

        if (!$isWeekend) {
            $workHLeft = max(0, round($workHoursLeft, 1));
            $workRemaining = match ($lang) {
                'en' => "{$workHLeft}h remaining", 'es' => "{$workHLeft}h restantes", default => "{$workHLeft}h restantes",
            };
            $lines[] = "💼 {$workLabel} : {$workBar} {$workPct}% — _{$workRemaining}_";
        }

        $lines[] = "";
        $lines[] = "🗓️ *{$planLabel}* :";
        $lines[] = "";

        if (empty($slots)) {
            $noSlots = match ($lang) {
                'en' => '_No more time slots today. Rest well!_',
                'es' => '_No hay más franjas horarias hoy. ¡Descansa bien!_',
                default => '_Plus de créneaux aujourd\'hui. Bonne soirée !_',
            };
            $lines[] = $noSlots;
        } else {
            array_push($lines, ...$slots);
        }

        // Tip of the day based on context
        $tip = match (true) {
            $isWeekend => match ($lang) { 'en' => 'Weekend mode — prioritize rest and personal growth', 'es' => 'Modo fin de semana — prioriza el descanso', default => 'Mode weekend — privilégie le repos et le développement perso' },
            $hour < 9  => match ($lang) { 'en' => 'Start with your hardest task while energy is peak', 'es' => 'Empieza con la tarea más difícil', default => 'Commence par ta tâche la plus difficile tant que l\'énergie est au max' },
            $hour >= 12 && $hour < 14 => match ($lang) { 'en' => 'Post-lunch dip — do light tasks or take a walk', 'es' => 'Bajón post-comida — tareas ligeras o paseo', default => 'Creux post-déjeuner — tâches légères ou marche rapide' },
            $hour >= 16 => match ($lang) { 'en' => 'Plan tomorrow\'s priorities before you leave', 'es' => 'Planifica las prioridades de mañana', default => 'Planifie les priorités de demain avant de partir' },
            default    => match ($lang) { 'en' => 'Use the Pomodoro technique: 25min focus + 5min break', 'es' => 'Usa la técnica Pomodoro: 25min + 5min pausa', default => 'Utilise la technique Pomodoro : 25min focus + 5min pause' },
        };

        $lines[] = "";
        $tipLabel = match ($lang) { 'en' => 'Tip', 'es' => 'Consejo', 'de' => 'Tipp', default => 'Astuce' };
        $lines[] = "💡 *{$tipLabel}* : _{$tip}_";
        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), [
            'action'      => 'productivity_planner',
            'energy_pct'  => $energyPct,
            'work_pct'    => $workPct,
            'slots_count' => count($slots),
            'is_weekend'  => $isWeekend,
        ]);
    }

    /**
     * Timezone Friendship — comprehensive comparison between user's timezone
     * and a target city: time difference, shared business hours, best call
     * window, current status, and communication tips.
     */
    private function handleTimezoneFriendship(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';
        $target    = $parsed['target'] ?? '';

        if (empty($target)) {
            $msg = match ($lang) {
                'en' => "🤝 Please specify a city. Example: *timezone friendship Tokyo*",
                'es' => "🤝 Especifica una ciudad. Ejemplo: *timezone friendship Tokyo*",
                default => "🤝 Précise une ville. Exemple : *amitié fuseau Tokyo*",
            };
            return AgentResult::reply($msg, ['action' => 'timezone_friendship', 'error' => 'missing_target']);
        }

        try {
            $userTz  = new DateTimeZone($userTzStr);
            $userNow = new DateTimeImmutable('now', $userTz);
        } catch (\Exception) {
            $userTz  = new DateTimeZone('UTC');
            $userNow = new DateTimeImmutable('now', $userTz);
        }

        // Resolve target city to timezone
        $targetTzStr = null;
        $targetLabel = $target;
        $lower = mb_strtolower(trim($target));

        // Try CITY_TIMEZONE_MAP first
        foreach (self::CITY_TIMEZONE_MAP as $city => $ianaTz) {
            if (mb_strtolower($city) === $lower) {
                $targetTzStr = $ianaTz;
                $targetLabel = $city;
                break;
            }
        }
        // Try partial match
        if (!$targetTzStr) {
            foreach (self::CITY_TIMEZONE_MAP as $city => $ianaTz) {
                if (str_contains(mb_strtolower($city), $lower)) {
                    $targetTzStr = $ianaTz;
                    $targetLabel = $city;
                    break;
                }
            }
        }
        // Try as direct IANA timezone
        if (!$targetTzStr) {
            try {
                new DateTimeZone($target);
                $targetTzStr = $target;
            } catch (\Exception) {
                // Not a valid timezone
            }
        }
        // Try TIMEZONE_ALIASES
        if (!$targetTzStr) {
            $upperTarget = strtoupper($target);
            if (isset(self::TIMEZONE_ALIASES[$upperTarget])) {
                $targetTzStr = self::TIMEZONE_ALIASES[$upperTarget];
            }
        }

        if (!$targetTzStr) {
            $msg = match ($lang) {
                'en' => "❌ City not found: *{$target}*. Try a major city name like Tokyo, London, New York.",
                'es' => "❌ Ciudad no encontrada: *{$target}*. Prueba con una ciudad como Tokyo, London, New York.",
                default => "❌ Ville non trouvée : *{$target}*. Essaie une grande ville comme Tokyo, Londres, New York.",
            };
            return AgentResult::reply($msg, ['action' => 'timezone_friendship', 'error' => 'city_not_found']);
        }

        try {
            $targetTz  = new DateTimeZone($targetTzStr);
            $targetNow = new DateTimeImmutable('now', $targetTz);
        } catch (\Exception) {
            $msg = match ($lang) {
                'en' => "❌ Could not resolve timezone for *{$targetLabel}*.",
                default => "❌ Impossible de résoudre le fuseau horaire pour *{$targetLabel}*.",
            };
            return AgentResult::reply($msg, ['action' => 'timezone_friendship', 'error' => 'tz_resolve_failed']);
        }

        // Calculate offset difference
        $userOffset   = $userTz->getOffset($userNow);
        $targetOffset = $targetTz->getOffset($targetNow);
        $diffSeconds  = $targetOffset - $userOffset;
        $diffHours    = $diffSeconds / 3600;
        $diffSign     = $diffHours >= 0 ? '+' : '';
        $diffLabel    = $diffSign . $diffHours . 'h';

        // Business hours overlap (9-18 local in each TZ)
        $sharedStart = max(9 + ($diffHours > 0 ? $diffHours : 0), 9);
        $sharedEnd   = min(18 + ($diffHours < 0 ? $diffHours : 0), 18);
        $sharedEnd   = max($sharedStart, min(18, $sharedEnd));
        if ($sharedEnd > 18) $sharedEnd = 18;

        // Recalculate properly
        // User business: 9-18 user time
        // Target business: 9-18 target time = (9+diffHours)-(18+diffHours) user time
        $targetStartInUserTime = 9 - $diffHours;
        $targetEndInUserTime   = 18 - $diffHours;
        $overlapStart = max(9, $targetStartInUserTime);
        $overlapEnd   = min(18, $targetEndInUserTime);
        $overlapHours = max(0, $overlapEnd - $overlapStart);

        // Current status in target city
        $targetHour   = (int) $targetNow->format('G');
        $targetIsoDay = (int) $targetNow->format('N');
        $targetIsOpen = $targetIsoDay < 6 && $targetHour >= 9 && $targetHour < 18;
        $targetIsWeekend = $targetIsoDay >= 6;

        $statusEmoji = match (true) {
            $targetIsWeekend => '🏖️',
            $targetIsOpen    => '🟢',
            $targetHour >= 22 || $targetHour < 6 => '😴',
            default          => '🔴',
        };
        $statusLabel = match (true) {
            $targetIsWeekend => match ($lang) { 'en' => 'Weekend', 'es' => 'Fin de semana', default => 'Weekend' },
            $targetIsOpen    => match ($lang) { 'en' => 'Open for business', 'es' => 'En horario laboral', default => 'Heures ouvrables' },
            $targetHour >= 22 || $targetHour < 6 => match ($lang) { 'en' => 'Sleeping', 'es' => 'Durmiendo', default => 'Dort probablement' },
            default          => match ($lang) { 'en' => 'Outside business hours', 'es' => 'Fuera de horario', default => 'Hors heures ouvrables' },
        };

        // Best call window
        if ($overlapHours > 0) {
            $bestStart = (int) ceil($overlapStart);
            $bestEnd   = (int) floor($overlapEnd);
            $bestStr   = "{$bestStart}h-{$bestEnd}h";
            $bestTargetStart = $bestStart + (int) $diffHours;
            $bestTargetEnd   = $bestEnd + (int) $diffHours;
            $bestTargetStr   = "{$bestTargetStart}h-{$bestTargetEnd}h";
        } else {
            $bestStr = match ($lang) { 'en' => 'No overlap', 'es' => 'Sin solapamiento', default => 'Aucun chevauchement' };
            $bestTargetStr = $bestStr;
        }

        // Communication difficulty
        $absDiff = abs($diffHours);
        [$difficultyEmoji, $difficultyLabel] = match (true) {
            $absDiff <= 2  => ['🟢', match ($lang) { 'en' => 'Easy — almost same hours', 'es' => 'Fácil — casi las mismas horas', default => 'Facile — presque les mêmes horaires' }],
            $absDiff <= 5  => ['🟡', match ($lang) { 'en' => 'Moderate — plan ahead', 'es' => 'Moderada — planifica', default => 'Modérée — planifie à l\'avance' }],
            $absDiff <= 8  => ['🟠', match ($lang) { 'en' => 'Challenging — limited overlap', 'es' => 'Difícil — solapamiento limitado', default => 'Difficile — chevauchement limité' }],
            default        => ['🔴', match ($lang) { 'en' => 'Very difficult — minimal overlap', 'es' => 'Muy difícil — solapamiento mínimo', default => 'Très difficile — chevauchement minimal' }],
        };

        // Build output
        $title = match ($lang) { 'en' => 'TIMEZONE FRIENDSHIP', 'es' => 'AMISTAD HORARIA', 'de' => 'ZEITZONEN-FREUNDSCHAFT', default => 'AMITIÉ FUSEAU' };
        $yourTimeLabel   = match ($lang) { 'en' => 'Your time', 'es' => 'Tu hora', default => 'Ton heure' };
        $theirTimeLabel  = match ($lang) { 'en' => 'Their time', 'es' => 'Su hora', default => 'Leur heure' };
        $diffLabel2      = match ($lang) { 'en' => 'Difference', 'es' => 'Diferencia', default => 'Décalage' };
        $overlapLabel    = match ($lang) { 'en' => 'Shared hours', 'es' => 'Horas compartidas', default => 'Heures partagées' };
        $bestLabel       = match ($lang) { 'en' => 'Best call window', 'es' => 'Mejor ventana', default => 'Meilleur créneau' };
        $statusLabel2    = match ($lang) { 'en' => 'Current status', 'es' => 'Estado actual', default => 'Statut actuel' };
        $difficultyLabel2= match ($lang) { 'en' => 'Difficulty', 'es' => 'Dificultad', default => 'Difficulté' };

        $overlapDisplay = $overlapHours > 0
            ? match ($lang) { 'en' => "{$overlapHours}h overlap", 'es' => "{$overlapHours}h de solapamiento", default => "{$overlapHours}h de chevauchement" }
            : match ($lang) { 'en' => 'No overlap', 'es' => 'Sin solapamiento', default => 'Aucun chevauchement' };

        $overlapBar = $this->progressBar(min(100, (int) round($overlapHours / 9 * 100)));

        // User city label from timezone
        $userCityLabel = $userTzStr;
        foreach (self::CITY_TIMEZONE_MAP as $city => $ianaTz) {
            if ($ianaTz === $userTzStr) {
                $userCityLabel = $city;
                break;
            }
        }

        $lines = [
            "🤝 *{$title}*",
            "════════════════",
            "*{$userCityLabel}* ↔ *{$targetLabel}*",
            "",
            "🕐 {$yourTimeLabel} : *{$userNow->format('H:i')}* ({$userTzStr})",
            "🕐 {$theirTimeLabel} : *{$targetNow->format('H:i')}* ({$targetTzStr})",
            "📏 {$diffLabel2} : *{$diffLabel}*",
            "",
            "{$statusEmoji} {$statusLabel2} : _{$statusLabel}_",
            "",
            "📊 {$overlapLabel} : {$overlapBar} {$overlapDisplay}",
        ];

        if ($overlapHours > 0) {
            $yourWindow  = match ($lang) { 'en' => 'Your hours', 'es' => 'Tus horas', default => 'Tes heures' };
            $theirWindow = match ($lang) { 'en' => 'Their hours', 'es' => 'Sus horas', default => 'Leurs heures' };
            $lines[] = "📞 {$bestLabel} :";
            $lines[] = "  • {$yourWindow} : *{$bestStr}*";
            $lines[] = "  • {$theirWindow} : *{$bestTargetStr}*";
        }

        $lines[] = "";
        $lines[] = "{$difficultyEmoji} {$difficultyLabel2} : _{$difficultyLabel}_";

        // Communication tips
        $tipLabel = match ($lang) { 'en' => 'Tips', 'es' => 'Consejos', default => 'Conseils' };
        $lines[] = "";
        $lines[] = "💡 *{$tipLabel}* :";

        $tips = [];
        if ($absDiff <= 2) {
            $tips[] = match ($lang) { 'en' => 'Real-time communication is easy — use chat freely', 'es' => 'La comunicación en tiempo real es fácil', default => 'Communication en temps réel facile — utilise le chat librement' };
        } elseif ($absDiff <= 5) {
            $tips[] = match ($lang) { 'en' => 'Schedule meetings in the overlap window', 'es' => 'Programa reuniones en la ventana de solapamiento', default => 'Planifie les réunions dans la fenêtre commune' };
            $tips[] = match ($lang) { 'en' => 'Use async tools (email, docs) for non-urgent items', 'es' => 'Usa herramientas asíncronas para lo no urgente', default => 'Utilise les outils asynchrones (email, docs) pour le non-urgent' };
        } else {
            $tips[] = match ($lang) { 'en' => 'Favor async communication — messages over meetings', 'es' => 'Prefiere comunicación asíncrona', default => 'Privilégie la communication asynchrone — messages plutôt que réunions' };
            $tips[] = match ($lang) { 'en' => 'Record video updates instead of live meetings', 'es' => 'Graba actualizaciones en vídeo', default => 'Enregistre des mises à jour vidéo plutôt que des réunions live' };
            if ($overlapHours > 0) {
                $tips[] = match ($lang) { 'en' => "Guard the {$overlapHours}h overlap for synchronous discussions only", 'es' => "Reserva las {$overlapHours}h de solapamiento para lo síncrono", default => "Réserve les {$overlapHours}h de chevauchement pour les discussions synchrones uniquement" };
            }
        }
        foreach ($tips as $tip) {
            $lines[] = "  • {$tip}";
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), [
            'action'        => 'timezone_friendship',
            'target'        => $targetLabel,
            'diff_hours'    => $diffHours,
            'overlap_hours' => $overlapHours,
            'target_open'   => $targetIsOpen,
        ]);
    }

    // =========================================================================
    // NEW HANDLERS v1.59.0
    // =========================================================================

    /**
     * Timezone Roulette — discover a random city with its current time,
     * a fun fact, and whether it's a good time to call.
     */
    private function handleTimezoneRoulette(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        // Pick a random city from the map
        $cities = array_keys(self::CITY_TIMEZONE_MAP);
        $randomCity = $cities[array_rand($cities)];
        $randomTzStr = self::CITY_TIMEZONE_MAP[$randomCity];

        try {
            $userTz    = new DateTimeZone($userTzStr);
            $randomTz  = new DateTimeZone($randomTzStr);
            $userNow   = new DateTimeImmutable('now', $userTz);
            $randomNow = new DateTimeImmutable('now', $randomTz);
        } catch (\Exception) {
            $userTz    = new DateTimeZone('UTC');
            $randomTz  = new DateTimeZone('UTC');
            $userNow   = new DateTimeImmutable('now', $userTz);
            $randomNow = new DateTimeImmutable('now', $randomTz);
        }

        // Offset difference
        $diffSeconds = $randomTz->getOffset($randomNow) - $userTz->getOffset($userNow);
        $diffHours   = $diffSeconds / 3600;
        $diffSign    = $diffHours >= 0 ? '+' : '';
        $diffLabel   = $diffSign . round($diffHours, 1) . 'h';

        // Current status
        $hour   = (int) $randomNow->format('G');
        $isoDay = (int) $randomNow->format('N');
        $isWeekend = $isoDay >= 6;

        $statusEmoji = match (true) {
            $isWeekend                       => '🏖️',
            $hour >= 9 && $hour < 18         => '🟢',
            $hour >= 22 || $hour < 6         => '😴',
            default                          => '🌅',
        };
        $statusLabel = match (true) {
            $isWeekend => match ($lang) { 'en' => 'Weekend', 'es' => 'Fin de semana', default => 'Weekend' },
            $hour >= 9 && $hour < 18 => match ($lang) { 'en' => 'Business hours', 'es' => 'Horario laboral', default => 'Heures ouvrables' },
            $hour >= 22 || $hour < 6 => match ($lang) { 'en' => 'Sleeping time', 'es' => 'Hora de dormir', default => 'Heure de sommeil' },
            default => match ($lang) { 'en' => 'Off hours', 'es' => 'Fuera de horario', default => 'Hors horaires' },
        };

        // Can call?
        $canCall = !$isWeekend && $hour >= 9 && $hour < 18;
        $callEmoji = $canCall ? '✅' : '❌';
        $callLabel = match (true) {
            $canCall => match ($lang) { 'en' => 'Good time to call!', 'es' => '¡Buen momento para llamar!', default => 'Bon moment pour appeler !' },
            $isWeekend => match ($lang) { 'en' => 'Weekend — wait until Monday', 'es' => 'Fin de semana — espera al lunes', default => 'Weekend — attends lundi' },
            $hour >= 22 || $hour < 6 => match ($lang) { 'en' => 'They\'re probably sleeping', 'es' => 'Probablemente durmiendo', default => 'Probablement en train de dormir' },
            default => match ($lang) { 'en' => 'Outside business hours', 'es' => 'Fuera de horario', default => 'Hors heures de bureau' },
        };

        // Fun facts per region
        $continent = explode('/', $randomTzStr)[0] ?? '';
        $funFacts = match ($continent) {
            'Asia' => match ($lang) {
                'en' => ['Asia hosts 60% of the world\'s population', 'The Asian continent spans 11 time zones', 'Tokyo is the world\'s most populous metropolitan area'],
                'es' => ['Asia alberga el 60% de la población mundial', 'El continente asiático abarca 11 husos horarios', 'Tokio es el área metropolitana más poblada del mundo'],
                default => ['L\'Asie abrite 60% de la population mondiale', 'Le continent asiatique couvre 11 fuseaux horaires', 'Tokyo est l\'aire métropolitaine la plus peuplée au monde'],
            },
            'Europe' => match ($lang) {
                'en' => ['Europe has 3 main time zones (WET, CET, EET)', 'The EU considered abolishing DST in 2019', 'Iceland never changes its clocks'],
                'es' => ['Europa tiene 3 zonas horarias principales', 'La UE consideró abolir el horario de verano en 2019', 'Islandia nunca cambia sus relojes'],
                default => ['L\'Europe a 3 fuseaux principaux (WET, CET, EET)', 'L\'UE a envisagé de supprimer le changement d\'heure en 2019', 'L\'Islande ne change jamais d\'heure'],
            },
            'America' => match ($lang) {
                'en' => ['The Americas span 9 time zones', 'The US has 6 standard time zones', 'Brazil has 4 time zones'],
                'es' => ['Las Américas abarcan 9 husos horarios', 'EEUU tiene 6 zonas horarias estándar', 'Brasil tiene 4 zonas horarias'],
                default => ['Les Amériques couvrent 9 fuseaux horaires', 'Les États-Unis ont 6 fuseaux standards', 'Le Brésil a 4 fuseaux horaires'],
            },
            'Australia', 'Pacific' => match ($lang) {
                'en' => ['Australia has 3 standard time zones', 'Some Australian states have half-hour offsets', 'Kiribati was the first to enter the year 2000'],
                'es' => ['Australia tiene 3 zonas horarias estándar', 'Algunos estados australianos tienen desfases de media hora', 'Kiribati fue el primero en entrar al año 2000'],
                default => ['L\'Australie a 3 fuseaux standards', 'Certains états australiens ont des décalages de 30 min', 'Kiribati a été le premier à entrer dans l\'an 2000'],
            },
            'Africa' => match ($lang) {
                'en' => ['Africa spans 6 time zones', 'Most African countries don\'t observe DST', 'South Africa uses a single time zone despite its size'],
                'es' => ['África abarca 6 husos horarios', 'La mayoría de los países africanos no usan horario de verano', 'Sudáfrica usa un solo huso horario a pesar de su tamaño'],
                default => ['L\'Afrique couvre 6 fuseaux horaires', 'La plupart des pays africains n\'observent pas l\'heure d\'été', 'L\'Afrique du Sud utilise un seul fuseau malgré sa taille'],
            },
            default => match ($lang) {
                'en' => ['There are 24 standard time zones worldwide', 'UTC was established in 1960', 'Some countries use quarter-hour offsets'],
                'es' => ['Hay 24 husos horarios estándar en el mundo', 'UTC se estableció en 1960', 'Algunos países usan desfases de 15 minutos'],
                default => ['Il y a 24 fuseaux horaires standards dans le monde', 'L\'UTC a été établi en 1960', 'Certains pays utilisent des décalages de 15 minutes'],
            },
        };
        $funFact = $funFacts[array_rand($funFacts)];

        // Day name in user's language
        $dayName = $this->getDayName((int) $randomNow->format('N'), $lang);

        // Build output
        $title = match ($lang) { 'en' => 'TIMEZONE ROULETTE', 'es' => 'RULETA HORARIA', 'de' => 'ZEITZONEN-ROULETTE', default => 'ROULETTE FUSEAU' };

        $lines = [
            "🎲 *{$title}*",
            "════════════════",
            "",
            "📍 *{$randomCity}*",
            "🕐 {$randomNow->format('H:i')} — {$dayName} {$randomNow->format('d/m/Y')}",
            "🌐 {$randomTzStr}",
            "📏 {$diffLabel} " . match ($lang) { 'en' => 'from you', 'es' => 'respecto a ti', default => 'par rapport à toi' },
            "",
            "{$statusEmoji} {$statusLabel}",
            "{$callEmoji} {$callLabel}",
            "",
            "💡 _{$funFact}_",
            "",
            "════════════════",
            match ($lang) {
                'en' => '_Type *timezone roulette* again for another city!_',
                'es' => '_Escribe *timezone roulette* otra vez para otra ciudad!_',
                default => '_Tape *roulette fuseau* à nouveau pour une autre ville !_',
            },
        ];

        return AgentResult::reply(implode("\n", $lines), [
            'action' => 'timezone_roulette',
            'city'   => $randomCity,
            'tz'     => $randomTzStr,
            'hour'   => $hour,
            'can_call' => $canCall,
        ]);
    }

    /**
     * Meeting Cost — calculate the timezone inconvenience for each participant
     * in a multi-city meeting, showing who is most "taxed" by the time difference.
     */
    private function handleMeetingCost(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';
        $cities    = $parsed['cities'] ?? [];
        $meetTime  = $parsed['time'] ?? null;

        if (count($cities) < 2) {
            $msg = match ($lang) {
                'en' => "⚠️ Please specify at least 2 cities. Example: *meeting cost Tokyo London New York*",
                'es' => "⚠️ Especifica al menos 2 ciudades. Ejemplo: *meeting cost Tokyo London New York*",
                default => "⚠️ Indique au moins 2 villes. Exemple : *coût réunion Tokyo London New York*",
            };
            return AgentResult::reply($msg, ['action' => 'meeting_cost', 'error' => 'too_few_cities']);
        }

        try {
            $userTz  = new DateTimeZone($userTzStr);
            $userNow = new DateTimeImmutable('now', $userTz);
        } catch (\Exception) {
            $userTz  = new DateTimeZone('UTC');
            $userNow = new DateTimeImmutable('now', $userTz);
        }

        // Determine meeting time in user's timezone
        if ($meetTime && preg_match('/^(\d{1,2}):?(\d{2})?$/', $meetTime, $m)) {
            $meetHour = (int) $m[1];
            $meetMin  = isset($m[2]) ? (int) $m[2] : 0;
        } else {
            $meetHour = (int) $userNow->format('G');
            $meetMin  = (int) $userNow->format('i');
        }

        $meetUserTime = sprintf('%02d:%02d', $meetHour, $meetMin);

        // Resolve cities to timezones
        $resolved = [];
        foreach ($cities as $city) {
            $cityLower = mb_strtolower(trim($city));
            $tzStr = null;
            $label = trim($city);

            foreach (self::CITY_TIMEZONE_MAP as $mapCity => $ianaTz) {
                if (mb_strtolower($mapCity) === $cityLower) {
                    $tzStr = $ianaTz;
                    $label = $mapCity;
                    break;
                }
            }
            if (!$tzStr) {
                foreach (self::CITY_TIMEZONE_MAP as $mapCity => $ianaTz) {
                    if (str_contains(mb_strtolower($mapCity), $cityLower)) {
                        $tzStr = $ianaTz;
                        $label = $mapCity;
                        break;
                    }
                }
            }
            if (!$tzStr) {
                try {
                    new DateTimeZone($city);
                    $tzStr = $city;
                } catch (\Exception) {
                    continue;
                }
            }

            if ($tzStr) {
                $resolved[] = ['city' => $label, 'tz' => $tzStr];
            }
        }

        if (count($resolved) < 2) {
            $msg = match ($lang) {
                'en' => "❌ Could not resolve enough cities. Try major city names like Tokyo, London, New York.",
                'es' => "❌ No se pudieron resolver suficientes ciudades. Prueba con nombres como Tokyo, London, New York.",
                default => "❌ Impossible de résoudre assez de villes. Essaie des noms comme Tokyo, Londres, New York.",
            };
            return AgentResult::reply($msg, ['action' => 'meeting_cost', 'error' => 'resolve_failed']);
        }

        // Calculate inconvenience for each city
        $participants = [];
        $userOffset = $userTz->getOffset($userNow);

        foreach ($resolved as $r) {
            try {
                $tz  = new DateTimeZone($r['tz']);
                $now = new DateTimeImmutable('now', $tz);
            } catch (\Exception) {
                continue;
            }

            $offset    = $tz->getOffset($now);
            $diffHours = ($offset - $userOffset) / 3600;
            $localHour = $meetHour + $diffHours;
            $localMin  = $meetMin;

            // Wrap around
            while ($localHour >= 24) $localHour -= 24;
            while ($localHour < 0) $localHour += 24;

            $localTime = sprintf('%02d:%02d', (int) $localHour, $localMin);

            // Calculate inconvenience score (0-100)
            // 0 = perfect (9-17), 100 = worst (2-5 AM)
            $h = (int) $localHour;
            $score = match (true) {
                $h >= 9 && $h < 12  => 0,   // Morning sweet spot
                $h >= 12 && $h < 14 => 10,  // Lunch time — minor inconvenience
                $h >= 14 && $h < 17 => 5,   // Afternoon — fine
                $h >= 17 && $h < 18 => 15,  // Late afternoon
                $h >= 8 && $h < 9   => 20,  // Early morning
                $h >= 18 && $h < 20 => 30,  // Early evening
                $h >= 7 && $h < 8   => 40,  // Very early
                $h >= 20 && $h < 22 => 50,  // Late evening
                $h >= 6 && $h < 7   => 60,  // Dawn
                $h >= 22 && $h < 23 => 70,  // Night
                $h >= 23 || $h < 3  => 90,  // Deep night
                default              => 80,  // Very early morning (3-6)
            };

            // Weekend penalty
            $isoDay = (int) $now->format('N');
            if ($isoDay >= 6) {
                $score = min(100, $score + 20);
            }

            $emoji = match (true) {
                $score <= 10 => '🟢',
                $score <= 30 => '🟡',
                $score <= 50 => '🟠',
                default      => '🔴',
            };

            $comfort = match (true) {
                $score <= 10 => match ($lang) { 'en' => 'Comfortable', 'es' => 'Cómodo', default => 'Confortable' },
                $score <= 30 => match ($lang) { 'en' => 'Acceptable', 'es' => 'Aceptable', default => 'Acceptable' },
                $score <= 50 => match ($lang) { 'en' => 'Inconvenient', 'es' => 'Incómodo', default => 'Gênant' },
                $score <= 70 => match ($lang) { 'en' => 'Difficult', 'es' => 'Difícil', default => 'Difficile' },
                default      => match ($lang) { 'en' => 'Unreasonable', 'es' => 'Irrazonable', default => 'Déraisonnable' },
            };

            $participants[] = [
                'city'      => $r['city'],
                'localTime' => $localTime,
                'score'     => $score,
                'emoji'     => $emoji,
                'comfort'   => $comfort,
            ];
        }

        // Sort by score descending (most inconvenienced first)
        usort($participants, fn($a, $b) => $b['score'] <=> $a['score']);

        $avgScore = count($participants) > 0
            ? (int) round(array_sum(array_column($participants, 'score')) / count($participants))
            : 0;

        // Overall meeting fairness
        $fairnessEmoji = match (true) {
            $avgScore <= 15 => '🟢',
            $avgScore <= 35 => '🟡',
            $avgScore <= 55 => '🟠',
            default         => '🔴',
        };
        $fairnessLabel = match (true) {
            $avgScore <= 15 => match ($lang) { 'en' => 'Fair for everyone', 'es' => 'Justo para todos', default => 'Équitable pour tous' },
            $avgScore <= 35 => match ($lang) { 'en' => 'Mostly fair', 'es' => 'Mayormente justo', default => 'Plutôt équitable' },
            $avgScore <= 55 => match ($lang) { 'en' => 'Someone is inconvenienced', 'es' => 'Alguien está incómodo', default => 'Quelqu\'un est gêné' },
            default         => match ($lang) { 'en' => 'Unfair — consider rescheduling', 'es' => 'Injusto — considera reprogramar', default => 'Injuste — envisage de replanifier' },
        };

        // Build output
        $title = match ($lang) { 'en' => 'MEETING COST', 'es' => 'COSTE DE REUNIÓN', 'de' => 'MEETING-KOSTEN', default => 'COÛT RÉUNION' };
        $meetLabel = match ($lang) { 'en' => 'Meeting at', 'es' => 'Reunión a las', default => 'Réunion à' };
        $yourTzLabel = match ($lang) { 'en' => 'your time', 'es' => 'tu hora', default => 'ton heure' };
        $fairLabel = match ($lang) { 'en' => 'Fairness', 'es' => 'Equidad', default => 'Équité' };
        $localLabel = match ($lang) { 'en' => 'local', 'es' => 'local', default => 'locale' };

        $lines = [
            "💰 *{$title}*",
            "════════════════",
            "🕐 {$meetLabel} *{$meetUserTime}* ({$yourTzLabel})",
            "",
        ];

        $rankLabel = match ($lang) { 'en' => 'Inconvenience ranking', 'es' => 'Ranking de inconveniencia', default => 'Classement d\'inconvénience' };
        $lines[] = "📊 *{$rankLabel}* :";
        $lines[] = "";

        foreach ($participants as $i => $p) {
            $rank = $i + 1;
            $bar  = $this->progressBar($p['score']);
            $lines[] = "{$rank}. {$p['emoji']} *{$p['city']}* — {$p['localTime']} {$localLabel}";
            $lines[] = "   {$bar} {$p['score']}/100 _{$p['comfort']}_";
        }

        $lines[] = "";
        $lines[] = "{$fairnessEmoji} {$fairLabel} : _{$fairnessLabel}_ ({$avgScore}/100)";

        // Suggestion
        if ($avgScore > 35 && count($participants) >= 2) {
            $leastInconv = end($participants);
            $mostInconv  = $participants[0];
            $suggestLabel = match ($lang) { 'en' => 'Suggestion', 'es' => 'Sugerencia', default => 'Suggestion' };
            $suggestion = match ($lang) {
                'en' => "*{$mostInconv['city']}* is the most impacted. Consider shifting the meeting closer to their business hours to balance the load.",
                'es' => "*{$mostInconv['city']}* es la más afectada. Considera mover la reunión más cerca de su horario laboral.",
                default => "*{$mostInconv['city']}* est la plus impactée. Envisage de décaler la réunion vers ses heures de bureau pour équilibrer.",
            };
            $lines[] = "";
            $lines[] = "💡 *{$suggestLabel}* : {$suggestion}";
        }

        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), [
            'action'    => 'meeting_cost',
            'cities'    => array_column($resolved, 'city'),
            'avg_score' => $avgScore,
            'meet_time' => $meetUserTime,
        ]);
    }

    // =========================================================================
    // NEW HANDLERS v1.60.0
    // =========================================================================

    /**
     * Currency Info — display currency information for a city/timezone.
     */
    private const TIMEZONE_CURRENCY_MAP = [
        'Europe/Paris'       => ['EUR', '€', 'Euro'],
        'Europe/Berlin'      => ['EUR', '€', 'Euro'],
        'Europe/Madrid'      => ['EUR', '€', 'Euro'],
        'Europe/Rome'        => ['EUR', '€', 'Euro'],
        'Europe/Amsterdam'   => ['EUR', '€', 'Euro'],
        'Europe/Brussels'    => ['EUR', '€', 'Euro'],
        'Europe/Vienna'      => ['EUR', '€', 'Euro'],
        'Europe/Lisbon'      => ['EUR', '€', 'Euro'],
        'Europe/Dublin'      => ['EUR', '€', 'Euro'],
        'Europe/Helsinki'    => ['EUR', '€', 'Euro'],
        'Europe/Athens'      => ['EUR', '€', 'Euro'],
        'Europe/London'      => ['GBP', '£', 'British Pound'],
        'Europe/Zurich'      => ['CHF', 'CHF', 'Swiss Franc'],
        'Europe/Stockholm'   => ['SEK', 'kr', 'Swedish Krona'],
        'Europe/Oslo'        => ['NOK', 'kr', 'Norwegian Krone'],
        'Europe/Copenhagen'  => ['DKK', 'kr', 'Danish Krone'],
        'Europe/Warsaw'      => ['PLN', 'zł', 'Polish Zloty'],
        'Europe/Prague'      => ['CZK', 'Kč', 'Czech Koruna'],
        'Europe/Budapest'    => ['HUF', 'Ft', 'Hungarian Forint'],
        'Europe/Bucharest'   => ['RON', 'lei', 'Romanian Leu'],
        'Europe/Moscow'      => ['RUB', '₽', 'Russian Ruble'],
        'Europe/Istanbul'    => ['TRY', '₺', 'Turkish Lira'],
        'America/New_York'   => ['USD', '$', 'US Dollar'],
        'America/Chicago'    => ['USD', '$', 'US Dollar'],
        'America/Denver'     => ['USD', '$', 'US Dollar'],
        'America/Los_Angeles'=> ['USD', '$', 'US Dollar'],
        'America/Toronto'    => ['CAD', 'CA$', 'Canadian Dollar'],
        'America/Vancouver'  => ['CAD', 'CA$', 'Canadian Dollar'],
        'America/Mexico_City'=> ['MXN', 'MX$', 'Mexican Peso'],
        'America/Sao_Paulo'  => ['BRL', 'R$', 'Brazilian Real'],
        'America/Argentina/Buenos_Aires' => ['ARS', 'AR$', 'Argentine Peso'],
        'America/Bogota'     => ['COP', 'CO$', 'Colombian Peso'],
        'America/Santiago'   => ['CLP', 'CL$', 'Chilean Peso'],
        'America/Lima'       => ['PEN', 'S/', 'Peruvian Sol'],
        'Asia/Tokyo'         => ['JPY', '¥', 'Japanese Yen'],
        'Asia/Shanghai'      => ['CNY', '¥', 'Chinese Yuan'],
        'Asia/Hong_Kong'     => ['HKD', 'HK$', 'Hong Kong Dollar'],
        'Asia/Seoul'         => ['KRW', '₩', 'South Korean Won'],
        'Asia/Taipei'        => ['TWD', 'NT$', 'Taiwan Dollar'],
        'Asia/Singapore'     => ['SGD', 'S$', 'Singapore Dollar'],
        'Asia/Bangkok'       => ['THB', '฿', 'Thai Baht'],
        'Asia/Jakarta'       => ['IDR', 'Rp', 'Indonesian Rupiah'],
        'Asia/Kuala_Lumpur'  => ['MYR', 'RM', 'Malaysian Ringgit'],
        'Asia/Manila'        => ['PHP', '₱', 'Philippine Peso'],
        'Asia/Ho_Chi_Minh'   => ['VND', '₫', 'Vietnamese Dong'],
        'Asia/Kolkata'       => ['INR', '₹', 'Indian Rupee'],
        'Asia/Karachi'       => ['PKR', 'Rs', 'Pakistani Rupee'],
        'Asia/Dubai'         => ['AED', 'د.إ', 'UAE Dirham'],
        'Asia/Riyadh'        => ['SAR', '﷼', 'Saudi Riyal'],
        'Asia/Qatar'         => ['QAR', 'ر.ق', 'Qatari Riyal'],
        'Africa/Cairo'       => ['EGP', 'E£', 'Egyptian Pound'],
        'Africa/Casablanca'  => ['MAD', 'د.م.', 'Moroccan Dirham'],
        'Africa/Johannesburg'=> ['ZAR', 'R', 'South African Rand'],
        'Africa/Lagos'       => ['NGN', '₦', 'Nigerian Naira'],
        'Africa/Nairobi'     => ['KES', 'KSh', 'Kenyan Shilling'],
        'Australia/Sydney'   => ['AUD', 'A$', 'Australian Dollar'],
        'Australia/Melbourne'=> ['AUD', 'A$', 'Australian Dollar'],
        'Pacific/Auckland'   => ['NZD', 'NZ$', 'New Zealand Dollar'],
        'Pacific/Fiji'       => ['FJD', 'FJ$', 'Fijian Dollar'],
    ];

    private function handleCurrencyInfo(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';
        $target    = $parsed['target'] ?? null;

        // Resolve target timezone
        $targetTzStr = $userTzStr;
        $cityLabel   = match ($lang) {
            'en' => 'Your timezone',
            'es' => 'Tu zona horaria',
            'de' => 'Deine Zeitzone',
            default => 'Ton fuseau',
        };

        if ($target !== null) {
            $resolved = $this->resolveTimezoneString($target);
            if ($resolved !== null) {
                $targetTzStr = $resolved;
                $cityLabel   = ucfirst($target);
            } else {
                // Try city map
                $targetLower = mb_strtolower($target);
                $found = false;
                foreach (self::CITY_TIMEZONE_MAP as $city => $tz) {
                    if (mb_strtolower($city) === $targetLower) {
                        $targetTzStr = $tz;
                        $cityLabel   = $city;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $msg = match ($lang) {
                        'en' => "⚠️ City or timezone not found: *{$target}*\n\n_Try: currency info Tokyo, devise à Paris, currency in London_",
                        'es' => "⚠️ Ciudad o zona horaria no encontrada: *{$target}*\n\n_Prueba: currency info Tokyo, devise à Paris_",
                        default => "⚠️ Ville ou fuseau non trouvé : *{$target}*\n\n_Essaie : devise à Tokyo, currency in London, monnaie en Suisse_",
                    };
                    return AgentResult::reply($msg, ['action' => 'currency_info', 'error' => 'city_not_found']);
                }
            }
        }

        // Look up currency
        $currency = self::TIMEZONE_CURRENCY_MAP[$targetTzStr] ?? null;

        // Try matching by region prefix if exact match fails
        if ($currency === null) {
            $prefix = explode('/', $targetTzStr)[0] ?? '';
            foreach (self::TIMEZONE_CURRENCY_MAP as $tz => $cur) {
                if (str_starts_with($tz, $prefix . '/')) {
                    $currency = $cur;
                    break;
                }
            }
        }

        if ($currency === null) {
            $currency = ['???', '?', match ($lang) {
                'en' => 'Unknown currency',
                'es' => 'Moneda desconocida',
                default => 'Devise inconnue',
            }];
        }

        [$isoCode, $symbol, $currencyName] = $currency;

        // Get user's currency for comparison
        $userCurrency = self::TIMEZONE_CURRENCY_MAP[$userTzStr] ?? null;
        $userIso      = $userCurrency[0] ?? '???';
        $sameAsMine   = $isoCode === $userIso;

        $title = match ($lang) {
            'en' => 'CURRENCY INFO',
            'es' => 'INFO MONEDA',
            'de' => 'WÄHRUNGSINFO',
            default => 'INFO DEVISE',
        };

        $lines = [
            "💱 *{$title}*",
            "════════════════",
            "",
            "📍 *{$cityLabel}*",
            "🌐 {$targetTzStr}",
            "",
        ];

        $currLabel = match ($lang) { 'en' => 'Currency', 'es' => 'Moneda', 'de' => 'Währung', default => 'Devise' };
        $codeLabel = match ($lang) { 'en' => 'ISO Code', 'es' => 'Código ISO', 'de' => 'ISO-Code', default => 'Code ISO' };
        $symLabel  = match ($lang) { 'en' => 'Symbol', 'es' => 'Símbolo', 'de' => 'Symbol', default => 'Symbole' };

        $lines[] = "💰 *{$currLabel}:* {$currencyName}";
        $lines[] = "🏷️ *{$codeLabel}:* {$isoCode}";
        $lines[] = "✏️ *{$symLabel}:* {$symbol}";
        $lines[] = "";

        if ($sameAsMine) {
            $sameMsg = match ($lang) {
                'en' => 'Same currency as yours!',
                'es' => '¡Misma moneda que la tuya!',
                default => 'Même devise que la tienne !',
            };
            $lines[] = "✅ {$sameMsg}";
        } elseif ($userCurrency !== null) {
            $diffMsg = match ($lang) {
                'en' => "Your currency: *{$userCurrency[2]}* ({$userCurrency[0]} {$userCurrency[1]})",
                'es' => "Tu moneda: *{$userCurrency[2]}* ({$userCurrency[0]} {$userCurrency[1]})",
                default => "Ta devise : *{$userCurrency[2]}* ({$userCurrency[0]} {$userCurrency[1]})",
            };
            $lines[] = "🔄 {$diffMsg}";
        }

        // Tip
        $lines[] = "";
        $tip = match ($lang) {
            'en' => "For live exchange rates, check your banking app or xe.com",
            'es' => "Para tasas en vivo, consulta tu app bancaria o xe.com",
            'de' => "Für aktuelle Wechselkurse nutze deine Banking-App oder xe.com",
            default => "Pour les taux en temps réel, consulte ton app bancaire ou xe.com",
        };
        $lines[] = "💡 _{$tip}_";

        $lines[] = "";
        $lines[] = "════════════════";
        $exLabel = match ($lang) {
            'en' => '_Try: currency in Tokyo, devise à London_',
            'es' => '_Prueba: currency in Tokyo, moneda en Londres_',
            default => '_Essaie : devise à Tokyo, currency in London_',
        };
        $lines[] = $exLabel;

        return AgentResult::reply(implode("\n", $lines), [
            'action'   => 'currency_info',
            'city'     => $cityLabel,
            'currency' => $isoCode,
            'symbol'   => $symbol,
        ]);
    }

    /**
     * Water Reminder — hydration tracking based on work hours and time of day.
     */
    private function handleWaterReminder(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $userTz  = new \DateTimeZone($userTzStr);
            $userNow = new \DateTimeImmutable('now', $userTz);
        } catch (\Exception) {
            $userTz  = new \DateTimeZone('UTC');
            $userNow = new \DateTimeImmutable('now', $userTz);
        }

        $hour   = (int) $userNow->format('G');
        $minute = (int) $userNow->format('i');

        // Work hours config
        $workStart = (int) ($prefs['work_start'] ?? 9);
        $workEnd   = (int) ($prefs['work_end'] ?? 18);

        // Calculate hydration progress
        $dailyGoalMl  = 2000; // 2L recommended daily
        $glassSize    = 250;  // 250ml per glass
        $totalGlasses = (int) ceil($dailyGoalMl / $glassSize); // 8 glasses

        // Estimate glasses that should have been drunk by now
        // Based on waking hours (assume 7am-11pm = 16h active window)
        $wakeHour  = 7;
        $sleepHour = 23;
        $activeHours = $sleepHour - $wakeHour;

        $hoursSinceWake = max(0, min($activeHours, $hour - $wakeHour + ($minute / 60)));
        $expectedGlasses = (int) round(($hoursSinceWake / $activeHours) * $totalGlasses);
        $expectedGlasses = min($expectedGlasses, $totalGlasses);

        $pctProgress = (int) round(($expectedGlasses / $totalGlasses) * 100);

        // Time until next glass suggestion
        $intervalMinutes = (int) round(($activeHours * 60) / $totalGlasses);
        $minutesSinceWake = (int) ($hoursSinceWake * 60);
        $nextGlassIn = $intervalMinutes - ($minutesSinceWake % $intervalMinutes);
        if ($nextGlassIn <= 0) $nextGlassIn = $intervalMinutes;

        // Status based on time of day
        $statusEmoji = match (true) {
            $hour < $wakeHour || $hour >= $sleepHour => '😴',
            $hour >= $workStart && $hour < $workEnd   => '💼',
            $hour >= $wakeHour && $hour < $workStart   => '🌅',
            default                                    => '🌙',
        };

        $periodLabel = match (true) {
            $hour < $wakeHour || $hour >= $sleepHour => match ($lang) { 'en' => 'Rest time', 'es' => 'Hora de descanso', default => 'Heure de repos' },
            $hour >= $workStart && $hour < $workEnd   => match ($lang) { 'en' => 'Work hours', 'es' => 'Horario laboral', default => 'Heures de travail' },
            $hour >= $wakeHour && $hour < $workStart   => match ($lang) { 'en' => 'Morning', 'es' => 'Mañana', default => 'Matin' },
            default                                    => match ($lang) { 'en' => 'Evening', 'es' => 'Noche', default => 'Soirée' },
        };

        // Hydration tips based on time
        $tip = match (true) {
            $hour >= $wakeHour && $hour < 9 => match ($lang) {
                'en' => 'Start your day with a glass of water before coffee!',
                'es' => '¡Empieza el día con un vaso de agua antes del café!',
                default => 'Commence ta journée avec un verre d\'eau avant le café !',
            },
            $hour >= 12 && $hour < 14 => match ($lang) {
                'en' => 'Drink a glass before and after lunch for better digestion.',
                'es' => 'Bebe un vaso antes y después del almuerzo para mejor digestión.',
                default => 'Bois un verre avant et après le déjeuner pour mieux digérer.',
            },
            $hour >= 14 && $hour < 16 => match ($lang) {
                'en' => 'Afternoon slump? A glass of water boosts focus better than coffee.',
                'es' => '¿Bajón de la tarde? Un vaso de agua mejora el enfoque más que el café.',
                default => 'Coup de barre ? Un verre d\'eau booste la concentration mieux que le café.',
            },
            $hour >= $workEnd && $hour < 20 => match ($lang) {
                'en' => 'Don\'t forget to hydrate after work — it helps recovery.',
                'es' => 'No olvides hidratarte después del trabajo.',
                default => 'N\'oublie pas de t\'hydrater après le travail — ça aide à récupérer.',
            },
            $hour >= 20 => match ($lang) {
                'en' => 'Reduce intake before bed to avoid waking up at night.',
                'es' => 'Reduce la ingesta antes de dormir para no despertar en la noche.',
                default => 'Réduis ta consommation avant de dormir pour ne pas te réveiller la nuit.',
            },
            default => match ($lang) {
                'en' => 'Regular sips throughout the day are better than drinking a lot at once.',
                'es' => 'Pequeños sorbos durante el día son mejores que beber mucho de una vez.',
                default => 'Des petites gorgées régulières sont mieux qu\'une grande quantité d\'un coup.',
            },
        };

        $title = match ($lang) { 'en' => 'HYDRATION TRACKER', 'es' => 'SEGUIMIENTO HIDRATACIÓN', 'de' => 'HYDRATION TRACKER', default => 'SUIVI HYDRATATION' };
        $bar = $this->progressBar($pctProgress);

        $lines = [
            "💧 *{$title}*",
            "════════════════",
            "",
            "{$statusEmoji} {$periodLabel} — {$userNow->format('H:i')}",
            "",
        ];

        $goalLabel = match ($lang) { 'en' => 'Daily goal', 'es' => 'Objetivo diario', default => 'Objectif journalier' };
        $progLabel = match ($lang) { 'en' => 'Expected progress', 'es' => 'Progreso esperado', default => 'Progression attendue' };
        $nextLabel = match ($lang) { 'en' => 'Next glass in', 'es' => 'Próximo vaso en', default => 'Prochain verre dans' };
        $minLabel  = match ($lang) { 'en' => 'min', 'es' => 'min', default => 'min' };

        $lines[] = "🎯 *{$goalLabel}:* {$totalGlasses} " . match ($lang) { 'en' => 'glasses', 'es' => 'vasos', default => 'verres' } . " ({$dailyGoalMl}ml)";
        $lines[] = "📊 *{$progLabel}:* {$expectedGlasses}/{$totalGlasses} {$bar}";
        $lines[] = "";

        if ($hour >= $wakeHour && $hour < $sleepHour) {
            $lines[] = "⏱️ *{$nextLabel}:* ~{$nextGlassIn} {$minLabel}";
            $remaining = $totalGlasses - $expectedGlasses;
            if ($remaining > 0) {
                $remainLabel = match ($lang) {
                    'en' => "glasses left today",
                    'es' => "vasos restantes hoy",
                    default => "verres restants aujourd'hui",
                };
                $lines[] = "🥛 *{$remaining}* {$remainLabel}";
            } else {
                $doneMsg = match ($lang) {
                    'en' => 'You should have reached your goal by now!',
                    'es' => '¡Deberías haber alcanzado tu objetivo!',
                    default => 'Tu devrais avoir atteint ton objectif !',
                };
                $lines[] = "🎉 {$doneMsg}";
            }
        } else {
            $sleepMsg = match ($lang) {
                'en' => 'Rest time — hydration resets tomorrow morning.',
                'es' => 'Hora de descanso — la hidratación se reinicia mañana.',
                default => 'Heure de repos — l\'hydratation redémarre demain matin.',
            };
            $lines[] = "😴 {$sleepMsg}";
        }

        // Schedule visualization
        $lines[] = "";
        $schedLabel = match ($lang) { 'en' => 'Suggested schedule', 'es' => 'Horario sugerido', default => 'Planning suggéré' };
        $lines[] = "📋 *{$schedLabel}:*";
        for ($g = 0; $g < $totalGlasses; $g++) {
            $glassTime = $wakeHour * 60 + ($g * $intervalMinutes);
            $gH = (int) floor($glassTime / 60);
            $gM = $glassTime % 60;
            $timeStr = sprintf('%02d:%02d', $gH, $gM);
            $done = ($g < $expectedGlasses) ? '✅' : '⬜';
            $lines[] = "  {$done} {$timeStr} — " . match ($lang) { 'en' => "Glass", 'es' => "Vaso", default => "Verre" } . " #" . ($g + 1);
        }

        $lines[] = "";
        $lines[] = "💡 _{$tip}_";
        $lines[] = "";
        $lines[] = "════════════════";

        return AgentResult::reply(implode("\n", $lines), [
            'action'          => 'water_reminder',
            'expected_glasses' => $expectedGlasses,
            'total_glasses'   => $totalGlasses,
            'next_glass_min'  => $nextGlassIn,
            'progress_pct'    => $pctProgress,
        ]);
    }

    // =========================================================================
    // NEW HANDLERS v1.61.0
    // =========================================================================

    /**
     * Meeting Countdown — visual countdown to a specific meeting time today.
     */
    private function handleMeetingCountdown(array $parsed, array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';

        try {
            $userTz  = new DateTimeZone($userTzStr);
            $userNow = new DateTimeImmutable('now', $userTz);
        } catch (\Exception) {
            $userTz  = new DateTimeZone('UTC');
            $userNow = new DateTimeImmutable('now', $userTz);
        }

        $timeStr = $parsed['time'] ?? null;

        if (empty($timeStr)) {
            $msg = match ($lang) {
                'en' => "⏰ *Meeting Countdown*\n\nPlease specify the meeting time!\n\n_Examples:_\n• _meeting at 14h30_\n• _meeting countdown 3pm_\n• _rdv à 10h_",
                'es' => "⏰ *Cuenta Regresiva Reunión*\n\nPor favor especifica la hora de la reunión!\n\n_Ejemplos:_\n• _reunión a las 14:30_\n• _meeting countdown 3pm_\n• _rdv à 10h_",
                default => "⏰ *Countdown Réunion*\n\nPrécise l'heure de la réunion !\n\n_Exemples :_\n• _réunion à 14h30_\n• _meeting countdown 3pm_\n• _rdv à 10h_",
            };
            return AgentResult::reply($msg, ['action' => 'meeting_countdown', 'error' => 'no_time']);
        }

        // Parse the time string
        $meetingTime = $this->parseTimeString($timeStr);
        if ($meetingTime === null) {
            $msg = match ($lang) {
                'en' => "⚠️ Could not parse time: *{$timeStr}*\n\n_Try: 14h30, 2pm, 09:00, 15h_",
                'es' => "⚠️ No pude entender la hora: *{$timeStr}*\n\n_Prueba: 14h30, 2pm, 09:00, 15h_",
                default => "⚠️ Impossible de comprendre l'heure : *{$timeStr}*\n\n_Essaie : 14h30, 2pm, 09:00, 15h_",
            };
            return AgentResult::reply($msg, ['action' => 'meeting_countdown', 'error' => 'parse_failed']);
        }

        [$meetHour, $meetMin] = $meetingTime;

        try {
            $meetingDt = $userNow->setTime($meetHour, $meetMin, 0);
        } catch (\Exception) {
            $msg = match ($lang) {
                'en' => "⚠️ Invalid meeting time.",
                'es' => "⚠️ Hora de reunión inválida.",
                default => "⚠️ Heure de réunion invalide.",
            };
            return AgentResult::reply($msg, ['action' => 'meeting_countdown', 'error' => 'invalid_time']);
        }

        $diffSec  = $meetingDt->getTimestamp() - $userNow->getTimestamp();
        $isPast   = $diffSec <= 0;
        $absDiff  = abs($diffSec);
        $diffH    = (int) floor($absDiff / 3600);
        $diffM    = (int) floor(($absDiff % 3600) / 60);
        $diffS    = $absDiff % 60;

        $meetTimeFormatted = sprintf('%02d:%02d', $meetHour, $meetMin);
        $nowFormatted      = $userNow->format('H:i:s');

        $title = match ($lang) {
            'en' => 'MEETING COUNTDOWN',
            'es' => 'CUENTA REGRESIVA REUNIÓN',
            'de' => 'MEETING COUNTDOWN',
            default => 'COUNTDOWN RÉUNION',
        };

        $lines = [
            "⏰ *{$title}*",
            "════════════════",
            "",
        ];

        $meetLabel = match ($lang) { 'en' => 'Meeting at', 'es' => 'Reunión a las', default => 'Réunion à' };
        $nowLabel  = match ($lang) { 'en' => 'Current time', 'es' => 'Hora actual', default => 'Heure actuelle' };
        $lines[] = "🎯 *{$meetLabel}:* {$meetTimeFormatted}";
        $lines[] = "🕐 *{$nowLabel}:* {$nowFormatted}";
        $lines[] = "";

        if ($isPast) {
            $agoLabel = match ($lang) {
                'en' => 'The meeting started',
                'es' => 'La reunión comenzó hace',
                default => 'La réunion a commencé il y a',
            };

            $timeAgoStr = '';
            if ($diffH > 0) {
                $timeAgoStr .= match ($lang) {
                    'en' => "{$diffH}h ",
                    default => "{$diffH}h ",
                };
            }
            $timeAgoStr .= match ($lang) {
                'en' => "{$diffM}min ago",
                'es' => "{$diffM}min",
                default => "{$diffM}min",
            };

            $lines[] = "🔴 {$agoLabel} {$timeAgoStr}";
            $lines[] = "";

            $tip = match ($lang) {
                'en' => "You might be late! Join now or check if it's still going.",
                'es' => "¡Puede que llegues tarde! Únete ahora.",
                default => "Tu es peut-être en retard ! Rejoins maintenant ou vérifie si c'est encore en cours.",
            };
            $lines[] = "💡 _{$tip}_";
        } else {
            // Build countdown display
            $parts = [];
            if ($diffH > 0) {
                $parts[] = match ($lang) {
                    'en' => "{$diffH} hour" . ($diffH > 1 ? 's' : ''),
                    'es' => "{$diffH} hora" . ($diffH > 1 ? 's' : ''),
                    default => "{$diffH} heure" . ($diffH > 1 ? 's' : ''),
                };
            }
            if ($diffM > 0) {
                $parts[] = match ($lang) {
                    'en' => "{$diffM} minute" . ($diffM > 1 ? 's' : ''),
                    'es' => "{$diffM} minuto" . ($diffM > 1 ? 's' : ''),
                    default => "{$diffM} minute" . ($diffM > 1 ? 's' : ''),
                };
            }
            if ($diffH === 0 && $diffM < 5) {
                $parts[] = match ($lang) {
                    'en' => "{$diffS} second" . ($diffS > 1 ? 's' : ''),
                    'es' => "{$diffS} segundo" . ($diffS > 1 ? 's' : ''),
                    default => "{$diffS} seconde" . ($diffS > 1 ? 's' : ''),
                };
            }
            $countdownStr = implode(', ', $parts);

            $remainLabel = match ($lang) { 'en' => 'Time remaining', 'es' => 'Tiempo restante', default => 'Temps restant' };
            $lines[] = "⏳ *{$remainLabel}:* {$countdownStr}";

            // Visual progress bar (day progress toward meeting)
            $dayStart   = (int) ($prefs['work_start'] ?? 9) * 60;
            $meetMinOfDay = $meetHour * 60 + $meetMin;
            $nowMinOfDay  = (int) $userNow->format('G') * 60 + (int) $userNow->format('i');
            if ($meetMinOfDay > $dayStart && $nowMinOfDay >= $dayStart) {
                $pct = (int) round(($nowMinOfDay - $dayStart) / ($meetMinOfDay - $dayStart) * 100);
                $pct = min(100, max(0, $pct));
                $bar = $this->progressBar($pct);
                $lines[] = "📊 {$bar}";
            }

            $lines[] = "";

            // Contextual tip based on time remaining
            $tip = match (true) {
                $diffSec <= 300 => match ($lang) {
                    'en' => 'Almost time! Get ready to join.',
                    'es' => '¡Casi es hora! Prepárate para unirte.',
                    default => 'C\'est bientôt ! Prépare-toi à rejoindre.',
                },
                $diffSec <= 900 => match ($lang) {
                    'en' => 'Less than 15 minutes — review your notes and prep.',
                    'es' => 'Menos de 15 minutos — revisa tus notas.',
                    default => 'Moins de 15 minutes — relis tes notes et prépare-toi.',
                },
                $diffSec <= 3600 => match ($lang) {
                    'en' => 'Good time to finish current tasks and prepare your agenda.',
                    'es' => 'Buen momento para terminar tareas y preparar tu agenda.',
                    default => 'Bon moment pour finir tes tâches en cours et préparer ton ordre du jour.',
                },
                default => match ($lang) {
                    'en' => 'Plenty of time — focus on deep work until then.',
                    'es' => 'Tiempo de sobra — enfócate en trabajo profundo.',
                    default => 'Largement le temps — concentre-toi sur du travail profond en attendant.',
                },
            };
            $lines[] = "💡 _{$tip}_";
        }

        $lines[] = "";
        $lines[] = "════════════════";
        $exLabel = match ($lang) {
            'en' => '_Try: meeting at 14h30, rdv à 10h, meeting countdown 3pm_',
            'es' => '_Prueba: reunión a las 14:30, meeting at 2pm_',
            default => '_Essaie : réunion à 14h30, rdv à 10h, meeting countdown 3pm_',
        };
        $lines[] = $exLabel;

        return AgentResult::reply(implode("\n", $lines), [
            'action'       => 'meeting_countdown',
            'meeting_time' => $meetTimeFormatted,
            'is_past'      => $isPast,
            'diff_seconds' => $diffSec,
        ]);
    }

    /**
     * Daily Planner — time-blocked daily plan template based on circadian rhythm and preferences.
     */
    private function handleDailyPlanner(array $prefs): AgentResult
    {
        $lang      = $prefs['language'] ?? 'fr';
        $userTzStr = $prefs['timezone'] ?? 'UTC';
        $style     = $prefs['communication_style'] ?? 'friendly';

        try {
            $userTz  = new DateTimeZone($userTzStr);
            $userNow = new DateTimeImmutable('now', $userTz);
        } catch (\Exception) {
            $userTz  = new DateTimeZone('UTC');
            $userNow = new DateTimeImmutable('now', $userTz);
        }

        $dateFormat = $prefs['date_format'] ?? 'd/m/Y';
        $isoDay     = (int) $userNow->format('N');
        $hour       = (int) $userNow->format('G');
        $isWeekend  = $isoDay >= 6;

        $workStart = (int) ($prefs['work_start'] ?? 9);
        $workEnd   = (int) ($prefs['work_end'] ?? 18);

        $dayName = $this->getDayName($isoDay, $lang);
        $dateStr = $userNow->format($dateFormat);

        $title = match ($lang) {
            'en' => 'DAILY PLANNER',
            'es' => 'PLANIFICADOR DIARIO',
            'de' => 'TAGESPLANER',
            'it' => 'PIANIFICATORE GIORNALIERO',
            'pt' => 'PLANEJADOR DIÁRIO',
            default => 'PLANNING JOURNALIER',
        };

        $lines = [
            "📋 *{$title}*",
            "════════════════",
            "📅 {$dayName} {$dateStr} — {$userNow->format('H:i')}",
            "",
        ];

        if ($isWeekend) {
            $weekendTitle = match ($lang) {
                'en' => 'Weekend Mode',
                'es' => 'Modo fin de semana',
                default => 'Mode week-end',
            };
            $lines[] = "🌴 *{$weekendTitle}*";
            $lines[] = "";

            $blocks = [
                ['08:00', '09:00', '🌅', match ($lang) { 'en' => 'Morning routine & breakfast', 'es' => 'Rutina matutina y desayuno', default => 'Routine matinale & petit-déjeuner' }],
                ['09:00', '10:30', '🏃', match ($lang) { 'en' => 'Exercise / outdoor activity', 'es' => 'Ejercicio / actividad al aire libre', default => 'Sport / activité en extérieur' }],
                ['10:30', '12:00', '📚', match ($lang) { 'en' => 'Personal projects / learning', 'es' => 'Proyectos personales / aprendizaje', default => 'Projets perso / apprentissage' }],
                ['12:00', '13:30', '🍽️', match ($lang) { 'en' => 'Lunch & relaxation', 'es' => 'Almuerzo y relajación', default => 'Déjeuner & détente' }],
                ['13:30', '15:30', '🎨', match ($lang) { 'en' => 'Hobbies / creative time', 'es' => 'Pasatiempos / tiempo creativo', default => 'Loisirs / temps créatif' }],
                ['15:30', '17:00', '👥', match ($lang) { 'en' => 'Social / family time', 'es' => 'Tiempo social / familiar', default => 'Temps social / famille' }],
                ['17:00', '19:00', '🛍️', match ($lang) { 'en' => 'Errands / free time', 'es' => 'Recados / tiempo libre', default => 'Courses / temps libre' }],
                ['19:00', '21:00', '🎬', match ($lang) { 'en' => 'Entertainment / dinner', 'es' => 'Entretenimiento / cena', default => 'Divertissement / dîner' }],
                ['21:00', '22:30', '📖', match ($lang) { 'en' => 'Wind down / reading', 'es' => 'Relajarse / lectura', default => 'Détente / lecture' }],
            ];
        } else {
            $workLabel = match ($lang) {
                'en' => 'Workday Mode',
                'es' => 'Modo laboral',
                default => 'Mode journée de travail',
            };
            $lines[] = "💼 *{$workLabel}*";
            $lines[] = "";

            $ws = sprintf('%02d:00', $workStart);
            $blocks = [
                [sprintf('%02d:00', max(6, $workStart - 2)), sprintf('%02d:00', max(7, $workStart - 1)), '🌅', match ($lang) { 'en' => 'Wake up & morning routine', 'es' => 'Despertar y rutina matutina', default => 'Réveil & routine matinale' }],
                [sprintf('%02d:00', max(7, $workStart - 1)), $ws, '☕', match ($lang) { 'en' => 'Breakfast & commute/prep', 'es' => 'Desayuno y preparación', default => 'Petit-déj & trajet/préparation' }],
                [$ws, sprintf('%02d:00', $workStart + 2), '⚡', match ($lang) { 'en' => 'Deep work (peak energy)', 'es' => 'Trabajo profundo (máxima energía)', default => 'Travail profond (pic d\'énergie)' }],
                [sprintf('%02d:00', $workStart + 2), sprintf('%02d:00', $workStart + 3), '💬', match ($lang) { 'en' => 'Meetings & collaboration', 'es' => 'Reuniones y colaboración', default => 'Réunions & collaboration' }],
                [sprintf('%02d:00', $workStart + 3), sprintf('%02d:00', $workStart + 4), '🍽️', match ($lang) { 'en' => 'Lunch break', 'es' => 'Pausa almuerzo', default => 'Pause déjeuner' }],
                [sprintf('%02d:00', $workStart + 4), sprintf('%02d:00', $workStart + 5), '📧', match ($lang) { 'en' => 'Emails & admin tasks', 'es' => 'Emails y tareas administrativas', default => 'Emails & tâches admin' }],
                [sprintf('%02d:00', $workStart + 5), sprintf('%02d:00', $workStart + 7), '🔥', match ($lang) { 'en' => 'Focused work (second wind)', 'es' => 'Trabajo enfocado (segunda energía)', default => 'Travail concentré (2e souffle)' }],
                [sprintf('%02d:00', $workStart + 7), sprintf('%02d:00', min(23, $workEnd)), '📝', match ($lang) { 'en' => 'Wrap up & plan tomorrow', 'es' => 'Cerrar y planificar mañana', default => 'Finalisation & planifier demain' }],
                [sprintf('%02d:00', min(23, $workEnd)), sprintf('%02d:00', min(23, $workEnd + 1)), '🏠', match ($lang) { 'en' => 'Commute / transition', 'es' => 'Trayecto / transición', default => 'Trajet / transition' }],
                [sprintf('%02d:00', min(23, $workEnd + 1)), sprintf('%02d:00', min(23, $workEnd + 3)), '🌙', match ($lang) { 'en' => 'Personal time & dinner', 'es' => 'Tiempo personal y cena', default => 'Temps perso & dîner' }],
                [sprintf('%02d:00', min(23, $workEnd + 3)), '22:30', '📖', match ($lang) { 'en' => 'Wind down & rest', 'es' => 'Relajarse y descansar', default => 'Détente & repos' }],
            ];
        }

        // Mark current block
        $nowMin = $hour * 60 + (int) $userNow->format('i');
        foreach ($blocks as [$start, $end, $emoji, $label]) {
            $startParts = explode(':', $start);
            $endParts   = explode(':', $end);
            $startMin   = (int) $startParts[0] * 60 + (int) ($startParts[1] ?? 0);
            $endMin     = (int) $endParts[0] * 60 + (int) ($endParts[1] ?? 0);

            $marker = ($nowMin >= $startMin && $nowMin < $endMin) ? '▶️' : '  ';
            $lines[] = "{$marker} *{$start}–{$end}* {$emoji} {$label}";
        }

        $lines[] = "";

        // Day progress
        $dayProgressPct = 0;
        if (!$isWeekend) {
            $workMinTotal = ($workEnd - $workStart) * 60;
            $elapsed      = max(0, $nowMin - ($workStart * 60));
            $dayProgressPct = min(100, (int) round($elapsed / max(1, $workMinTotal) * 100));
            $bar = $this->progressBar($dayProgressPct);
            $progLabel = match ($lang) { 'en' => 'Workday progress', 'es' => 'Progreso jornada', default => 'Progression journée' };
            $lines[] = "📊 *{$progLabel}:* {$bar} {$dayProgressPct}%";
        }

        // Productivity tip
        $tip = match (true) {
            $hour < $workStart => match ($lang) {
                'en' => 'Set your top 3 priorities before starting work.',
                'es' => 'Define tus 3 prioridades antes de empezar a trabajar.',
                default => 'Définis tes 3 priorités avant de commencer ta journée.',
            },
            $hour >= $workStart && $hour < $workStart + 2 => match ($lang) {
                'en' => 'Peak energy! Tackle your hardest task now.',
                'es' => '¡Máxima energía! Aborda tu tarea más difícil ahora.',
                default => 'Pic d\'énergie ! Attaque ta tâche la plus difficile maintenant.',
            },
            $hour >= 12 && $hour < 14 => match ($lang) {
                'en' => 'Post-lunch dip: handle lighter tasks or take a walk.',
                'es' => 'Bajón post-almuerzo: tareas ligeras o un paseo.',
                default => 'Coup de barre post-déjeuner : tâches légères ou petite marche.',
            },
            $hour >= $workEnd => match ($lang) {
                'en' => 'Work is done! Disconnect and recharge for tomorrow.',
                'es' => '¡Trabajo terminado! Desconecta y recarga para mañana.',
                default => 'Journée terminée ! Déconnecte et recharge pour demain.',
            },
            default => match ($lang) {
                'en' => 'Stay focused — use the Pomodoro technique for sustained productivity.',
                'es' => 'Mantén el enfoque — usa la técnica Pomodoro.',
                default => 'Reste concentré — utilise la technique Pomodoro pour une productivité soutenue.',
            },
        };

        $lines[] = "";
        $lines[] = "💡 _{$tip}_";
        $lines[] = "";
        $lines[] = "════════════════";
        $exLabel = match ($lang) {
            'en' => '_Try: meeting at 14h30, focus score, energy level, pomodoro_',
            'es' => '_Prueba: reunión a las 14:30, focus score, energy level, pomodoro_',
            default => '_Essaie : réunion à 14h30, focus score, niveau énergie, pomodoro_',
        };
        $lines[] = $exLabel;

        return AgentResult::reply(implode("\n", $lines), [
            'action'        => 'daily_planner',
            'is_weekend'    => $isWeekend,
            'day_progress'  => $dayProgressPct,
            'current_hour'  => $hour,
        ]);
    }

    // -------------------------------------------------------------------------
    // v1.62.0 — Timezone Matrix: visual cross-timezone planning grid
    // -------------------------------------------------------------------------

    private function handleTimezoneMatrix(array $parsed, array $prefs): AgentResult
    {
        $lang = $prefs['language'] ?? 'fr';
        $userTz = $prefs['timezone'] ?? 'UTC';

        try {
            $userZone = new \DateTimeZone($userTz);
        } catch (\Throwable) {
            $userZone = new \DateTimeZone('UTC');
            $userTz = 'UTC';
        }

        // Resolve cities: from parsed params, or use worldclock defaults
        $requestedCities = $parsed['cities'] ?? [];
        $cityTimezones = [];

        if (!empty($requestedCities)) {
            foreach ($requestedCities as $city) {
                $resolved = $this->resolveTimezoneString($city);
                if ($resolved) {
                    $cityTimezones[ucfirst($city)] = $resolved;
                }
            }
        }

        // Fallback to default world clock cities if none specified or none resolved
        if (empty($cityTimezones)) {
            $defaults = ['Paris' => 'Europe/Paris', 'New York' => 'America/New_York', 'Tokyo' => 'Asia/Tokyo', 'London' => 'Europe/London', 'Sydney' => 'Australia/Sydney'];
            // Remove user's own timezone to avoid duplication
            foreach ($defaults as $name => $tz) {
                if ($tz !== $userTz) {
                    $cityTimezones[$name] = $tz;
                }
            }
        }

        // Limit to 6 cities max for readability on WhatsApp
        $cityTimezones = array_slice($cityTimezones, 0, 6, true);

        $now = new \DateTimeImmutable('now', $userZone);
        $title = match ($lang) {
            'en' => "🗺️ *Timezone Matrix*",
            'es' => "🗺️ *Matriz de Zonas Horarias*",
            'de' => "🗺️ *Zeitzonen-Matrix*",
            'it' => "🗺️ *Matrice Fusi Orari*",
            'pt' => "🗺️ *Matriz de Fusos Horários*",
            default => "🗺️ *Matrice des Fuseaux Horaires*",
        };

        $lines = [$title, ""];

        // Header: show current hour reference
        $yourCity = match ($lang) {
            'en' => 'You',
            'es' => 'Tú',
            'de' => 'Du',
            default => 'Toi',
        };

        // Build the matrix: rows = hours (current ±6h), columns = cities
        $allZones = array_merge([$yourCity => $userTz], $cityTimezones);

        // Build header row
        $maxNameLen = max(array_map('mb_strlen', array_keys($allZones)));
        $maxNameLen = max($maxNameLen, 6);
        $headerPad = str_repeat(' ', $maxNameLen + 1);
        $hourLabels = '';
        $baseHour = (int) $now->format('G');

        // Show 12 hours starting from current hour
        $hours = [];
        for ($i = 0; $i < 12; $i += 2) {
            $hours[] = ($baseHour + $i) % 24;
        }

        foreach ($hours as $h) {
            $hourLabels .= str_pad(sprintf('%02d', $h), 5);
        }
        $lines[] = "```";
        $lines[] = "{$headerPad}{$hourLabels}";

        // Build row for each city
        foreach ($allZones as $name => $tz) {
            try {
                $zone = new \DateTimeZone($tz);
                $cityNow = $now->setTimezone($zone);
                $cityBaseHour = (int) $cityNow->format('G');
                $offsetDiff = ($zone->getOffset($now) - $userZone->getOffset($now)) / 3600;
            } catch (\Throwable) {
                continue;
            }

            $paddedName = mb_str_pad($name, $maxNameLen);
            $row = "{$paddedName} ";

            foreach ($hours as $h) {
                $cityHour = ($h + (int) round($offsetDiff)) % 24;
                if ($cityHour < 0) $cityHour += 24;

                // Visual indicator: ☀ day (8-18), 🌙 night (22-6), 🌅 transition
                if ($cityHour >= 9 && $cityHour <= 17) {
                    $indicator = ' ☀ ';
                } elseif ($cityHour >= 22 || $cityHour <= 5) {
                    $indicator = ' 🌙';
                } elseif ($cityHour >= 6 && $cityHour <= 8) {
                    $indicator = ' 🌅';
                } else {
                    $indicator = ' 🌇';
                }

                $row .= str_pad(sprintf('%02d', $cityHour), 2) . $indicator;
            }

            $lines[] = $row;
        }
        $lines[] = "```";

        // Legend
        $lines[] = "";
        $legendLabel = match ($lang) {
            'en' => "☀ Work hours  🌅 Morning  🌇 Evening  🌙 Night",
            'es' => "☀ Horario laboral  🌅 Mañana  🌇 Tarde  🌙 Noche",
            'de' => "☀ Arbeitszeit  🌅 Morgen  🌇 Abend  🌙 Nacht",
            default => "☀ Bureau  🌅 Matin  🌇 Soir  🌙 Nuit",
        };
        $lines[] = $legendLabel;

        // Best overlap hint
        $overlapHours = [];
        for ($h = 0; $h < 24; $h++) {
            $allInWork = true;
            foreach ($allZones as $tz) {
                try {
                    $zone = new \DateTimeZone($tz);
                    $offsetDiff = ($zone->getOffset($now) - $userZone->getOffset($now)) / 3600;
                    $cityHour = ($h + (int) round($offsetDiff)) % 24;
                    if ($cityHour < 0) $cityHour += 24;
                    if ($cityHour < 9 || $cityHour > 17) {
                        $allInWork = false;
                        break;
                    }
                } catch (\Throwable) {
                    $allInWork = false;
                    break;
                }
            }
            if ($allInWork) {
                $overlapHours[] = sprintf('%02d:00', $h);
            }
        }

        $lines[] = "";
        if (!empty($overlapHours)) {
            $overlapStr = implode(', ', array_slice($overlapHours, 0, 4));
            if (count($overlapHours) > 4) $overlapStr .= '…';
            $overlapLabel = match ($lang) {
                'en' => "✅ *Best meeting window* (your time): {$overlapStr}",
                'es' => "✅ *Mejor ventana para reunión* (tu hora): {$overlapStr}",
                'de' => "✅ *Bestes Meeting-Fenster* (deine Zeit): {$overlapStr}",
                default => "✅ *Meilleur créneau commun* (ton heure) : {$overlapStr}",
            };
            $lines[] = $overlapLabel;
        } else {
            $noOverlap = match ($lang) {
                'en' => "⚠️ _No common business hours overlap found. Consider async communication._",
                'es' => "⚠️ _No se encontró horario laboral en común. Considera comunicación asíncrona._",
                'de' => "⚠️ _Keine gemeinsamen Geschäftszeiten gefunden. Erwäge asynchrone Kommunikation._",
                default => "⚠️ _Aucun créneau de bureau commun trouvé. Envisage la communication asynchrone._",
            };
            $lines[] = $noOverlap;
        }

        $lines[] = "";
        $lines[] = "════════════════";
        $exLabel = match ($lang) {
            'en' => '_Try: timezone matrix Paris Tokyo Sydney, meeting planner, worldclock_',
            'es' => '_Prueba: timezone matrix Paris Tokyo Sydney, meeting planner, worldclock_',
            default => '_Essaie : matrice fuseaux Paris Tokyo Sydney, meeting planner, worldclock_',
        };
        $lines[] = $exLabel;

        return AgentResult::reply(implode("\n", $lines), [
            'action' => 'timezone_matrix',
            'cities_count' => count($cityTimezones),
            'overlap_hours' => count($overlapHours),
        ]);
    }
}
       