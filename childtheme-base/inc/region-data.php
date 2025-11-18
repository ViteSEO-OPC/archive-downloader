<?php
/**
 * Lightweight loader for JSON data stored in the theme's /data directory.
 */
if (!defined('ABSPATH')) {
  exit;
}

if (!function_exists('child_load_region_data')) {
  /**
   * Load and memoize decoded JSON data files shipped with the theme.
   *
   * @return array<string, mixed>
   */
  function child_load_region_data() {
    static $cache = null;

    if (is_array($cache)) {
      return $cache;
    }

    $cache = [];
    $data_dir = trailingslashit(get_stylesheet_directory()) . 'data';

    if (!is_dir($data_dir)) {
      return $cache;
    }

    $files = glob($data_dir . '/*.json');
    if (empty($files)) {
      return $cache;
    }

    foreach ($files as $file) {
      $contents = file_get_contents($file);
      if ($contents === false) {
        continue;
      }

      $decoded = json_decode($contents, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $cache = array_merge($cache, $decoded);
      }
    }

    /**
     * Allow themes/plugins to modify the loaded data set if needed.
     */
    return apply_filters('child_region_data', $cache, $files);
  }
}
