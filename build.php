<?php
/**
 * Build Script pro Dobitý Baterky Plugin
 * Vytváří produkční distribuci pluginu
 */

// Bezpečnostní kontrola
if (!defined('ABSPATH')) {
    // Pokud nejsme v WordPress, načteme WordPress
    $wp_load_paths = [
        '../../../wp-load.php',
        '../../../../wp-load.php',
        '../../../../../wp-load.php'
    ];
    
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
}

class PluginBuilder {
    
    private $plugin_dir;
    private $build_dir;
    private $version;
    private $exclude_patterns;
    
    public function __construct() {
        $this->plugin_dir = __DIR__;
        $this->build_dir = $this->plugin_dir . '/build';
        $this->version = $this->get_version();
        $this->exclude_patterns = [
            'build/',
            'build.php',
            '*.log',
            '.git/',
            '.gitignore',
            '*.md',
            '*.backup',
            'test-*.php',
            'debug-*.php',
            'check-*.php',
            'fix-*.php',
            'clear-*.php',
            'cleanup-*.php',
            'force-*.php',
            'update-*.sh',
            'wp-update-*.sh',
            'activate-*.php',
            'uninstall.php',
            'tests/',
            'attachements/',
            'lib/',
            '*.json.backup'
        ];
    }
    
    private function get_version() {
        $plugin_file = $this->plugin_dir . '/dobity-baterky.php';
        if (file_exists($plugin_file)) {
            $content = file_get_contents($plugin_file);
            if (preg_match('/Version:\s*([0-9.]+)/', $content, $matches)) {
                return $matches[1];
            }
        }
        return '2.0.0';
    }
    
    public function build() {
        echo "🏗️  Vytvářím produkční build verze {$this->version}...\n\n";
        
        // Vytvořit build adresář
        $this->create_build_directory();
        
        // Kopírovat soubory
        $this->copy_files();
        
        // Vyčistit debug kód
        $this->clean_debug_code();
        
        // Vytvořit ZIP distribuci
        $this->create_zip_distribution();
        
        echo "✅ Build dokončen! Distribuce: {$this->build_dir}/dobity-baterky-{$this->version}.zip\n";
    }
    
    private function create_build_directory() {
        if (is_dir($this->build_dir)) {
            $this->remove_directory($this->build_dir);
        }
        
        if (!mkdir($this->build_dir, 0755, true)) {
            throw new Exception("Nelze vytvořit build adresář: {$this->build_dir}");
        }
        
        echo "📁 Vytvořen build adresář: {$this->build_dir}\n";
    }
    
    private function copy_files() {
        $plugin_name = basename($this->plugin_dir);
        $dest_dir = $this->build_dir . '/' . $plugin_name;
        
        if (!mkdir($dest_dir, 0755, true)) {
            throw new Exception("Nelze vytvořit destinaci: {$dest_dir}");
        }
        
        $this->copy_directory($this->plugin_dir, $dest_dir);
        
        echo "📋 Zkopírovány soubory pluginu\n";
    }
    
    private function copy_directory($src, $dest) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $src_path = $item->getPathname();
            $relative_path = substr($src_path, strlen($src) + 1);
            $dest_path = $dest . '/' . $relative_path;
            
            // Zkontrolovat exclude patterns
            if ($this->should_exclude($relative_path)) {
                continue;
            }
            
            if ($item->isDir()) {
                if (!is_dir($dest_path)) {
                    mkdir($dest_path, 0755, true);
                }
            } else {
                copy($src_path, $dest_path);
            }
        }
    }
    
    private function should_exclude($path) {
        foreach ($this->exclude_patterns as $pattern) {
            if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }
        return false;
    }
    
    private function clean_debug_code() {
        $plugin_name = basename($this->plugin_dir);
        $dest_dir = $this->build_dir . '/' . $plugin_name;
        
        // Vyčistit debug kód z PHP souborů
        $this->clean_php_files($dest_dir);
        
        // Vyčistit debug kód z JavaScript souborů
        $this->clean_js_files($dest_dir);
        
        echo "🧹 Vyčištěn debug kód\n";
    }
    
    private function clean_php_files($dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $this->clean_php_file($file->getPathname());
            }
        }
    }
    
    private function clean_php_file($file) {
        $content = file_get_contents($file);
        
        // Odstranit debug error_log statements (kromě těch s DB_DEBUG)
        $content = preg_replace('/error_log\([^)]*\[.*DEBUG.*\]/i', '// Debug removed', $content);
        $content = preg_replace('/error_log\([^)]*\[.*DEBUG.*\]\);/i', '// Debug removed', $content);
        
        // Zachovat pouze DB_DEBUG logging
        $content = preg_replace('/\/\/ Debug removed[^;]*;/', '// Debug removed', $content);
        
        file_put_contents($file, $content);
    }
    
    private function clean_js_files($dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), ['js', 'css'])) {
                $this->clean_js_file($file->getPathname());
            }
        }
    }
    
    private function clean_js_file($file) {
        $content = file_get_contents($file);
        
        // Odstranit console.log statements
        $content = preg_replace('/console\.log\([^)]*\);/g', '', $content);
        $content = preg_replace('/console\.debug\([^)]*\);/g', '', $content);
        
        file_put_contents($file, $content);
    }
    
    private function create_zip_distribution() {
        $plugin_name = basename($this->plugin_dir);
        $zip_file = $this->build_dir . "/dobity-baterky-{$this->version}.zip";
        
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception("Nelze vytvořit ZIP soubor: {$zip_file}");
        }
        
        $this->add_directory_to_zip($this->build_dir . '/' . $plugin_name, $zip, $plugin_name . '/');
        $zip->close();
        
        echo "📦 Vytvořena ZIP distribuce: " . basename($zip_file) . "\n";
        
        // Zobrazit velikost
        $size = filesize($zip_file);
        $size_mb = round($size / 1024 / 1024, 2);
        echo "📊 Velikost: {$size_mb} MB\n";
    }
    
    private function add_directory_to_zip($dir, $zip, $zip_path = '') {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $relative_path = $zip_path . substr($item->getPathname(), strlen($dir) + 1);
            
            if ($item->isDir()) {
                $zip->addEmptyDir($relative_path);
            } else {
                $zip->addFile($item->getPathname(), $relative_path);
            }
        }
    }
    
    private function remove_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        
        rmdir($dir);
    }
}

// Spuštění build procesu
try {
    $builder = new PluginBuilder();
    $builder->build();
} catch (Exception $e) {
    echo "❌ Chyba při build: " . $e->getMessage() . "\n";
    exit(1);
}
