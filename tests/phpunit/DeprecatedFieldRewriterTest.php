<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Tests;

use Keboola\GoogleAds\DeprecatedFieldRewriter;
use PHPUnit\Framework\TestCase;

class DeprecatedFieldRewriterTest extends TestCase
{
    public function testRewritesRenamedVideoMetric(): void
    {
        $result = DeprecatedFieldRewriter::rewrite(
            'SELECT campaign.id, metrics.video_views FROM campaign',
        );
        self::assertSame(
            'SELECT campaign.id, metrics.video_trueview_views FROM campaign',
            $result['query'],
        );
        self::assertSame(
            ['metrics.video_views' => 'metrics.video_trueview_views'],
            $result['applied'],
        );
    }

    public function testRewritesCampaignDates(): void
    {
        $result = DeprecatedFieldRewriter::rewrite(
            'SELECT campaign.start_date, campaign.end_date FROM campaign',
        );
        self::assertSame(
            'SELECT campaign.start_date_time, campaign.end_date_time FROM campaign',
            $result['query'],
        );
        self::assertSame([
            'campaign.start_date' => 'campaign.start_date_time',
            'campaign.end_date' => 'campaign.end_date_time',
        ], $result['applied']);
    }

    public function testDoesNotCollideOnOverlappingNames(): void
    {
        $result = DeprecatedFieldRewriter::rewrite(
            'SELECT metrics.video_view_rate, metrics.video_view_rate_in_stream FROM campaign',
        );
        self::assertSame(
            'SELECT metrics.video_trueview_view_rate, '
            . 'metrics.video_trueview_view_rate_in_stream FROM campaign',
            $result['query'],
        );
    }

    public function testLeavesAlreadyMigratedQueryUnchanged(): void
    {
        $query = 'SELECT campaign.start_date_time, metrics.video_trueview_views FROM campaign';
        $result = DeprecatedFieldRewriter::rewrite($query);
        self::assertSame($query, $result['query']);
        self::assertSame([], $result['applied']);
    }

    public function testRewritesFieldsInWhereAndOrderBy(): void
    {
        $result = DeprecatedFieldRewriter::rewrite(
            'SELECT campaign.id FROM campaign WHERE campaign.start_date > "2020-01-01" '
            . 'ORDER BY campaign.start_date',
        );
        self::assertSame(
            'SELECT campaign.id FROM campaign WHERE campaign.start_date_time > "2020-01-01" '
            . 'ORDER BY campaign.start_date_time',
            $result['query'],
        );
    }

    public function testReportColumnOverridesMapNewKeyToLegacyName(): void
    {
        $applied = [
            'metrics.video_views' => 'metrics.video_trueview_views',
            'campaign.start_date' => 'campaign.start_date_time',
        ];
        self::assertSame([
            'metrics.videoTrueviewViews' => 'metricsVideoViews',
            'campaign.startDateTime' => 'campaignStartDate',
        ], DeprecatedFieldRewriter::reportColumnOverrides($applied));
    }

    public function testDateColumnNamesOnlyForDateRenames(): void
    {
        $applied = [
            'metrics.video_views' => 'metrics.video_trueview_views',
            'campaign.start_date' => 'campaign.start_date_time',
            'campaign.end_date' => 'campaign.end_date_time',
        ];
        self::assertSame(
            ['campaignStartDate', 'campaignEndDate'],
            DeprecatedFieldRewriter::dateColumnNames($applied),
        );
    }

    public function testTruncateToDate(): void
    {
        self::assertSame('2024-05-06', DeprecatedFieldRewriter::truncateToDate('2024-05-06 12:34:56'));
        self::assertSame('2024-05-06', DeprecatedFieldRewriter::truncateToDate('2024-05-06T12:34:56'));
        self::assertSame('2024-05-06', DeprecatedFieldRewriter::truncateToDate('2024-05-06'));
        self::assertSame('', DeprecatedFieldRewriter::truncateToDate(''));
    }

    public function testColumnNameHelpers(): void
    {
        self::assertSame(
            'metricsVideoViews',
            DeprecatedFieldRewriter::columnNameFromPath('metrics.video_views'),
        );
        self::assertSame(
            'metrics.videoViews',
            DeprecatedFieldRewriter::columnKeyFromPath('metrics.video_views'),
        );
    }

    public function testRewritesRemainingRenamedMetrics(): void
    {
        $result = DeprecatedFieldRewriter::rewrite(
            'SELECT metrics.average_cpv, metrics.video_view_rate_in_feed, '
            . 'metrics.video_view_rate_shorts FROM campaign',
        );
        self::assertSame(
            'SELECT metrics.trueview_average_cpv, metrics.video_trueview_view_rate_in_feed, '
            . 'metrics.video_trueview_view_rate_shorts FROM campaign',
            $result['query'],
        );
        self::assertSame([
            'metrics.average_cpv' => 'metrics.trueview_average_cpv',
            'metrics.video_view_rate_in_feed' => 'metrics.video_trueview_view_rate_in_feed',
            'metrics.video_view_rate_shorts' => 'metrics.video_trueview_view_rate_shorts',
        ], $result['applied']);
    }

    public function testApplyColumnOverridesRenamesPresentKeysOnly(): void
    {
        $listColumns = [
            'metrics.videoTrueviewViews' => 'metricsVideoTrueviewViews',
            'campaign.startDateTime' => 'campaignStartDateTime',
            'campaign.id' => 'campaignId',
        ];
        $applied = [
            'metrics.video_views' => 'metrics.video_trueview_views',
            'campaign.start_date' => 'campaign.start_date_time',
            'campaign.end_date' => 'campaign.end_date_time',
        ];
        self::assertSame([
            'metrics.videoTrueviewViews' => 'metricsVideoViews',
            'campaign.startDateTime' => 'campaignStartDate',
            'campaign.id' => 'campaignId',
        ], DeprecatedFieldRewriter::applyColumnOverrides($listColumns, $applied));
    }

    public function testTruncateDateColumnsOnlyTouchesDateColumns(): void
    {
        $applied = [
            'campaign.start_date' => 'campaign.start_date_time',
            'metrics.video_views' => 'metrics.video_trueview_views',
        ];
        $row = [
            'campaignStartDate' => '2024-05-06 12:34:56',
            'metricsVideoViews' => '123',
            'campaignId' => '42',
        ];
        self::assertSame([
            'campaignStartDate' => '2024-05-06',
            'metricsVideoViews' => '123',
            'campaignId' => '42',
        ], DeprecatedFieldRewriter::truncateDateColumns($row, $applied));
    }

    public function testTruncateDateColumnsLeavesNullAndMissingUntouched(): void
    {
        $applied = ['campaign.start_date' => 'campaign.start_date_time'];
        $row = ['campaignStartDate' => null, 'campaignId' => '42'];
        self::assertSame(
            ['campaignStartDate' => null, 'campaignId' => '42'],
            DeprecatedFieldRewriter::truncateDateColumns($row, $applied),
        );
    }
}
