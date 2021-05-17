<?php
/**
 * PHPCSUtils, utility functions and classes for PHP_CodeSniffer sniff developers.
 *
 * @package   PHPCSUtils
 * @copyright 2019-2020 PHPCSUtils Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCSStandards/PHPCSUtils
 */

namespace PHPCSUtils\Tests\Utils\Lists\IsShortArrayOrListPerformance;

use PHPCSUtils\TestUtils\UtilityMethodTestCase;
use PHPCSUtils\Utils\Arrays;
use PHPCSUtils\Utils\Lists;

/**
 * Tests the performance of the "is short array/short list" determination to make sure it doesn't degrade.
 *
 * @group arrays
 * @group lists
 *
 * @since 1.0.0
 */
abstract class AbstractPerformanceTestCase extends UtilityMethodTestCase
{

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
            $this->assertSame(
                $expect,
                Arrays::isShortArray(self::$phpcsFile, $i),
                'Test failed for token ' . $i . ' of type ' . $tokens[$i]['type'] . ' on line ' . $tokens[$i]['line']
            );
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
            $this->assertSame(
                $expect,
                Lists::isShortList(self::$phpcsFile, $i),
                'Test failed for token ' . $i . ' of type ' . $tokens[$i]['type'] . ' on line ' . $tokens[$i]['line']
            );
            ++$counter;
        }

        $this->assertGreaterThan(1000, $counter, 'Less than 1000 brackets found to test');
    }
}
