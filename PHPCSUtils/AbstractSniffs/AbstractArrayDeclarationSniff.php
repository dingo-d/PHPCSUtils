<?php
/**
 * PHPCSUtils, utility functions and classes for PHP_CodeSniffer sniff developers.
 *
 * @package   PHPCSUtils
 * @copyright 2019-2020 PHPCSUtils Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCSStandards/PHPCSUtils
 */

namespace PHPCSUtils\AbstractSniffs;

use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\BackCompat\BCTokens;
use PHPCSUtils\Utils\Arrays;
use PHPCSUtils\Utils\Numbers;
use PHPCSUtils\Utils\PassedParameters;
use PHPCSUtils\Utils\TextStrings;

/**
 * Abstract sniff to easily examine all parts of an array declaration.
 *
 * @since 1.0.0
 */
abstract class AbstractArrayDeclarationSniff implements Sniff
{

    /**
     * The stack pointer to the array keyword or the short array open token.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected $stackPtr;

    /**
     * The token stack for the current file being examined.
     *
     * @since 1.0.0
     *
     * @var array
     */
    protected $tokens;

    /**
     * The stack pointer to the array opener.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected $arrayOpener;

    /**
     * The stack pointer to the array closer.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected $arrayCloser;

    /**
     * A multi-dimentional array with information on each array item.
     *
     * The array index is 1-based and contains the following information on each array item:
     * - 'start' : The stack pointer to the first token in the array item.
     * - 'end'   : The stack pointer to the first token in the array item.
     * - 'raw'   : A string with the contents of all tokens between `start` and `end`.
     * - 'clean' : Same as `raw`, but all comment tokens have been stripped out.
     *
     * @since 1.0.0
     *
     * @var array
     */
    protected $arrayItems;

    /**
     * How many items are in the array.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected $itemCount = 0;

    /**
     * Whether or not the array is single line.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected $singleLine;

    /**
     * List of tokens which can safely be used with an eval() expression.
     *
     * @since 1.0.0
     *
     * @var array
     */
    private $acceptedTokens = [
        \T_NULL                     => \T_NULL,
        \T_TRUE                     => \T_TRUE,
        \T_FALSE                    => \T_FALSE,
        \T_LNUMBER                  => \T_LNUMBER,
        \T_DNUMBER                  => \T_DNUMBER,
        \T_CONSTANT_ENCAPSED_STRING => \T_CONSTANT_ENCAPSED_STRING,
        \T_STRING_CONCAT            => \T_STRING_CONCAT,
        \T_INLINE_THEN              => \T_INLINE_THEN,
        \T_INLINE_ELSE              => \T_INLINE_ELSE,
        \T_BOOLEAN_NOT              => \T_BOOLEAN_NOT,
    ];

    /**
     * Set up this class.
     *
     * @since 1.0.0
     *
     * @codeCoverageIgnore
     *
     * @return void
     */
    final public function __construct()
    {
        // Enhance the list of accepted tokens.
        $this->acceptedTokens += BCTokens::assignmentTokens();
        $this->acceptedTokens += BCTokens::comparisonTokens();
        $this->acceptedTokens += BCTokens::arithmeticTokens();
        $this->acceptedTokens += BCTokens::operators();
        $this->acceptedTokens += BCTokens::booleanOperators();
        $this->acceptedTokens += BCTokens::castTokens();
        $this->acceptedTokens += BCTokens::bracketTokens();
        $this->acceptedTokens += BCTokens::heredocTokens();
    }

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @since 1.0.0
     *
     * @codeCoverageIgnore
     *
     * @return array
     */
    public function register()
    {
        return [
            \T_ARRAY,
            \T_OPEN_SHORT_ARRAY,
            \T_OPEN_SQUARE_BRACKET,
        ];
    }

    /**
     * Processes this test when one of its tokens is encountered.
     *
     * This method fills the properties with relevant information for examining the array
     * and then passes off to the `processArray()` method.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The PHP_CodeSniffer file where the
     *                                               token was found.
     * @param int                         $stackPtr  The position in the PHP_CodeSniffer
     *                                               file's token stack where the token
     *                                               was found.
     *
     * @return void
     */
    final public function process(File $phpcsFile, $stackPtr)
    {
        try {
            $this->arrayItems = PassedParameters::getParameters($phpcsFile, $stackPtr);
        } catch (RuntimeException $e) {
            // Parse error, short list, real square open bracket or incorrectly tokenized short array token.
            return;
        }

        $this->stackPtr    = $stackPtr;
        $this->tokens      = $phpcsFile->getTokens();
        $openClose         = Arrays::getOpenClose($phpcsFile, $stackPtr, true);
        $this->arrayOpener = $openClose['opener'];
        $this->arrayCloser = $openClose['closer'];
        $this->itemCount   = \count($this->arrayItems);

        $this->singleLine = true;
        if ($this->tokens[$openClose['opener']]['line'] !== $this->tokens[$openClose['closer']]['line']) {
            $this->singleLine = false;
        }

        $this->processArray($phpcsFile);

        // Reset select properties between calls to this sniff to lower memory usage.
        unset($this->tokens, $this->arrayItems);
    }

