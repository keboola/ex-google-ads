<?php

declare(strict_types=1);

namespace Keboola\GoogleAds;

/**
 * Rewrites deprecated GAQL field names in a user report query to the names the current Google Ads
 * API (v25) accepts, and derives the matching output column names, so that a query written for the
 * old field names keeps working and the output table keeps the column names and value types it had
 * before the rename — unless the customer opts out (Config::rewriteDeprecatedFieldsEnabled()).
 *
 * Matching is per whole field-path token (a tokenizing regex plus an exact map lookup), never by
 * substring replacement, so overlapping names such as "metrics.video_view_rate" and
 * "metrics.video_view_rate_in_stream" never collide and a query that already uses the current name
 * is left untouched.
 *
 * Matching is whole-query, not scoped to field positions, so a deprecated token appearing inside a
 * GAQL string literal (e.g. a quoted filter value) would also be rewritten, though no legitimate
 * query does this.
 */
class DeprecatedFieldRewriter
{
    /**
     * Deprecated field path (v21) => current field path (v25), from the Google Ads API v22/v23
     * breaking-change release notes. See
     * docs/superpowers/specs/2026-09-21-google-ads-v25-backwards-compat-design.md.
     *
     * @var array<string, string>
     */
    public const RENAMES = [
        'metrics.average_cpv' => 'metrics.trueview_average_cpv',
        'metrics.video_views' => 'metrics.video_trueview_views',
        'metrics.video_view_rate' => 'metrics.video_trueview_view_rate',
        'metrics.video_view_rate_in_feed' => 'metrics.video_trueview_view_rate_in_feed',
        'metrics.video_view_rate_in_stream' => 'metrics.video_trueview_view_rate_in_stream',
        'metrics.video_view_rate_shorts' => 'metrics.video_trueview_view_rate_shorts',
        'campaign.start_date' => 'campaign.start_date_time',
        'campaign.end_date' => 'campaign.end_date_time',
    ];

    /**
     * Renamed fields whose type changed from date to datetime; their values are truncated back to
     * a date so the output stays identical to the pre-rename output.
     *
     * @var array<int, string>
     */
    private const DATE_RENAME_OLD_PATHS = [
        'campaign.start_date',
        'campaign.end_date',
    ];

    /** Matches one whole GAQL field-path token, e.g. "campaign.start_date", "metrics.video_views". */
    private const FIELD_TOKEN_PATTERN = '/[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+/';

    /**
     * @return array{query: string, applied: array<string, string>}
     */
    public static function rewrite(string $query): array
    {
        $applied = [];
        $rewritten = preg_replace_callback(
            self::FIELD_TOKEN_PATTERN,
            /** @param array<int, string> $matches */
            static function (array $matches) use (&$applied): string {
                $token = $matches[0];
                if (array_key_exists($token, self::RENAMES)) {
                    $applied[$token] = self::RENAMES[$token];
                    return self::RENAMES[$token];
                }
                return $token;
            },
            $query,
        );

        return [
            'query' => $rewritten ?? $query,
            'applied' => $applied,
        ];
    }

    /**
     * Maps each rewritten field's response column key back to its legacy output column name.
     *
     * @param array<string, string> $applied old path => new path, from rewrite()
     * @return array<string, string> new column key => legacy column name
     */
    public static function reportColumnOverrides(array $applied): array
    {
        $overrides = [];
        foreach ($applied as $oldPath => $newPath) {
            $overrides[self::columnKeyFromPath($newPath)] = self::columnNameFromPath($oldPath);
        }
        return $overrides;
    }

    /**
     * Legacy column names whose values must be truncated from datetime back to date.
     *
     * @param array<string, string> $applied old path => new path, from rewrite()
     * @return array<int, string>
     */
    public static function dateColumnNames(array $applied): array
    {
        $names = [];
        foreach (array_keys($applied) as $oldPath) {
            if (in_array($oldPath, self::DATE_RENAME_OLD_PATHS, true)) {
                $names[] = self::columnNameFromPath($oldPath);
            }
        }
        return $names;
    }

    /**
     * Apply the legacy-column-name overrides to a report field-mask column map (column key =>
     * column name), so the output table keeps its original column names. Only keys actually
     * present are changed; column order is preserved (in-place update, no re-keying).
     *
     * @param array<string, string> $listColumns column key => column name
     * @param array<string, string> $applied     old path => new path, from rewrite()
     * @return array<string, string>
     */
    public static function applyColumnOverrides(array $listColumns, array $applied): array
    {
        foreach (self::reportColumnOverrides($applied) as $newKey => $legacyName) {
            if (array_key_exists($newKey, $listColumns)) {
                $listColumns[$newKey] = $legacyName;
            }
        }
        return $listColumns;
    }

    /**
     * Truncate the renamed date columns of one output row from datetime back to date. Columns that
     * are absent, null, or non-string are left untouched (isset excludes null; is_string guards the
     * rest — a value here is always a date string from the API, but we stay defensive).
     *
     * @param array<string, mixed> $row     output row: column name => value
     * @param array<string, string> $applied old path => new path, from rewrite()
     * @return array<string, mixed>
     */
    public static function truncateDateColumns(array $row, array $applied): array
    {
        foreach (self::dateColumnNames($applied) as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $row[$column] = self::truncateToDate($row[$column]);
            }
        }
        return $row;
    }

    /** e.g. "metrics.video_views" => "metrics.videoViews" (the response field-mask key). */
    public static function columnKeyFromPath(string $path): string
    {
        return lcfirst(str_replace('_', '', ucwords($path, '_')));
    }

    /** e.g. "metrics.video_views" => "metricsVideoViews" (the output column name). */
    public static function columnNameFromPath(string $path): string
    {
        return lcfirst(str_replace(['.', '_'], '', ucwords($path, '._')));
    }

    public static function truncateToDate(string $value): string
    {
        if (preg_match('~^(\d{4}-\d{2}-\d{2})[ T]\d{2}:\d{2}:\d{2}~', $value, $matches) === 1) {
            return $matches[1];
        }
        return $value;
    }
}
