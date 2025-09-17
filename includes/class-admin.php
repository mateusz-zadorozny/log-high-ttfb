<?php
/**
 * Admin UI for Log High TTFB plugin.
 */

namespace Log_High_TTFB;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Log_High_TTFB\Logs_List_Table;
use function __;
use function add_menu_page;
use function add_settings_field;
use function add_settings_section;
use function add_submenu_page;
use function current_user_can;
use function do_settings_sections;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function get_date_from_gmt;
use function get_option;
use function number_format_i18n;
use function register_setting;
use function sanitize_email;
use function sanitize_text_field;
use function selected;
use function settings_fields;
use function submit_button;
use function wp_date;
use function wp_parse_args;
use function wp_timezone;
use function wp_unslash;

defined( 'ABSPATH' ) || exit;

class Admin {
    private Database $database;

    public function __construct( Database $database ) {
        $this->database = $database;
    }

    public function register_menu_pages(): void {
        $capability = 'manage_options';

        add_menu_page(
            __( 'TTFB Monitor', 'log-high-ttfb' ),
            __( 'TTFB Monitor', 'log-high-ttfb' ),
            $capability,
            'log-high-ttfb',
            [ $this, 'render_logs_page' ],
            'dashicons-performance',
            80
        );

        add_submenu_page(
            'log-high-ttfb',
            __( 'Logs', 'log-high-ttfb' ),
            __( 'Logs', 'log-high-ttfb' ),
            $capability,
            'log-high-ttfb',
            [ $this, 'render_logs_page' ]
        );

        add_submenu_page(
            'log-high-ttfb',
            __( 'Insights', 'log-high-ttfb' ),
            __( 'Insights', 'log-high-ttfb' ),
            $capability,
            'log-high-ttfb-insights',
            [ $this, 'render_insights_page' ]
        );

        add_submenu_page(
            'log-high-ttfb',
            __( 'Settings', 'log-high-ttfb' ),
            __( 'Settings', 'log-high-ttfb' ),
            $capability,
            'log-high-ttfb-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting(
            'log_high_ttfb_settings',
            Plugin::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_options' ],
                'default'           => [
                    'enable_email'     => 0,
                    'email_recipients' => get_option( 'admin_email' ),
                ],
            ]
        );

        add_settings_section(
            'log_high_ttfb_notifications',
            __( 'Email Notifications', 'log-high-ttfb' ),
            null,
            'log_high_ttfb_settings'
        );

        add_settings_field(
            'log_high_ttfb_enable_email',
            __( 'Enable daily summary email', 'log-high-ttfb' ),
            [ $this, 'render_enable_email_field' ],
            'log_high_ttfb_settings',
            'log_high_ttfb_notifications'
        );

