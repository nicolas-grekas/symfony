<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Extractor\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor;

/**
 * Extracts the labels, help messages and placeholders passed as options to the add() and create() methods of form builders.
 *
 * The domain is the "translation_domain" option of the field when it is set, else the default one of the enclosing form type.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class FormVisitor extends AbstractVisitor implements NodeVisitor
{
    private const MESSAGE_OPTIONS = ['label', 'help', 'placeholder'];

    private NodeFinder $finder;

    /**
     * @var list<list<string|null>> The domains of the enclosing classes, empty when their fields are not translated
     */
    private array $classDomains = [];

    public function __construct()
    {
        $this->finder = new NodeFinder();
    }

    public function beforeTraverse(array $nodes): ?Node
    {
        $this->classDomains = [];

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classDomains[] = $this->getClassDomains($node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classDomains);

            return null;
        }

        if (!$node instanceof Node\Expr\MethodCall || !$node->name instanceof Node\Identifier || !\in_array($node->name->toLowerString(), ['add', 'create'], true)) {
            return null;
        }

        if (!$options = $this->getOptions($node)) {
            return null;
        }

        $domains = $this->getDomains($options['translation_domain'] ?? null, $this->classDomains ? end($this->classDomains) : [null]);

        foreach (self::MESSAGE_OPTIONS as $name) {
            if (!isset($options[$name])) {
                continue;
            }

            foreach (array_unique($this->getStringValues($options[$name])) as $message) {
                if ('' === $message) {
                    continue;
                }

                foreach ($domains as $domain) {
                    $this->addMessageToCatalogue($message, $domain, $options[$name]->getStartLine());
                }
            }
        }

        return null;
    }

    public function afterTraverse(array $nodes): ?Node
    {
        return null;
    }

    /**
     * @return array<string, Node\Expr> The options passed as an array literal, indexed by name
     */
    private function getOptions(Node\Expr\MethodCall $node): array
    {
        $args = $node->getRawArgs();
        $options = 2 < $this->nodeFirstNamedArgumentIndex($node) ? $args[2] ?? null : array_find($args, static fn ($arg) => $arg instanceof Node\Arg && 'options' === $arg->name?->toString());

        return $options instanceof Node\Arg && $options->value instanceof Node\Expr\Array_ ? $this->getArrayItems($options->value) : [];
    }

    /**
     * Finds the "translation_domain" option that the class sets with setDefaults() or setDefault().
     *
     * @return list<string|null>
     */
    private function getClassDomains(Node\Stmt\ClassLike $class): array
    {
        foreach ($this->finder->findInstanceOf($class->stmts, Node\Expr\MethodCall::class) as $call) {
            if (!$call->name instanceof Node\Identifier || !($option = $call->getRawArgs()[0] ?? null) instanceof Node\Arg) {
                continue;
            }

            $domain = null;

            if ('setdefaults' === $call->name->toLowerString() && $option->value instanceof Node\Expr\Array_) {
                $domain = $this->getArrayItems($option->value)['translation_domain'] ?? null;
            } elseif ('setdefault' === $call->name->toLowerString() && $option->value instanceof Node\Scalar\String_ && 'translation_domain' === $option->value->value && ($value = $call->getRawArgs()[1] ?? null) instanceof Node\Arg) {
                $domain = $value->value;
            }

            if (null !== $domain) {
                return $this->getDomains($domain, [null]);
            }
        }

        return [null];
    }

    /**
     * @param list<string|null> $default
     *
     * @return list<string|null> The domains of the messages, empty when they are not translated
     */
    private function getDomains(?Node\Expr $domain, array $default): array
    {
        if (null === $domain) {
            return $default;
        }

        if ($domain instanceof Node\Expr\ConstFetch && 'false' === $domain->name->toLowerString()) {
            return [];
        }

        return array_values(array_unique($this->getStringValues($domain))) ?: $default;
    }

    /**
     * @return array<string, Node\Expr>
     */
    private function getArrayItems(Node\Expr\Array_ $array): array
    {
        $items = [];

        foreach ($array->items as $item) {
            if ($item->key instanceof Node\Scalar\String_) {
                $items[$item->key->value] = $item->value;
            }
        }

        return $items;
    }
}
