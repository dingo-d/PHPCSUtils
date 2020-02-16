<?php
/**
 * PHPCSUtils, utility functions and classes for PHP_CodeSniffer sniff developers.
 *
 * @package   PHPCSUtils
 * @copyright 2019-2020 PHPCSUtils Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCSStandards/PHPCSUtils
 */

namespace PHPCSUtils\Tests\Utils\ControlStructures;

use PHPCSUtils\TestUtils\UtilityMethodTestCase;
use PHPCSUtils\Utils\ControlStructures;

/**
 * Tests for the \PHPCSUtils\Utils\ControlStructures::hasBody() method.
 *
 * @covers \PHPCSUtils\Utils\ControlStructures::hasBody
 *
 * @group controlstructures
 *
 * @since 1.0.0
 */
class HasBodyParseError2Test extends UtilityMethodTestCase
{

    /**
     * Test whether the function correctly identifies whether a control structure has a body
     * in the case of live coding.
     *
     * @return void
     */
    public function testHasBodyLiveCodingNonEmptyBody()
    {
        $stackPtr = $this->getTargetToken('/* testLiveCoding */', \T_ELSE);

        $result = ControlStructures::hasBody(self::$phpcsFile, $stackPtr);
        $this->assertTrue($result, 'Failed hasBody check with $allowEmpty = true');

        $result = ControlStructures::hasBody(self::$phpcsFile, $stackPtr, false);
        $this->assertTrue($result, 'Failed hasBody check with $allowEmpty = false');
    }
}
