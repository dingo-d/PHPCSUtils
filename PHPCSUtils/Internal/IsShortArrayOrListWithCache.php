<?php
/**
 * PHPCSUtils, utility functions and classes for PHP_CodeSniffer sniff developers.
 *
 * @package   PHPCSUtils
 * @copyright 2019-2020 PHPCSUtils Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCSStandards/PHPCSUtils
 */

namespace PHPCSUtils\Internal;

use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Files\File;
use PHPCSUtils\Internal\IsShortArrayOrList;
use PHPCSUtils\Tokens\Collections;

/**
 * Determination of short array vs short list vs square brackets.
 *
 * Uses caching of previous results to mitigate performance issues.
 *
 * This class is only intended for internal use by PHPCSUtils and is not part of the public API.
 * This also means that it has no promise of backward compatibility.
 *
 * End-user should use the {@see \PHPCSUtils\Utils\Arrays::isShortArray()}
 * or the {@see \PHPCSUtils\Utils\Lists::isShortList()} function instead.
 *
 * @internal
 *
 * @since 1.0.0
 */
final class IsShortArrayOrListWithCache
{

    /**
     * Instance of this class.
     *
     * @since 1.0.0
     *
     * @var \PHPCSUtils\Internal\IsShortArrayOrList
     */
    private static $instance;

    /**
     * Cache of previously seen tokens by file.
     *
     * The array format of the cache is:
     * ```
     * [
     *   'filename' => [
     *     $opener => [
     *       'opener' => $opener,
     *       'closer' => $closer,
     *       'type'   => 'short array'|'short list'|'square brackets'|'',
     *     ]
     *   ]
     * ]
     * ```
     *
     * @since 1.0.0
     *
     * @var array
     */
    private $seen;

    /**
     * Default values for a cache entry.
     *
     * @since 1.0.0
     *
     * @var array
     */
    private $cacheDefaults = [
        'opener' => null,
        'closer' => null,
        'type'   => '',
    ];

    /**
     * Determine whether a T_OPEN/CLOSE_SHORT_ARRAY token is a short array construct
     * and not a short list.
     *
     * This method also accepts `T_OPEN/CLOSE_SQUARE_BRACKET` tokens to allow it to be
     * PHPCS cross-version compatible as the short array tokenizing has been plagued by
     * a number of bugs over time.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the short array bracket token.
     *
     * @return bool `TRUE` if the token passed is the open/close bracket of a short array.
     *              `FALSE` if the token is a short list bracket, a plain square bracket
     *              or not one of the accepted tokens.
     */
    public static function isShortArray(File $phpcsFile, $stackPtr)
    {
        return (self::getType($phpcsFile, $stackPtr) === IsShortArrayOrList::SHORT_ARRAY);
    }

    /**
     * Determine whether a T_OPEN/CLOSE_SHORT_ARRAY token is a short list construct
     * in contrast to a short array.
     *
     * This method also accepts `T_OPEN/CLOSE_SQUARE_BRACKET` tokens to allow it to be
     * PHPCS cross-version compatible as the short array tokenizing has been plagued by
     * a number of bugs over time, which affects the short list determination.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the short array bracket token.
     *
     * @return bool `TRUE` if the token passed is the open/close bracket of a short list.
     *              `FALSE` if the token is a short array bracket or plain square bracket
     *              or not one of the accepted tokens.
     */
    public static function isShortList(File $phpcsFile, $stackPtr)
    {
        return (self::getType($phpcsFile, $stackPtr) === IsShortArrayOrList::SHORT_LIST);
    }

    /**
     * Determine whether a T_OPEN/CLOSE_SHORT_ARRAY token is a short array or short list construct.
     *
     * This method also accepts `T_OPEN/CLOSE_SQUARE_BRACKET` tokens to allow it to be
     * PHPCS cross-version compatible as the short array tokenizing has been plagued by
     * a number of bugs over time, which affects the short list determination.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the short array bracket token.
     *
     * @return string|false The type of construct this bracket was determined to be.
     *                      Either 'short array', 'short list' or 'square brackets'.
     *                      Or FALSE is this was not a bracket token.
     */
    public static function getType(File $phpcsFile, $stackPtr)
    {
        $instance = self::getInstance();
        return $instance->process($phpcsFile, $stackPtr);
    }

