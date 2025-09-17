<?php
/**
 * REST controller for receiving TTFB measurements.
 */

namespace Log_High_TTFB;

use function __;
use function esc_url_raw;
use function is_user_logged_in;
use function register_rest_route;
use function sanitize_text_field;
use function wp_get_current_user;
use function wp_json_encode;
use function wp_unslash;
use function wp_verify_nonce;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rest_Controller extends WP_REST_Controller {
    private Database $database;
    protected $namespace = 'log-high-ttfb/v1';
    protected $rest_base = 'log';
    protected $schema;

    public function __construct( Database $database ) {
        $this->database = $database;
    }

    public function get_item_schema() {
        if ( $this->schema ) {
            return $this->schema;
        }

        $this->schema = [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'log_high_ttfb_entry',
            'type'       => 'object',
            'properties' => [
                'ttfb' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => __( 'Measured time to first byte in milliseconds.', 'log-high-ttfb' ),
                ],
                'url' => [
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => __( 'Page URL that was measured.', 'log-high-ttfb' ),
                ],
                'timestamp' => [
                    'type'        => 'string',
                    'description' => __( 'ISO 8601 timestamp from the browser.', 'log-high-ttfb' ),
                ],
                'queryParamKeys' => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'string' ],
                    'description' => __( 'Query parameter keys present on the page.', 'log-high-ttfb' ),
                ],
                'cookieNames' => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'string' ],
                    'description' => __( 'Cookie names present in the browser.', 'log-high-ttfb' ),
                ],
                'deviceType' => [
                    'type'        => 'string',
                    'description' => __( 'Client device category.', 'log-high-ttfb' ),
                ],
                'browser' => [
                    'type'        => 'string',
                    'description' => __( 'Client browser name.', 'log-high-ttfb' ),
                ],
                'referrer' => [
                    'type'        => 'string',
                    'description' => __( 'Document referrer.', 'log-high-ttfb' ),
                ],
            ],
            'required'   => [ 'ttfb', 'url' ],
        ];

        return $this->schema;
    }

    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'permission_callback' => [ $this, 'permission_check' ],
                    'callback'            => [ $this, 'create_item' ],
                    'args'                => $this->get_endpoint_args_for_item_schema( true ),
                ],
            ]
        );
    }

    public function permission_check( WP_REST_Request $request ) {
        $nonce = $request->get_header( 'x-log-high-ttfb-nonce' );

        if ( $nonce && wp_verify_nonce( $nonce, Plugin::NONCE_ACTION ) ) {
            return true;
        }

        if ( is_user_logged_in() ) {
            return true;
        }

        return new WP_Error( 'rest_forbidden', __( 'Invalid security token.', 'log-high-ttfb' ), [ 'status' => 403 ] );
    }

    public function create_item( $request ) {
        $ttfb = (int) $request->get_param( 'ttfb' );

        if ( $ttfb <= 800 ) {
            return new WP_REST_Response( [ 'logged' => false, 'reason' => 'below-threshold' ], 200 );
        }

        $url       = esc_url_raw( $request->get_param( 'url' ) );
        $referrer  = esc_url_raw( $request->get_param( 'referrer' ) );
        $device    = sanitize_text_field( $request->get_param( 'deviceType' ) );
        $browser   = sanitize_text_field( $request->get_param( 'browser' ) );
        $timestamp = $request->get_param( 'timestamp' );

        $category = ( $ttfb >= 1800 ) ? 'bad' : 'warning';

        $query_param_keys = $request->get_param( 'queryParamKeys' );
        $cookie_names     = $request->get_param( 'cookieNames' );

        $query_params = [];
        if ( is_array( $query_param_keys ) ) {
            foreach ( $query_param_keys as $key ) {
                $key = sanitize_text_field( $key );
                if ( $key !== '' ) {
                    $query_params[] = $key;
                }
            }
        }

        $cookies = [];
        if ( is_array( $cookie_names ) ) {
            foreach ( $cookie_names as $name ) {
                $name = sanitize_text_field( $name );
                if ( $name !== '' ) {
                    $cookies[] = $name;
                }
            }
        }

        $user          = wp_get_current_user();
        $user_role     = ( $user instanceof \WP_User && ! empty( $user->roles ) ) ? $user->roles[0] : 'guest';
        $country       = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) : '';
        $country       = strtoupper( substr( $country, 0, 3 ) );
        $recorded_time = $this->normalize_timestamp( $timestamp );

        $inserted = $this->database->insert_log(
            [
                'recorded_at'  => $recorded_time,
                'ttfb_ms'      => $ttfb,
                'category'     => $category,
                'url'          => $url,
                'query_params' => $query_params ? wp_json_encode( array_values( array_unique( $query_params ) ) ) : null,
                'cookies'      => $cookies ? wp_json_encode( array_values( array_unique( $cookies ) ) ) : null,
                'user_role'    => $user_role,
                'country'      => $country,
                'device_type'  => $device,
                'browser'      => $browser,
                'referrer'     => $referrer,
            ]
        );

        if ( ! $inserted ) {
            return new WP_Error( 'log_high_ttfb_failed', __( 'Failed to store measurement.', 'log-high-ttfb' ), [ 'status' => 500 ] );
        }

        return new WP_REST_Response( [ 'logged' => true, 'category' => $category ], 201 );
    }

    private function normalize_timestamp( $timestamp ): string {
        if ( empty( $timestamp ) ) {
            return gmdate( 'Y-m-d H:i:s' );
        }

        $time = strtotime( $timestamp );

        if ( ! $time ) {
            return gmdate( 'Y-m-d H:i:s' );
        }

        return gmdate( 'Y-m-d H:i:s', $time );
    }
}
