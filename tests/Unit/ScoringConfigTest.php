<?php

use PHPUnit\Framework\TestCase;
use App\Support\ScoringConfig;

/**
 * @group fast
 */
class ScoringConfigTest extends TestCase
{
    public function testStarRatingBoundaries(): void
    {
        $this->assertSame(3, ScoringConfig::getStarRating(ScoringConfig::STAR_3_THRESHOLD));
        $this->assertSame(2, ScoringConfig::getStarRating(ScoringConfig::STAR_2_THRESHOLD));
        $this->assertSame(1, ScoringConfig::getStarRating(10));
    }

    public function testUsageBonusCapped(): void
    {
        $maxBonus = ScoringConfig::USAGE_BONUS_MAX;
        $this->assertSame($maxBonus, ScoringConfig::calculateUsageBonus(1000));
        $this->assertLessThan($maxBonus, ScoringConfig::calculateUsageBonus(1));
    }
}
