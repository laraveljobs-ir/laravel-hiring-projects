<?php

declare(strict_types=1);

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\Phpdoc;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\DocBlock\DocBlock;
use PhpCsFixer\DocBlock\TypeExpression;
use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Sebastiaan Stok <s.stok@rollerscapes.net>
 * @author Graham Campbell <hello@gjcampbell.co.uk>
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 * @author Jakub Kwaśniewski <jakub@zero-85.pl>
 */
final class PhpdocAlignFixer extends AbstractFixer implements ConfigurableFixerInterface, WhitespacesAwareFixerInterface
{
    /**
     * @internal
     */
    public const ALIGN_LEFT = 'left';

    /**
     * @internal
     */
    public const ALIGN_VERTICAL = 'vertical';

    private const DEFAULT_TAGS = [
        'method',
        'param',
        'property',
        'return',
        'throws',
        'type',
        'var',
    ];

    private const TAGS_WITH_NAME = [
        'param',
        'property',
        'property-read',
        'property-write',
        'phpstan-param',
        'phpstan-property',
        'phpstan-property-read',
        'phpstan-property-write',
        'phpstan-assert',
        'phpstan-assert-if-true',
        'phpstan-assert-if-false',
        'psalm-param',
        'psalm-param-out',
        'psalm-property',
        'psalm-property-read',
        'psalm-property-write',
        'psalm-assert',
        'psalm-assert-if-true',
        'psalm-assert-if-false',
    ];

    private const TAGS_WITH_METHOD_SIGNATURE = [
        'method',
        'phpstan-method',
        'psalm-method',
    ];

    private const DEFAULT_SPACING = 1;

    private const DEFAULT_SPACING_KEY = '_default';

    private string $regex;

    private string $regexCommentLine;

    private string $align;

    /**
     * same spacing for all or specific for different tags.
     *
     * @var int|int[]
     */
    private $spacing = 1;

    public function configure(array $configuration): void
    {
        parent::configure($configuration);

        $tagsWithNameToAlign = array_intersect($this->configuration['tags'], self::TAGS_WITH_NAME);
        $tagsWithMethodSignatureToAlign = array_intersect($this->configuration['tags'], self::TAGS_WITH_METHOD_SIGNATURE);
        $tagsWithoutNameToAlign = array_diff($this->configuration['tags'], $tagsWithNameToAlign, $tagsWithMethodSignatureToAlign);

        $indentRegex = '^(?P<indent>(?:\ {2}|\t)*)\ ?';

        $types = [];

        // e.g. @param <hint> <$var>
        if ([] !== $tagsWithNameToAlign) {
            $types[] = '(?P<tag>'.implode('|', $tagsWithNameToAlign).')\s+(?P<hint>(?:'.TypeExpression::REGEX_TYPES.')?)\s*(?P<var>(?:&|\.{3})?\$\S+)';
        }

        // e.g. @return <hint>
        if ([] !== $tagsWithoutNameToAlign) {
            $types[] = '(?P<tag2>'.implode('|', $tagsWithoutNameToAlign).')\s+(?P<hint2>(?:'.TypeExpression::REGEX_TYPES.')?)';
        }

        // e.g. @method <hint> <signature>
        if ([] !== $tagsWithMethodSignatureToAlign) {
            $types[] = '(?P<tag3>'.implode('|', $tagsWithMethodSignatureToAlign).')(\s+(?P<static>static))?(\s+(?P<hint3>(?:'.TypeExpression::REGEX_TYPES.')?))\s+(?P<signature>.+\))';
        }

        // optional <desc>
        $desc = '(?:\s+(?P<desc>\V*))';

        $this->regex = '/'.$indentRegex.'\*\h*@(?J)(?:'.implode('|', $types).')'.$desc.'\h*\r?$/';
        $this->regexCommentLine = '/'.$indentRegex.'\*(?!\h?+@)(?:\s+(?P<desc>\V+))(?<!\*\/)\r?$/';
        $this->align = $this->configuration['align'];
        $this->spacing = $this->configuration['spacing'];
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        $code = <<<'EOF'
            <?php
            /**
             * @param  EngineInterface $templating
             * @param string      $format
             * @param  int  $code       an HTTP response status code
             * @param    bool         $debug
             * @param  mixed    &$reference     a parameter passed by reference
             *
             * @return Foo description foo
             *
             * @throws Foo            description foo
             *             description foo
             *
             */

            EOF;

        return new FixerDefinition(
            'All items of the given PHPDoc tags must be either left-aligned or (by default) aligned vertically.',
            [
                new CodeSample($code),
                new CodeSample($code, ['align' => self::ALIGN_VERTICAL]),
                new CodeSample($code, ['align' => self::ALIGN_LEFT]),
                new CodeSample($code, ['align' => self::ALIGN_LEFT, 'spacing' => 2]),
                new CodeSample($code, ['align' => self::ALIGN_LEFT, 'spacing' => ['param' => 2]]),
            ],
        );
    }

