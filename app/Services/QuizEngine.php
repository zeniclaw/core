<?php

namespace App\Services;

class QuizEngine
{
    private const QUESTIONS_PER_QUIZ = 5;

    private const CATEGORIES = [
        'histoire' => [
            ['question' => 'En quelle année la Révolution française a-t-elle commencé ?', 'options' => ['1776', '1789', '1804', '1815'], 'answer' => 1],
            ['question' => 'Qui a découvert l\'Amérique en 1492 ?', 'options' => ['Magellan', 'Vasco de Gama', 'Christophe Colomb', 'Marco Polo'], 'answer' => 2],
            ['question' => 'Quelle civilisation a construit les pyramides de Gizeh ?', 'options' => ['Romaine', 'Grecque', 'Égyptienne', 'Mésopotamienne'], 'answer' => 2],
            ['question' => 'Qui était le premier empereur romain ?', 'options' => ['Jules César', 'Auguste', 'Néron', 'Caligula'], 'answer' => 1],
            ['question' => 'En quelle année le mur de Berlin est-il tombé ?', 'options' => ['1987', '1989', '1991', '1993'], 'answer' => 1],
            ['question' => 'Quel pays a lancé le premier satellite artificiel ?', 'options' => ['USA', 'URSS', 'France', 'Chine'], 'answer' => 1],
            ['question' => 'Qui a peint la Joconde ?', 'options' => ['Michel-Ange', 'Raphaël', 'Léonard de Vinci', 'Botticelli'], 'answer' => 2],
            ['question' => 'La Seconde Guerre mondiale a pris fin en quelle année ?', 'options' => ['1943', '1944', '1945', '1946'], 'answer' => 2],
            ['question' => 'Quel pharaon avait le masque funéraire en or ?', 'options' => ['Ramsès II', 'Toutânkhamon', 'Khéops', 'Cléopâtre'], 'answer' => 1],
            ['question' => 'Quelle guerre a duré de 1337 à 1453 ?', 'options' => ['Guerre de Trente Ans', 'Guerre de Cent Ans', 'Croisades', 'Guerre des Roses'], 'answer' => 1],
        ],
        'science' => [
            ['question' => 'Quel est le symbole chimique de l\'or ?', 'options' => ['Ag', 'Au', 'Fe', 'Cu'], 'answer' => 1],
            ['question' => 'Combien d\'os le corps humain adulte possède-t-il ?', 'options' => ['186', '206', '226', '256'], 'answer' => 1],
            ['question' => 'Quelle planète est la plus proche du Soleil ?', 'options' => ['Vénus', 'Mars', 'Mercure', 'Terre'], 'answer' => 2],
            ['question' => 'Quel gaz représente environ 78% de l\'atmosphère terrestre ?', 'options' => ['Oxygène', 'Azote', 'CO2', 'Argon'], 'answer' => 1],
            ['question' => 'Quelle est la vitesse de la lumière (km/s) ?', 'options' => ['200 000', '300 000', '400 000', '150 000'], 'answer' => 1],
            ['question' => 'Quel organe produit l\'insuline ?', 'options' => ['Foie', 'Pancréas', 'Rein', 'Estomac'], 'answer' => 1],
            ['question' => 'Combien de chromosomes possède une cellule humaine ?', 'options' => ['23', '44', '46', '48'], 'answer' => 2],
            ['question' => 'Quel est l\'élément le plus abondant dans l\'univers ?', 'options' => ['Oxygène', 'Carbone', 'Hélium', 'Hydrogène'], 'answer' => 3],
            ['question' => 'Qu\'est-ce qu\'un année-lumière mesure ?', 'options' => ['Temps', 'Distance', 'Masse', 'Vitesse'], 'answer' => 1],
            ['question' => 'Quel scientifique a formulé la théorie de la relativité ?', 'options' => ['Newton', 'Einstein', 'Hawking', 'Bohr'], 'answer' => 1],
        ],
        'pop' => [
            ['question' => 'Quel film a remporté l\'Oscar du meilleur film en 1994 ?', 'options' => ['Pulp Fiction', 'Forrest Gump', 'Le Roi Lion', 'Shawshank'], 'answer' => 1],
            ['question' => 'Qui chante "Bohemian Rhapsody" ?', 'options' => ['Beatles', 'Queen', 'Led Zeppelin', 'Pink Floyd'], 'answer' => 1],
            ['question' => 'Dans quel univers se trouve Wakanda ?', 'options' => ['DC Comics', 'Marvel', 'Image Comics', 'Dark Horse'], 'answer' => 1],
            ['question' => 'Quel jeu vidéo met en scène Mario ?', 'options' => ['Sega', 'Nintendo', 'Atari', 'Sony'], 'answer' => 1],
            ['question' => 'Qui a créé Mickey Mouse ?', 'options' => ['Steven Spielberg', 'Walt Disney', 'Jim Henson', 'George Lucas'], 'answer' => 1],
            ['question' => 'Quel est le vrai nom de Batman ?', 'options' => ['Clark Kent', 'Bruce Wayne', 'Peter Parker', 'Tony Stark'], 'answer' => 1],
            ['question' => 'Dans quelle saga apparaît Gandalf ?', 'options' => ['Harry Potter', 'Narnia', 'Le Seigneur des Anneaux', 'Star Wars'], 'answer' => 2],
            ['question' => 'Quel groupe a chanté "Hotel California" ?', 'options' => ['Fleetwood Mac', 'Eagles', 'Bee Gees', 'ABBA'], 'answer' => 1],
            ['question' => 'Quel réseau social utilise des "stories" éphémères en premier ?', 'options' => ['Instagram', 'Snapchat', 'Facebook', 'TikTok'], 'answer' => 1],
            ['question' => 'Combien de films Harry Potter existe-t-il ?', 'options' => ['6', '7', '8', '9'], 'answer' => 2],
        ],
        'sport' => [
            ['question' => 'Combien de joueurs composent une équipe de football ?', 'options' => ['9', '10', '11', '12'], 'answer' => 2],
            ['question' => 'Dans quel pays se sont déroulés les JO de 2024 ?', 'options' => ['Japon', 'USA', 'France', 'Australie'], 'answer' => 2],
            ['question' => 'Quel sport pratique Roger Federer ?', 'options' => ['Golf', 'Tennis', 'Badminton', 'Squash'], 'answer' => 1],
            ['question' => 'Combien de sets faut-il gagner pour remporter un match de tennis masculin en Grand Chelem ?', 'options' => ['2', '3', '4', '5'], 'answer' => 1],
            ['question' => 'Quel pays a remporté le plus de Coupes du Monde de football ?', 'options' => ['Allemagne', 'Argentine', 'Brésil', 'Italie'], 'answer' => 2],
            ['question' => 'Quelle est la distance d\'un marathon ?', 'options' => ['40 km', '42.195 km', '45 km', '50 km'], 'answer' => 1],
            ['question' => 'Quel sport se joue avec un volant ?', 'options' => ['Tennis', 'Squash', 'Badminton', 'Ping-pong'], 'answer' => 2],
            ['question' => 'Combien de points vaut un touchdown au football américain ?', 'options' => ['3', '5', '6', '7'], 'answer' => 2],
            ['question' => 'Qui détient le record du 100m ?', 'options' => ['Carl Lewis', 'Usain Bolt', 'Tyson Gay', 'Yohan Blake'], 'answer' => 1],
            ['question' => 'Dans quel sport utilise-t-on un fleuret ?', 'options' => ['Boxe', 'Escrime', 'Karaté', 'Lutte'], 'answer' => 1],
        ],
        'geo' => [
            ['question' => 'Quelle est la capitale de l\'Australie ?', 'options' => ['Sydney', 'Melbourne', 'Canberra', 'Brisbane'], 'answer' => 2],
            ['question' => 'Quel est le plus long fleuve du monde ?', 'options' => ['Amazone', 'Nil', 'Mississippi', 'Yangtsé'], 'answer' => 1],
            ['question' => 'Quel pays a la plus grande superficie ?', 'options' => ['Canada', 'Chine', 'USA', 'Russie'], 'answer' => 3],
            ['question' => 'Sur quel continent se trouve l\'Égypte ?', 'options' => ['Asie', 'Afrique', 'Europe', 'Moyen-Orient'], 'answer' => 1],
            ['question' => 'Quel océan est le plus grand ?', 'options' => ['Atlantique', 'Indien', 'Pacifique', 'Arctique'], 'answer' => 2],
            ['question' => 'Quelle est la capitale du Japon ?', 'options' => ['Osaka', 'Kyoto', 'Tokyo', 'Yokohama'], 'answer' => 2],
            ['question' => 'Quel pays est surnommé le "pays du soleil levant" ?', 'options' => ['Chine', 'Corée', 'Japon', 'Thaïlande'], 'answer' => 2],
            ['question' => 'Quel est le plus haut sommet du monde ?', 'options' => ['K2', 'Everest', 'Kilimandjaro', 'Mont Blanc'], 'answer' => 1],
            ['question' => 'Combien de continents y a-t-il ?', 'options' => ['5', '6', '7', '8'], 'answer' => 2],
            ['question' => 'Quel pays a la forme d\'une botte ?', 'options' => ['Grèce', 'Espagne', 'Italie', 'Portugal'], 'answer' => 2],
        ],
        'tech' => [
            ['question' => 'Qui a fondé Microsoft ?', 'options' => ['Steve Jobs', 'Bill Gates', 'Mark Zuckerberg', 'Jeff Bezos'], 'answer' => 1],
            ['question' => 'Quel langage de programmation a créé Guido van Rossum ?', 'options' => ['Java', 'Ruby', 'Python', 'PHP'], 'answer' => 2],
            ['question' => 'Que signifie HTML ?', 'options' => ['HyperText Markup Language', 'High Tech Modern Language', 'Home Tool Markup Language', 'Hyper Transfer Markup Language'], 'answer' => 0],
            ['question' => 'En quelle année l\'iPhone a-t-il été lancé ?', 'options' => ['2005', '2007', '2009', '2010'], 'answer' => 1],
            ['question' => 'Quel est le système d\'exploitation mobile de Google ?', 'options' => ['iOS', 'Windows', 'Android', 'Linux'], 'answer' => 2],
            ['question' => 'Que signifie CPU ?', 'options' => ['Central Process Unit', 'Central Processing Unit', 'Computer Personal Unit', 'Core Processing Unit'], 'answer' => 1],
            ['question' => 'Quel protocole sécurise les sites web (cadenas) ?', 'options' => ['HTTP', 'FTP', 'HTTPS', 'SSH'], 'answer' => 2],
            ['question' => 'Combien de bits dans un octet ?', 'options' => ['4', '8', '16', '32'], 'answer' => 1],
            ['question' => 'Quel réseau social a été créé par Mark Zuckerberg ?', 'options' => ['Twitter', 'Instagram', 'Facebook', 'Snapchat'], 'answer' => 2],
            ['question' => 'Quel est le langage principal du web côté client ?', 'options' => ['Python', 'Java', 'JavaScript', 'C++'], 'answer' => 2],
        ],
    ];

