<?php
/**
 * Table view for logged entries.
 */

namespace Log_High_TTFB;

use WP_List_Table;
use function __;
use function esc_html;
use function esc_url;
use function get_date_from_gmt;
use function get_option;
use function number_format_i18n;
use function sanitize_text_field;
use function wp_date;
use function wp_unslash;

defined( 'ABSPATH' ) || exit;

class Logs_List_Table extends WP_List_Table {
    private Database $database;

    public function __construct( Database $database ) {
        $this->database = $database;

        parent::__construct(
            [
                'singular' => 'ttfb_log',
                'plural'   => 'ttfb_logs',
                'ajax'     => false,
            ]
        );
    }

    public function prepare_items(): void {
        $per_page     = 20;
        $current_page = $this->get_pagenum();

        $category = isset( $_REQUEST['category'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['category'] ) ) : '';
        $search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

        $this->items = $this->database->get_logs(
            [
                'per_page' => $per_page,
                'page'     => $current_page,
                'category' => $category,
                'search'   => $search,
            ]
        );

        $total_items = $this->database->count_logs(
            [
                'category' => $category,
                'search'   => $search,
            ]
        );

        $this->set_pagination_args(
            [
                'total_items' => $total_items,
                'per_page'    => $per_page,
            ]
        );

        $this->_column_headers = [ $this->get_columns(), [], [] ];
    }

    public function get_columns(): array {
        return [
            'recorded_at'  => __( 'Recorded', 'log-high-ttfb' ),
            'ttfb_ms'      => __( 'TTFB (ms)', 'log-high-ttfb' ),
            'category'     => __( 'Severity', 'log-high-ttfb' ),
            'url'          => __( 'URL', 'log-high-ttfb' ),
            'query_params' => __( 'Query params', 'log-high-ttfb' ),
            'cookies'      => __( 'Cookies', 'log-high-ttfb' ),
            'user_role'    => __( 'User role', 'log-high-ttfb' ),
            'country'      => __( 'Country', 'log-high-ttfb' ),
            'device_type'  => __( 'Device', 'log-high-ttfb' ),
            'browser'      => __( 'Browser', 'log-high-ttfb' ),
            'referrer'     => __( 'Referrer', 'log-high-ttfb' ),
        ];
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'url':
            case 'referrer':
                return $this->format_url( $item[ $column_name ] ?? '' );
            case 'query_params':
            case 'cookies':
                return $this->format_list_column( $item[ $column_name ] ?? '' );
            case 'ttfb_ms':
                return esc_html( number_format_i18n( (int) $item['ttfb_ms'] ) );
            case 'category':
                return esc_html( ucfirst( $item['category'] ) );
            case 'user_role':
            case 'country':
            case 'device_type':
            case 'browser':
                return esc_html( $item[ $column_name ] );
            default:
                return esc_html( $item[ $column_name ] ?? '' );
        }
    }

    public function column_recorded_at( $item ) {
        $gmt_time = $item['recorded_at'];
        $local    = get_date_from_gmt( $gmt_time, 'Y-m-d H:i:s' );

        return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $local ) ) );
    }

    private function format_url( string $url ): string {
        if ( empty( $url ) ) {
            return 'N/A';
        }

        return sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( $url ), esc_html( $url ) );
    }

    private function format_list_column( string $json ): string {
        if ( empty( $json ) ) {
            return 'N/A';
        }

        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) {
            return esc_html( $json );
        }

        $items = array_filter( array_map( 'trim', $decoded ) );

        return esc_html( implode( ', ', $items ) );
    }
}
