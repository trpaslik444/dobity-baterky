<?php
/**
 * Favorites manager for storing user folders and assignments.
 *
 * @package DobityBaterky
 */

namespace DB;

class Favorites_Manager {
    const META_KEY = '_db_favorites';
    const DEFAULT_FOLDER_ID = 'default';
    const DEFAULT_FOLDER_NAME = 'Moje oblíbené';
    const DEFAULT_FOLDER_ICON = '⭐️';
    const DEFAULT_FOLDER_LIMIT = 200;
    const CUSTOM_FOLDER_LIMIT = 500;
    const MAX_CUSTOM_FOLDERS = 12;

    /** @var Favorites_Manager|null */
    private static $instance = null;

    public static function get_instance(): Favorites_Manager {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function is_enabled_for_current_user(): bool {
        return is_user_logged_in() && function_exists( '\\DB\\db_user_can_see_map' ) ? db_user_can_see_map() : is_user_logged_in();
    }

    public function get_state( int $user_id ): array {
        $raw = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }
        if ( ! isset( $raw['folders'] ) || ! is_array( $raw['folders'] ) ) {
            $raw['folders'] = array();
        }
        if ( ! isset( $raw['assignments'] ) || ! is_array( $raw['assignments'] ) ) {
            $raw['assignments'] = array();
        }

        $raw['folders'] = $this->ensure_default_folder( $raw['folders'] );
        $raw['assignments'] = $this->sanitize_assignments( $raw['assignments'], $raw['folders'] );

        return $raw;
    }

    public function save_state( int $user_id, array $state ): void {
        update_user_meta( $user_id, self::META_KEY, array(
            'folders'     => $state['folders'] ?? array(),
            'assignments' => $state['assignments'] ?? array(),
        ) );
    }

    public function get_folders( int $user_id ): array {
        $state = $this->get_state( $user_id );
        return $this->format_folders_with_counts( $state );
    }

    public function get_assignments( int $user_id ): array {
        $state = $this->get_state( $user_id );
        return $state['assignments'];
    }

    public function get_assignment_for_post( int $user_id, int $post_id ): ?string {
        $assignments = $this->get_assignments( $user_id );
        return $assignments[ $post_id ] ?? null;
    }

    public function get_folder( int $user_id, string $folder_id ): ?array {
        $state = $this->get_state( $user_id );
        foreach ( $state['folders'] as $folder ) {
            if ( isset( $folder['id'] ) && (string) $folder['id'] === (string) $folder_id ) {
                $folder = $this->normalize_folder( $folder );
                $folder['count'] = $this->count_items_in_folder( $state, $folder['id'] );
                return $folder;
            }
        }
        return null;
    }

    public function create_folder( int $user_id, string $name, ?string $icon = null ): array {
        $state = $this->get_state( $user_id );
        $custom_folders = array_filter( $state['folders'], static function( $folder ) {
            return isset( $folder['type'] ) && $folder['type'] === 'custom';
        } );

        if ( count( $custom_folders ) >= self::MAX_CUSTOM_FOLDERS ) {
            throw new \RuntimeException( __( 'Dosáhli jste maximálního počtu složek.', 'dobity-baterky' ) );
        }

        $folder_id = $this->generate_folder_id( $state['folders'] );
        $folder = array(
            'id'    => $folder_id,
            'name'  => $this->sanitize_folder_name( $name ),
            'icon'  => $this->sanitize_icon( $icon ) ?: '📁',
            'limit' => self::CUSTOM_FOLDER_LIMIT,
            'type'  => 'custom',
            'order' => count( $state['folders'] ),
        );

        $state['folders'][] = $folder;
        $this->save_state( $user_id, $state );

        $folder['count'] = 0;
        return $folder;
    }

    public function assign_post( int $user_id, int $post_id, string $folder_id, bool $force = false ): array {
        $state = $this->get_state( $user_id );
        $folder = $this->find_folder_by_id( $state['folders'], $folder_id );
        if ( ! $folder ) {
            throw new \RuntimeException( __( 'Zvolená složka neexistuje.', 'dobity-baterky' ) );
        }

        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            throw new \InvalidArgumentException( 'Invalid post id' );
        }

        $assignments = $state['assignments'];
        $current_folder = $assignments[ $post_id ] ?? null;
        if ( $current_folder && $current_folder !== $folder_id && ! $force ) {
            return array(
                'requires_confirmation' => true,
                'current_folder'        => $current_folder,
            );
        }