    private const CATEGORY_LABELS = [
        'histoire' => '📜 Histoire',
        'science' => '🔬 Science',
        'pop' => '🎬 Pop Culture',
        'sport' => '⚽ Sport',
        'geo' => '🌍 Géographie',
        'tech' => '💻 Technologie',
    ];

    private const CATEGORY_ALIASES = [
        'history' => 'histoire',
        'sciences' => 'science',
        'culture' => 'pop',
        'cinema' => 'pop',
        'film' => 'pop',
        'musique' => 'pop',
        'sports' => 'sport',
        'football' => 'sport',
        'geographie' => 'geo',
        'geography' => 'geo',
        'pays' => 'geo',
        'capitale' => 'geo',
        'technologie' => 'tech',
        'technology' => 'tech',
        'info' => 'tech',
        'informatique' => 'tech',
        'programming' => 'tech',
    ];

    public static function getCategories(): array
    {
        return self::CATEGORY_LABELS;
    }

    public static function resolveCategory(?string $input): ?string
    {
        if (!$input) return null;
        $input = mb_strtolower(trim($input));

        if (isset(self::CATEGORIES[$input])) return $input;
        if (isset(self::CATEGORY_ALIASES[$input])) return self::CATEGORY_ALIASES[$input];

        // Fuzzy match
        foreach (array_keys(self::CATEGORIES) as $cat) {
            if (str_contains($input, $cat) || str_contains($cat, $input)) return $cat;
        }
        foreach (self::CATEGORY_ALIASES as $alias => $cat) {
            if (str_contains($input, $alias)) return $cat;
        }

        return null;
    }

