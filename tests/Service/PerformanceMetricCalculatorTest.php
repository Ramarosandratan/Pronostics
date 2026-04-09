<?php

namespace App\Tests\Service;

use App\Service\PerformanceMetricCalculator;
use PHPUnit\Framework\TestCase;

class PerformanceMetricCalculatorTest extends TestCase
{
    public function testCalculateComputesTopMetricsAndRankError(): void
    {
        $calculator = new PerformanceMetricCalculator();

        $predictions = [
            ['participation_id' => 101, 'rank' => 1],
            ['participation_id' => 102, 'rank' => 2],
            ['participation_id' => 103, 'rank' => 3],
            ['participation_id' => 104, 'rank' => 4],
        ];

        $actual = [
            101 => 2,
            102 => 1,
            103 => 3,
            104 => 4,
        ];

        $metrics = $calculator->calculate($predictions, $actual);

        self::assertSame(0.0, $metrics['top1_accuracy']);
        self::assertSame(1.0, $metrics['top3_hit_rate']);
        self::assertSame(4, $metrics['comparable_entries']);
        self::assertEqualsWithDelta(0.5, $metrics['mean_rank_error'], 0.001);
        self::assertGreaterThan(0.0, $metrics['ndcg_at_5']);
        self::assertLessThanOrEqual(1.0, $metrics['ndcg_at_5']);
    }

    public function testCalculateReturnsZeroesWhenNoComparableData(): void
    {
        $calculator = new PerformanceMetricCalculator();

        $predictions = [
            ['participation_id' => 1, 'rank' => 1],
            ['participation_id' => 2, 'rank' => 2],
        ];

        $metrics = $calculator->calculate($predictions, []);

        self::assertSame(0.0, $metrics['top1_accuracy']);
        self::assertSame(0.0, $metrics['top3_hit_rate']);
        self::assertSame(0, $metrics['comparable_entries']);
        self::assertSame(0.0, $metrics['mean_rank_error']);
        self::assertSame(0.0, $metrics['ndcg_at_5']);
    }
}
