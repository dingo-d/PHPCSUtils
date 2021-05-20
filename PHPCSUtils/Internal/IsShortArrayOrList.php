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
use PHPCSUtils\Internal\IsShortArrayOrListWithCache;
use PHPCSUtils\Tokens\Collections;
use PHPCSUtils\Utils\Arrays;
use PHPCSUtils\Utils\Context;
use PHPCSUtils\Utils\FunctionDeclarations;
use PHPCSUtils\Utils\Lists;
use PHPCSUtils\Utils\PassedParameters;

/**
 * Determination of short array vs short list vs square brackets.
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
	
    /**
     * Type annotation for short arrays.
     *
     * @since 1.0.0
     *
     * @var string
     */
	const SHORT_ARRAY = 'short array';

    /**
     * Type annotation for short lists.
     *
     * @since 1.0.0
     *
     * @var string
     */
	const SHORT_LIST = 'short list';

    /**
     * Type annotation for square brackets.
     *
     * @since 1.0.0
     *
     * @var string
     */
	const SQUARE_BRACKETS = 'square brackets';

	/**
	 * Limit for retrieving the items within an array/list.
     *
     * @since 1.0.0
	 *
	 * @var int
	 */
	const ITEM_LIMIT = 5;

	/**
	 * The PHPCS file in which the current stackPtr was found.
     *
     * @since 1.0.0
	 *
	 * @var \PHP_CodeSniffer\Files\File
	 */
	private $phpcsFile;

	/**
	 * The current stackPtr.
     *
     * @since 1.0.0
	 *
	 * @var int
	 */
	private $stackPtr;

	/**
	 * The currently available cache for the current file.
     *
     * @since 1.0.0
	 *
	 * @var int
	 */
	private $cache;

	/**
	 * The token stack from the current file.
     *
     * @since 1.0.0
	 *
	 * @var array
	 */
	private $tokens;

	/**
	 * The current open bracket.
     *
     * @since 1.0.0
	 *
	 * @var int
	 */
	private $opener;

	/**
	 * The current close bracket.
     *
     * @since 1.0.0
	 *
	 * @var int
	 */
	private $closer;

	/**
	 * Constructor.
     *
     * @since 1.0.0
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int						  $stackPtr  The position of the short array bracket token.
	 * @param array                       $cache     The currently available cache for the current file.
	 *
	 * @return void
	 */
	public function __construct(File $phpcsFile, $stackPtr, $cache)
	{
		$tokens = $phpcsFile->getTokens();
		if (isset($tokens[$stackPtr]) === false
			|| isset(Collections::$shortArrayTokensBC[$tokens[$stackPtr]['code']]) === false
		) {
			throw new RuntimeException('Non-array bracket stackpointer passed');
		}

		$this->phpcsFile = $phpcsFile;
		$this->stackPtr  = $stackPtr;
		$this->cache     = $cache;
		$this->tokens	 = $tokens;

		$this->opener = $stackPtr;
		if (isset($this->tokens[$stackPtr]['bracket_opener'])
			&& $stackPtr !== $this->tokens[$stackPtr]['bracket_opener']
		) {
			$this->opener = $this->tokens[$stackPtr]['bracket_opener'];
		}

		$this->closer = $stackPtr;
		if (isset($this->tokens[$stackPtr]['bracket_closer'])
			&& $stackPtr !== $this->tokens[$stackPtr]['bracket_closer']
		) {
			$this->closer = $this->tokens[$stackPtr]['bracket_closer'];
		}
	}

	/**
	 *
	 * @since 1.0.0
	 *
	 * @return string Either 'short array', 'short list' or 'square brackets'.
	 */
	public function solve()
	{
		if ($this->opener === $this->closer) {
			// Parse error (unclosed bracket) or live coding. Bow out.
			return self::SQUARE_BRACKETS;
		}

		// Check if this is a bracket we need to examine or a mistokenization.
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
/*
Probably not needed.
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
*/

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
// Do we need this extra check ? And is it correct ?
// `as $key` can never be an array as the original array can never have a key in array format.
// So the only one we'd want to exclude would be nested ones which could still be both
					if ($this->tokens[$nextAfterCloser]['code'] === \T_CLOSE_PARENTHESIS) {
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

		if ($this->tokens[$nextAfterCloser]['code'] === \T_CLOSE_SHORT_ARRAY
			|| $this->tokens[$nextAfterCloser]['code'] === \T_CLOSE_SQUARE_BRACKET
		) {
			return IsShortArrayOrListWithCache::getType($this->phpcsFile, $nextAfterCloser);
		}

		if ($this->tokens[$prevBeforeOpener]['code'] === \T_OPEN_SHORT_ARRAY
			|| $this->tokens[$prevBeforeOpener]['code'] === \T_OPEN_SQUARE_BRACKET
		) {
			if ($this->tokens[$nextAfterCloser]['code'] === \T_DOUBLE_ARROW) {
				// Array key within short list is the only option here.
				return self::SHORT_ARRAY;
            }

			return IsShortArrayOrListWithCache::getType($this->phpcsFile, $nextAfterCloser);
		}

		/*
		 * If the array closer is not followed by an equals sign, list closing bracket or a comma
		 * and is not in a foreach condition, we know for sure it is a short array and not a short list.
		 * The comma is the most problematic one as that can mean a nested short array or nested short list.
		 */
// TODO: This needs revisiting for match expressions. Handle this later, separately from this refactor.
		if ($this->tokens[$nextAfterCloser]['code'] !== \T_COMMA) {
			// Definitely short array.
			return self::SHORT_ARRAY;
		}


		// Okay, so as of here, we know this set of brackets is followed by a comma.
		
		/*
		 * If there is anything, but a comma or double arrow (or bracket opener, but that is handled above)
		 * before this set of brackets, it can only ever be a short array.
		 */
		if ($this->tokens[$prevBeforeOpener]['code'] !== \T_COMMA
			&& $this->tokens[$prevBeforeOpener]['code'] !== \T_DOUBLE_ARROW
		) {
			return self::SHORT_ARRAY;
		}

		/*
		 * Check if this could be a (nested) short list at all.
		 * A list must have at least one variable inside and can not be empty.
		 * An array, however, cannot contain empty items, so let's have a closer look.
		 */
		$type = $this->walkInside();
		if ($type !== false) {
			return $type;
		}
/*
		// In all other circumstances, make sure this isn't a (nested) short list instead of a short array.
		if (Lists::isShortList($this->phpcsFile, $stackPtr) === false) {
			$lastSeenList = $setLastSeenList($lastSeenList, $phpcsFile->getFilename(), $opener, $closer);
			return true;
		}

		return false;
*/

  		return '';
	}


	/**
	 * Verify that the current open bracket is not affected by known PHPCS cross-version tokenizer issues.
	 *
	 * @since 1.0.0
	 *
	 * @return TRUE if this is actually a short array bracket which needs to be examined,
	 *         FALSE if it is an (incorrectly tokenized) square bracket.
	 */
	protected function isShortArrayBracket()
	{
		$phpcsVersion = Helper::getVersion();
		$prevNonEmpty = $this->phpcsFile->findPrevious(Tokens::$emptyTokens, ($this->opener - 1), null, true);

        /*
         * Deal with square brackets which may be incorrectly tokenized short arrays.
         */
		if ($this->tokens[$this->opener]['code'] === \T_OPEN_SQUARE_BRACKET) {
			if (\version_compare($phpcsVersion, '3.3.0', '>=') === true) {
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
			if (\version_compare($phpcsVersion, '2.8.0', '>=')
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
			if (\version_compare($phpcsVersion, '2.8.0', '<')
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
			if (\version_compare($phpcsVersion, '3.6.0', '<')
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
			if (\version_compare($phpcsVersion, '3.5.6', '<')
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
			if (\version_compare($phpcsVersion, '2.9.0', '<')
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
			if (\version_compare($phpcsVersion, '2.9.0', '<')
				&& \version_compare($phpcsVersion, '2.8.0', '>=')
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
     * @since 1.0.0
	 *
	 * @param int $prevBeforeOpener Stack pointer to first non-empty token before
	 *                              the opener of the current set of brackets.
	 * @param int $nextAfterCloser  Stack pointer to first non-empty token after
	 *                              the closer of the current set of brackets.
	 *
	 * @return string|false The determined type /*(which could be an empty string) ??? * /
	 *                      or FALSE if no cache entries were found for this token came from.
	 */
	protected function checkCacheForOuterBrackets($prevBeforeOpener, $nextAfterCloser)
	{
		if (empty($this->cache) === true) {
			return false;
		}
		
		// Reverse sort the cache as we want to find the deepest entry in which these brackets are nested.
		$seenBrackets = $this->cache;
		krsort($seenBrackets, SORT_NUMERIC);

		foreach ($seenBrackets as $opener => $bracketInfo) {
			if ($bracketInfo['opener'] < $this->opener && $bracketInfo['closer'] > $this->closer) {
				// Catch just typical array case as we're walking the seen brackets cache anyway.
				if ($bracketInfo['type'] === self::SHORT_ARRAY
					&& $bracketInfo['closer'] === $nextAfterCloser
				) {
					return self::SHORT_ARRAY;
				}
// TODO: doesn't the same as for lists also apply to short arrays ?
// i.e. if same condition + nesting + previous is comma or double arrow or opener and next is comma or closer -> always short array ?
// No, it does not, this gets into trouble when a nested short list is in a short array as they may look the same and the token of
// the short list may not have been passed.
/* TODO: verify these test cases are covered:
$array = [
    'key' => [$a, $b, [$c]] = $foo,
    'key' => [[$a], $b, $c] = $foo,
    'key' => [$a, [$b], $c] = $foo,
    'key' => [$a, 'key' => [$b], $c] = $foo,
];
*/
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


	/**
	 * Walk the first part of the contents between the brackets to see if we can determine if this is an array or short list.
	 *
	 * This won't walk the complete contents as that could be a huge performance drain. Just the first x items.
     *
     * @since 1.0.0
	 *
	 * @return string|false The determined type or FALSE if undetermined.
	 */
	protected function walkInside()
	{
		// Get the first 5 "parameters" and ignore the "is short array" check.
		$items = PassedParameters::getParameters($this->phpcsFile, $this->opener, self::ITEM_LIMIT, true);

		if ($items === []) {
		    // A list can not be empty, so this must be an array.
			return self::SHORT_ARRAY;
		}

		foreach ($items as $item) {
			/*
			 * If we encounter a completely empty item, this must be a short list as arrays cannot contain
			 * empty items.
			 */
			if ($item['raw'] === '') {
				return self::SHORT_LIST;
			}

			/*
			 * If the "value" part of the entry doesn't start with a variable or a (nested) short list/array,
			 * we know for sure that it will be an array.
			 */
			$arrow = Arrays::getDoubleArrowPtr($this->phpcsFile, $item['start'], $item['end']);
			if ($arrow === false) {
				$firstNonEmptyInValue = $this->phpcsFile->findNext(Tokens::$emptyTokens, $item['start'], ($item['end'] + 1), true);
			} else {
				$firstNonEmptyInValue = $this->phpcsFile->findNext(Tokens::$emptyTokens, ($arrow + 1), ($item['end'] + 1), true);
			}

			if ($this->tokens[$firstNonEmptyInValue]['code'] !== \T_VARIABLE
				&& isset(Collections::$shortArrayTokensBC[$this->tokens[$firstNonEmptyInValue]['code']]) === false
			) {
				return self::SHORT_ARRAY;
			}

			/*
			 * If the "value" part starts with an open bracket, but has other tokens after it, it will also
			 * always be an array.
			 */
			$lastNonEmptyInValue = $this->phpcsFile->findPrevious(Tokens::$emptyTokens, $item['end'], ($arrow + 1), true);
			if (isset(Collections::$shortArrayTokensBC[$this->tokens[$firstNonEmptyInValue]['code']]) === true
				&& isset($this->tokens[$firstNonEmptyInValue]['bracket_closer']) === true
				&& $this->tokens[$firstNonEmptyInValue]['bracket_closer'] !== $lastNonEmptyInValue
			) {
				return self::SHORT_ARRAY;
			}

			/*
			if ($arrow === false) {
				continue;
			}

			// Maybe examine key ?? if array it must be short list
			*/
		}

		// Undetermined.
		return false;
	}

/*
If close bracket is followed by a =>, it will always be a short list (providing it isn't a real square bracket $a['key'])
as arrays can't have array keys.
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
			// Maybe colon ? inline else ? probably also null coalesce -> check findStartOfStatement list
// HELL YES! Need to stop as soon as possible.
// Even more important now with match in the picture.
// Maybe also check $condition and $nested_parenthesis for being the same

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
		
		
/*
ORIGINAL CODE:
        $nextNonEmpty = $phpcsFile->findNext(Tokens::$emptyTokens, ($closer + 1), null, true);
        if ($nextNonEmpty !== false && $tokens[$nextNonEmpty]['code'] === \T_EQUAL) {
            return true;
        }

        // Check for short list in foreach, i.e. `foreach($array as [$a, $b])`.
        $prevNonEmpty = $phpcsFile->findPrevious(Tokens::$emptyTokens, ($opener - 1), null, true);
        if ($prevNonEmpty !== false
            && ($tokens[$prevNonEmpty]['code'] === \T_AS
                || $tokens[$prevNonEmpty]['code'] === \T_DOUBLE_ARROW)
            && Parentheses::lastOwnerIn($phpcsFile, $prevNonEmpty, \T_FOREACH) !== false
        ) {
            return true;
        }

        // Maybe this is a short list syntax nested inside another short list syntax ?
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
                return false;
            }
        } while (isset($tokens[$parentOpen]['bracket_closer']) === true
            && $tokens[$parentOpen]['bracket_closer'] < $opener
        );

        return self::isShortList($phpcsFile, $parentOpen);
*/
	}
}
