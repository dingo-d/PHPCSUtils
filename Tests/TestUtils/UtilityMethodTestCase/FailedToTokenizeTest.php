<?php
/**
 * PHPCSUtils, utility functions and classes for PHP_CodeSniffer sniff developers.
 *
 * @package   PHPCSUtils
 * @copyright 2019-2021 PHPCSUtils Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCSStandards/PHPCSUtils
 */

namespace PHPCSUtils\Tests\TestUtils\UtilityMethodTestCase;

use PHPCSUtils\TestUtils\UtilityMethodTestCase;

/**
 * Tests for the \PHPCSUtils\TestUtils\UtilityMethodTestCase class.
 *
 * @covers \PHPCSUtils\TestUtils\UtilityMethodTestCase::setUpTestFile
 *
 * @group testutils
 *
 * @since 1.0.0
 */
class FailedToTokenizeTest extends UtilityMethodTestCase
{

    /**
     * Overload the "normal" set up.
     *
     * @beforeClass
     *
     * @return void
     */
    public static function setUpTestFile()
    {
        // Deliberately left empty.
    }

    /**
     * Test that the setUpTestFile() fails a test when the tokenizer errored out.
     *
     * @return void
     */
    public function testMissingCaseFile()
    {
        $msg       = 'Tokenizing of the test case file failed for case file: ';
        $exception = 'PHPUnit\Framework\AssertionFailedError';
        if (\class_exists('PHPUnit_Framework_AssertionFailedError')) {
            // PHPUnit < 6.
            $exception = 'PHPUnit_Framework_AssertionFailedError';
        }

        if (\method_exists($this, 'expectException')) {
            // PHPUnit 5+.
            $this->expectException($exception);
            $this->expectExceptionMessage($msg);
        } else {
            // PHPUnit 4.
            $this->setExpectedException($exception, $msg);
        }

        parent::setUpTestFile();
    }
}