    /**
     * Process every part of the array declaration.
     *
     * This contains the default logic for the sniff, but can be overloaded in a concrete child class
     * if needed.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The PHP_CodeSniffer file where the
     *                                               token was found.
     *
     * @return void
     */
    public function processArray(File $phpcsFile)
    {
        if ($this->processOpenClose($phpcsFile, $this->arrayOpener, $this->arrayCloser) === true) {
            return;
        }

        if ($this->itemCount === 0) {
            return;
        }

        foreach ($this->arrayItems as $itemNr => $arrayItem) {
            $arrowPtr = Arrays::getDoubleArrowPtr($phpcsFile, $arrayItem['start'], $arrayItem['end']);

            if ($arrowPtr !== false) {
                if ($this->processKey($phpcsFile, $arrayItem['start'], ($arrowPtr - 1), $itemNr) === true) {
                    return;
                }

                if ($this->processArrow($phpcsFile, $arrowPtr, $itemNr) === true) {
                    return;
                }

                if ($this->processValue($phpcsFile, ($arrowPtr + 1), $arrayItem['end'], $itemNr) === true) {
                    return;
                }
            } else {
                if ($this->processNoKey($phpcsFile, $arrayItem['start'], $itemNr) === true) {
                    return;
                }

                if ($this->processValue($phpcsFile, $arrayItem['start'], $arrayItem['end'], $itemNr) === true) {
                    return;
                }
            }

            $commaPtr = ($arrayItem['end'] + 1);
            if ($itemNr < $this->itemCount || $this->tokens[$commaPtr]['code'] === \T_COMMA) {
                if ($this->processComma($phpcsFile, $commaPtr, $itemNr) === true) {
                    return;
                }
            }
        }
    }

    /**
     * Process the array opener and closer.
     *
     * Optional method to be implemented in concrete child classes.
     *
     * @since 1.0.0
     *
     * @codeCoverageIgnore
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The PHP_CodeSniffer file where the
     *                                               token was found.
     * @param int                         $openPtr   The position of the array opener token in the token stack.
     * @param int                         $closePtr  The position of the array closer token in the token stack.
     *
     * @return true|void Returning `true` will short-circuit the sniff and stop processing.
     */
    public function processOpenClose(File $phpcsFile, $openPtr, $closePtr)
    {
    }

    /**
     * Process the tokens in an array key.
     *
     * Optional method to be implemented in concrete child classes.
     *
     * The $startPtr and $endPtr do not discount whitespace or comments, but are all inclusive to
     * allow examining all tokens in an array key.
     *
     * @since 1.0.0
     *
     * @codeCoverageIgnore
     *
     * @see \PHPCSUtils\AbstractSniffs\AbstractArrayDeclarationSniff::getActualArrayKey() Optional helper function.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The PHP_CodeSniffer file where the
     *                                               token was found.
     * @param int                         $startPtr  The stack pointer to the first token in the "key" part of
     *                                               an array item.
     * @param int                         $endPtr    The stack pointer to the last token in the "key" part of
     *                                               an array item.
     * @param int                         $itemNr    Which item in the array is being handled.
     *                                               1-based, i.e. the first item is item 1, the second 2 etc.
     *
     * @return true|void Returning `true` will short-circuit the array item loop and stop processing.
     */
    public function processKey(File $phpcsFile, $startPtr, $endPtr, $itemNr)
    {
    }

    /**
     * Process an array item without an array key.
     *
     * Optional method to be implemented in concrete child classes.
     *
     * @since 1.0.0
     *
     * @codeCoverageIgnore
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The PHP_CodeSniffer file where the
     *                                               token was found.
     * @param int                         $startPtr  The stack pointer to the first token in the array item,
     *                                               which in this case will be the first token of the array
     *                                               value part of the array item.
     * @param int                         $itemNr    Which item in the array is being handled.
     *                                               1-based, i.e. the first item is item 1, the second 2 etc.
     *
     * @return true|void Returning `true` will short-circuit the array item loop and stop processing.
     */
    public function processNoKey(File $phpcsFile, $startPtr, $itemNr)
    {
    }

    /**
     * Process the double arrow.
     *
     * Optional method to be implemented in concrete child classes.
     *
     * @since 1.0.0
     *
     * @codeCoverageIgnore
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The PHP_CodeSniffer file where the
     *                                               token was found.
     * @param int                         $arrowPtr  The stack pointer to the double arrow for the array item.
     * @param int                         $itemNr    Which item in the array is being handled.
     *                                               1-based, i.e. the first item is item 1, the second 2 etc.
     *
     * @return true|void Returning `true` will short-circuit the array item loop and stop processing.
     */
    public function processArrow(File $phpcsFile, $arrowPtr, $itemNr)
    {
    }

