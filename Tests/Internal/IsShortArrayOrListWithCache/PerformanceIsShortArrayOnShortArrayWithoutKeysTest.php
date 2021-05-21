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
class PerformanceIsShortArrayOnShortArrayWithoutKeysTest extends AbstractPerformanceTestCase
{

    /**
     * Name of the test case file to use.
     *
     * @var string
     */
    const TEST_FILE = 'PerformanceShortArrayWithoutKeysTest.inc';

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
        $this->examineAllBracketsAsArray(true);
        return (\microtime(true) - $start);
    }

    /**
     * Test the performance of the IsShortArrayOrListWithCache::isShortArray() function again
	 * now the cache has been warmed up.
     *
     * @small
     * @depends testWithoutInitialCache
     *
     * @param float $time Time the first test run examining all arrays took.
     *
     * @return void
     */
    public function testEffectOfCaching($time)
    {
        if ($time < 0.3) { // 300 microseconds.
            $this->markTestSkipped('Uncached run wasn\'t slow');
        }

        $start = \microtime(true);
        $this->examineAllBracketsAsArray(true);
        $cachedTime = (\microtime(true) - $start);

        $this->assertLessThan(0.3, $cachedTime);

        /*
         * Verify that retrieving the results from cache is at least 15 times faster than the original run.
         */
        $this->assertGreaterThan(
            15,
            ($time / $cachedTime),
            'Short array determination was not significantly faster with a warmed up cache'
        );
    }
}
