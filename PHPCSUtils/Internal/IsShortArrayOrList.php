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
final class IsShortArrayOrList
{
	const SHORT_ARRAY     = 'short array';
	const SHORT_LIST      = 'short list';
	const SQUARE_BRACKETS = 'square brackets';

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
	 * PHPCS version used during the run.
	 *
	 * @var string
	 */
	private $phpcsVersion;

	/**
	 * TODO
	 *
	 * @var bool
	 */
	private $phpcsGte330;
	/**
	 * TODO
	 *
	 * @var bool
	 */
	private $phpcsGte280;
	/**
	 * TODO
	 *
	 * @var bool
	 */
	private $phpcsLt360;
	/**
	 * TODO
	 *
	 * @var bool
	 */
	private $phpcsLt356;
	/**
	 * TODO
	 *
	 * @var bool
	 */
	private $phpcsLt290;
	/**
	 * TODO
	 *
	 * @var bool
	 */
	private $phpcsLt280;

	/**
	 * The PHPCS file in which the current stackPtr was found.
	 *
	 * Temporary value. This property will be reset after each request to this class.
	 *
	 * @var \PHP_CodeSniffer\Files\File
	 */
	private $phpcsFile;

	/**
	 * The current stackPtr.
	 *
	 * Temporary value. This property will be reset after each request to this class.
	 *
	 * @var int
	 */
	private $stackPtr;

	/**
	 * The token stack from the current file.
	 *
	 * Temporary value. This property will be reset after each request to this class.
	 *
	 * @var array
	 */
	private $tokens;

	/**
	 * The current open bracket.
	 *
	 * Temporary value. This property will be reset after each request to this class.
	 *
	 * @var int
	 */
	private $opener;

	/**
	 * The current close bracket.
	 *
	 * Temporary value. This property will be reset after each request to this class.
	 *
	 * @var int
	 */
	private $closer;