        $count = $this->count_items_in_folder( $state, $folder_id );
        $limit = isset( $folder['limit'] ) ? (int) $folder['limit'] : ( $folder['type'] === 'default' ? self::DEFAULT_FOLDER_LIMIT : self::CUSTOM_FOLDER_LIMIT );
        if ( $count >= $limit ) {
            throw new \RuntimeException( __( 'Tato složka je plná.', 'dobity-baterky' ) );
        }

        $assignments[ $post_id ] = $folder_id;
        $state['assignments'] = $assignments;
        $this->save_state( $user_id, $state );

        return array(
            'folder_id'       => $folder_id,
            'previous_folder' => $current_folder && $current_folder !== $folder_id ? $current_folder : null,
            'counts'          => $this->get_counts( $state ),
            'assignments'     => $state['assignments'],
        );
    }

    public function remove_post( int $user_id, int $post_id ): array {
        $state = $this->get_state( $user_id );
        $post_id = absint( $post_id );
        if ( isset( $state['assignments'][ $post_id ] ) ) {
            unset( $state['assignments'][ $post_id ] );
            $this->save_state( $user_id, $state );
        }

        return array(
            'counts'      => $this->get_counts( $state ),
            'assignments' => $state['assignments'],
        );
    }

    /**
     * Delete a custom folder and remove all assignments pointing to it.
     */
    public function delete_folder( int $user_id, string $folder_id ): array {
        $state = $this->get_state( $user_id );
        $folder_id = (string) $folder_id;

        // Prevent deleting default folder
        if ( $folder_id === self::DEFAULT_FOLDER_ID ) {
            throw new \RuntimeException( __( 'Výchozí složku nelze smazat.', 'dobity-baterky' ) );
        }

        // Keep only folders that don't match folder_id
        $new_folders = array();
        $found = false;
        foreach ( $state['folders'] as $folder ) {
            if ( isset( $folder['id'] ) && (string) $folder['id'] === $folder_id ) {
                // Allow delete only custom folders
                $normalized = $this->normalize_folder( $folder );
                if ( $normalized['type'] !== 'custom' ) {
                    throw new \RuntimeException( __( 'Tuto složku nelze smazat.', 'dobity-baterky' ) );
                }
                $found = true;
                continue;
            }
            $new_folders[] = $folder;
        }

        if ( ! $found ) {
            throw new \RuntimeException( __( 'Složka nebyla nalezena.', 'dobity-baterky' ) );
        }

        // Remove assignments pointing to this folder
        $new_assignments = array();
        foreach ( $state['assignments'] as $post_id => $assigned_folder ) {
            if ( (string) $assigned_folder !== $folder_id ) {
                $new_assignments[ $post_id ] = $assigned_folder;
            }
        }

        $state['folders'] = array_values( $new_folders );
        $state['assignments'] = $new_assignments;
        $this->save_state( $user_id, $state );

        return array(
            'folders'     => $this->format_folders_with_counts( $state ),
            'assignments' => $state['assignments'],
        );
    }

    public function get_localized_payload( int $user_id ): array {
        $state   = $this->get_state( $user_id );
        $folders = $this->format_folders_with_counts( $state );

        return array(
            'enabled'            => true,
            'restUrl'            => rest_url( 'db/v1/favorites' ),
            'maxCustomFolders'   => self::MAX_CUSTOM_FOLDERS,
            'defaultLimit'       => self::DEFAULT_FOLDER_LIMIT,
            'customLimit'        => self::CUSTOM_FOLDER_LIMIT,
            'folders'            => $folders,
            'assignments'        => $state['assignments'],
        );
    }

    private function ensure_default_folder( array $folders ): array {
        $has_default = false;
        foreach ( $folders as $folder ) {
            if ( isset( $folder['id'] ) && (string) $folder['id'] === self::DEFAULT_FOLDER_ID ) {
                $has_default = true;
                break;
            }
        }

        if ( ! $has_default ) {
            array_unshift( $folders, array(
                'id'    => self::DEFAULT_FOLDER_ID,
                'name'  => self::DEFAULT_FOLDER_NAME,
                'icon'  => self::DEFAULT_FOLDER_ICON,
                'limit' => self::DEFAULT_FOLDER_LIMIT,
                'type'  => 'default',
                'order' => 0,
            ) );
        }

        // Normalize
        $normalized = array();
        $order      = 0;
        foreach ( $folders as $folder ) {
            $folder           = $this->normalize_folder( $folder );
            $folder['order']  = $order++;
            $normalized[]     = $folder;
        }

        return $normalized;
    }

    private function normalize_folder( array $folder ): array {
        $folder['id']    = isset( $folder['id'] ) ? (string) $folder['id'] : uniqid( 'fav_', false );
        $folder['name']  = $this->sanitize_folder_name( $folder['name'] ?? '' );
        $folder['icon']  = $this->sanitize_icon( $folder['icon'] ?? '' );
        $folder['type']  = isset( $folder['type'] ) && $folder['type'] === 'custom' ? 'custom' : ( (string) $folder['id'] === self::DEFAULT_FOLDER_ID ? 'default' : 'custom' );
        $folder['limit'] = isset( $folder['limit'] ) ? (int) $folder['limit'] : ( $folder['type'] === 'default' ? self::DEFAULT_FOLDER_LIMIT : self::CUSTOM_FOLDER_LIMIT );

        if ( $folder['type'] === 'default' ) {
            $folder['name'] = self::DEFAULT_FOLDER_NAME;
            $folder['icon'] = self::DEFAULT_FOLDER_ICON;
            $folder['limit'] = self::DEFAULT_FOLDER_LIMIT;
        }

        return $folder;
    }

    private function sanitize_folder_name( string $name ): string {
        $name = wp_strip_all_tags( $name );
        $name = trim( $name );
        if ( $name === '' ) {
            $name = __( 'Nová složka', 'dobity-baterky' );
        }
        if ( function_exists( 'mb_substr' ) ) {
            $name = mb_substr( $name, 0, 60 );
        } else {
            $name = substr( $name, 0, 60 );
        }
        return $name;
    }

    private function sanitize_icon( string $icon ): string {
        $icon = trim( wp_strip_all_tags( $icon ) );
        if ( function_exists( 'mb_substr' ) ) {
            $icon = mb_substr( $icon, 0, 4 );
        } else {
            $icon = substr( $icon, 0, 4 );
        }
        return $icon ?: self::DEFAULT_FOLDER_ICON;
    }

    private function sanitize_assignments( array $assignments, array $folders ): array {
        $valid_ids = array();
        foreach ( $folders as $folder ) {
            if ( isset( $folder['id'] ) ) {
                $valid_ids[ (string) $folder['id'] ] = true;
            }
        }

        $sanitized = array();
        foreach ( $assignments as $post_id => $folder_id ) {
            $post_id = absint( $post_id );
            $folder_id = (string) $folder_id;
            if ( $post_id > 0 && isset( $valid_ids[ $folder_id ] ) ) {
                $sanitized[ $post_id ] = $folder_id;
            }
        }

        return $sanitized;
    }

    private function format_folders_with_counts( array $state ): array {
        $folders = array();
        foreach ( $state['folders'] as $folder ) {
            $folder            = $this->normalize_folder( $folder );
            $folder['count']   = $this->count_items_in_folder( $state, $folder['id'] );
            $folders[]         = $folder;
        }

        usort( $folders, static function( $a, $b ) {
            return ( $a['order'] ?? 0 ) <=> ( $b['order'] ?? 0 );
        } );

        return $folders;
    }

    private function count_items_in_folder( array $state, string $folder_id ): int {
        $count = 0;
        foreach ( $state['assignments'] as $post_id => $assigned_folder ) {
            if ( (string) $assigned_folder === (string) $folder_id ) {
                $count++;
            }
        }
        return $count;
    }

    private function generate_folder_id( array $folders ): string {
        $existing = array();
        foreach ( $folders as $folder ) {
            if ( isset( $folder['id'] ) ) {
                $existing[ (string) $folder['id'] ] = true;
            }
        }
        do {
            $id = 'fav_' . wp_generate_password( 8, false, false );
        } while ( isset( $existing[ $id ] ) );
        return $id;
    }

    private function find_folder_by_id( array $folders, string $folder_id ): ?array {
        foreach ( $folders as $folder ) {
            if ( isset( $folder['id'] ) && (string) $folder['id'] === (string) $folder_id ) {
                return $this->normalize_folder( $folder );
            }
        }
        return null;
    }

    private function get_counts( array $state ): array {
        $counts = array();
        foreach ( $state['folders'] as $folder ) {
            if ( isset( $folder['id'] ) ) {
                $counts[ $folder['id'] ] = $this->count_items_in_folder( $state, $folder['id'] );
            }
        }
        return $counts;
    }
}
