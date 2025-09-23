<?php
/**
 * Jednoduchý Build Script pro Dobitý Baterky Plugin
 * Vytváří produkční distribuci pluginu bez rekurzivních problémů
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

class SimplePluginBuilder {
    
    private $plugin_dir;
    private $build_dir;
    private $version;
    
    public function __construct() {
        $this->plugin_dir = __DIR__;
        $this->build_dir = $this->plugin_dir . '/build';
        $this->version = $this->get_version();
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
        
        // Kopírovat soubory pomocí rsync
        $this->copy_files_with_rsync();
        
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
    
    private function copy_files_with_rsync() {
        $plugin_name = 'dobity-baterky';
        $dest_dir = $this->build_dir . '/' . $plugin_name;
        
        // Vytvořit destinaci
        if (!mkdir($dest_dir, 0755, true)) {
            throw new Exception("Nelze vytvořit destinaci: {$dest_dir}");
        }
        
        // Použít rsync pro kopírování s excludy
        $exclude_patterns = [
            'build/',
            'build.php',
            'build-simple.php',
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
            '*.json.backup',
            '.DS_Store',
            '*.zip',
            'Sites/'
        ];
        
        $exclude_args = '';
        foreach ($exclude_patterns as $pattern) {
            $exclude_args .= " --exclude='{$pattern}'";
        }
        
        $command = "rsync -av{$exclude_args} \"{$this->plugin_dir}/\" \"{$dest_dir}/\"";
        
        echo "📋 Kopíruji soubory pomocí rsync...\n";
        $output = [];
        $return_code = 0;
        exec($command, $output, $return_code);
        
        if ($return_code !== 0) {
            echo "❌ Chyba při kopírování: " . implode("\n", $output) . "\n";
            throw new Exception("Rsync selhal s kódem: {$return_code}");
        }
        
        echo "📋 Zkopírovány soubory pluginu\n";
    }
    
    private function create_zip_distribution() {
        $plugin_name = 'dobity-baterky';
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
    $builder = new SimplePluginBuilder();
    $builder->build();
} catch (Exception $e) {
    echo "❌ Chyba při build: " . $e->getMessage() . "\n";
    exit(1);
}