    /**
     * {@inheritdoc}
     *
     * Must run after AlignMultilineCommentFixer, CommentToPhpdocFixer, GeneralPhpdocAnnotationRemoveFixer, GeneralPhpdocTagRenameFixer, NoBlankLinesAfterPhpdocFixer, NoEmptyPhpdocFixer, NoSuperfluousPhpdocTagsFixer, PhpdocAddMissingParamAnnotationFixer, PhpdocAnnotationWithoutDotFixer, PhpdocIndentFixer, PhpdocInlineTagNormalizerFixer, PhpdocLineSpanFixer, PhpdocNoAccessFixer, PhpdocNoAliasTagFixer, PhpdocNoEmptyReturnFixer, PhpdocNoPackageFixer, PhpdocNoUselessInheritdocFixer, PhpdocOrderByValueFixer, PhpdocOrderFixer, PhpdocParamOrderFixer, PhpdocReadonlyClassCommentToKeywordFixer, PhpdocReturnSelfReferenceFixer, PhpdocScalarFixer, PhpdocSeparationFixer, PhpdocSingleLineVarSpacingFixer, PhpdocSummaryFixer, PhpdocTagCasingFixer, PhpdocTagTypeFixer, PhpdocToCommentFixer, PhpdocToParamTypeFixer, PhpdocToPropertyTypeFixer, PhpdocToReturnTypeFixer, PhpdocTrimConsecutiveBlankLineSeparationFixer, PhpdocTrimFixer, PhpdocTypesFixer, PhpdocTypesOrderFixer, PhpdocVarAnnotationCorrectOrderFixer, PhpdocVarWithoutNameFixer.
     */
    public function getPriority(): int
    {
        /*
         * Should be run after all other docblock fixers. This because they
         * modify other annotations to change their type and or separation
         * which totally change the behavior of this fixer. It's important that
         * annotations are of the correct type, and are grouped correctly
         * before running this fixer.
         */
        return -42;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_DOC_COMMENT);
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!$token->isGivenKind(T_DOC_COMMENT)) {
                continue;
            }

            $content = $token->getContent();
            $docBlock = new DocBlock($content);
            $this->fixDocBlock($docBlock);
            $newContent = $docBlock->getContent();
            if ($newContent !== $content) {
                $tokens[$index] = new Token([T_DOC_COMMENT, $newContent]);
            }
        }
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        $allowPositiveIntegers = static function ($value) {
            $spacings = \is_array($value) ? $value : [$value];
            foreach ($spacings as $val) {
                if (\is_int($val) && $val <= 0) {
                    throw new InvalidOptionsException('The option "spacing" is invalid. All spacings must be greater than zero.');
                }
            }

            return true;
        };

        $tags = new FixerOptionBuilder(
            'tags',
            'The tags that should be aligned. Allowed values are tags with name (`\''.implode('\', \'', self::TAGS_WITH_NAME).'\'`), tags with method signature (`\''.implode('\', \'', self::TAGS_WITH_METHOD_SIGNATURE).'\'`) and any custom tag with description (e.g. `@tag <desc>`).'
        );
        $tags
            ->setAllowedTypes(['array'])
            ->setDefault(self::DEFAULT_TAGS)
        ;

        $align = new FixerOptionBuilder('align', 'How comments should be aligned.');
        $align
            ->setAllowedTypes(['string'])
            ->setAllowedValues([self::ALIGN_LEFT, self::ALIGN_VERTICAL])
            ->setDefault(self::ALIGN_VERTICAL)
        ;

        $spacing = new FixerOptionBuilder(
            'spacing',
            'Spacing between tag, hint, comment, signature, etc. You can set same spacing for all tags using a positive integer or different spacings for different tags using an associative array of positive integers `[\'tagA\' => spacingForA, \'tagB\' => spacingForB]`. If you want to define default spacing to more than 1 space use `_default` key in config array, e.g.: `[\'tagA\' => spacingForA, \'tagB\' => spacingForB, \'_default\' => spacingForAllOthers]`.'
        );
        $spacing->setAllowedTypes(['int', 'int[]'])
            ->setAllowedValues([$allowPositiveIntegers])
            ->setDefault(self::DEFAULT_SPACING)
        ;

        return new FixerConfigurationResolver([$tags->getOption(), $align->getOption(), $spacing->getOption()]);
    }

    private function fixDocBlock(DocBlock $docBlock): void
    {
        $lineEnding = $this->whitespacesConfig->getLineEnding();

        for ($i = 0, $l = \count($docBlock->getLines()); $i < $l; ++$i) {
            $matches = $this->getMatches($docBlock->getLine($i)->getContent());

            if (null === $matches) {
                continue;
            }

            $current = $i;
            $items = [$matches];

            while (true) {
                if (null === $docBlock->getLine(++$i)) {
                    break 2;
                }

                $matches = $this->getMatches($docBlock->getLine($i)->getContent(), true);
                if (null === $matches) {
                    break;
                }

                $items[] = $matches;
            }

            // compute the max length of the tag, hint and variables
            $hasStatic = false;
            $tagMax = 0;
            $hintMax = 0;
            $varMax = 0;

            foreach ($items as $item) {
                if (null === $item['tag']) {
                    continue;
                }

                $hasStatic |= '' !== $item['static'];
                $tagMax = max($tagMax, \strlen($item['tag']));
                $hintMax = max($hintMax, \strlen($item['hint']));
                $varMax = max($varMax, \strlen($item['var']));
            }

            $itemOpeningLine = null;

            $currTag = null;
            $spacingForTag = $this->spacingForTag($currTag);

            // update
            foreach ($items as $j => $item) {
                if (null === $item['tag']) {
                    if ('@' === $item['desc'][0]) {
                        $line = $item['indent'].' * '.$item['desc'];
                        $docBlock->getLine($current + $j)->setContent($line.$lineEnding);

                        continue;
                    }

                    $extraIndent = 2 * $spacingForTag;

                    if (\in_array($itemOpeningLine['tag'], self::TAGS_WITH_NAME, true) || \in_array($itemOpeningLine['tag'], self::TAGS_WITH_METHOD_SIGNATURE, true)) {
                        $extraIndent += $varMax + $spacingForTag;
                    }

                    if ($hasStatic) {
                        $extraIndent += 7; // \strlen('static ');
                    }

                    $line =
                        $item['indent']
                        .' * '
                        .('' !== $itemOpeningLine['hint'] ? ' ' : '')
                        .$this->getIndent(
                            $tagMax + $hintMax + $extraIndent,
                            $this->getLeftAlignedDescriptionIndent($items, $j)
                        )
                        .$item['desc'];

                    $docBlock->getLine($current + $j)->setContent($line.$lineEnding);

                    continue;
                }

                $currTag = $item['tag'];

                $spacingForTag = $this->spacingForTag($currTag);

                $itemOpeningLine = $item;

                $line =
                    $item['indent']
                    .' * @'
                    .$item['tag'];

                if ($hasStatic) {
                    $line .=
                        $this->getIndent(
                            $tagMax - \strlen($item['tag']) + $spacingForTag,
                            '' !== $item['static'] ? $spacingForTag : 0
                        )
                        .('' !== $item['static'] ? $item['static'] : $this->getIndent(6 /* \strlen('static') */, 0));
                    $hintVerticalAlignIndent = $spacingForTag;
                } else {
                    $hintVerticalAlignIndent = $tagMax - \strlen($item['tag']) + $spacingForTag;
                }

                $line .=
                    $this->getIndent(
                        $hintVerticalAlignIndent,
                        '' !== $item['hint'] ? $spacingForTag : 0
                    )
                    .$item['hint'];

                if ('' !== $item['var']) {
                    $line .=
                        $this->getIndent((0 !== $hintMax ? $hintMax : -1) - \strlen($item['hint']) + $spacingForTag, $spacingForTag)
                        .$item['var']
                        .(
                            '' !== $item['desc']
                            ? $this->getIndent($varMax - \strlen($item['var']) + $spacingForTag, $spacingForTag).$item['desc']
                            : ''
                        );
                } elseif ('' !== $item['desc']) {
                    $line .= $this->getIndent($hintMax - \strlen($item['hint']) + $spacingForTag, $spacingForTag).$item['desc'];
                }

                $docBlock->getLine($current + $j)->setContent($line.$lineEnding);
            }
        }
    }

    private function spacingForTag(?string $tag): int
    {
        return (\is_int($this->spacing))
            ? $this->spacing
            : ($this->spacing[$tag] ?? $this->spacing[self::DEFAULT_SPACING_KEY] ?? self::DEFAULT_SPACING);
    }

    /**
     * @TODO Introduce proper DTO instead of an array
     *
     * @return null|array{indent: null|string, tag: null|string, hint: string, var: null|string, static: string, desc?: null|string}
     */
    private function getMatches(string $line, bool $matchCommentOnly = false): ?array
    {
        if (Preg::match($this->regex, $line, $matches)) {
            if (isset($matches['tag2']) && '' !== $matches['tag2']) {
                $matches['tag'] = $matches['tag2'];
                $matches['hint'] = $matches['hint2'];
                $matches['var'] = '';
            }

            if (isset($matches['tag3']) && '' !== $matches['tag3']) {
                $matches['tag'] = $matches['tag3'];
                $matches['hint'] = $matches['hint3'];
                $matches['var'] = $matches['signature'];

                // Since static can be both a return type declaration & a keyword that defines static methods
                // we assume it's a type declaration when only one value is present
                if ('' === $matches['hint'] && '' !== $matches['static']) {
                    $matches['hint'] = $matches['static'];
                    $matches['static'] = '';
                }
            }

            if (isset($matches['hint'])) {
                $matches['hint'] = trim($matches['hint']);
            }

            if (!isset($matches['static'])) {
                $matches['static'] = '';
            }

            return $matches;
        }

        if ($matchCommentOnly && Preg::match($this->regexCommentLine, $line, $matches)) {
            $matches['tag'] = null;
            $matches['var'] = '';
            $matches['hint'] = '';
            $matches['static'] = '';

            return $matches;
        }

        return null;
    }

    private function getIndent(int $verticalAlignIndent, int $leftAlignIndent = 1): string
    {
        $indent = self::ALIGN_VERTICAL === $this->align ? $verticalAlignIndent : $leftAlignIndent;

        return str_repeat(' ', $indent);
    }

    /**
     * @param non-empty-list<array{indent: null|string, tag: null|string, hint: string, var: null|string, static: string, desc?: null|string}> $items
     */
    private function getLeftAlignedDescriptionIndent(array $items, int $index): int
    {
        if (self::ALIGN_LEFT !== $this->align) {
            return 0;
        }

        // Find last tagged line:
        $item = null;
        for (; $index >= 0; --$index) {
            $item = $items[$index];
            if (null !== $item['tag']) {
                break;
            }
        }

        // No last tag found — no indent:
        if (null === $item) {
            return 0;
        }

        $spacingForTag = $this->spacingForTag($item['tag']);

        // Indent according to existing values:
        return
            $this->getSentenceIndent($item['static'], $spacingForTag) +
            $this->getSentenceIndent($item['tag'], $spacingForTag) +
            $this->getSentenceIndent($item['hint'], $spacingForTag) +
            $this->getSentenceIndent($item['var'], $spacingForTag);
    }

    /**
     * Get indent for sentence.
     */
    private function getSentenceIndent(?string $sentence, int $spacingForTag = 1): int
    {
        if (null === $sentence) {
            return 0;
        }

        $length = \strlen($sentence);

        return 0 === $length ? 0 : $length + $spacingForTag;
    }
}
