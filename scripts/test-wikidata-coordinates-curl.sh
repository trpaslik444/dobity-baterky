#!/bin/bash
# Test Wikidata API na konkrétních souřadnicích pomocí curl
# Použití: bash scripts/test-wikidata-coordinates-curl.sh

echo "🔍 Testování Wikidata API na konkrétních souřadnicích"
echo ""

# Testovací souřadnice
declare -a COORDS=(
    "49.9333900,14.1843919,Location 1"
    "49.9433411,14.6045947,Location 2"
    "49.9230239,14.5762439,Location 3"
    "49.8978919,14.7136489,Location 4"
    "49.7138500,14.9122900,Location 5"
)

RADIUS_KM=2

echo "Radius: ${RADIUS_KM} km"
echo ""

TOTAL_POIS=0
TOTAL_LOCATIONS=${#COORDS[@]}

for coord_data in "${COORDS[@]}"; do
    IFS=',' read -r LAT LNG NAME <<< "$coord_data"
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "📍 $NAME"
    echo "   GPS: $LAT, $LNG"
    echo ""
    
    # SPARQL query
    QUERY="SELECT ?item ?itemLabel ?location ?lat ?lon WHERE {
      SERVICE wikibase:around {
        ?item wdt:P625 ?location .
        bd:serviceParam wikibase:center \"Point($LNG $LAT)\"^^geo:wktLiteral .
        bd:serviceParam wikibase:radius \"${RADIUS_KM}\" .
      }
      {
        ?item wdt:P31/wdt:P279* ?type .
        VALUES ?type {
          wd:Q33506    # Museum
          wd:Q190598   # Art gallery
          wd:Q570116   # Tourist attraction
          wd:Q1075788  # Viewpoint
          wd:Q22698    # Park
        }
      }
      BIND(geof:latitude(?location) AS ?lat)
      BIND(geof:longitude(?location) AS ?lon)
      SERVICE wikibase:label { 
        bd:serviceParam wikibase:language \"cs,en\" . 
      }
    }
    LIMIT 20"
    
    echo "   🔄 Volám Wikidata SPARQL API..."
    
    # Volat Wikidata API
    RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "https://query.wikidata.org/sparql" \
      -H "Accept: application/sparql-results+json" \
      -H "Content-Type: application/x-www-form-urlencoded" \
      -H "User-Agent: DobityBaterky-Test/1.0" \
      --data-urlencode "query=${QUERY}" \
      --data-urlencode "format=json")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
    BODY=$(echo "$RESPONSE" | sed '$d')
    
    if [ "$HTTP_CODE" = "200" ]; then
        # Parse JSON response pomocí Python
        if command -v python3 &> /dev/null; then
            POIS_COUNT=$(echo "$BODY" | python3 -c "
import json
import sys

try:
    data = json.load(sys.stdin)
    if 'results' in data and 'bindings' in data['results']:
        pois = data['results']['bindings']
        print(len(pois))
        for i, poi in enumerate(pois, 1):
            name = poi.get('itemLabel', {}).get('value', 'N/A')
            lat = poi.get('lat', {}).get('value', 'N/A')
            lon = poi.get('lon', {}).get('value', 'N/A')
            item_uri = poi.get('item', {}).get('value', '')
            item_id = 'N/A'
            if 'Q' in item_uri:
                import re
                match = re.search(r'Q\d+', item_uri)
                if match:
                    item_id = match.group(0)
            
            print(f'{i}. {name}')
            print(f'   GPS: {lat}, {lon} | Wikidata ID: {item_id}')
    else:
        print('0')
except Exception as e:
    print('0')
" 2>/dev/null)
            
            if [ $? -eq 0 ]; then
                POIS_LIST=$(echo "$BODY" | python3 -c "
import json
import sys

try:
    data = json.load(sys.stdin)
    if 'results' in data and 'bindings' in data['results']:
        pois = data['results']['bindings']
        for i, poi in enumerate(pois, 1):
            name = poi.get('itemLabel', {}).get('value', 'N/A')
            lat = poi.get('lat', {}).get('value', 'N/A')
            lon = poi.get('lon', {}).get('value', 'N/A')
            item_uri = poi.get('item', {}).get('value', '')
            item_id = 'N/A'
            if 'Q' in item_uri:
                import re
                match = re.search(r'Q\d+', item_uri)
                if match:
                    item_id = match.group(0)
            
            print(f'{i}. {name}')
            print(f'   GPS: {lat}, {lon} | Wikidata ID: {item_id}')
except:
    pass
" 2>/dev/null)
                
                COUNT=$(echo "$POIS_COUNT" | head -n1)
                TOTAL_POIS=$((TOTAL_POIS + COUNT))
                
                echo "   ✅ Nalezeno $COUNT POIs"
                echo ""
                if [ "$COUNT" -gt 0 ]; then
                    echo "   📋 Seznam POIs:"
                    echo "$POIS_LIST" | sed 's/^/   /'
                    echo ""
                else
                    echo "   ⚠️  Žádné POIs nenalezeny"
                    echo ""
                fi
            else
                echo "   ❌ Chyba při parsování JSON"
            fi
        else
            echo "   ⚠️  Python není dostupný pro parsování JSON"
            echo "   HTTP Status: $HTTP_CODE"
        fi
    else
        echo "   ❌ HTTP Status: $HTTP_CODE"
        echo "   Response: $(echo "$BODY" | head -c 200)"
        echo ""
    fi
    
    # Pauza mezi requesty
    if [ "$NAME" != "Location 5" ]; then
        sleep 1
    fi
done

# Shrnutí
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📊 SHRNUTÍ"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Celkem testovaných lokací: $TOTAL_LOCATIONS"
echo "Celkem nalezených POIs: $TOTAL_POIS"
echo ""
echo "✅ Test dokončen!"

