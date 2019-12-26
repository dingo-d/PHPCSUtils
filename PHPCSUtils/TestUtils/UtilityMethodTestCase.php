<?php
/**
 * PHPCSUtils, utility functions and classes for PHP_CodeSniffer sniff developers.
 *
 * @package   PHPCSUtils
 * @copyright 2019 PHPCSUtils Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCSStandards/PHPCSUtils
 */

namespace PHPCSUtils\TestUtils;

use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Exceptions\TokenizerException;
use PHPCSUtils\BackCompat\Helper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Base class for use when testing utility methods for PHP_CodeSniffer.
 *
 * This class is compatible with PHP_CodeSniffer 2.x as well as 3.x.
 *
 * This class is compatible with PHPUnit 4.5 - 8.x providing the PHPCSUtils autoload
 * file is included in the test bootstrap.
 *
 * To allow for testing of tab vs space content, the tabWidth is set to `4` by default.
 *
 * Typical usage:
 *
 * Test case file `path/to/ClassUnderTestUnitTest.inc`:
 * ```php
 * <?php
 *
 * /* testTestCaseDescription * /
 * const BAR = false;
 * ```
 *
 * Test file `path/to/ClassUnderTestUnitTest.php`:
 * ```php
 * <?php
 *
 * use PHPCSUtils\TestUtils\UtilityMethodTestCase;
 * use YourStandard\ClassUnderTest;
 *
 * class ClassUnderTestUnitTest extends UtilityMethodTestCase {
 *
 *     /**
 *      * Testing utility method MyMethod.
 *      *
 *      * @dataProvider dataMyMethod
 *      *
 *      * @covers \YourStandard\ClassUnderTest::MyMethod
 *      *
 *      * @param string $commentString The comment which prefaces the target token in the test file.
 *      * @param string $expected      The expected return value.
 *      *
 *      * @return void
 *      * /
 *    public function testMyMethod($commentString, $expected)
 *    {
 *        $stackPtr = $this->getTargetToken($commentString, [\T_TOKEN_CONSTANT, \T_ANOTHER_TOKEN]);
 *        $class    = new ClassUnderTest();
 *        $result   = $class->MyMethod(self::$phpcsFile, $stackPtr);
 *        // Or for static utility methods:
 *        $result   = ClassUnderTest::MyMethod(self::$phpcsFile, $stackPtr);
 *
 *        $this->assertSame($expected, $result);
 *    }
 *
 *    /**
 *     * Data Provider.
 *     *
 *     * @see testMyMethod() For the array format.
 *     *
 *     * @return array
 *     * /
 *    public function dataMyMethod()
 *    {
 *        return array(
 *            array('/* testTestCaseDescription * /', false),
 *        );
 *    }
 * }
 * ```
 *
 * Note:
 * - Remove the space between the comment closers `* /` for a working example.
 * - Each test case separator comment MUST start with `/* test`.
 *   This is to allow the `getTargetToken()` method to distinquish between the
 *   test separation comments and comments which may be part of the test case.
 * - The test case file and unit test file should be placed in the same directory.
 * - For working examples using this abstract class, have a look at the unit tests
 *   for the PHPCSUtils utility functions themselves.
 *
 * @since 1.0.0
 */
abstract class UtilityMethodTestCase extends TestCase
{

    /**
     * The file extension of the test case file (without leading dot).
     *
     * This allows concrete test classes to overrule the default `inc` with, for instance,
     * `js` or `css` when applicable.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected static $fileExtension = 'inc';

    /**
     * Full path to the test case file associated with the concrete test class.
     *
     * Optional. If left empty, the case file will be presumed to be in
     * the same directory and named the same as the test class, but with an
     * `inc` file extension.
     *
     * @var string
     */
    protected static $caseFile = '';

    /**
     * The {@see \PHP_CodeSniffer\Files\File} object containing the parsed contents of the test case file.
     *
     * @since 1.0.0
     *
     * @var \PHP_CodeSniffer\Files\File
     */
    protected static $phpcsFile;

    /**
     * Set the name of a sniff to pass to PHPCS to limit the run (and force it to record errors).
     *
     * Normally, this propery won't need to be overloaded, but for utility methods which record
     * violations and contain fixers, setting a dummy sniff name equal to the sniff name passed
     * in the error code for `addError()`/`addWarning()` during the test, will allow for testing
     * the recording of these violations, as well as testing the fixer.
     *
     * @since 1.0.0
     *
     * @var array
     */
    protected static $selectedSniff = ['Dummy.Dummy.Dummy'];