    /**
     * Get the singleton instance of this class.
     *
     * @since 1.0.0
     *
     * @codeCoverageIgnore
     *
     * @return self
     */
    private static function getInstance()
    {
        if ((self::$instance instanceof self) === false) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Determine whether a T_OPEN/CLOSE_SHORT_ARRAY token is a short array or short list construct
     * using previously cached results whenever possible.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the short array bracket token.
     *
     * @return string|false The type of construct this bracket was determined to be.
     *                      Either 'short array', 'short list' or 'square brackets'.
     *                      Or FALSE is this was not a bracket token.
     */
    private function process(File $phpcsFile, $stackPtr)
    {
        if ($this->isValidStackPtr($phpcsFile, $stackPtr) === false) {
            return false;
        }

        $tokens = $phpcsFile->getTokens();
        $opener = $stackPtr;
        if (isset($tokens[$stackPtr]['bracket_opener'])
            && $stackPtr !== $tokens[$stackPtr]['bracket_opener']
        ) {
            $opener = $tokens[$stackPtr]['bracket_opener'];
        }

        /*
         * Check the cache in case we've seen this token before.
         * The cache _may_ return an empty string.
         */
        $type = $this->getFromCache($phpcsFile, $opener);
        if ($type !== false) {
            return $type;
        }

        /*
         * If we've not seen the token before, try and solve it and cache the results.
         */
        $solver = new IsShortArrayOrList($phpcsFile, $opener, $this->getCacheForFile($phpcsFile));
        $type   = $solver->solve();

        $this->updateCache($phpcsFile, $opener, $type);

        return $type;
    }

    /**
     * Verify the passed token could potentially be a short array or short list token.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the short array bracket token.
     *
     * @return bool
     */
    private function isValidStackPtr(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        if (isset($tokens[$stackPtr]) === false
            || isset(Collections::$shortArrayTokensBC[$tokens[$stackPtr]['code']]) === false
        ) {
            return false;
        }

        return true;
    }

    /**
     * Retrieve the bracket "type" of a token from the cache.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the short array OPEN bracket token.
     *
     * @return string|false The previously determined type (which could be an empty string)
     *                      or FALSE if no cache entry was found for this token.
     */
    private function getFromCache(File $phpcsFile, $stackPtr)
    {
        $fileName = $phpcsFile->getFilename();
        if (isset($this->seen[$fileName][$stackPtr]) === true) {
            return $this->seen[$fileName][$stackPtr]['type'];
        }

        return false;
    }

    /**
     * Retrieve the cache for a particular file.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     *
     * @return array
     */
    private function getCacheForFile(File $phpcsFile)
    {
        $fileName = $phpcsFile->getFilename();
        if (isset($this->seen[$fileName]) === true) {
            return $this->seen[$fileName];
        }

        return [];
    }

    /**
     * Update the cache with information about a particular bracket token.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the short array OPEN bracket token.
     * @param string                      $type      Optional. The type this bracket has been determined to be.
     *                                               Either 'short array', 'short list' or 'square brackets'.
     *
     * @return void
     */
    private function updateCache($phpcsFile, $stackPtr, $type = '')
    {
        $entry           = $this->cacheDefaults;
        $entry['opener'] = $stackPtr;

        $tokens = $phpcsFile->getTokens();
        $closer = $stackPtr;
        if (isset($tokens[$stackPtr]['bracket_closer'])
            && $stackPtr !== $tokens[$stackPtr]['bracket_closer']
        ) {
            $closer = $tokens[$stackPtr]['bracket_closer'];
        }
        $entry['closer'] = $closer;

        if ($type === IsShortArrayOrList::SHORT_ARRAY
            || $type === IsShortArrayOrList::SHORT_LIST
            || $type === IsShortArrayOrList::SQUARE_BRACKETS
        ) {
            $entry['type'] = $type;
        }

        $fileName = $phpcsFile->getFilename();

        $this->seen[$fileName][$stackPtr] = $entry;
    }
}
