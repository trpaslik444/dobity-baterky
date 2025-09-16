<?php
namespace DB\Admin;

class Nearby_Settings_Simple {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action("admin_menu", array($this, "add_admin_menu"));
    }

    public function add_admin_menu() {
        add_submenu_page(
            "tools.php",
            "Nearby Settings",
            "Nearby Settings",
            "manage_options", 
            "db-nearby-settings",
            array($this, "render_settings_page")
        );
    }

    public function render_settings_page() {
        echo "<div class=\"wrap\"><h1>Nearby Settings</h1><p>Settings management bude implementov��no</p></div>";
    }
}
