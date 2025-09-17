<?php
/**
 * Simple autoloader for Log High TTFB plugin classes.
 */

namespace Log_High_TTFB;

use function spl_autoload_register;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

spl_autoload_register(
    static function ( $class ) {
        if ( strpos( $class, __NAMESPACE__ . '\\' ) !== 0 ) {
            return;
        }

        $relative = substr( $class, strlen( __NAMESPACE__ . '\\' ) );
        $relative = strtolower( str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) );
        $relative = str_replace( '_', '-', $relative );

        $path = __DIR__ . '/class-' . $relative . '.php';

        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
);
