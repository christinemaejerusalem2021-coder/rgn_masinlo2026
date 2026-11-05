<?php

$host = 'localhost';
$dbname = 'rgn_masinlo_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Unable to connect to the database. Check your XAMPP and database settings.');
}

const CAMP_NAME          = 'RGN Masinlo Beach Camp';
const CAMP_LOCATION      = 'Sitio Asis, Brgy. Maroyogroyog, Linapacan, Palawan';
const CAMP_PACKAGE_PRICE = 1000.00;   
const CAMP_CAPACITY      = 36;        
const CAMP_MIN_GUESTS    = 18;        
const CAMP_NIGHTS        = 2;
const KID_FREE_QTY       = 5;         // unang 5 bata (3 years old & below) ay libre
const KID_MAX_QTY        = 10;        // max na bilang ng batang 3 years old & below
const KID_PRICE          = 300.00;    // presyo bawat batang lampas sa KID_FREE_QTY

// Kasama sa package (walang dagdag na bayad).
const INCLUDED_MEALS = ['Breakfast', 'Lunch', 'Dinner'];
const INCLUDED_ACTIVITIES = ['Videoke', 'Bonfire', 'Fire Spinning', 'Volleyball', 'Picnic', 'Camping'];

// Water activities: HINDI kasama sa package, may dagdag na bayad.
// SAMPLE RATES LANG ITO. Palitan ng totoong presyo ng camp.
// Format: 'key' => ['Pangalan', 'Description', presyo, 'pax' o 'boat']
//   'pax'  = presyo bawat tao
//   'boat' = presyo bawat bangka (para sa buong grupo)
const PAID_ACTIVITIES = [
    'island_hopping' => ['Island Hopping',     'Explore nearby islands, beaches, and beautiful coastal scenery.', 4500, 'boat'],
    'snorkeling'     => ['Snorkeling',         'Discover colorful fish and marine life underwater.',               300, 'pax'],
    'freediving'     => ['Freediving',         'Introductory freediving session with an instructor.',             1500, 'pax'],
    'scuba'          => ['Scuba Diving',       'Guided dive with full equipment.',                                3500, 'pax'],
    'kayaking'       => ['Kayaking',           'Paddle along the coastline and enjoy the ocean views.',            300, 'pax'],
    'lagoon'         => ['Lagoon Exploration', 'Boat trip with a guide to the nearby lagoons.',                    500, 'pax'],
    'boat_cruising'  => ['Boat Cruising',      'Relaxing boat cruise with scenic island views.',                  3500, 'boat'],
];

// Activity Requirements & Safety Guidelines (lumalabas sa booking form).
// DRAFT ito. Ipa-review sa camp / sa operator ng bawat activity bago i-publish.
const GENERAL_SAFETY = [
    'All water activities are subject to weather and sea conditions. The camp may cancel or reschedule for safety.',
    'Guests with heart, breathing, or other medical conditions should consult a doctor first and inform the camp.',
    'Always follow the instructions of the guide, boat crew, or instructor.',
];

const ACTIVITY_GUIDELINES = [
    'Snorkeling' => [
        'Children must be accompanied by a responsible adult.',
        'Properly fitted life jackets are recommended for children and non-swimmers.',
        "Follow the guide's safety instructions.",
    ],
    'Scuba Diving' => [
        'Minimum age depends on the diving program and operator.',
        "Children may participate only if they meet the program's age and safety requirements.",
        'Required training and health screening must be completed where applicable.',
    ],
    'Freediving' => [
        "Participants must meet the instructor's age and swimming requirements.",
        'Minors require parent or guardian consent where required.',
        'Beginners must receive proper instruction and supervision.',
    ],
    'Kayaking' => [
        'Life jackets must be worn at all times.',
        'Children must be accompanied by a responsible adult.',
        "Stay within the area set by the guide.",
    ],
    'Island Hopping, Boat Cruising & Lagoon Exploration' => [
        'Life jackets must be worn on the boat, especially by children and non-swimmers.',
        'Children must be accompanied by a responsible adult.',
        "Follow the boat crew's and guide's instructions.",
    ],
];

// Activity Packages (mas mura kaysa isa-isang bayad). SAMPLE DISCOUNTS LANG.
// Format: 'key' => ['Pangalan', [mga activity key mula sa PAID_ACTIVITIES], discount %]
// Per-person ang presyo ng package (kasama ang per-bangka na activity).
const ACTIVITY_BUNDLES = [
    'island_fun' => ['Island Fun Package', ['island_hopping', 'snorkeling', 'kayaking'], 15],
    'lagoon_cruise' => ['Lagoon & Cruise Package', ['lagoon', 'boat_cruising', 'snorkeling'], 15],
    'complete_water' => ['Complete Water Adventure', ['island_hopping', 'snorkeling', 'freediving', 'scuba', 'kayaking', 'lagoon', 'boat_cruising'], 25],
];

// Mga karagdagang request ng guest (depende sa availability).
// Format: 'Pangalan' => [presyo, 'pax' o 'flat']
//   'pax'  = presyo bawat guest (i-multiply sa bilang ng guests)
//   'flat' = isang bayad lang para sa buong grupo
// ESTIMATED RATES LANG ITO. Palitan ng totoong presyo ng camp.
const REQUEST_OPTIONS = [
    'Towel Rental'                => [50,   'pax'],
    'Video / Photo Service'       => [3500, 'flat'],
    'Birthday / Celebration Setup' => [2500, 'flat'],
    'Extra Pillows & Blankets'    => [100,  'pax'],
    'Ice & Cooler'                => [500,  'flat'],
];