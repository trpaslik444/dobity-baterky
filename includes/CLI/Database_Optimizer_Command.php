<?php
/**
 * WP-CLI příkazy pro optimalizaci databáze
 * @package DobityBaterky
 */

namespace DB\CLI;

use DB\Database_Optimizer;

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

class Database_Optimizer_Command {

    /**
     * Vytvořit databázové indexy pro optimalizaci nearby dotazů
     *
     * ## EXAMPLES
     *
     *     wp db optimize indexes
     *
     * @when after_wp_load
     */
    public function indexes() {
        WP_CLI::log( 'Vytváření databázových indexů...' );
        
        $result = Database_Optimizer::create_indexes();
        
        if ( ! empty( $result['errors'] ) ) {
            foreach ( $result['errors'] as $error ) {
                WP_CLI::error( $error );
            }
        }
        
        WP_CLI::success( sprintf( 'Vytvořeno %d indexů', $result['indexes_created'] ) );
    }

    /**
     * Vyčistit starý cache
     *
     * ## EXAMPLES
     *
     *     wp db optimize cleanup
     *
     * @when after_wp_load
     */
    public function cleanup() {
        WP_CLI::log( 'Čištění starého cache...' );
        
        $deleted = Database_Optimizer::cleanup_old_cache();
        
        WP_CLI::success( sprintf( 'Smazáno %d záznamů z cache', $deleted ) );
    }

    /**
     * Zobrazit statistiky výkonu
     *
     * ## EXAMPLES
     *
     *     wp db optimize stats
     *
     * @when after_wp_load
     */
    public function stats() {
        WP_CLI::log( 'Statistiky výkonu databáze:' );
        
        $stats = Database_Optimizer::get_performance_stats();
        
        $table_data = array(
            array( 'Metrika', 'Hodnota' ),
            array( 'Cache záznamy', $stats['cache_records'] ),
            array( 'Cache velikost (MB)', $stats['cache_size'] ),
            array( 'Meta záznamy', $stats['meta_records'] ),
        );
        
        WP_CLI\Utils\format_items( 'table', $table_data, array( 'Metrika', 'Hodnota' ) );
    }
}

WP_CLI::add_command( 'db optimize', 'DB\CLI\Database_Optimizer_Command' );
