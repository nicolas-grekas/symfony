<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Config\Definition\Builder;

use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidDefinitionException;
use Symfony\Component\Config\Definition\NodeInterface;

/**
 * This class provides a fluent interface for defining a node.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
abstract class NodeDefinition implements NodeParentInterface
{
    protected $name;
    protected $normalization;
    protected $validation;
    protected $defaultValue;
    protected $default = false;
    protected $required = false;
    protected $deprecation = [];
    protected $merge;
    protected $allowEmptyValue = true;
    protected $nullEquivalent;
    protected $trueEquivalent = true;
    protected $falseEquivalent = false;
    protected $pathSeparator = BaseNode::DEFAULT_PATH_SEPARATOR;
    protected $parent;
    protected $attributes = [];

    public function __construct(?string $name, NodeParentInterface $parent = null)
    {
        $this->parent = $parent;
        $this->name = $name;
    }

    /**
     * Sets the parent node.
     *
     * @return $this
     */
    public function setParent(NodeParentInterface $parent)
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * Sets info message.
     *
     * @return $this
     */
    public function info(string $info)
    {
        return $this->attribute('info', $info);
    }

    /**
     * Sets example configuration.
     *
     * @return $this
     */
    public function example(string|array $example)
    {
        return $this->attribute('example', $example);
    }

    /**
     * Sets an attribute on the node.
     *
     * @return $this
     */
    public function attribute(string $key, mixed $value)
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Returns the parent node.
     *
     * @return NodeParentInterface|NodeBuilder|NodeDefinition|ArrayNodeDefinition|VariableNodeDefinition|null The builder of the parent node
     */
    public function end()
    {
        return $this->parent;
    }

    /**
     * Creates the node.
     *
     * @return NodeInterface
     */
    public function getNode(bool $forceRootNode = false)
    {
        if ($forceRootNode) {
            $this->parent = null;
        }

        if (null !== $this->normalization) {
            $this->normalization->before = ExprBuilder::buildExpressions($this->normalization->before);
        }

        if (null !== $this->validation) {
            $this->validation->rules = ExprBuilder::buildExpressions($this->validation->rules);
        }

        $node = $this->createNode();
        if ($node instanceof BaseNode) {
            $node->setAttributes($this->attributes);
        }

        return $node;
    }

    /**
     * Sets the default value.
     *
     * @return $this
     */
    public function defaultValue(mixed $value)
    {
        $this->default = true;
        $this->defaultValue = $value;

        return $this;
    }

    /**
     * Sets the node as required.
     *
     * @return $this
     */
    public function isRequired()
    {
        $this->required = true;

        return $this;
    }

    /**
     * Sets the node as deprecated.
     *
     * @param string $package The name of the composer package that is triggering the deprecation
     * @param string $version The version of the package that introduced the deprecation
     * @param string $message the deprecation message to use
     *
     * You can use %node% and %path% placeholders in your message to display,
     * respectively, the node name and its complete path
     *
     * @return $this
     */
    public function setDeprecated(string $package, string $version, string $message = 'The child node "%node%" at path "%path%" is deprecated.')
    {
        $this->deprecation = [
            'package' => $package,
            'version' => $version,
            'message' => $message,
        ];

        return $this;
    }

    /**
     * Sets the equivalent value used when the node contains null.
     *
     * @return $this
     */
    public function treatNullLike(mixed $value)
    {
        $this->nullEquivalent = $value;

        return $this;
    }

    /**
     * Sets the equivalent value used when the node contains true.
     *
     * @return $this
     */
    public function treatTrueLike(mixed $value)
    {
        $this->trueEquivalent = $value;

        return $this;
    }

    /**
     * Sets the equivalent value used when the node contains false.
     *
     * @return $this
     */
    public function treatFalseLike(mixed $value)
    {
        $this->falseEquivalent = $value;

        return $this;
    }

    /**
     * Sets null as the default value.
     *
     * @return $this
     */
    public function defaultNull()
    {
        return $this->defaultValue(null);
    }

    /**
     * Sets true as the default value.
     *
     * @return $this
     */
    public function defaultTrue()
    {
        return $this->defaultValue(true);
    }

    /**
     * Sets false as the default value.
     *
     * @return $this
     */
    public function defaultFalse()
    {
        return $this->defaultValue(false);
    }

    /**
     * Sets an expression to run before the normalization.
     *
     * @return ExprBuilder
     */
    public function beforeNormalization()
    {
        return $this->normalization()->before();
    }

    /**
     * Denies the node value being empty.
     *
     * @return $this
     */
    public function cannotBeEmpty()
    {
        $this->allowEmptyValue = false;

        return $this;
    }

    /**
     * Sets an expression to run for the validation.
     *
     * The expression receives the value of the node and must return it. It can
     * modify it.
     * An exception should be thrown when the node is not valid.
     *
     * @return ExprBuilder
     */
    public function validate()
    {
        return $this->validation()->rule();
    }

    /**
     * Sets whether the node can be overwritten.
     *
     * @return $this
     */
    public function cannotBeOverwritten(bool $deny = true)
    {
        $this->merge()->denyOverwrite($deny);

        return $this;
    }

    /**
     * Gets the builder for validation rules.
     *
     * @return ValidationBuilder
     */
    protected function validation()
    {
        if (null === $this->validation) {
            $this->validation = new ValidationBuilder($this);
        }

        return $this->validation;
    }

    /**
     * Gets the builder for merging rules.
     *
     * @return MergeBuilder
     */
    protected function merge()
    {
        if (null === $this->merge) {
            $this->merge = new MergeBuilder($this);
        }

        return $this->merge;
    }

    /**
     * Gets the builder for normalization rules.
     *
     * @return NormalizationBuilder
     */
    protected function normalization()
    {
        if (null === $this->normalization) {
            $this->normalization = new NormalizationBuilder($this);
        }

        return $this->normalization;
    }

    /**
     * Instantiate and configure the node according to this definition.
     *
     * @return NodeInterface The node instance
     *
     * @throws InvalidDefinitionException When the definition is invalid
     */
    abstract protected function createNode();

    /**
     * Set PathSeparator to use.
     *
     * @return $this
     */
    public function setPathSeparator(string $separator)
    {
        if ($this instanceof ParentNodeDefinitionInterface) {
            foreach ($this->getChildNodeDefinitions() as $child) {
                $child->setPathSeparator($separator);
            }
        }

        $this->pathSeparator = $separator;

        return $this;
    }
}