    /**
     * Initialize PHPCS & tokenize the test case file.
     *
     * The test case file for a unit test class has to be in the same directory
     * directory and use the same file name as the test class, using the .inc extension.
     *
     * @since 1.0.0
     *
     * @beforeClass
     *
     * @return void
     */
    public static function setUpTestFile()
    {
        parent::setUpBeforeClass();

        $caseFile = static::$caseFile;
        if (\is_string($caseFile) === false || $caseFile === '') {
            $testClass = \get_called_class();
            $testFile  = (new ReflectionClass($testClass))->getFileName();
            $caseFile  = \substr($testFile, 0, -3) . static::$fileExtension;
        }

        if (\is_readable($caseFile) === false) {
            self::fail("Test case file missing. Expected case file location: $caseFile");
        }

        $contents = \file_get_contents($caseFile);

        if (\version_compare(Helper::getVersion(), '2.99.99', '>')) {
            // PHPCS 3.x.
            $config = new \PHP_Codesniffer\Config();

            /*
             * We just need to provide a standard so PHPCS will tokenize the file.
             * The standard itself doesn't actually matter for testing utility methods,
             * so use the smallest one to get the fastest results.
             */
            $config->standards = ['PSR1'];

            /*
             * Limiting the run to just one sniff will make it, yet again, slightly faster.
             * Picked the simplest/fastest sniff available which is registered in PSR1.
             */
            $config->sniffs = static::$selectedSniff;

            // Disable caching.
            $config->cache = false;

            // Also set a tab-width to enable testing tab-replaced vs `orig_content`.
            $config->tabWidth = 4;

            $ruleset = new \PHP_CodeSniffer\Ruleset($config);

            // Make sure the file gets parsed correctly based on the file type.
            $contents = 'phpcs_input_file: ' . $caseFile . \PHP_EOL . $contents;

            self::$phpcsFile = new \PHP_CodeSniffer\Files\DummyFile($contents, $ruleset, $config);

            // Only tokenize the file, do not process it.
            try {
                self::$phpcsFile->parse();
            } catch (TokenizerException $e) {
                // PHPCS 3.5.0 and higher.
            } catch (RuntimeException $e) {
                // PHPCS 3.0.0 < 3.5.0.
            }
        } else {
            // PHPCS 2.x.
            $phpcs           = new \PHP_CodeSniffer(null, 4); // Tab width set to 4.
            self::$phpcsFile = new \PHP_CodeSniffer_File(
                $caseFile,
                [],
                [],
                $phpcs
            );

            /*
             * Using error silencing to drown out "undefined index" notices for tokenizer
             * issues in PHPCS 2.x which won't get fixed anymore anyway.
             */
            @self::$phpcsFile->start($contents);
        }

        // Fail the test if the case file failed to tokenize.
        if (self::$phpcsFile->numTokens === 0) {
            self::fail("Tokenizing of the test case file failed for case file: $caseFile");
        }
    }

    /**
     * Clean up after finished test.
     *
     * @since 1.0.0
     *
     * @afterClass
     *
     * @return void
     */
    public static function resetTestFile()
    {
        self::$phpcsFile = null;
    }

    /**
     * Get the token pointer for a target token based on a specific comment found on the line before.
     *
     * Note: the test delimiter comment MUST start with "/* test" to allow this function to
     * distinguish between comments used *in* a test and test delimiters.
     *
     * @since 1.0.0
     *
     * @param string           $commentString The delimiter comment to look for.
     * @param int|string|array $tokenType     The type of token(s) to look for.
     * @param string           $tokenContent  Optional. The token content for the target token.
     *
     * @return int
     */
    public function getTargetToken($commentString, $tokenType, $tokenContent = null)
    {
        $start   = (self::$phpcsFile->numTokens - 1);
        $comment = self::$phpcsFile->findPrevious(
            \T_COMMENT,
            $start,
            null,
            false,
            $commentString
        );

        $tokens = self::$phpcsFile->getTokens();
        $end    = ($start + 1);

        // Limit the token finding to between this and the next delimiter comment.
        for ($i = ($comment + 1); $i < $end; $i++) {
            if ($tokens[$i]['code'] !== \T_COMMENT) {
                continue;
            }

            if (\stripos($tokens[$i]['content'], '/* test') === 0) {
                $end = $i;
                break;
            }
        }

        $target = self::$phpcsFile->findNext(
            $tokenType,
            ($comment + 1),
            $end,
            false,
            $tokenContent
        );

        if ($target === false) {
            $msg = 'Failed to find test target token for comment string: ' . $commentString;
            if ($tokenContent !== null) {
                $msg .= ' With token content: ' . $tokenContent;
            }

            $this->fail($msg);
        }

        return $target;
    }

    /**
     * Helper method to tell PHPUnit to expect a PHPCS Exception in a PHPUnit cross-version
     * compatible manner.
     *
     * @param string $msg  The expected exception message.
     * @param string $type The exception type to expect. Either 'runtime' or 'tokenizer'.
     *                     Defaults to 'runtime'.
     *
     * @return void
     */
    public function expectPhpcsException($msg, $type = 'runtime')
    {
        $exception = 'PHP_CodeSniffer\Exceptions\RuntimeException';
        if ($type === 'tokenizer') {
            $exception = 'PHP_CodeSniffer\Exceptions\TokenizerException';
        }

        if (\method_exists($this, 'expectException')) {
            // PHPUnit 5+.
            $this->expectException($exception);
            $this->expectExceptionMessage($msg);
        } else {
            // PHPUnit 4.
            $this->setExpectedException($exception, $msg);
        }
    }
}
