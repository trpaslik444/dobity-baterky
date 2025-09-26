<?php
// Jednodušší build skript využívající rsync pro spolehlivé kopírování s excludy

class PluginBuilderSimple {
    private $plugin_dir;
    private $build_dir;
    private $version;

    public function __construct() {
        $this->plugin_dir = __DIR__;
        $this->build_dir = $this->plugin_dir . '/build';
        $this->version = $this->get_version();
    }

    public function build() {
        echo "\n🏗️  Vytvářím produkční build verze {$this->version}...\n\n";
        $this->create_build_dir();
        $this->copy_files_rsync();
        $this->clean_debug_code();
        $zip = $this->create_zip();
        $size = $this->human_filesize(filesize($zip));
        echo "📦 Vytvořena ZIP distribuce: " . basename($zip) . "\n";
        echo "📊 Velikost: {$size}\n";
        echo "✅ Build dokončen! Distribuce: {$zip}\n\n";
    }

    private function get_version() {
        $plugin_file = $this->plugin_dir . '/dobity-baterky.php';
        $contents = file_exists($plugin_file) ? file_get_contents($plugin_file) : '';
        if (preg_match('/Version:\s*([\d\.]+)/i', $contents, $m)) {
            return $m[1];
        }
        return '2.0.0';
    }

    private function create_build_dir() {
        if (!is_dir($this->build_dir)) {
            if (!mkdir($this->build_dir, 0755, true)) {
                throw new Exception('Nelze vytvořit build adresář: ' . $this->build_dir);
            }
        }
        echo "📁 Vytvořen build adresář: {$this->build_dir}\n";
    }

    private function copy_files_rsync() {
        $plugin_name = basename($this->plugin_dir);
        $dest_dir = $this->build_dir . '/' . $plugin_name;

        if (is_dir($dest_dir)) {
            $this->rrmdir($dest_dir);
        }
        if (!mkdir($dest_dir, 0755, true)) {
            throw new Exception('Nelze vytvořit destinaci: ' . $dest_dir);
        }

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
            $exclude_args .= " --exclude='" . $pattern . "'";
        }

        $cmd = "rsync -av{$exclude_args} \"{$this->plugin_dir}/\" \"{$dest_dir}/\"";
        echo "📋 Kopíruji soubory pomocí rsync...\n";
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new Exception("Rsync selhal s kódem: {$code}\n" . implode("\n", $output));
        }
        echo "📋 Zkopírovány soubory pluginu\n";
    }

    private function clean_debug_code() {
        // Vyčistit PHP a JS soubory ve složce destinace
        $plugin_name = basename($this->plugin_dir);
        $dest_dir = $this->build_dir . '/' . $plugin_name;

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest_dir));
        foreach ($rii as $file) {
            if ($file->isDir()) continue;
            $path = $file->getPathname();
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'php' || $ext === 'js') {
                $content = file_get_contents($path);
                if ($content === false) continue;
                // odstranit pro produkci pouze browser console logy (error_log ponecháme kvůli stabilní syntaxi)
                $content = preg_replace('/\bconsole\.(log|debug|warn|error)\s*\(.*?\)\s*;?/s', '', $content);
                file_put_contents($path, $content);
            }
        }
        echo "🧹 Vyčištěn debug kód\n";
    }

    private function create_zip() {
        $plugin_name = basename($this->plugin_dir);
        $src_dir = $this->build_dir . '/' . $plugin_name;
        $zip_path = $this->build_dir . "/{$plugin_name}-{$this->version}.zip";

        if (file_exists($zip_path)) {
            unlink($zip_path);
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
            throw new Exception('Nelze vytvořit zip: ' . $zip_path);
        }

        // Přidej top-level složku, aby WP rozpoznal update (nikoliv novou instalaci)
        $zip->addEmptyDir($plugin_name);

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src_dir));
        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            $filePath = $file->getPathname();
            $relative = substr($filePath, strlen($src_dir) + 1);
            $localName = $plugin_name . '/' . $relative;
            $zip->addFile($filePath, $localName);
        }

        $zip->close();
        return $zip_path;
    }

    private function rrmdir($dir) {
        if (!is_dir($dir)) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    private function human_filesize($bytes, $decimals = 2) {
        $size = ['B','KB','MB','GB','TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%0.{$decimals}f ", $bytes / pow(1024, $factor)) . $size[$factor];
    }
}

// Spuštění
(new PluginBuilderSimple())->build();

?>

