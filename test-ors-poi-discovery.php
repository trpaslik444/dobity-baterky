<?php
/**
 * Test script pro otestování OpenRouteService POI API
 * a porovnání s Google Places API pro získávání POI v okolí nabíječek
 */

// Testovací souřadnice nabíječky
$charging_station = [
    'id' => 4898,
    'name' => 'Pražská energetika, a.s. - Mírového hnutí',
    'lat' => 50.03815,
    'lng' => 14.500194,
    'google_place_id' => 'ChIJh6wKiDaSC0cRKeHbhH8HLs8'
];

echo "=== TEST OPENROUTESERVICE POI API ===\n";
echo "Nabíječka: {$charging_station['name']}\n";
echo "Souřadnice: {$charging_station['lat']}, {$charging_station['lng']}\n\n";

// Test ORS API (s omezením 2km)
$ors_api_key = getenv('ORS_API_KEY') ?: 'your_ors_api_key_here';

$ors_request = [
    'request' => 'pois',
    'geometry' => [
        'geojson' => [
            'type' => 'Point',
            'coordinates' => [$charging_station['lng'], $charging_station['lat']]
        ],
        'buffer' => 2000 // maximálně 2km kvůli limitaci ORS
    ],
    'filters' => [
        'category_ids' => [130, 131, 132, 133], // restaurace, fast food, kavárny, bary
        'amenity' => ['restaurant', 'cafe', 'fast_food', 'bar']
    ],
    'limit' => 50
];

echo "ORS Request:\n";
echo json_encode($ors_request, JSON_PRETTY_PRINT) . "\n\n";

// Simulace ORS odpovědi (protože nemáme API klíč)
$ors_response = [
    'type' => 'FeatureCollection',
    'features' => [
        [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [14.502, 50.040]
            ],
            'properties' => [
                'name' => 'Restaurace U Mírového hnutí',
                'amenity' => 'restaurant',
                'cuisine' => 'czech',
                'opening_hours' => 'Mo-Su 11:00-22:00',
                'website' => 'https://example.com',
                'phone' => '+420 123 456 789'
            ]
        ],
        [
            'type' => 'Feature', 
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [14.498, 50.036]
            ],
            'properties' => [
                'name' => 'Kavárna Dobrá chuť',
                'amenity' => 'cafe',
                'cuisine' => 'coffee_shop',
                'opening_hours' => 'Mo-Fr 07:00-18:00',
                'website' => null,
                'phone' => null
            ]
        ]
    ]
];

echo "ORS Response (simulace):\n";
echo json_encode($ors_response, JSON_PRETTY_PRINT) . "\n\n";

echo "=== POROVNÁNÍ S GOOGLE PLACES API ===\n\n";

// Google Places Nearby Search
$google_api_key = getenv('GOOGLE_API_KEY') ?: 'your_google_api_key_here';

$google_request = [
    'location' => "{$charging_station['lat']},{$charging_station['lng']}",
    'radius' => 5000, // 5km - žádné omezení
    'type' => 'restaurant|cafe|food|lodging',
    'minprice' => 0,
    'maxprice' => 4,
    'fields' => 'place_id,name,rating,user_ratings_total,vicinity,geometry,photos,opening_hours'
];

echo "Google Places Request:\n";
echo json_encode($google_request, JSON_PRETTY_PRINT) . "\n\n";

// Simulace Google odpovědi
$google_response = [
    'results' => [
        [
            'place_id' => 'ChIJ123456789',
            'name' => 'Restaurace U Mírového hnutí',
            'rating' => 4.5,
            'user_ratings_total' => 234,
            'vicinity' => 'Praha 4, Mírového hnutí',
            'geometry' => [
                'location' => [
                    'lat' => 50.040,
                    'lng' => 14.502
                ]
            ],
            'photos' => [
                [
                    'photo_reference' => 'Aap_uEA...',
                    'height' => 1080,
                    'width' => 1920
                ]
            ],
            'opening_hours' => [
                'open_now' => true,
                'weekday_text' => [
                    'Monday: 11:00 AM – 10:00 PM',
                    'Tuesday: 11:00 AM – 10:00 PM',
                    // ...
                ]
            ]
        ],
        [
            'place_id' => 'ChIJ987654321',
            'name' => 'Kavárna Dobrá chuť',
            'rating' => 4.2,
            'user_ratings_total' => 156,
            'vicinity' => 'Praha 4, Mírového hnutí',
            'geometry' => [
                'location' => [
                    'lat' => 50.036,
                    'lng' => 14.498
                ]
            ],
            'photos' => [],
            'opening_hours' => [
                'open_now' => false,
                'weekday_text' => [
                    'Monday: 7:00 AM – 6:00 PM',
                    'Tuesday: 7:00 AM – 6:00 PM',
                    // ...
                ]
            ]
        ]
    ],
    'status' => 'OK'
];

echo "Google Places Response (simulace):\n";
echo json_encode($google_response, JSON_PRETTY_PRINT) . "\n\n";

echo "=== ANALÝZA A DOPORUČENÍ ===\n\n";

echo "LIMITY OPENROUTESERVICE:\n";
echo "❌ Maximální rádius pouze 2 km (požadováno 5 km)\n";
echo "❌ Žádné hodnocení POI (požadováno > 4 hvězdy)\n";
echo "❌ Omezené kategorie a metadata\n";
echo "✅ Zdarma k použití\n";
echo "✅ Data z OpenStreetMap\n\n";

echo "VÝHODY GOOGLE PLACES API:\n";
echo "✅ Neomezený rádius (5 km možné)\n";
echo "✅ Hodnocení a počet hodnocení\n";
echo "✅ Bohatá metadata (fotky, otevírací doba, kontakty)\n";
echo "✅ Vysoká kvalita dat\n";
echo "❌ Placené API\n";
echo "❌ Quota limity\n\n";

echo "DOPORUČENÍ:\n";
echo "Pro váš use case (POI v okolí 5km s hodnocením > 4 hvězdy) \n";
echo "doporučuji použít Google Places API, které už máte implementované.\n\n";

echo "MOŽNÁ HYBRIDNÍ ŘEŠENÍ:\n";
echo "1. Použít ORS pro základní POI v okruhu 2km (zdarma)\n";
echo "2. Doplňit Google Places pro vzdálenější POI s hodnocením\n";
echo "3. Kombinovat výsledky a odstraňovat duplicity\n\n";

echo "IMPLEMENTACE:\n";
echo "Můžete rozšířit stávající Charging_Discovery třídu o metodu:\n";
echo "- discoverNearbyPOIs(\$charging_station_id, \$radius = 5000, \$min_rating = 4.0)\n";
echo "- Kombinovat ORS (do 2km) + Google Places (celý rádius)\n";
echo "- Uložit výsledky jako nové POI posty nebo jako metadata k nabíječce\n";
