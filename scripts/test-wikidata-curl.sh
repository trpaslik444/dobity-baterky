#!/bin/bash
# Test Wikidata API pomocí curl
# Použití: bash scripts/test-wikidata-curl.sh

echo "🔍 Testování Wikidata API"
echo ""

# Testovací souřadnice (Praha)
LAT=50.0755
LNG=14.4378
RADIUS_KM=2

echo "📍 Testovací lokace: Praha (${LAT}, ${LNG})"
echo "Radius: ${RADIUS_KM} km"
echo ""

# SPARQL query
QUERY="SELECT ?item ?itemLabel ?location ?lat ?lon WHERE {
  SERVICE wikibase:around {
    ?item wdt:P625 ?location .
    bd:serviceParam wikibase:center \"Point(${LNG} ${LAT})\"^^geo:wktLiteral .
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
LIMIT 10"

echo "🔄 Volám Wikidata SPARQL API..."
echo ""

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
    echo "✅ HTTP Status: $HTTP_CODE"
    echo ""
    
    # Parse JSON response (použijeme Python nebo jq, pokud je dostupný)
    if command -v python3 &> /dev/null; then
        echo "📋 Nalezené POIs:"
        echo ""
        echo "$BODY" | python3 -c "
import json
import sys

try:
    data = json.load(sys.stdin)
    if 'results' in data and 'bindings' in data['results']:
        pois = data['results']['bindings']
        print(f'Celkem: {len(pois)} POIs')
        print('')
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
            print('')
    else:
        print('❌ Neplatná struktura odpovědi')
        print(json.dumps(data, indent=2)[:500])
except Exception as e:
    print(f'❌ Chyba při parsování JSON: {e}')
    print('Response:')
    print(sys.stdin.read()[:500])
" 2>/dev/null
        
        if [ $? -ne 0 ]; then
            echo "⚠️  Python není dostupný pro parsování JSON"
            echo "Raw response (prvních 1000 znaků):"
            echo "$BODY" | head -c 1000
            echo ""
        fi
    elif command -v jq &> /dev/null; then
        echo "📋 Nalezené POIs:"
        echo ""
        echo "$BODY" | jq -r '.results.bindings[] | "\(.itemLabel.value) | GPS: \(.lat.value), \(.lon.value) | ID: \(.item.value)"' 2>/dev/null
    else
        echo "⚠️  Python ani jq nejsou dostupné pro parsování JSON"
        echo "Raw response (prvních 1000 znaků):"
        echo "$BODY" | head -c 1000
        echo ""
        echo ""
        echo "💡 Pro lepší výstup nainstalujte Python3 nebo jq"
    fi
else
    echo "❌ HTTP Status: $HTTP_CODE"
    echo "Response:"
    echo "$BODY" | head -c 500
    echo ""
fi

echo ""
echo "✅ Test dokončen!"

