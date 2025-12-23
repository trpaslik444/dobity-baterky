<?php
/**
 * AJAX handlery pro admin panel v mapě
 * @package DobityBaterky
 */

namespace DB;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Panel_Handlers {
    
    public function __construct() {
        add_action('wp_ajax_db_update_recommended', [$this, 'handle_update_recommended']);
        add_action('wp_ajax_db_upload_photo', [$this, 'handle_upload_photo']);
    }
    
    /**
     * AJAX handler pro aktualizaci DB doporučuje
     */
    public function handle_update_recommended() {
        // Kontrola oprávnění
        if (!current_user_can('administrator') && !current_user_can('editor')) {
            wp_send_json_error('Nedostatečná oprávnění');
            return;
        }
        
        // Kontrola nonce
        if (!wp_verify_nonce($_POST['nonce'], 'db_admin_actions')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        $recommended = sanitize_text_field($_POST['recommended']) === '1';
        
        if (empty($post_id)) {
            wp_send_json_error('Chybí post_id');
            return;
        }
        
        // Kontrola, zda příspěvek existuje a uživatel ho může editovat
        $post = get_post($post_id);
        if (!$post || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Příspěvek neexistuje nebo nemáte oprávnění k editaci');
            return;
        }
        
        // Aktualizovat meta hodnotu
        $result = update_post_meta($post_id, '_db_recommended', $recommended ? '1' : '0');
        
        if ($result !== false) {
            // Synchronizovat db_recommended_ids options z meta hodnot (pro všechny typy: charging_location, poi, rv_spot)
            try {
                if (class_exists('\\DB\\REST_Map')) {
                    $rest_map = \DB\REST_Map::get_instance();
                    if (method_exists($rest_map, 'sync_recommended_ids_from_meta')) {
                        $rest_map->sync_recommended_ids_from_meta();
                    }
                }
            } catch (\Exception $e) {
                // Logovat chybu, ale nepřerušit běh aplikace
                error_log('Failed to sync recommended IDs: ' . $e->getMessage());
            }
            
            wp_send_json_success([
                'post_id' => $post_id,
                'recommended' => $recommended
            ]);
        } else {
            wp_send_json_error('Chyba při aktualizaci meta hodnoty');
        }
    }
    
    /**
     * AJAX handler pro nahrávání fotek
     */
    public function handle_upload_photo() {
        // Kontrola oprávnění
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Nedostatečná oprávnění pro nahrávání souborů');
            return;
        }
        
        // Kontrola nonce
        if (!wp_verify_nonce($_POST['nonce'], 'db_admin_actions')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        
        if (empty($post_id)) {
            wp_send_json_error('Chybí post_id');
            return;
        }
        
        // Kontrola, zda příspěvek existuje a uživatel ho může editovat
        $post = get_post($post_id);
        if (!$post || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Příspěvek neexistuje nebo nemáte oprávnění k editaci');
            return;
        }
        
        // Načíst potřebné WordPress funkce
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $uploaded_files = [];
        $thumbnail_url = '';
        
        // Zpracovat všechny nahrané soubory
        foreach ($_FILES as $key => $file) {
            if (strpos($key, 'photo_') === 0 && $file['error'] === UPLOAD_ERR_OK) {
                // Nahrát soubor
                $attachment_id = media_handle_upload($key, $post_id);
                
                if (!is_wp_error($attachment_id)) {
                    $uploaded_files[] = $attachment_id;
                    
                    // Nastavit jako featured image, pokud ještě není
                    if (empty($thumbnail_url)) {
                        set_post_thumbnail($post_id, $attachment_id);
                        $thumbnail_url = wp_get_attachment_url($attachment_id);
                    }
                }
            }
        }
        
        if (empty($uploaded_files)) {
            wp_send_json_error('Nepodařilo se nahrát žádné soubory');
            return;
        }
        
        wp_send_json_success([
            'uploaded_count' => count($uploaded_files),
            'attachment_ids' => $uploaded_files,
            'thumbnail_url' => $thumbnail_url
        ]);
    }
}
