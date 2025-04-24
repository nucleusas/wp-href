<?php

namespace WP_Hreflang;

/**
 * Asset management helper class
 * 
 * Loads JavaScript and CSS files using the generated manifest.json file
 */
class Assets {
    /**
     * The manifest data
     * 
     * @var array
     */
    private static $manifest = null;

    /**
     * Get the asset URL from the manifest file
     * 
     * @param string $asset_name The name of the asset without extension, e.g. 'admin-settings'
     * @param string $extension The file extension to look for (js or css)
     * @return string The URL to the asset
     */
    public static function get_asset_url($asset_name, $extension = 'js') {
        self::load_manifest();
        
        $lookup_key = "{$asset_name}.{$extension}";
        
        // Check if we have this asset in the manifest
        if (isset(self::$manifest[$lookup_key])) {
            // Prepend 'dist/' to the path from the manifest
            return \WP_HREFLANG_PLUGIN_URL . 'dist/' . self::$manifest[$lookup_key];
        }
        
        // Fallback to the non-hashed version
        return \WP_HREFLANG_PLUGIN_URL . "dist/{$extension}/{$asset_name}.{$extension}";
    }
    
    /**
     * Load the manifest.json file
     */
    private static function load_manifest() {
        if (self::$manifest === null) {
            $manifest_file = \WP_HREFLANG_PLUGIN_DIR . 'dist/manifest.json';
            
            if (file_exists($manifest_file)) {
                $manifest_json = file_get_contents($manifest_file);
                self::$manifest = json_decode($manifest_json, true) ?: [];
            } else {
                self::$manifest = [];
            }
        }
    }
    
    /**
     * Enqueue a script using the manifest
     * 
     * @param string $handle The script handle
     * @param string $asset_name The asset name (without extension)
     * @param array $deps Dependencies
     * @param bool $in_footer Whether to enqueue in footer
     */
    public static function enqueue_script($handle, $asset_name, $deps = [], $in_footer = true) {
        wp_enqueue_script(
            $handle,
            self::get_asset_url($asset_name, 'js'),
            $deps,
            null, // Using null since the file name already contains the hash
            $in_footer
        );
    }
    
    /**
     * Enqueue a stylesheet using the manifest
     * 
     * @param string $handle The style handle
     * @param string $asset_name The asset name (without extension)
     * @param array $deps Dependencies
     */
    public static function enqueue_style($handle, $asset_name, $deps = []) {
        wp_enqueue_style(
            $handle,
            self::get_asset_url($asset_name, 'css'),
            $deps,
            null // Using null since the file name already contains the hash
        );
    }
} 