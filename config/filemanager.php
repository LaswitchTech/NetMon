<?php

/**
 * File Manager module configuration.
 *
 * Each entry in 'roots' defines one storage root accessible through the File Manager UI.
 *
 * Root fields:
 *   id       string   URL-safe identifier used in route segments (e.g. "storage")
 *   label    string   Human-readable name shown in the UI
 *   path     string   Absolute filesystem path to the root directory
 *   enabled  bool     When false the root is hidden from the UI and all operations on it are refused
 *
 * Safety rules:
 *   - The 'path' must be an absolute path to an existing directory.
 *   - No operation can escape outside this path (the PathResolver enforces this).
 *   - IDs must be URL-safe: lowercase letters, digits, hyphens, and underscores only.
 *
 * To add a root:
 *   1. Add an entry here (or override in config/local.php under the 'filemanager' key).
 *   2. Ensure the directory exists and is readable/writable by the web process.
 *   3. Grant users the 'files.manage' permission via Admin > Groups.
 */
return [
    'roots' => [
        [
            'id'      => 'storage',
            'label'   => 'Application Storage',
            'path'    => dirname(__DIR__) . '/storage/files',
            'enabled' => true,
        ],
    ],
];
