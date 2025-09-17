<?php
/**
 * Front-end integration to capture TTFB.
 */

namespace Log_High_TTFB;

use function is_admin;
use function rest_url;
use function wp_enqueue_script;
use function wp_create_nonce;
use function wp_localize_script;
use function wp_register_script;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Frontend {
    private Plugin $plugin;
    private string $handle = 'log-high-ttfb-frontend';

    public function __construct( Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    public function enqueue_scripts(): void {
        if ( is_admin() ) {
            return;
        }

        $src     = $this->plugin->get_plugin_url() . 'assets/js/frontend.js';
        $version = file_exists( $this->plugin->get_plugin_dir() . 'assets/js/frontend.js' ) ? filemtime( $this->plugin->get_plugin_dir() . 'assets/js/frontend.js' ) : Plugin::VERSION;

        wp_register_script( $this->handle, $src, [], $version, true );

        $data = [
            'restUrl'          => rest_url( 'log-high-ttfb/v1/log' ),
            'nonce'            => $this->plugin->get_nonce(),
            'restNonce'        => wp_create_nonce( 'wp_rest' ),
            'warningThreshold' => 800,
            'slowThreshold'    => 1800,
        ];

        wp_localize_script( $this->handle, 'LogHighTtfbSettings', $data );
        wp_enqueue_script( $this->handle );
    }
}