    public static function generateQuiz(?string $category = null, int $count = null): array
    {
        $count = $count ?? self::QUESTIONS_PER_QUIZ;

        if (!$category || !isset(self::CATEGORIES[$category])) {
            // Random mix from all categories
            $allQuestions = [];
            foreach (self::CATEGORIES as $cat => $questions) {
                foreach ($questions as $q) {
                    $q['category'] = $cat;
                    $allQuestions[] = $q;
                }
            }
            shuffle($allQuestions);
            $selected = array_slice($allQuestions, 0, $count);
            $categoryLabel = '🎲 Mix';
        } else {
            $questions = self::CATEGORIES[$category];
            shuffle($questions);
            $selected = array_slice($questions, 0, min($count, count($questions)));
            foreach ($selected as &$q) {
                $q['category'] = $category;
            }
            $categoryLabel = self::CATEGORY_LABELS[$category] ?? $category;
        }

        // Shuffle options for each question
        foreach ($selected as &$q) {
            $correctAnswer = $q['options'][$q['answer']];
            shuffle($q['options']);
            $q['answer'] = array_search($correctAnswer, $q['options']);
        }

        return [
            'category' => $category ?? 'mix',
            'category_label' => $categoryLabel,
            'questions' => $selected,
        ];
    }

    public static function formatQuestion(array $question, int $questionNumber, int $totalQuestions): string
    {
        $letters = ['A', 'B', 'C', 'D'];
        $categoryEmoji = self::CATEGORY_LABELS[$question['category'] ?? 'general'] ?? '❓';

        $text = "📝 *Question {$questionNumber}/{$totalQuestions}* ({$categoryEmoji})\n\n";
        $text .= "_{$question['question']}_\n\n";

        foreach ($question['options'] as $i => $option) {
            $text .= "{$letters[$i]}. {$option}\n";
        }

        $text .= "\n💡 Réponds avec *A*, *B*, *C* ou *D*";

        return $text;
    }

