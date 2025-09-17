<?php
/**
 * Email scheduler and summary builder.
 */

namespace Log_High_TTFB;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use function __;
use function get_option;
use function implode;
use function wp_clear_scheduled_hook;
use function wp_date;
use function wp_mail;
use function wp_next_scheduled;
use function wp_schedule_event;
use function wp_timezone;
use function wp_unschedule_event;

defined( 'ABSPATH' ) || exit;

class Email {
    private Database $database;

    public function __construct( Database $database ) {
        $this->database = $database;
    }

    public function schedule_summary(): void {
        if ( wp_next_scheduled( Plugin::CRON_HOOK ) ) {
            return;
        }

        $timestamp = $this->next_run_timestamp();
        wp_schedule_event( $timestamp, 'daily', Plugin::CRON_HOOK );
    }

    public function clear_schedule(): void {
        $timestamp = wp_next_scheduled( Plugin::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, Plugin::CRON_HOOK );
        }

        wp_clear_scheduled_hook( Plugin::CRON_HOOK );
    }

    public function send_daily_summary(): void {
        $options = get_option( Plugin::OPTION_KEY, [] );
        $enabled = ! empty( $options['enable_email'] );

        if ( ! $enabled ) {
            return;
        }

        $recipients = ! empty( $options['email_recipients'] ) ? $options['email_recipients'] : get_option( 'admin_email' );
        if ( empty( $recipients ) ) {
            return;
        }

        $timezone = wp_timezone();
        $now      = new DateTimeImmutable( 'now', $timezone );
        $start    = $now->sub( new DateInterval( 'P1D' ) )->setTime( 0, 0 );
        $end      = $start->setTime( 23, 59, 59 );

        $start_gmt = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $end_gmt   = $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

        $counts      = $this->database->get_summary_counts( $start_gmt, $end_gmt );
        $top_slowest = $this->database->get_top_slowest( $start_gmt, $end_gmt, 50 );

        $subject_date = wp_date( 'F j, Y', $start->getTimestamp() );
        $subject      = sprintf( __( 'TTFB summary for %s', 'log-high-ttfb' ), $subject_date );

        $body_lines = [];
        $body_lines[] = sprintf( __( 'Monitoring window: %s - %s', 'log-high-ttfb' ),
            wp_date( 'Y-m-d H:i', $start->getTimestamp() ),
            wp_date( 'Y-m-d H:i', $end->getTimestamp() )
        );
        $body_lines[] = '';
        $body_lines[] = __( 'Totals', 'log-high-ttfb' ) . ':';
        $body_lines[] = sprintf( '- %s: %d', __( 'Warnings (> 800ms)', 'log-high-ttfb' ), $counts['warning'] ?? 0 );
        $body_lines[] = sprintf( '- %s: %d', __( 'Slow (>= 1800ms)', 'log-high-ttfb' ), $counts['bad'] ?? 0 );
        $body_lines[] = '';
        $body_lines[] = __( 'Top slowest requests', 'log-high-ttfb' ) . ':';

        if ( empty( $top_slowest ) ) {
            $body_lines[] = __( 'No slow requests logged yesterday.', 'log-high-ttfb' );
        } else {
            foreach ( $top_slowest as $index => $entry ) {
                $body_lines[] = sprintf(
                    '%d. %d ms - %s',
                    $index + 1,
                    (int) $entry['ttfb_ms'],
                    $entry['url']
                );
            }
        }

        $body_lines[] = '';
        $body_lines[] = __( 'Similarity hints', 'log-high-ttfb' ) . ':';

        $normalized_entries = array_map( [ $this, 'normalize_entry' ], $top_slowest );

        $body_lines = array_merge( $body_lines, $this->build_similarity_section( $normalized_entries, 'url', __( 'By URL', 'log-high-ttfb' ) ) );
        $body_lines = array_merge( $body_lines, $this->build_similarity_section( $normalized_entries, 'query_params', __( 'By query params', 'log-high-ttfb' ) ) );
        $body_lines = array_merge( $body_lines, $this->build_similarity_section( $normalized_entries, 'cookies', __( 'By cookies', 'log-high-ttfb' ) ) );

        $body = implode( "\n", $body_lines );

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        wp_mail( array_map( 'trim', explode( ',', $recipients ) ), $subject, $body, $headers );
    }

    private function next_run_timestamp(): int {
        $timezone = wp_timezone();
        $now      = new DateTimeImmutable( 'now', $timezone );
        $target   = $now->setTime( 8, 0, 0 );

        if ( $target <= $now ) {
            $target = $target->add( new DateInterval( 'P1D' ) );
        }

        return $target->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
    }

    private function normalize_entry( array $entry ): array {
        $entry['query_params'] = $this->decode_list_field( $entry['query_params'] ?? '' );
        $entry['cookies']      = $this->decode_list_field( $entry['cookies'] ?? '' );

        return $entry;
    }

    private function decode_list_field( string $value ): string {
        if ( empty( $value ) ) {
            return '';
        }

        $decoded = json_decode( $value, true );
        if ( ! is_array( $decoded ) ) {
            return $value;
        }

        $decoded = array_filter( array_map( 'trim', $decoded ) );

        return implode( ', ', $decoded );
    }

    private function build_similarity_section( array $entries, string $field, string $label ): array {
        $lines = [];
        $lines[] = $label . ':';

        if ( empty( $entries ) ) {
            $lines[] = __( 'No data.', 'log-high-ttfb' );
            $lines[] = '';
            return $lines;
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

        $i = 0;
        foreach ( $buckets as $value => $stats ) {
            $avg = (int) round( $stats['sum'] / max( 1, $stats['count'] ) );
            $lines[] = sprintf( '- %s - %d %s (avg %d ms)', $value, $stats['count'], __( 'hits', 'log-high-ttfb' ), $avg );
            $i++;
            if ( $i >= 5 ) {
                break;
            }
        }

        $lines[] = '';

        return $lines;
    }
}
