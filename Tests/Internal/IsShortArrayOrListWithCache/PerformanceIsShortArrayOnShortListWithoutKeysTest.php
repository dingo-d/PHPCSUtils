<?php
/**
 * PHPCSUtils, utility functions and classes for PHP_CodeSniffer sniff developers.
 *
 * @package   PHPCSUtils
 * @copyright 2019-2020 PHPCSUtils Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCSStandards/PHPCSUtils
 */

namespace PHPCSUtils\Tests\Internal\IsShortArrayOrListWithCache;

use PHPCSUtils\Tests\Internal\IsShortArrayOrListWithCache\AbstractPerformanceTestCase;

/**
 * Tests the performance of the "is short array/short list" determination to make sure it doesn't degrade.
 *
 * @covers \PHPCSUtils\Internal\IsShortArrayOrListWithCache
 *
 * @since 1.0.0
 */
class PerformanceIsShortArrayOnShortListWithoutKeysTest extends AbstractPerformanceTestCase
{

    /**
     * Name of the test case file to use.
     *
     * @var string
     */
    const TEST_FILE = 'PerformanceShortListWithoutKeysTest.inc';

    /**
     * Test the performance of the IsShortArrayOrListWithCache::isShortArray() function
     * without a cache in place.
     *
     * @small
     *
     * @return float
     */
    public function testWithoutInitialCache()
    {
        $start = \microtime(true);
        $this->examineAllBracketsAsArray(false);
        return (\microtime(true) - $start);
    }

    /**
     * Test the performance of the IsShortArrayOrListWithCache::isShortArray() function again
     * now the cache has been warmed up.
     *
     * @small
     * @depends testWithoutInitialCache
     *
     * @param float $time Time the first test run examining all lists took.
     *
     * @return void
     */
    public function testEffectOfCaching($time)
    {
        $start = \microtime(true);
        $this->examineAllBracketsAsArray(false);
        $cachedTime = (\microtime(true) - $start);

        $this->assertLessThan(
            $this->getCacheRuntimeLimit(),
            $cachedTime
        );

        if ($time > $this->getNocacheRuntimeSlow()) {
            /*
             * Verify that retrieving the results from cache is at least x times faster than the original run.
             */
            $this->assertGreaterThan(
                $this->getSpeedupFactor(),
                ($time / $cachedTime),
                'Short array determination was not significantly faster with a warmed up cache'
            );
        }
    }
}