    public static function checkAnswer(array $question, string $userAnswer): bool
    {
        $letters = ['a' => 0, 'b' => 1, 'c' => 2, 'd' => 3];
        $answer = mb_strtolower(trim($userAnswer));

        // Accept letter
        if (isset($letters[$answer])) {
            return $letters[$answer] === $question['answer'];
        }

        // Accept number (1-4)
        $num = intval($answer);
        if ($num >= 1 && $num <= 4) {
            return ($num - 1) === $question['answer'];
        }

        // Accept full text match
        foreach ($question['options'] as $i => $option) {
            if (mb_strtolower($option) === $answer) {
                return $i === $question['answer'];
            }
        }

        return false;
    }

    public static function getCorrectAnswerText(array $question): string
    {
        $letters = ['A', 'B', 'C', 'D'];
        $idx = $question['answer'];
        return "{$letters[$idx]}. {$question['options'][$idx]}";
    }

    public static function formatScore(int $correct, int $total): string
    {
        $pct = $total > 0 ? round(($correct / $total) * 100) : 0;

        if ($pct === 100) return "🏆 PARFAIT ! {$correct}/{$total} (100%)";
        if ($pct >= 80) return "🌟 Excellent ! {$correct}/{$total} ({$pct}%)";
        if ($pct >= 60) return "👍 Bien joué ! {$correct}/{$total} ({$pct}%)";
        if ($pct >= 40) return "😊 Pas mal ! {$correct}/{$total} ({$pct}%)";
        return "💪 Continue ! {$correct}/{$total} ({$pct}%)";
    }
}
