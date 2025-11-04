<?php
/**
 * Translation Manager - Správa překladů pro frontend
 * @package DobityBaterky
 */

namespace DB;

class Translation_Manager {
    private static $instance = null;
    private $translations = array();
    private $current_lang = 'cs';
    
    /**
     * Singleton instance
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Konstruktor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Inicializace - načtení jazyka
     */
    private function init() {
        // Detekce jazyka z prohlížeče nebo WordPressu
        $this->current_lang = $this->detect_language();
        $this->load_translations();
    }
    
    /**
     * Detekce jazyka
     */
    private function detect_language() {
        // 1. Zkus získat z WordPressu, pokud je plugin pro multilang aktivní
        if ( function_exists( 'get_locale' ) ) {
            $locale = get_locale();
            // Zjistit zda je to EN nebo CS
            if ( strpos( $locale, 'en' ) === 0 ) {
                return 'en';
            }
            if ( strpos( $locale, 'cs' ) === 0 || strpos( $locale, 'sk' ) === 0 ) {
                return 'cs';
            }
        }
        
        // 2. Zkontrolovat HTTP_ACCEPT_LANGUAGE header (pokud je dostupný)
        if ( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
            $browser_lang = substr( $_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2 );
            if ( in_array( $browser_lang, array( 'cs', 'sk' ), true ) ) {
                return 'cs';
            }
            if ( $browser_lang === 'en' ) {
                return 'en';
            }
        }
        
        // 3. Default CS
        return 'cs';
    }
    
    /**
     * Načtení překladů z JSON souboru
     */
    private function load_translations() {
        $lang_file = DB_PLUGIN_DIR . 'languages/' . $this->current_lang . '.json';
        
        if ( ! file_exists( $lang_file ) ) {
            // Fallback na CS pokud soubor neexistuje
            $lang_file = DB_PLUGIN_DIR . 'languages/cs.json';
            $this->current_lang = 'cs';
        }
        
        $json_content = file_get_contents( $lang_file );
        if ( $json_content !== false ) {
            $translations = json_decode( $json_content, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $translations ) ) {
                $this->translations = $translations;
            }
        }
    }
    
    /**
     * Získat překlad podle klíče
     * 
     * @param string $key Klíč ve formátu "category.key" nebo "category.nested.key"
     * @param string $default Výchozí hodnota, pokud překlad neexistuje
     * @return string Přeložený text
     */
    public function get( $key, $default = '' ) {
        $keys = explode( '.', $key );
        $value = $this->translations;
        
        foreach ( $keys as $k ) {
            if ( isset( $value[ $k ] ) ) {
                $value = $value[ $k ];
            } else {
                return $default ?: $key;
            }
        }
        
        return $value;
    }
    
    /**
     * Získat všechny překlady
     * 
     * @return array Všechny překlady
     */
    public function get_all() {
        return $this->translations;
    }
    
    /**
     * Získat aktuální jazyk
     * 
     * @return string Aktuální jazyk ('cs' nebo 'en')
     */
    public function get_current_lang() {
        return $this->current_lang;
    }
    
    /**
     * Získat překlady pro frontend (JavaScript)
     * 
     * @return array Překlady pro frontend
     */
    public function get_frontend_translations() {
        return array(
            'lang' => $this->current_lang,
            'translations' => $this->translations,
        );
    }
    
    /**
     * Změnit jazyk ručně
     * 
     * @param string $lang 'cs' nebo 'en'
     * @return bool Úspěch
     */
    public function set_language( $lang ) {
        if ( in_array( $lang, array( 'cs', 'en' ), true ) ) {
            $this->current_lang = $lang;
            $this->load_translations();
            return true;
        }
        return false;
    }
}

