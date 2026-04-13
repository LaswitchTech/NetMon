<?php

/**
 * Application configuration.
 *
 * Long-lived application identity and baseline settings.
 * Values are read from environment variables set by .env (loaded in
 * public/index.php before this file is required). Defaults cover the case
 * where .env is absent (e.g. during CI or before first install).
 *
 * These values can also be overridden via config/local.php:
 *   return ['app' => ['debug' => false]];
 */
return [
    'name'      => (getenv('APP_NAME')      ?: 'NetMon'),
    'env'       => (getenv('APP_ENV')        ?: 'development'),
    'debug'     => (bool) filter_var(getenv('APP_DEBUG')      ?: 'true',  FILTER_VALIDATE_BOOLEAN),
    'url'       => (getenv('APP_URL')        ?: 'http://localhost'),
    'installed' => (bool) filter_var(getenv('APP_INSTALLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
];