	/**
	 * Operators which can be used with arrays (but not with lists).
	 *
	 * @var array
	 */
	private $arrayOperators = [
		T_PLUS             => T_PLUS,
		T_IS_EQUAL         => T_IS_EQUAL,
		T_IS_IDENTICAL     => T_IS_IDENTICAL,
		T_IS_NOT_EQUAL     => T_IS_NOT_EQUAL,
		T_IS_NOT_IDENTICAL => T_IS_NOT_IDENTICAL,
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
		return ($instance->process($phpcsFile, $stackPtr) === self::SHORT_ARRAY);
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
		return ($instance->process($phpcsFile, $stackPtr) === self::SHORT_LIST);
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
	 * Constructor.
	 *
	 * Sets up some basic properties to be available throughout the PHPCS run.
	 *
	 * @return void
	 */
	private function __construct()
	{
		$this->phpcsVersion = Helper::getVersion();
		$this->phpcsGte330	= \version_compare($this->phpcsVersion, '3.3.0', '>=');
		$this->phpcsGte280	= \version_compare($this->phpcsVersion, '2.8.0', '>=');
		$this->phpcsLt360	= \version_compare($this->phpcsVersion, '3.6.0', '<');
		$this->phpcsLt356	= \version_compare($this->phpcsVersion, '3.5.6', '<');
		$this->phpcsLt290	= \version_compare($this->phpcsVersion, '2.9.0', '<');
		$this->phpcsLt280	= \version_compare($this->phpcsVersion, '2.8.0', '<');
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

		// Set some properties for this solver run only.
		$this->setTempProps($phpcsFile, $stackPtr);

		if ($this->opener === $this->closer) {
			// Parse error (unclosed bracket) or live coding. Bow out.
			$this->resetTempProps();
			return self::SQUARE_BRACKETS;
		}

		/*
		 * Check the cache in case we've seen this token before.
		 * The cache _may_ return an empty string.
		 */
		$type = $this->checkCache();
		if ($type !== false) {
			$this->resetTempProps();
			return $type;
		}


		$type = $this->solve();

		$this->updateCache($type);
		
		// Reset the temporary properties.
		$this->resetTempProps();

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
	 * Set some properties for this solver run only.
	 *
	 * @since 1.0.0
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int						  $stackPtr  The position of the short array bracket token.
	 *
	 * @return void
	 */
	protected function setTempProps(File $phpcsFile, $stackPtr)
	{
		$this->phpcsFile = $phpcsFile;
		$this->stackPtr  = $stackPtr;
		$this->tokens	 = $phpcsFile->getTokens();

		$this->opener = $this->stackPtr;
		if (isset($this->tokens[$this->stackPtr]['bracket_opener'])
			&& $this->stackPtr !== $this->tokens[$this->stackPtr]['bracket_opener']
		) {
			$this->opener = $this->tokens[$this->stackPtr]['bracket_opener'];
		}

		$this->closer = $this->stackPtr;
		if (isset($this->tokens[$this->stackPtr]['bracket_closer'])
			&& $this->stackPtr !== $this->tokens[$this->stackPtr]['bracket_closer']
		) {
			$this->closer = $this->tokens[$this->stackPtr]['bracket_closer'];
		}
	}

	/**
	 * Reset the temporary properties which were set for this solver run only.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function resetTempProps()
	{
		unset($this->phpcsFile, $this->stackPtr, $this->tokens, $this->opener, $this->closer);
	}

	/**
	 * TODO
	 *
	 * @since 1.0.0
	 *
	 * @return string|false The previously determined type (which could be an empty string)
	 *                      or FALSE if no cache entry was found for this token.
	 */
	protected function checkCache()
	{
		$fileName = $this->phpcsFile->getFilename();
		if (isset($this->seen[$fileName][$this->opener]) === true) {
			return $this->seen[$fileName][$this->opener]['type'];
		}
		
		return false;
	}

	/**
	 * TODO
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Optional. The type this bracket has been determined to be.
	 *                     Either 'short_array' or 'short_list'.
	 *                     Defaults to an empty string which means "neither",
	 *                     i.e. plain square brackets.
	 *
	 * @return void
	 */
	protected function updateCache($type = '')
	{
		$entry           = $this->cacheDefaults;
		$entry['opener'] = $this->opener;
		$entry['closer'] = $this->closer;

		if ($type === self::SHORT_ARRAY
			|| $type === self::SHORT_LIST
			|| $type === self::SQUARE_BRACKETS
		) {
			$entry['type'] = $type;
		}

		$fileName = $this->phpcsFile->getFilename();

		$this->seen[$fileName][$this->opener] = $entry;
	}


	/**
	 *
	 * @return string Either 'short array', 'short list' or 'square brackets'.
	 */
	public function solve()
	{
		// Check if this is a bracket we need to examine.
		if ($this->isShortArrayBracket() === false) {
			return self::SQUARE_BRACKETS;
		}

		$prevBeforeOpener = $this->phpcsFile->findPrevious(Tokens::$emptyTokens, ($this->opener - 1), null, true);
		$nextAfterCloser  = $this->phpcsFile->findNext(Tokens::$emptyTokens, ($this->closer + 1), null, true);

		if ($nextAfterCloser === false) {
			// Live coding. Short array until told differently.
			return self::SHORT_ARRAY;
		}
		
		$type = $this->checkCacheForOuterBrackets($prevBeforeOpener, $nextAfterCloser);
		if (empty($type) === false) {
			return $type;
		}

		// If the array closer is followed by an equals sign, it's always a short list.
		if ($this->tokens[$nextAfterCloser]['code'] === \T_EQUAL) {
			return self::SHORT_LIST;
		}

		// If the array closer is followed by a real square bracket (dereferenced), it's always a short array.
		if ($this->tokens[$nextAfterCloser]['code'] === \T_OPEN_SQUARE_BRACKET) {
			return self::SHORT_ARRAY;
		}

		// If the array closer is followed by a semi-colon, it's always a short array.
		if ($this->tokens[$nextAfterCloser]['code'] === \T_SEMICOLON) {
			return self::SHORT_ARRAY;
		}

		// If the array closer is followed by an operator which can be used with arrays, it is always a short array.
		if (isset($this->arrayOperators[$this->tokens[$nextAfterCloser]['code']]) === true) {
			return self::SHORT_ARRAY;
		}

		// If the array opener is preceded by an equals sign, and we already know it isn't followed
		// by one (check above), it's always a short array.
		if ($this->tokens[$prevBeforeOpener]['code'] === \T_EQUAL) {
			return self::SHORT_ARRAY;
		}


		/* If the array closer is followed by a double arrow, it's always a short list.
// Needs tests for match expressions as I'm pretty sure it will bork on those.
		if ($tokens[$nextAfterCloser]['code'] === \T_DOUBLE_ARROW) {
			return self::SHORT_LIST;
		}
*/

		/*
		 * Check if this is a foreach expression.
		 *
		 * If the bracket is before the AS and it's the outer array, it will be a short array, i.e. `foreach([1, 2, 3] as $value])`.
		 * If it's after the AS, it will be a short list, i.e. `foreach($array as [$a, $b])`.
		 */
		$inForeach = Context::inForeachCondition($this->phpcsFile, $this->opener);
		if ($inForeach !== false) {
			switch ($inForeach) {
				case 'beforeAs':
					if ($this->tokens[$nextAfterCloser]['code'] === \T_AS) {
						return self::SHORT_ARRAY;
					}

					break;

				case 'afterAs':
					if ($this->tokens[$prevBeforeOpener]['code'] === \T_AS) {
						return self::SHORT_LIST;
					}

					break;
			}
/*
			// When in a foreach condition, there are only two options: array or list and we know which this is.
			if ($inForeach === 'beforeAs') {
				return true;
			}

			// This is an "outer" list, update the $lastSeenList, which is checked before this.
			$lastSeenList = $setLastSeenList($lastSeenList, $phpcsFile->getFilename(), $opener, $closer);
			return false;
*/
		}


	}


	/**
	 * TODO
	 *
	 * @since 1.0.0
	 *
	 * @return TRUE if this is actually a short array bracket which needs to be examined,
	 *         FALSE if it is an (incorrectly tokenized) square bracket.
	 */
	protected function isShortArrayBracket()
	{
		$prevNonEmpty = $this->phpcsFile->findPrevious(Tokens::$emptyTokens, ($this->opener - 1), null, true);

        /*
         * Deal with square brackets which may be incorrectly tokenized short arrays.
         */
		if ($this->tokens[$this->opener]['code'] === \T_OPEN_SQUARE_BRACKET) {
			if ($this->phpcsGte330 === true) {
				// These will just be properly tokenized, plain square brackets. No need for further checks.
				return false;
			}

			/*
			 * BC: Work around a bug in the tokenizer of PHPCS 2.8.0 - 3.2.3 where a `[` would be
			 * tokenized as T_OPEN_SQUARE_BRACKET instead of T_OPEN_SHORT_ARRAY if it was
			 * preceded by a PHP open tag at the very start of the file.
			 *
			 * @link https://github.com/squizlabs/PHP_CodeSniffer/issues/1971
			 */
// TODO: examine if this bug also exists with T_OPEN_TAG_WITH_ECHO (probably yes)
			if ($this->phpcsGte280 === true
			    && $prevNonEmpty === 0
				&& $this->tokens[$prevNonEmpty]['code'] === \T_OPEN_TAG
			) {
				return true;
			}

			/*
			 * BC: Work around a bug in the tokenizer of PHPCS < 2.8.0 where a `[` would be
			 * tokenized as T_OPEN_SQUARE_BRACKET instead of T_OPEN_SHORT_ARRAY if it was
			 * preceded by a close curly of a control structure.
			 *
			 * @link https://github.com/squizlabs/PHP_CodeSniffer/issues/1284
			 */
			if ($this->phpcsLt280 === true
				&& $this->tokens[$prevNonEmpty]['code'] === \T_CLOSE_CURLY_BRACKET
				&& isset($this->tokens[$prevNonEmpty]['scope_condition']) === true
			) {
				return true;
			}

			/*
			 * If we have square brackets which are neither of the above specific situations,
			 * they are just plain square brackets.
			 */
			return false;
		}

		/*
		 * Deal with short array brackets which may be incorrectly tokenized plain square brackets.
		 */
		if ($this->tokens[$this->opener]['code'] === \T_OPEN_SHORT_ARRAY) {

			/*
			 * BC: Work around a bug in the tokenizer of PHPCS < 3.6.0 where dereferencing
			 * of interpolated text string (PHP >= 8.0) would be incorrectly tokenized as short array.
			 *
			 * @link https://github.com/squizlabs/PHP_CodeSniffer/pull/3172
			 */
			if ($this->phpcsLt360 === true
				&& $this->tokens[$prevNonEmpty]['code'] === \T_DOUBLE_QUOTED_STRING
			) {
				return false;
			}

			/*
			 * BC: Work around a bug in the tokenizer of PHPCS < 3.5.6 where dereferencing
			 * of magic constants (PHP >= 8.0) would be incorrectly tokenized as short array.
			 * I.e. the square brackets in `__FILE__[0]` would be tokenized as short array.
			 *
			 * @link https://github.com/squizlabs/PHP_CodeSniffer/pull/3013
			 */
			if ($this->phpcsLt356 === true
				&& isset(Collections::$magicConstants[$this->tokens[$prevNonEmpty]['code']]) === true
			) {
				return false;
			}

			/*
			 * BC: Work around a bug in the tokenizer of PHPCS < 2.9.0 where array dereferencing
			 * of short array and string literals would be incorrectly tokenized as short array.
			 * I.e. the square brackets in `'PHP'[0]` would be tokenized as short array.
			 *
			 * @link https://github.com/squizlabs/PHP_CodeSniffer/issues/1381
			 */
			if ($this->phpcsLt290 === true
			    && ($this->tokens[$prevNonEmpty]['code'] === \T_CLOSE_SHORT_ARRAY
					|| $this->tokens[$prevNonEmpty]['code'] === \T_CONSTANT_ENCAPSED_STRING)
			) {
				return false;
			}

			/*
			 * BC: Work around a bug in the tokenizer of PHPCS 2.8.0 and 2.8.1 where array dereferencing
			 * of a variable variable would be incorrectly tokenized as short array.
			 *
			 * @link https://github.com/squizlabs/PHP_CodeSniffer/issues/1284
			 */
			if ($this->phpcsLt290 === true && $this->phpcsGte280 === true
				&& $this->tokens[$prevNonEmpty]['code'] === \T_CLOSE_CURLY_BRACKET
			) {
				$openCurly	   = $this->tokens[$prevNonEmpty]['bracket_opener'];
				$beforeCurlies = $this->phpcsFile->findPrevious(Tokens::$emptyTokens, ($openCurly - 1), null, true);
				if ($this->tokens[$beforeCurlies]['code'] === \T_DOLLAR) {
					return false;
				}
			}
			
			return true;
		}
		
		// Unreachable as the only tokens which will ever be passed to this function are the ones accounted for.
		return false;
	}


	/**
	 * Check previous cache entries to see if we can determine the type of the
	 * current set of brackets based on that.
	 *
	 * @param int $prevBeforeOpener Stack pointer to first non-empty token before
	 *                              the opener of the current set of brackets.
	 * @param int $nextAfterCloser  Stack pointer to first non-empty token after
	 *                              the closer of the current set of brackets.
	 *
	 * @return string|false The determined type /*(which could be an empty string) ??? * /
	 *                      or FALSE if no cache entries were found for the file this token came from.
	 */
	protected function checkCacheForOuterBrackets($prevBeforeOpener, $nextAfterCloser)
	{
		$fileName = $this->phpcsFile->getFilename();
		if (isset($this->seen[$fileName]) === false) {
			return false;
		}
		
		// Reverse the array as we want to find the deepest entry in which these brackets are nested.
		$seenBrackets = array_reverse($this->seen[$fileName]);

		foreach ($seenBrackets as $opener => $bracketInfo) {
			if ($bracketInfo['opener'] < $this->opener && $bracketInfo['closer'] > $this->closer) {
				// Catch just one typical case as we're walking the seen brackets cache anyway.
				if ($bracketInfo['type'] === self::SHORT_ARRAY
					&& $bracketInfo['closer'] === $nextAfterCloser
				) {
					return self::SHORT_ARRAY;
				}

				if ($bracketInfo['type'] !== self::SHORT_LIST) {
					// Only interested in short lists as short arrays can still contain anything.
					continue;
				}

				/*
				 * Okay, so we've found an "outer" short list.
				 * That means this will always be a short list too, UNLESS it is some convoluted
				 * list key setting via an array, so do some extra checking to prevent false positives.
				 */
				if ($this->tokens[$bracketInfo['opener']]['conditions'] === $this->tokens[$this->opener]['conditions']
					&& ((isset($this->tokens[$bracketInfo['opener']]['nested_parenthesis']) === false
						&& isset($this->tokens[$this->opener]['nested_parenthesis']) === false)
					|| (isset($this->tokens[$bracketInfo['opener']]['nested_parenthesis'], $this->tokens[$this->opener]['nested_parenthesis'] )=== true
						&& $this->tokens[$bracketInfo['opener']]['nested_parenthesis'] === $this->tokens[$this->opener]['nested_parenthesis']))
					&& ($prevBeforeOpener === $bracketInfo['opener']
						|| $this->tokens[$prevBeforeOpener]['code'] === \T_DOUBLE_ARROW
						|| $this->tokens[$prevBeforeOpener]['code'] === \T_COMMA)
					&& ($nextAfterCloser === $bracketInfo['closer']
						|| $this->tokens[$nextAfterCloser]['code'] === \T_COMMA)
				) {
					return self::SHORT_LIST;
				}

				// Short array within an outer short list. Most likely some convoluted key setting.
// TODO: NEEDS DOUBLE-CHECKING & THINKING THROUGH - IS THIS REALLY ALWAYS A SHORT ARRAY ?
				return self::SHORT_ARRAY;

				// Examined the deepest entry in which these brackets were nested. No need to look any further.
//				break;
			}
		}
		
		// Undetermined.
		return false;
	}



/*
If close bracket is followed by a =>, it will always be a short list (providing it isn't a real square bracket $a['key'])
as arrays can't have array keys.
*/

/*
If even one entry has a => followed by anything but a variable or open bracket, it will be a short array as lists can only have variables
or nested lists as the value.
*/

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
	 * @param int						  $stackPtr  The position of the short array bracket token.
	 *
	 * @return bool `TRUE` if the token passed is the open/close bracket of a short array.
	 *				`FALSE` if the token is a short list bracket, a plain square bracket
	 *				or not one of the accepted tokens.
	 */
	public static function OLDisShortArray(File $phpcsFile, $stackPtr)
	{


/*
TODO "cache" outer short list info from foreach + equal sign and check before doing anything else
as with these changes, we won't be passing "outer" lists to the isShortList anymore, so that will become less efficient.
*/
		$prevBeforeOpener = $phpcsFile->findPrevious(Tokens::$emptyTokens, ($opener - 1), null, true);
		$nextAfterCloser  = $phpcsFile->findNext(Tokens::$emptyTokens, ($closer + 1), null, true);

		if ($nextAfterCloser === false) {
			// Live coding. Undetermined. Array until told differently.
			return true;
		}

		/*
		 * Check if the cache to see if we already know this is a nested list and bow out if we can.
		 */
		if ($lastSeenList['file'] === $phpcsFile->getFilename()) {
			if ($lastSeenList['opener'] === $opener && $lastSeenList['closer'] === $closer) {
				// We've seen this list before.
				return false;
			}

			if ($lastSeenList['opener'] < $opener && $lastSeenList['closer'] > $closer) {
				// Now, we need to prevent false positives on brackets being used in list keys.
				if ($tokens[$lastSeenList['opener']]['conditions'] === $tokens[$opener]['conditions']
					&& ((isset($tokens[$lastSeenList['opener']]['nested_parenthesis']) === false
						&& isset($tokens[$opener]['nested_parenthesis']) === false)
					|| (isset($tokens[$lastSeenList['opener']]['nested_parenthesis'], $tokens[$opener]['nested_parenthesis'] )=== true
						&& $tokens[$lastSeenList['opener']]['nested_parenthesis'] === $tokens[$opener]['nested_parenthesis']))
					&& ($prevBeforeOpener === $lastSeenList['opener']
						|| $tokens[$prevBeforeOpener]['code'] === \T_DOUBLE_ARROW
						|| $tokens[$prevBeforeOpener]['code'] === \T_COMMA)
					&& ($nextAfterCloser === $lastSeenList['closer']
						|| $tokens[$nextAfterCloser]['code'] === \T_COMMA)
				) {
					// No need to update the last seen list as we know this is a nested list.
					return false;
				}

				// Short array within an outer short list. Most likely some convoluted key setting.
				return true;
			}
		}

		// If the array closer is followed by an equals sign, it's always a short list.
		if ($tokens[$nextAfterCloser]['code'] === \T_EQUAL) {
			// This is an "outer" list, update the $lastSeenList.
			$lastSeenList = $setLastSeenList($lastSeenList, $phpcsFile->getFilename(), $opener, $closer);
			return false;
		}

		// Check for short array in foreach, i.e. `foreach([1, 2, 3] as $value])`.
		$inForeach = Context::inForeachCondition($phpcsFile, $opener);
		if ($inForeach !== false) {
			// When in a foreach condition, there are only two options: array or list and we know which this is.
			if ($inForeach === 'beforeAs') {
				return true;
			}

			// This is an "outer" list, update the $lastSeenList, which is checked before this.
			$lastSeenList = $setLastSeenList($lastSeenList, $phpcsFile->getFilename(), $opener, $closer);
			return false;
		}

		/*
		 * If the array closer is not followed by an equals sign, list closing bracket or a comma
		 * and is not in a foreach condition, we know for sure it is a short array and not a short list.
		 * The comma is the most problematic one as that can mean a nested short array or nested short list.
		 */
		if ($tokens[$nextAfterCloser]['code'] === \T_CLOSE_SHORT_ARRAY
			|| $tokens[$nextAfterCloser]['code'] === \T_CLOSE_SQUARE_BRACKET
		) {
// Should this be short list or short array ?
			if (self::isShortArray($phpcsFile, $nextAfterCloser) === true) {
				// LastSeenList will have been updated in the function call in the condition.
				return true;
			}

			return false;
		}

		if ($tokens[$nextAfterCloser]['code'] !== \T_COMMA) {
			// Definitely short array.
			return true;
		}

		/*
		 * Check if this could be a (nested) short list at all.
		 * A list must have at least one variable inside and not be empty.
		 */
		$nonEmptyInside = $phpcsFile->findNext(Tokens::$emptyTokens, ($opener + 1), $closer, true);
		if ($nonEmptyInside === false) {
			// This is an empty array.
			return true;
		}
// This will be slow for large nested arrays.
		$varInside = $phpcsFile->findNext(\T_VARIABLE, $nonEmptyInside, $closer);
		if ($varInside === false) {
			// No variables, so definitely not a list.
			return true;
		}

		// In all other circumstances, make sure this isn't a (nested) short list instead of a short array.
		if (Lists::isShortList($phpcsFile, $stackPtr) === false) {
			$lastSeenList = $setLastSeenList($lastSeenList, $phpcsFile->getFilename(), $opener, $closer);
			return true;
		}

		return false;
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
	 * @param int						  $stackPtr  The position of the short array bracket token.
	 *
	 * @return bool `TRUE` if the token passed is the open/close bracket of a short list.
	 *				`FALSE` if the token is a short array bracket or plain square bracket
	 *				or not one of the accepted tokens.
	 */
	public static function OLDisShortList(File $phpcsFile, $stackPtr)
	{

		$phpcsVersion = Helper::getVersion();
/*
echo '=============================================',PHP_EOL;
echo 'Line: ', $tokens[$stackPtr]['line'], ' | Token type: ', $tokens[$stackPtr]['type'], PHP_EOL;
var_dump($lastSeenList);
*/
		/*
		 * BC: Work around a bug in the tokenizer of PHPCS 2.8.0 - 3.2.3 where a `[` would be
		 * tokenized as T_OPEN_SQUARE_BRACKET instead of T_OPEN_SHORT_ARRAY if it was
		 * preceded by a PHP open tag at the very start of the file.
		 *
		 * In that case, we also know for sure that it is a short list as long as the close
		 * bracket is followed by an `=` sign.
		 *
		 * @link https://github.com/squizlabs/PHP_CodeSniffer/issues/1971
		 *
		 * Also work around a bug in the tokenizer of PHPCS < 2.8.0 where a `[` would be
		 * tokenized as T_OPEN_SQUARE_BRACKET instead of T_OPEN_SHORT_ARRAY if it was
		 * preceded by a closing curly belonging to a control structure.
		 *
		 * @link https://github.com/squizlabs/PHP_CodeSniffer/issues/1284
		 */
		if ($tokens[$stackPtr]['code'] === \T_OPEN_SQUARE_BRACKET
			|| $tokens[$stackPtr]['code'] === \T_CLOSE_SQUARE_BRACKET
		) {

			$prevNonEmpty = $phpcsFile->findPrevious(Tokens::$emptyTokens, ($opener - 1), null, true);
			if ((($prevNonEmpty === 0 && $tokens[$prevNonEmpty]['code'] === \T_OPEN_TAG) // Bug #1971.
				|| ($tokens[$prevNonEmpty]['code'] === \T_CLOSE_CURLY_BRACKET
					&& isset($tokens[$prevNonEmpty]['scope_condition']))) // Bug #1284.
			) {

/// This part is not dealt with yet!
				$closer 	  = $tokens[$opener]['bracket_closer'];
				$nextNonEmpty = $phpcsFile->findNext(Tokens::$emptyTokens, ($closer + 1), null, true);
				if ($nextNonEmpty !== false && $tokens[$nextNonEmpty]['code'] === \T_EQUAL) {
					// This is an "outer" list, update the $lastSeenList.
					$lastSeenList = $setLastSeenList($lastSeenList, $phpcsFile->getFilename(), $opener, $closer);
//echo 'true: buggy one with assignment after', PHP_EOL;
					return true;
				}
/// end of.
			}
		
			return false;
		}
	


		// Check for short list assignment.
		$prevBeforeOpener = $phpcsFile->findPrevious(Tokens::$emptyTokens, ($opener - 1), null, true);
		$nextAfterCloser  = $phpcsFile->findNext(Tokens::$emptyTokens, ($closer + 1), null, true);

		if ($nextAfterCloser === false) {
			// Parse error or live coding.
//echo 'false: nothing after this', PHP_EOL;
			return false;
		}

		/*
		 * Check if we already know this is a nested list.
		 */
		if ($lastSeenList['file'] === $phpcsFile->getFilename()) {

			if ($lastSeenList['opener'] === $opener && $lastSeenList['closer'] === $closer) {
//echo 'true: outer list, seen before', PHP_EOL;
				return true;
			}

			if ($lastSeenList['opener'] < $opener && $lastSeenList['closer'] > $closer) {
				// Now, we need to prevent false positives on brackets being used in list keys.
				if ($tokens[$lastSeenList['opener']]['conditions'] === $tokens[$opener]['conditions']
				&& ((isset($tokens[$lastSeenList['opener']]['nested_parenthesis']) === false
						&& isset($tokens[$opener]['nested_parenthesis']) === false)
					|| (isset($tokens[$lastSeenList['opener']]['nested_parenthesis'], $tokens[$opener]['nested_parenthesis'] )=== true
					&& $tokens[$lastSeenList['opener']]['nested_parenthesis'] === $tokens[$opener]['nested_parenthesis']))
					&& ($prevBeforeOpener === $lastSeenList['opener']
						|| $tokens[$prevBeforeOpener]['code'] === \T_DOUBLE_ARROW
						|| $tokens[$prevBeforeOpener]['code'] === \T_COMMA)
					&& ($nextAfterCloser === $lastSeenList['closer']
						|| $tokens[$nextAfterCloser]['code'] === \T_COMMA)
				) {
					// No need to update the last seen list as we know this is a nested list.
//echo 'true: nested list', PHP_EOL;
					return true;
				}

				// Short array within an outer short list. Most likely some convoluted key setting.
//echo 'false: short array in nested list', PHP_EOL;
				return false;
			}
		}

		if ($tokens[$nextAfterCloser]['code'] === \T_EQUAL) {
			$lastSeenList = $setLastSeenList($lastSeenList, $phpcsFile->getFilename(), $opener, $closer);
//echo 'true: outer list assignment', PHP_EOL;
			return true;
		}

		// Check for short list in foreach, i.e. `foreach($array as [$a, $b])`.
		$inForeach = Context::inForeachCondition($phpcsFile, $opener);
		if ($inForeach !== false) {
			// When in a foreach condition, there are only two options: array or list and we know which this is.
			if ($inForeach === 'afterAs') {
				// This is an "outer" list, update the $lastSeenList, which is checked before this.
				$lastSeenList = $setLastSeenList($lastSeenList, $phpcsFile->getFilename(), $opener, $closer);
				return true;
			}

			return false;
		}



// BELOW WILL ONLY WORK WHEN ALL BRACKETS ARE PASSED AND WE CANT BE SURE THEY ARE
		/*
		 * This is not an outer list with assignment or in a foreach, nor a nested list in a known
		 * outer list, so we can be sure it's a short array.
		 */
/*
		if ($lastSeenList['file'] !== null) {
echo 'false: this is probably wrong', PHP_EOL;
			return false;
		}
*/

		/*
		 * Check if this could be a list at all. Must have at least one variable inside.
		 * This will also automatically discount empty lists, which are not allowed anyway.
		 */
		$varInside = $phpcsFile->findNext(\T_VARIABLE, ($opener + 1), $closer);
		if ($varInside === false) {
//echo 'false: no variable, so not a list', PHP_EOL;
			return false;
		}

		// Maybe this is a short list syntax nested inside another short list syntax ?
/*
		$parentOpen = $opener;
		do {
			$parentOpen = $phpcsFile->findPrevious(
				[\T_OPEN_SHORT_ARRAY, \T_OPEN_SQUARE_BRACKET], // BC: PHPCS#1971.
				($parentOpen - 1),
				null,
				false,
				null,
				true
			);

			if ($parentOpen === false) {
echo 'false: reached start of file', PHP_EOL;
				return false;
			}
		} while (isset($tokens[$parentOpen]['bracket_closer']) === true
			&& $tokens[$parentOpen]['bracket_closer'] < $opener
		);
*/

/*
$lastSeenList = [
	'filename' =>
	'opener'   =>
	'closer'   =>
];

// When finding prev -> skip over reference (bitwise and) ? <= Don't think this is necessary as ref can only be in front of variable in this case

Nice other check which can be done: if there are no variables inside, it will never be a short list

if (opener < currentOpen && closer > currentCloser && conditions === same && parentheses === same)
   XXX THIS IS WRONG ??? or maybe not - it only has to make sense for lists!!! not for arrays.
   VVV in which case, this may actually be correct.
	if (token before current open === double arrow || token before current open === comma || === opener) && (after current close === comma || === closer)
		return true
	else
		return false ???

TODO: Check - can a list contain an empty list ? If not, those will always be array (or rather square brackets)
plain empty -> invalid as of PHP 7, but if followed by = sign, recognize as short list
nested empty -> fatal error and as short lists is 7.1, we could go either way here.

CHECKED: mixing long/short list is not allowed, so if there is a condition list(), it will always be a short array

TESTING: run over PHPCompat lists tests to verify!

 */

/*
 TODO: maybe remember previous "false" and if opener < current opener + closer > current closer, limit token walking to
 within that.
 
 Also: if closer - current closer < current opener - opener, walk forward instead of backward
*/

		for ($i = ($opener - 1); $i >= 0; $i--) {
			// Skip over block comments (just in case).
			if ($tokens[$i]['code'] === \T_DOC_COMMENT_CLOSE_TAG) {
				$i = $tokens[$i]['comment_opener'];
				continue;
			}

			if (isset(Tokens::$emptyTokens[$tokens[$i]['code']]) === true) {
				continue;
			}

			// Stop on an end of statement.
			if ($tokens[$i]['code'] === \T_SEMICOLON) {
				// End of previous statement.
//echo 'false: reached end of previous statement', PHP_EOL;
				return false;
			}
			
			// Can we also stop on open curly with close curly after ?
			// And on open parenthesis with closer after ?
			// And on PHP open tags
			// Maybe colon ? inline else ?

			// Skip over all (close) braces
			if (isset($tokens[$i]['scope_opener']) === true
				&& $i === $tokens[$i]['scope_closer']
			) {
				if (isset($tokens[$i]['scope_owner']) === true) {
					$i = $tokens[$i]['scope_owner'];
					continue;
				}

				$i = $tokens[$i]['scope_opener'];
				continue;
			}

			if (isset($tokens[$i]['parenthesis_opener']) === true
				&& $i === $tokens[$i]['parenthesis_closer']
			) {
				$i = $tokens[$i]['parenthesis_opener'];
				continue;
			}

			// If this is a close bracket, it's not the outer wrapper, so we can ignore it completely.
			if (isset($tokens[$i]['bracket_opener']) === true
				&& $i === $tokens[$i]['bracket_closer']
			) {
				$i = $tokens[$i]['bracket_opener'];
				continue;
			}

			// Open brace
			if (($tokens[$i]['code'] === \T_OPEN_SQUARE_BRACKET
				|| $tokens[$i]['code'] === \T_OPEN_SHORT_ARRAY)
				&& isset($tokens[$i]['bracket_closer']) === true
				&& $tokens[$i]['bracket_closer'] > $closer
			) {
				// This is one we have to examine further as it could be the outer short list.
				break;
			}
		}

/*

			$found = (bool) $exclude;
			foreach ($types as $type) {
				if ($this->tokens[$i]['code'] === $type) {
					$found = !$exclude;
					break;
				}
			}

			if ($found === true) {
				if ($value === null) {
					return $i;
				} else if ($this->tokens[$i]['content'] === $value) {
					return $i;
				}
			}


/*
if isset scope_opener + scope closer && i === scope opener && scope closer > "list closer"
return false

if isset parenthesis_opener + parenthesis_closer && i === parenthesis_opener && parenthesis_closer > "list closer"
return false



			if ($local === true) {
				if (isset($this->tokens[$i]['scope_opener']) === true
					&& $i === $this->tokens[$i]['scope_closer']
				) {
					$i = $this->tokens[$i]['scope_opener'];
				} else if (isset($this->tokens[$i]['bracket_opener']) === true
					&& $i === $this->tokens[$i]['bracket_closer']
				) {
					$i = $this->tokens[$i]['bracket_opener'];
				} else if (isset($this->tokens[$i]['parenthesis_opener']) === true
					&& $i === $this->tokens[$i]['parenthesis_closer']
				) {
					$i = $this->tokens[$i]['parenthesis_opener'];
				} else if ($this->tokens[$i]['code'] === T_SEMICOLON) {
					break;
				}
			}
		}//end for
*/
//echo 'going back in for next loop round', PHP_EOL;
		return self::isShortList($phpcsFile, $i);
	}
}
