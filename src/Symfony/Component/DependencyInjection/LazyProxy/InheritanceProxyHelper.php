<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\LazyProxy;

use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @internal
 */
class InheritanceProxyHelper
{
    public static function getReflector(\ReflectionClass $class, $name, $id, $msg = 'tail')
    {
        if (!$class->hasMethod($name)) {
            throw new RuntimeException(sprintf('Unable to configure %s injection for service "%s": method "%s::%s" does not exist.', $msg, $id, $class->name, $name));
        }
        $r = $class->getMethod($name);
        if ($r->isPrivate()) {
            throw new RuntimeException(sprintf('Unable to configure %s injection for service "%s": method "%s::%s" must be public or protected.', $msg, $id, $class->name, $r->name));
        }
        if ($r->isStatic()) {
            throw new RuntimeException(sprintf('Unable to configure %s injection for service "%s": method "%s::%s" cannot be static.', $msg, $id, $class->name, $r->name));
        }
        if ($r->isFinal()) {
            throw new RuntimeException(sprintf('Unable to configure %s injection for service "%s": method "%s::%s" cannot be marked as final.', $msg, $id, $class->name, $r->name));
        }

        return $r;
    }

    public static function getGetterReflector(\ReflectionClass $class, $name, $id)
    {
        $r = self::getReflector($class, $name, $id, 'getter');
        if ($r->returnsReference()) {
            throw new RuntimeException(sprintf('Unable to configure getter injection for service "%s": method "%s::%s" cannot return by reference.', $id, $class->name, $r->name));
        }
        if (0 < $r->getNumberOfParameters()) {
            throw new RuntimeException(sprintf('Unable to configure getter injection for service "%s": method "%s::%s" cannot have any arguments.', $id, $class->name, $r->name));
        }

        return $r;
    }

    /**
     * @return string The signature of the passed function, return type and function/method name included if any
     */
    public static function getSignature(\ReflectionFunctionAbstract $r, &$call = null, array $tailArgs = array())
    {
        $signature = array();
        $call = array();

        foreach ($r->getParameters() as $i => $p) {
            $k = '$'.$p->name;
            if (method_exists($p, 'isVariadic') && $p->isVariadic()) {
                $k = '...'.$k;
            }
            $call[] = $k;

            if ($p->isPassedByReference()) {
                $k = '&'.$k;
            }
            if ($type = self::getTypeHint($r, $p)) {
                $k = $type.' '.$k;
            }
            if ($type && $p->allowsNull()) {
                $k = '?'.$k;
            }

            try {
                $k .= ' = '.(array_key_exists($i, $tailArgs) ? 'null' : self::export($p->getDefaultValue()));
                if ($type && $p->allowsNull() && (array_key_exists($i, $tailArgs) || null === $p->getDefaultValue())) {
                    $k = substr($k, 1);
                }
            } catch (\ReflectionException $e) {
                if ($type && $p->allowsNull() && !class_exists('ReflectionNamedType', false)) {
                    $k .= ' = null';
                    $k = substr($k, 1);
                }
            }

            $signature[] = $k;
        }
        $call = ($r->isClosure() ? '' : $r->name).'('.implode(', ', $call).')';

        if ($type = self::getTypeHint($r)) {
            $type = ': '.($r->getReturnType()->allowsNull() ? '?' : '').$type;
        }

        return ($r->returnsReference() ? '&' : '').($r->isClosure() ? '' : $r->name).'('.implode(', ', $signature).')'.$type;
    }

    /**
     * @return string|null The FQCN or builtin name of the type hint, or null when the type hint references an invalid self|parent context
     */
    public static function getTypeHint(\ReflectionFunctionAbstract $r, \ReflectionParameter $p = null, $noBuiltin = false)
    {
        if ($p instanceof \ReflectionParameter) {
            if (method_exists($p, 'getType')) {
                $type = $p->getType();
            } elseif (preg_match('/^(?:[^ ]++ ){4}([a-zA-Z_\x7F-\xFF][^ ]++)/', $p, $type)) {
                $name = $type = $type[1];

                if ('callable' === $name || 'array' === $name) {
                    return $noBuiltin ? null : $name;
                }
            }
        } else {
            $type = method_exists($r, 'getReturnType') ? $r->getReturnType() : null;
        }
        if (!$type) {
            return;
        }
        if (!is_string($type)) {
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : $type->__toString();

            if ($type->isBuiltin()) {
                return $noBuiltin ? null : $name;
            }
        }
        $lcName = strtolower($name);
        $prefix = $noBuiltin ? '' : '\\';

        if ('self' !== $lcName && 'parent' !== $lcName) {
            return $prefix.$name;
        }
        if (!$r instanceof \ReflectionMethod) {
            return;
        }
        if ('self' === $lcName) {
            return $prefix.$r->getDeclaringClass()->name;
        }
        if ($parent = $r->getDeclaringClass()->getParentClass()) {
            return $prefix.$parent->name;
        }
    }

    public static function getTailArgs(\ReflectionMethod $r, $defaultArgs, $id)
    {
        if (!$defaultArgs) {
            return array();
        }
        $params = $r->getParameters();
        $numParams = count($params);
        for ($i = 0; $i < $numParams; ++$i) {
            if (array_key_exists($i, $defaultArgs)) {
                break;
            }
        }
        $tailArgs = array();
        for (; $i < $numParams; ++$i) {
            if (method_exists($params[$i], 'isVariadic') && $params[$i]->isVariadic()) {
                break;
            }
            if (!array_key_exists($i, $defaultArgs) && !$params[$i]->isDefaultValueAvailable()) {
                throw new RuntimeException(sprintf('Unable to configure service "%s": tail of method "%s::%s()" misses a value for argument %d ($%s).', $id, $r->class, $r->name, 1 + $i, $params[$i]->name));
            }
            $tailArgs[$i] = array($params[$i], $defaultArgs[$i]);
            unset($defaultArgs[$i]);
        }
        if ($defaultArgs) {
            throw new RuntimeException(sprintf('Unable to configure service "%s": tail of method "%s::%s()" has extra arguments "%s".', $id, $r->class, $r->name, implode('", "', array_keys($defaultArgs))));
        }

        return $tailArgs;
    }

    private static function export($value)
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }
        $code = array();
        foreach ($value as $k => $v) {
            $code[] = sprintf('%s => %s', var_export($k, true), self::export($v));
        }

        return sprintf('array(%s)', implode(', ', $code));
    }
}
