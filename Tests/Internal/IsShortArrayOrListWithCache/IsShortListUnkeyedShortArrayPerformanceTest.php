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
 * @group arrays
 * @group lists
 *
 * @since 1.0.0
 */
class IsShortListUnkeyedShortArrayPerformanceTest extends AbstractPerformanceTestCase
{

    /**
     * Name of the test case file to use.
     *
     * @var string
     */
    const TEST_FILE = 'UnkeyedShortArrayPerformanceTest.inc';

    /**
     * Test the performance of the Lists::isShortList() function without caching.
     *
     * @small
     *
     * @return float
     */
    public function testIsShortList()
    {
        $start = \microtime(true);
        $this->examineAllBracketsAsList(false);
        return (\microtime(true) - $start);
    }

    /**
     * Test the performance of the Lists::isShortList() function again now the cache has been warmed up.
     *
     * @small
     * @depends testIsShortList
     *
     * @param float $time Time the first test run examining all arrays took.
     *
     * @return void
     */
    public function testEffectOfCaching($time)
    {
        $start = \microtime(true);
        $this->examineAllBracketsAsList(false);
        $cachedTime = (\microtime(true) - $start);

        if ($time > 0.05) { // 50 microseconds = 0.05 second.
            /*
             * Verify that retrieving the results from cache is at least 15 times faster than the original run,
             * providing the original run was slow.
             */
            $this->assertGreaterThan(
                15,
                ($time / $cachedTime),
                'Short list determination was not significantly faster with a warmed up cache'
            );
        }
    }
}