    /**
     * Process the tokens in an array value.
     *
     * Optional method to be implemented in concrete child classes.
     *
     * The $startPtr and $endPtr do not discount whitespace or comments, but are all inclusive to
     * allow examining all tokens in an array value.
     *
     * @since 1.0.0
     *
     * @codeCoverageIgnore
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The PHP_CodeSniffer file where the
     *                                               token was found.
     * @param int                         $startPtr  The stack pointer to the first token in the "value" part of
     *                                               an array item.
     * @param int                         $endPtr    The stack pointer to the last token in the "value" part of
     *                                               an array item.
     * @param int                         $itemNr    Which item in the array is being handled.
     *                                               1-based, i.e. the first item is item 1, the second 2 etc.
     *
     * @return true|void Returning `true` will short-circuit the array item loop and stop processing.
     */
    public function processValue(File $phpcsFile, $startPtr, $endPtr, $itemNr)
    {
    }

    /**
     * Process the comma after an array item.
     *
     * Optional method to be implemented in concrete child classes.
     *
     * @since 1.0.0
     *
     * @codeCoverageIgnore
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The PHP_CodeSniffer file where the
     *                                               token was found.
     * @param int                         $commaPtr  The stack pointer to the comma.
     * @param int                         $itemNr    Which item in the array is being handled.
     *                                               1-based, i.e. the first item is item 1, the second 2 etc.
     *
     * @return true|void Returning `true` will short-circuit the array item loop and stop processing.
     */
    public function processComma(File $phpcsFile, $commaPtr, $itemNr)
    {
    }

    /**
     * Determine what the actual array key would be.
     *
     * Optional helper function for processsing array keys in the processKey() function.
     *
     * @since 1.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The PHP_CodeSniffer file where the
     *                                               token was found.
     * @param int                         $startPtr  The stack pointer to the first token in the "key" part of
     *                                               an array item.
     * @param int                         $endPtr    The stack pointer to the last token in the "key" part of
     *                                               an array item.
     *
     * @return string|int|void The string or integer array key or void if the array key could not
     *                         reliably be determined.
     */
    public function getActualArrayKey(File $phpcsFile, $startPtr, $endPtr)
    {
        /*
         * Determine the value of the key.
         */
        $firstNonEmpty = $phpcsFile->findNext(Tokens::$emptyTokens, $startPtr, null, true);
        $lastNonEmpty  = $phpcsFile->findPrevious(Tokens::$emptyTokens, $endPtr, null, true);

        $content = '';

        for ($i = $firstNonEmpty; $i <= $lastNonEmpty; $i++) {
            if (isset(Tokens::$commentTokens[$this->tokens[$i]['code']]) === true) {
                continue;
            }

            if ($this->tokens[$i]['code'] === \T_WHITESPACE) {
                $content .= ' ';
                continue;
            }

            if (isset($this->acceptedTokens[$this->tokens[$i]['code']]) === false) {
                // This is not a key we can evaluate. Might be a variable or constant.
                return;
            }

            // Take PHP 7.4 numeric literal separators into account.
            if ($this->tokens[$i]['code'] === \T_LNUMBER || $this->tokens[$i]['code'] === \T_DNUMBER) {
                try {
                    $number   = Numbers::getCompleteNumber($phpcsFile, $i);
                    $content .= $number['content'];
                    $i        = $number['last_token'];
                } catch (RuntimeException $e) {
                    // This must be PHP 3.5.3 with the broken backfill. Let's presume it's a ordinary number.
                    // If it's not, the sniff will bow out on the following T_STRING anyway if the
                    // backfill was broken.
                    $content .= \str_replace('_', '', $this->tokens[$i]['content']);
                }
                continue;
            }

            // Account for heredoc with vars.
            if ($this->tokens[$i]['code'] === \T_START_HEREDOC) {
                $text = TextStrings::getCompleteTextString($phpcsFile, $i);

                // Check if there's a variable in the heredoc.
                if (\preg_match('`(?<![\\\\])\$`', $text) === 1) {
                    return;
                }

                for ($j = $i; $j <= $this->tokens[$i]['scope_closer']; $j++) {
                    $content .= $this->tokens[$j]['content'];
                }

                $i = $this->tokens[$i]['scope_closer'];
                continue;
            }

            $content .= $this->tokens[$i]['content'];
        }

        // The PHP_EOL is to prevent getting parse errors when the key is a heredoc/nowdoc.
        $key = eval('return ' . $content . ';' . \PHP_EOL);

        /*
         * Ok, so now we know the base value of the key, let's determine whether it is
         * an acceptable index key for an array and if not, what it would turn into.
         */

        switch (\gettype($key)) {
            case 'NULL':
                // An array key of `null` will become an empty string.
                return '';

            case 'boolean':
                return ($key === true) ? 1 : 0;

            case 'integer':
                return $key;

            case 'double':
                return (int) $key; // Will automatically cut off the decimal part.

            case 'string':
                if (Numbers::isDecimalInt($key) === true) {
                    return (int) $key;
                }

                return $key;

            default:
                /*
                 * Shouldn't be possible. Either way, if it's not one of the above types,
                 * this is not a key we can handle.
                 */
                return;
        }
    }
}
