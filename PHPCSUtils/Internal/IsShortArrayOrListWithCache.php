<?php
/**
 * PHPCSUtils, utility functions and classes for PHP_CodeSniffer sniff developers.
 *
 * @package   PHPCSUtils
 * @copyright 2019-2020 PHPCSUtils Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link	  https://github.com/PHPCSStandards/PHPCSUtils
 */

namespace PHPCSUtils\Internal;

use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\BackCompat\Helper;
use PHPCSUtils\Internal\IsShortArrayOrList;
use PHPCSUtils\Tokens\Collections;
use PHPCSUtils\Utils\Context;
use PHPCSUtils\Utils\FunctionDeclarations;
use PHPCSUtils\Utils\Lists;

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
	
// TODO: I should probably also have a performance test with a huge array without keys....

	/**
	 * Instance of this class.
	 *
	 * @var PHPCSUtils\Internal\IsShortArrayOrList
	 */
	private static $instance;

	/**
	 * Cache of seen tokens by file.
	 *
	 * The array format of the cache is:
	 * ```
	 * [
	 *   'filename' => [
	 *     $opener => [
	 *       'opener' => $opener,
	 *       'closer' => $closer,
	 *       'type'   => 'short array'|'short list'|'square brackets',
	 *     ]
	 *   ]
	 * ]
	 * ```
	 *
	 * @var array
	 */
	private $seen;

	/**
	 * Default values for a cache entry.
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
		$instance = self::getInstance();
		return ($instance->process($phpcsFile, $stackPtr) === IsShortArrayOrList::SHORT_ARRAY);
	}

    /**
     * Determine whether a T_OPEN/CLOSE_SHORT_ARRAY token is a short list() construct.
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
		$instance = self::getInstance();
		return ($instance->process($phpcsFile, $stackPtr) === IsShortArrayOrList::SHORT_LIST);
	}

    /**
     * Determine whether a T_OPEN/CLOSE_SHORT_ARRAY token is a short list() construct.
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
	public static function getType(File $phpcsFile, $stackPtr)
	{
		$instance = self::getInstance();
		return $instance->process($phpcsFile, $stackPtr);
	}

	/**
	 * Get the singleton instance of this class.
	 *
	 * @return self
	 */
	protected static function getInstance()
	{
		if ((self::$instance instanceof self) === false) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	/**
	 *
	 * @since 1.0.0
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int						  $stackPtr  The position of the short array bracket token.
	 *
	 * @return string|false The type of construct this bracket was determined to be.
	 *                      Either 'short array', 'short list' or 'square brackets'.
	 *                      Or FALSE is this was not a bracket token.
	 */
	protected function process(File $phpcsFile, $stackPtr)
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
		$type = $this->checkCache($phpcsFile, $opener);
		if ($type !== false) {
			return $type;
		}

		$solver = new IsShortArrayOrList($phpcsFile, $opener, $this->getCacheForFile($phpcsFile));
		$type   = $solver->process();

		$this->updateCache($phpcsFile, $opener, $type);
		
		return $type;
	}

	/**
	 * Verify the passed token could potentially be a short array or short list token.
	 *
	 * @since 1.0.0
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int						  $stackPtr  The position of the short array bracket token.
	 *
	 * @return bool
	 */
	protected function isValidStackPtr(File $phpcsFile, $stackPtr)
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
	 * TODO
	 *
	 * @since 1.0.0
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int						  $stackPtr  The position of the short array OPEN bracket token.
	 *
	 * @return string|false The previously determined type (which could be an empty string)
	 *                      or FALSE if no cache entry was found for this token.
	 */
	protected function checkCache(File $phpcsFile, $stackPtr)
	{
		$fileName = $phpcsFile->getFilename();
		if (isset($this->seen[$fileName][$stackPtr]) === true) {
			return $this->seen[$fileName][$stackPtr]['type'];
		}
		
		return false;
	}

	/**
	 * TODO
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function getCacheForFile(File $phpcsFile)
	{
		$fileName = $phpcsFile->getFilename();
		if (isset($this->seen[$fileName]) === true) {
			return $this->seen[$fileName];
		}

		return [];
	}

	/**
	 * TODO
	 *
	 * @since 1.0.0
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int						  $stackPtr  The position of the short array OPEN bracket token.
	 * @param string                      $type      Optional. The type this bracket has been determined to be.
	 *                                               Either 'short array', 'short list' or 'square brackets'.
	 *
	 * @return void
	 */
	protected function updateCache($phpcsFile, $stackPtr, $type = '')
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
