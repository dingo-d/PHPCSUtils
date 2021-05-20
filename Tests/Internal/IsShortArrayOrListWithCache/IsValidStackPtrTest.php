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
 * Tests for the \PHPCSUtils\Utils\IsShortArrayOrListWithCache class.
 *
 * @covers \PHPCSUtils\Internal\IsShortArrayOrListWithCache::isValidStackPtr
 *
 * @group arrays
 * @group lists
 *
 * @since 1.0.0
 */
class IsValidStackPtrTest extends UtilityMethodTestCase
{

    /**
     * Test passing a non-existent token pointer.
     *
     * @return void
     */
    public function testNonExistentToken()
    {
        $this->assertFalse(IsShortArrayOrListWithCache::getType(self::$phpcsFile, 100000));
    }

    /**
     * Test that false is returned when a non-bracket token is passed.
     *
     * @dataProvider dataNotBracket
     *
     * @param string           $testMarker  The comment which prefaces the target token in the test file.
     * @param int|string|array $targetToken The token type(s) to look for.
     *
     * @return void
     */
    public function testNotBracket($testMarker, $targetToken)
    {
        $target = $this->getTargetToken($testMarker, $targetToken);
        $this->assertFalse(IsShortArrayOrListWithCache::getType(self::$phpcsFile, $target));
    }

    /**
     * Data provider.
     *
     * @see testNotBracket() For the array format.
     *
     * @return array
     */
    public function dataNotBracket()
    {
        return [
            'long-array' => [
                '/* testLongArray */',
                \T_ARRAY,
            ],
            'long-list' => [
                '/* testLongList */',
                \T_LIST,
            ],
        ];
    }
}
