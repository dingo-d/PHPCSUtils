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

use PHPCSUtils\Internal\IsShortArrayOrListWithCache;
use PHPCSUtils\TestUtils\UtilityMethodTestCase;

/**
 * Helpers to test the performance of the "is short array/short list" determination to make sure it doesn't degrade.
 *
 * @since 1.0.0
 */
abstract class AbstractPerformanceTestCase extends UtilityMethodTestCase
{
	
	/**
	 * When to consider a run without a warmed cache to be slow.
	 *
	 * @var float
	 */
	const NOCACHE_RUNTIME_SLOW = 0.05; // 50 microseconds.

	/**
	 * Runtime limit for the test runs using a warmed cache.
	 *
	 * @var float
	 */
	const CACHE_RUNTIME_LIMIT = 0.025; // 25 microseconds.

	/**
	 * How much faster a run with a warmed up cache is expected to be compared to
	 * a run without a warmed up cache.
	 *
	 * @var int
	 */
	const SPEEDUP_FACTOR = 20;

    /**
     * Full path to the test case file associated with this test class.
     *
     * @var string
     */
    protected static $caseFile = '';

    /**
     * Initialize PHPCS & tokenize the test case file.
     *
     * Overloaded to re-use the `$caseFile` from the BCFile test.
     *
     * @beforeClass
     *
     * @return void
     */
    public static function setUpTestFile()
    {
        self::$caseFile = __DIR__ . '/' . static::TEST_FILE;
        parent::setUpTestFile();
    }

	/**
	 * Retrieve the runtime limit for when to consider a run without a warmed cache as slow.
	 *
	 * @return float
	 */
	protected function getNocacheRuntimeSlow()
	{
//extension_loaded('xdebug')
		if (\function_exists('xdebug_code_coverage_started')
			&& xdebug_code_coverage_started() === true
		) {
			// Adjust the expected time to allow for slow down due to code coverage being on.
			return (self::NOCACHE_RUNTIME_SLOW * 10);
		}

		return self::NOCACHE_RUNTIME_SLOW;
	}

	/**
	 * Retrieve the runtime limit for runs with a warmed up cache.
	 *
	 * @return float
	 */
	protected function getCacheRuntimeLimit()
	{
		if (\function_exists('xdebug_code_coverage_started')
			&& xdebug_code_coverage_started() === true
		) {
			// Adjust the expected time to allow for slow down due to code coverage being on.
			return (self::CACHE_RUNTIME_LIMIT * 12);
		}

		return self::CACHE_RUNTIME_LIMIT;
	}

	/**
	 * Retrieve the expected speed up factor for comparing runs with and without warmed up cache.
	 *
	 * @return int
	 */
	protected function getSpeedupFactor()
	{
		if (\function_exists('xdebug_code_coverage_started')
			&& xdebug_code_coverage_started() === true
		) {
			// Adjust the expected time to allow for slow down due to code coverage being on.
			return (self::SPEEDUP_FACTOR -5);
		}

		return self::SPEEDUP_FACTOR;
	}

    /**
     * Verify that for all brackets it is correctly identified whether these are short arrays.
     *
     * @param bool $expect Expected result.
     *
     * @return void
     */
    protected function examineAllBracketsAsArray($expect)
    {
        $tokens  = self::$phpcsFile->getTokens();
        $i       = 0;
        $counter = 0;
        while (($i = self::$phpcsFile->findNext([\T_OPEN_SHORT_ARRAY, \T_OPEN_SQUARE_BRACKET], ($i + 1))) !== false) {
            $result = IsShortArrayOrListWithCache::isShortArray(self::$phpcsFile, $i);
            if ($result !== $expect) {
                // Only use an assertion when the result is unexpected to prevent assertion inflation.
                $this->assertSame(
                    $expect,
                    $result,
                    'Test failed for token ' . $i . ' of type ' . $tokens[$i]['type'] . ' on line ' . $tokens[$i]['line']
                );
            }

            ++$counter;
        }

        $this->assertGreaterThan(1000, $counter, 'Less than 1000 brackets found to test');
    }

    /**
     * Verify that for all brackets it is correctly identified whether these are short lists.
     *
     * @param bool $expect Expected result.
     *
     * @return void
     */
    protected function examineAllBracketsAsList($expect)
    {
        $tokens  = self::$phpcsFile->getTokens();
        $i       = 0;
        $counter = 0;
        while (($i = self::$phpcsFile->findNext([\T_OPEN_SHORT_ARRAY, \T_OPEN_SQUARE_BRACKET], ($i + 1))) !== false) {
            $result = IsShortArrayOrListWithCache::isShortList(self::$phpcsFile, $i);
            if ($result !== $expect) {
                // Only use an assertion when the result is unexpected to prevent assertion inflation.
                $this->assertSame(
                    $expect,
                    $result,
                    'Test failed for token ' . $i . ' of type ' . $tokens[$i]['type'] . ' on line ' . $tokens[$i]['line']
                );
            }

            ++$counter;
        }

        $this->assertGreaterThan(1000, $counter, 'Less than 1000 brackets found to test');
    }
}
