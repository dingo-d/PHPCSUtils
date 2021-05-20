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

use PHPCSUtils\Internal\IsShortArrayOrList;
use PHPCSUtils\Internal\IsShortArrayOrListWithCache;
use PHPCSUtils\TestUtils\UtilityMethodTestCase;

/**
 * Tests for the \PHPCSUtils\Utils\IsShortArrayOrListWithCache class.
 *
 * @covers \PHPCSUtils\Internal\IsShortArrayOrListWithCache::process
 *
 * @group arrays
 * @group lists
 *
 * @since 1.0.0
 */
class ProcessTest extends UtilityMethodTestCase
{

    /**
     * Test the process method works for all supported token types which are allowed to be passed to it.
     *
     * @dataProvider dataSupportedBrackets
     *
     * @param string           $testMarker The comment which prefaces the target token in the test file.
     * @param int|string|array $targetType The token type(s) to look for.
     * @param string|false     $expected   The expected function return value.
     *
     * @return void
     */
    public function testSupportedBrackets($testMarker, $targetType, $expected)
    {
        $target = $this->getTargetToken($testMarker, $targetType);
        $this->assertSame($expected, IsShortArrayOrListWithCache::getType(self::$phpcsFile, $target));
    }

    /**
     * Data provider.
     *
     * @see testSupportedBrackets() For the array format.
     *
     * @return array
     */
    public function dataSupportedBrackets()
    {
        return [
            'short-array-open-bracket' => [
                '/* testShortArray */',
                \T_OPEN_SHORT_ARRAY,
                IsShortArrayOrList::SHORT_ARRAY,
            ],
            'short-array-close-bracket' => [
                '/* testShortArray */',
                \T_CLOSE_SHORT_ARRAY,
                IsShortArrayOrList::SHORT_ARRAY,
            ],
            'short-list-open-bracket' => [
                '/* testShortList */',
                \T_OPEN_SHORT_ARRAY,
                IsShortArrayOrList::SHORT_LIST,
            ],
            'short-list-close-bracket' => [
                '/* testShortList */',
                \T_CLOSE_SHORT_ARRAY,
                IsShortArrayOrList::SHORT_LIST,
            ],
            'square-brackets-open-bracket' => [
                '/* testSquareBrackets */',
                \T_OPEN_SQUARE_BRACKET,
                IsShortArrayOrList::SQUARE_BRACKETS,
            ],
            'square-brackets-closer-bracket' => [
                '/* testSquareBrackets */',
                \T_CLOSE_SQUARE_BRACKET,
                IsShortArrayOrList::SQUARE_BRACKETS,
            ],
        ];
    }
}
