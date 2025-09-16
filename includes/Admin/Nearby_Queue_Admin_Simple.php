<?php
namespace DB\Admin;

class Nearby_Queue_Admin_Simple {
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
            "Nearby Queue",
            "Nearby Queue", 
            "manage_options",
            "db-nearby-queue",
            array($this, "render_queue_page")
        );
    }

    public function render_queue_page() {
        echo "<div class=\"wrap\"><h1>Nearby Queue Management</h1><p>Queue management bude implementov��no</p></div>";
    }
}
