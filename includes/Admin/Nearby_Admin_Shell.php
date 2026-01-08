<?php
/**
 * Nearby Admin Shell - Centralizované admin UI pro Nearby
 * @package DobityBaterky
 */

namespace DB\Admin;

class Nearby_Admin_Shell {
    
    private $queue_admin;
    private $settings_admin;
    private $icon_admin;
    private $poi_service_admin;
    
    public function __construct() {
        // Načíst potřebné třídy
        if ( file_exists( __DIR__ . '/Nearby_Queue_Admin.php' ) ) {
            require_once __DIR__ . '/Nearby_Queue_Admin.php';
        }
        if ( file_exists( __DIR__ . '/Nearby_Settings.php' ) ) {
            require_once __DIR__ . '/Nearby_Settings.php';
        }
        if ( file_exists( __DIR__ . '/../Icon_Admin.php' ) ) {
            require_once __DIR__ . '/../Icon_Admin.php';
        }
        if ( file_exists( __DIR__ . '/POI_Service_Admin.php' ) ) {
            require_once __DIR__ . '/POI_Service_Admin.php';
        }
        
        // Vytvořit instance s vypnutou registrací menu
        if ( class_exists( 'DB\Admin\Nearby_Queue_Admin' ) ) {
            $this->queue_admin = new Nearby_Queue_Admin(false);
        }
        if ( class_exists( 'DB\Admin\Nearby_Settings' ) ) {
            $this->settings_admin = new Nearby_Settings(false);
        }
        if ( class_exists( 'DB\Icon_Admin' ) ) {
            $this->icon_admin = new \DB\Icon_Admin(false);
        }
        if ( class_exists( 'DB\Admin\POI_Service_Admin' ) ) {
            $this->poi_service_admin = new \DB\Admin\POI_Service_Admin(false);
        }
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    public function add_admin_menu() {
        // Top-level menu "Dobitý Baterky"
        add_menu_page(
            'Dobitý Baterky',
            'Dobitý Baterky',
            'manage_options',
            'db-admin',
            array($this, 'render_main_page'),
            'dashicons-admin-generic',
            30
        );
        
        // Submenu "Nearby"
        add_submenu_page(
            'db-admin',
            'Nearby',
            'Nearby',
            'manage_options',
            'db-nearby',
            array($this, 'render_nearby_page')
        );
        
        // Submenu "Správa ikon" - registrováno až po vytvoření top-level menu
        if ( $this->icon_admin ) {
            add_submenu_page(
                'db-admin',
                __( 'Správa ikon', 'dobity-baterky' ),
                __( 'Správa ikon', 'dobity-baterky' ),
                'manage_options',
                'db-icon-admin',
                array( $this->icon_admin, 'render_page' )
            );
        }
        
        // Submenu "POI Microservice" - registrováno až po vytvoření top-level menu
        if ( $this->poi_service_admin ) {
            add_submenu_page(
                'db-admin',
                'POI Microservice',
                'POI Microservice',
                'manage_options',
                'db-poi-service',
                array( $this->poi_service_admin, 'render_page' )
            );
        }
    }
    
    public function render_main_page() {
        // Redirect na Nearby stránku jako výchozí
        wp_safe_redirect(admin_url('admin.php?page=db-nearby'));
        exit;
    }
    
    public function render_nearby_page() {
        // Získat aktuální tab (default: queue)
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'queue';
        
        // Povolené taby
        $allowed_tabs = array('queue', 'processed', 'settings', 'isochrones');
        if (!in_array($current_tab, $allowed_tabs)) {
            $current_tab = 'queue';
        }
        
        ?>
        <div class="wrap">
            <h1>Nearby</h1>
            
            <!-- Tab navigace -->
            <nav class="nav-tab-wrapper" style="margin-top: 10px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=db-nearby&tab=queue')); ?>" 
                   class="nav-tab <?php echo $current_tab === 'queue' ? 'nav-tab-active' : ''; ?>">
                    Queue
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=db-nearby&tab=processed')); ?>" 
                   class="nav-tab <?php echo $current_tab === 'processed' ? 'nav-tab-active' : ''; ?>">
                    Processed
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=db-nearby&tab=settings')); ?>" 
                   class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    Settings
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=db-nearby&tab=isochrones')); ?>" 
                   class="nav-tab <?php echo $current_tab === 'isochrones' ? 'nav-tab-active' : ''; ?>">
                    Isochrones
                </a>
            </nav>
            
            <!-- Obsah podle tabu -->
            <div style="margin-top: 20px;">
                <?php
                switch ($current_tab) {
                    case 'queue':
                        $this->queue_admin->render_queue_page(true);
                        break;
                    case 'processed':
                        $this->queue_admin->render_processed_page(true);
                        break;
                    case 'settings':
                        $this->settings_admin->render_settings_page(true);
                        break;
                    case 'isochrones':
                        $this->queue_admin->render_isochrones_settings_page(true);
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
}