        add_settings_field(
            'log_high_ttfb_email_recipients',
            __( 'Email recipients', 'log-high-ttfb' ),
            [ $this, 'render_recipients_field' ],
            'log_high_ttfb_settings',
            'log_high_ttfb_notifications'
        );
    }

    public function render_logs_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

        $table = new Logs_List_Table( $this->database );
        $table->prepare_items();

        $current_category = isset( $_REQUEST['category'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['category'] ) ) : '';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TTFB Monitor Logs', 'log-high-ttfb' ); ?></h1>
            <form method="get">
                <input type="hidden" name="page" value="log-high-ttfb" />
                <?php $table->search_box( __( 'Search URLs', 'log-high-ttfb' ), 'log-high-ttfb' ); ?>
                <select name="category">
                    <option value="" <?php selected( '', $current_category ); ?>><?php esc_html_e( 'All severities', 'log-high-ttfb' ); ?></option>
                    <option value="warning" <?php selected( 'warning', $current_category ); ?>><?php esc_html_e( 'Warnings (> 800ms)', 'log-high-ttfb' ); ?></option>
                    <option value="bad" <?php selected( 'bad', $current_category ); ?>><?php esc_html_e( 'Slow (>= 1800ms)', 'log-high-ttfb' ); ?></option>
                </select>
                <?php submit_button( __( 'Filter', 'log-high-ttfb' ), 'secondary', '', false ); ?>
            </form>
            <form method="post">
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }

    public function render_insights_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $timezone    = wp_timezone();
        $end_local   = new DateTimeImmutable( 'now', $timezone );
        $start_local = $end_local->sub( new DateInterval( 'P7D' ) )->setTime( 0, 0, 0 );

        $start_gmt = $start_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $end_gmt   = $end_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

        $counts      = $this->database->get_summary_counts( $start_gmt, $end_gmt );
        $top_slowest = $this->database->get_top_slowest( $start_gmt, $end_gmt, 50 );

        $normalized_entries = array_map( [ $this, 'normalize_entry_for_display' ], $top_slowest );
        $url_groups         = $this->build_similarity_rows( $normalized_entries, 'url' );
        $param_groups       = $this->build_similarity_rows( $normalized_entries, 'query_params' );
        $cookie_groups      = $this->build_similarity_rows( $normalized_entries, 'cookies' );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TTFB Insights', 'log-high-ttfb' ); ?></h1>
            <p><?php printf( esc_html__( 'Window: %1$s - %2$s', 'log-high-ttfb' ), esc_html( wp_date( 'Y-m-d H:i', $start_local->getTimestamp() ) ), esc_html( wp_date( 'Y-m-d H:i', $end_local->getTimestamp() ) ) ); ?></p>

            <h2><?php esc_html_e( 'Totals (last 7 days)', 'log-high-ttfb' ); ?></h2>
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Warnings (> 800ms)', 'log-high-ttfb' ); ?></th>
                        <td><?php echo esc_html( number_format_i18n( $counts['warning'] ?? 0 ) ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Slow (>= 1800ms)', 'log-high-ttfb' ); ?></th>
                        <td><?php echo esc_html( number_format_i18n( $counts['bad'] ?? 0 ) ); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Top slowest requests', 'log-high-ttfb' ); ?></h2>
            <?php if ( empty( $top_slowest ) ) : ?>
                <p><?php esc_html_e( 'No slow requests captured in this window.', 'log-high-ttfb' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Rank', 'log-high-ttfb' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'TTFB (ms)', 'log-high-ttfb' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'URL', 'log-high-ttfb' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Recorded', 'log-high-ttfb' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $top_slowest as $index => $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $index + 1 ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( (int) $entry['ttfb_ms'] ) ); ?></td>
                                <td><a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $entry['url'] ); ?></a></td>
                                <td><?php echo esc_html( $this->format_recorded_time( $entry['recorded_at'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Similarity hints', 'log-high-ttfb' ); ?></h2>
            <div class="log-high-ttfb-similarity">
                <?php $this->render_similarity_table( __( 'By URL', 'log-high-ttfb' ), $url_groups ); ?>
                <?php $this->render_similarity_table( __( 'By query params', 'log-high-ttfb' ), $param_groups ); ?>
                <?php $this->render_similarity_table( __( 'By cookies', 'log-high-ttfb' ), $cookie_groups ); ?>
            </div>
        </div>
        <?php
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $options = $this->get_options();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TTFB Monitor Settings', 'log-high-ttfb' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'log_high_ttfb_settings' );
                do_settings_sections( 'log_high_ttfb_settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_enable_email_field(): void {
        $options = $this->get_options();
        $checked = ! empty( $options['enable_email'] ) ? 'checked' : '';
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( Plugin::OPTION_KEY ); ?>[enable_email]" value="1" <?php echo esc_attr( $checked ); ?> />
            <?php esc_html_e( 'Send a daily summary email at 8:00 AM site time.', 'log-high-ttfb' ); ?>
        </label>
        <?php
    }

    public function render_recipients_field(): void {
        $options    = $this->get_options();
        $recipients = isset( $options['email_recipients'] ) ? $options['email_recipients'] : get_option( 'admin_email' );
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( Plugin::OPTION_KEY ); ?>[email_recipients]" value="<?php echo esc_attr( $recipients ); ?>" placeholder="you@example.com, team@example.com" />
        <p class="description">
            <?php esc_html_e( 'Comma-separated list of email addresses.', 'log-high-ttfb' ); ?>
        </p>
        <?php
    }

    public function sanitize_options( $options ): array {
        $clean = [
            'enable_email'     => 0,
            'email_recipients' => '',
        ];

        if ( isset( $options['enable_email'] ) ) {
            $clean['enable_email'] = (int) (bool) $options['enable_email'];
        }

        if ( isset( $options['email_recipients'] ) ) {
            $emails = explode( ',', $options['email_recipients'] );
            $emails = array_filter(
                array_map(
                    static function ( $email ) {
                        $email = sanitize_email( trim( $email ) );
                        return $email ?: null;
                    },
                    $emails
                )
            );

            $clean['email_recipients'] = implode( ',', $emails );
        }

        $email_service = Plugin::get_instance()->get_email_service();

        if ( $clean['enable_email'] ) {
            $email_service->schedule_summary();
        } else {
            $email_service->clear_schedule();
        }

        return $clean;
    }

    private function get_options(): array {
        $defaults = [
            'enable_email'     => 0,
            'email_recipients' => get_option( 'admin_email' ),
        ];

        $options = get_option( Plugin::OPTION_KEY, [] );

        return wp_parse_args( $options, $defaults );
    }

    private function render_similarity_table( string $heading, array $rows ): void {
        ?>
        <div class="postbox">
            <h3 class="hndle"><span><?php echo esc_html( $heading ); ?></span></h3>
            <div class="inside">
                <?php if ( empty( $rows ) ) : ?>
                    <p><?php esc_html_e( 'No data found for this dimension.', 'log-high-ttfb' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'Value', 'log-high-ttfb' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Hits', 'log-high-ttfb' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Average TTFB (ms)', 'log-high-ttfb' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $rows as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $row['label'] ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $row['average'] ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function build_similarity_rows( array $entries, string $field, int $limit = 5 ): array {
        if ( empty( $entries ) ) {
            return [];
        }

        $buckets = [];
        foreach ( $entries as $entry ) {
            $value = trim( (string) ( $entry[ $field ] ?? '' ) );
            if ( '' === $value ) {
                $value = __( 'None', 'log-high-ttfb' );
            }

            if ( ! isset( $buckets[ $value ] ) ) {
                $buckets[ $value ] = [ 'count' => 0, 'sum' => 0 ];
            }

            $buckets[ $value ]['count']++;
            $buckets[ $value ]['sum'] += (int) $entry['ttfb_ms'];
        }

        uasort(
            $buckets,
            static function ( $a, $b ) {
                return $b['count'] <=> $a['count'];
            }
        );

        $rows = [];
        $i    = 0;
        foreach ( $buckets as $label => $stats ) {
            $average = (int) round( $stats['sum'] / max( 1, $stats['count'] ) );
            $rows[]  = [
                'label'   => $label,
                'count'   => $stats['count'],
                'average' => $average,
            ];
            $i++;
            if ( $i >= $limit ) {
                break;
            }
        }

        return $rows;
    }

    private function normalize_entry_for_display( array $entry ): array {
        $entry['query_params'] = $this->decode_list_field_for_display( $entry['query_params'] ?? '' );
        $entry['cookies']      = $this->decode_list_field_for_display( $entry['cookies'] ?? '' );

        return $entry;
    }

    private function decode_list_field_for_display( string $value ): string {
        if ( '' === $value ) {
            return '';
        }

        $decoded = json_decode( $value, true );
        if ( ! is_array( $decoded ) ) {
            return $value;
        }

        $decoded = array_filter( array_map( 'trim', $decoded ) );

        return implode( ', ', $decoded );
    }

    private function format_recorded_time( string $gmt ): string {
        $local = get_date_from_gmt( $gmt, 'Y-m-d H:i:s' );

        return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $local ) );
    }
}
