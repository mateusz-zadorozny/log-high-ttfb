<?php
/**
 * Main plugin bootstrap.
 */

namespace Log_High_TTFB;

use Log_High_TTFB\Admin;
use Log_High_TTFB\Database;
use Log_High_TTFB\Email;
use Log_High_TTFB\Frontend;
use Log_High_TTFB\Rest_Controller;
use function add_action;
use function load_plugin_textdomain;
use function plugin_basename;
use function plugin_dir_path;
use function plugin_dir_url;
use function wp_create_nonce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {
    public const VERSION     = '0.1.0';
    public const OPTION_KEY  = 'log_high_ttfb_options';
    public const CRON_HOOK   = 'log_high_ttfb_daily_summary';
    public const NONCE_ACTION = 'log_high_ttfb';

    private static ?Plugin $instance = null;

    private Database $database;
    private Rest_Controller $rest_controller;
    private Frontend $frontend;
    private Admin $admin;
    private Email $email;

    private function __construct() {
        $this->database        = new Database();
        $this->rest_controller = new Rest_Controller( $this->database );
        $this->frontend        = new Frontend( $this );
        $this->admin           = new Admin( $this->database );
        $this->email           = new Email( $this->database );

        $this->register_hooks();
    }

    public static function get_instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function init(): void {
        self::get_instance();
    }

    public static function activate(): void {
        $instance = self::get_instance();
        $instance->database->create_table();
        $instance->email->schedule_summary();
    }

    public static function deactivate(): void {
        $instance = self::get_instance();
        $instance->email->clear_schedule();
    }

    public function get_plugin_dir(): string {
        return plugin_dir_path( $this->get_plugin_file() );
    }

    public function get_plugin_url(): string {
        return plugin_dir_url( $this->get_plugin_file() );
    }

    public function get_plugin_file(): string {
        return LOG_HIGH_TTFB_PLUGIN_FILE;
    }

    public function get_database(): Database {
        return $this->database;
    }

    public function get_email_service(): Email {
        return $this->email;
    }

    public function get_nonce(): string {
        return wp_create_nonce( self::NONCE_ACTION );
    }

    private function register_hooks(): void {
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        add_action( 'rest_api_init', [ $this->rest_controller, 'register_routes' ] );
        add_action( 'wp_enqueue_scripts', [ $this->frontend, 'enqueue_scripts' ] );
        add_action( 'admin_menu', [ $this->admin, 'register_menu_pages' ] );
        add_action( 'admin_init', [ $this->admin, 'register_settings' ] );
        add_action( self::CRON_HOOK, [ $this->email, 'send_daily_summary' ] );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'log-high-ttfb', false, dirname( plugin_basename( $this->get_plugin_file() ) ) . '/languages' );
    }
}
