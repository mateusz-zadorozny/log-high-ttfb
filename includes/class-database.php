<?php
/**
 * Data persistence layer for Log High TTFB plugin.
 */

namespace Log_High_TTFB;

use function __;
use function dbDelta;
use function wp_parse_args;

use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

class Database
{
    private string $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'log_high_ttfb';
    }

    public function get_table_name(): string
    {
        return $this->table_name;
    }

    public function create_table(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $this->table_name;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            recorded_at DATETIME NOT NULL,
            ttfb_ms MEDIUMINT(8) UNSIGNED NOT NULL,
            category VARCHAR(20) NOT NULL,
            url TEXT NOT NULL,
            query_params TEXT NULL,
            cookies TEXT NULL,
            user_role VARCHAR(100) NOT NULL DEFAULT '',
            country VARCHAR(10) NOT NULL DEFAULT '',
            device_type VARCHAR(20) NOT NULL DEFAULT '',
            browser VARCHAR(100) NOT NULL DEFAULT '',
            referrer TEXT NULL,
            PRIMARY KEY  (id),
            KEY recorded_at (recorded_at),
            KEY category (category),
            KEY ttfb (ttfb_ms)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    public function insert_log(array $entry): bool
    {
        global $wpdb;

        $data = [
            'recorded_at' => $entry['recorded_at'] ?? gmdate('Y-m-d H:i:s'),
            'ttfb_ms' => (int) $entry['ttfb_ms'],
            'category' => $entry['category'],
            'url' => $entry['url'],
            'query_params' => $entry['query_params'] ?? null,
            'cookies' => $entry['cookies'] ?? null,
            'user_role' => $entry['user_role'] ?? '',
            'country' => $entry['country'] ?? '',
            'device_type' => $entry['device_type'] ?? '',
            'browser' => $entry['browser'] ?? '',
            'referrer' => $entry['referrer'] ?? null,
        ];

        $formats = ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        return (bool) $wpdb->insert($this->table_name, $data, $formats);
    }

    public function get_logs(array $args = []): array
    {
        global $wpdb;

        $defaults = [
            'per_page' => 20,
            'page' => 1,
            'category' => '',
            'search' => '',
        ];

        $args = wp_parse_args($args, $defaults);
        $limit = max(1, (int) $args['per_page']);
        $offset = max(0, ((int) $args['page'] - 1) * $limit);

        $where = 'WHERE 1=1';
        $query = [];

        if (!empty($args['category'])) {
            $where .= ' AND category = %s';
            $query[] = $args['category'];
        }

        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= ' AND url LIKE %s';
            $query[] = $like;
        }

        $sql = "SELECT * FROM {$this->table_name} {$where} ORDER BY recorded_at DESC LIMIT %d OFFSET %d";

        $query[] = $limit;
        $query[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $query), ARRAY_A);
    }

    public function count_logs(array $args = []): int
    {
        global $wpdb;

        $defaults = [
            'category' => '',
            'search' => '',
        ];

        $args = wp_parse_args($args, $defaults);
        $where = 'WHERE 1=1';
        $query = [];

        if (!empty($args['category'])) {
            $where .= ' AND category = %s';
            $query[] = $args['category'];
        }

        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= ' AND url LIKE %s';
            $query[] = $like;
        }

        $sql = "SELECT COUNT(*) FROM {$this->table_name} {$where}";

        $prepared = empty($query) ? $sql : $wpdb->prepare($sql, $query);

        return (int) $wpdb->get_var($prepared);
    }

    public function get_summary_counts(string $start_gmt, string $end_gmt): array
    {
        global $wpdb;

        $sql = "SELECT category, COUNT(*) as total
                FROM {$this->table_name}
                WHERE recorded_at BETWEEN %s AND %s
                GROUP BY category";

        $results = $wpdb->get_results($wpdb->prepare($sql, $start_gmt, $end_gmt), ARRAY_A);

        $summary = [
            'warning' => 0,
            'bad' => 0,
        ];

        foreach ($results as $row) {
            $category = $row['category'];
            if (isset($summary[$category])) {
                $summary[$category] = (int) $row['total'];
            }
        }

        return $summary;
    }

    public function get_top_slowest(string $start_gmt, string $end_gmt, int $limit = 50): array
    {
        global $wpdb;

        $limit = max(1, $limit);

        $sql = "SELECT * FROM {$this->table_name}
                WHERE recorded_at BETWEEN %s AND %s
                  AND category = 'bad'
                ORDER BY ttfb_ms DESC
                LIMIT %d";

        return $wpdb->get_results($wpdb->prepare($sql, $start_gmt, $end_gmt, $limit), ARRAY_A);
    }

    public function group_by_field(array $entries, string $field): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            $value = $entry[$field] ?? '';
            if (empty($value)) {
                $value = __('None', 'log-high-ttfb');
            }

            if (!isset($grouped[$value])) {
                $grouped[$value] = ['count' => 0, 'ttfb_sum' => 0, 'examples' => []];
            }

            $grouped[$value]['count']++;
            $grouped[$value]['ttfb_sum'] += (int) $entry['ttfb_ms'];

            if (count($grouped[$value]['examples']) < 3) {
                $grouped[$value]['examples'][] = $entry;
            }
        }

        uasort(
            $grouped,
            static function ($a, $b) {
                return $b['count'] <=> $a['count'];
            }
        );

        return $grouped;
    }
}
