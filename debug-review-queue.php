<?php
/**
 * Debug review queue pro post_id 4038
 */

require_once('../../../wp-config.php');

echo "=== DEBUG REVIEW QUEUE POST_ID 4038 ===\n\n";

$post_id = 4038;

// Zkontrolovat review queue
global $wpdb;
$table_name = $wpdb->prefix . 'db_charging_discovery_queue';

echo "🔄 Zkontrolovat review queue...\n";
$queue_items = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_name WHERE post_id = %d ORDER BY created_at DESC LIMIT 5",
    $post_id
));

if ($queue_items) {
    echo "Review queue items:\n";
    foreach ($queue_items as $item) {
        echo "  ID: {$item->id}, Status: {$item->status}, Created: {$item->created_at}\n";
        echo "  Google ID: " . ($item->google_id ?: 'null') . "\n";
        echo "  OCM ID: " . ($item->ocm_id ?: 'null') . "\n";
        echo "  Distance: " . ($item->distance ?: 'null') . "\n";
        echo "  Reason: " . ($item->reason ?: 'null') . "\n\n";
    }
} else {
    echo "Žádné položky v review queue\n";
}

// Manuálně spustit discovery a sledovat, jestli se přidá do queue
echo "🔄 Manuálně spustit discovery...\n";
$svc = new \DB\Charging_Discovery();
$result = $svc->discoverForCharging($post_id, true, false, true, true);

echo "Discovery výsledek:\n";
echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

// Zkontrolovat queue po discovery
echo "\n=== QUEUE PO DISCOVERY ===\n";
$queue_items_after = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_name WHERE post_id = %d ORDER BY created_at DESC LIMIT 5",
    $post_id
));

if ($queue_items_after) {
    echo "Review queue items po discovery:\n";
    foreach ($queue_items_after as $item) {
        echo "  ID: {$item->id}, Status: {$item->status}, Created: {$item->created_at}\n";
        echo "  Google ID: " . ($item->google_id ?: 'null') . "\n";
        echo "  OCM ID: " . ($item->ocm_id ?: 'null') . "\n";
        echo "  Distance: " . ($item->distance ?: 'null') . "\n";
        echo "  Reason: " . ($item->reason ?: 'null') . "\n\n";
    }
} else {
    echo "Žádné položky v review queue po discovery\n";
}

// Zkontrolovat fallback metadata
echo "=== FALLBACK METADATA ===\n";
$fallback_meta = get_post_meta($post_id, '_charging_fallback_metadata', true);
echo "Fallback metadata: " . ($fallback_meta ? 'přítomno' : 'nepřítomno') . "\n";

if ($fallback_meta) {
    echo "Fallback obsah:\n";
    echo json_encode($fallback_meta, JSON_PRETTY_PRINT) . "\n";
}

echo "\n=== DOKONČENO ===\n";
?>
