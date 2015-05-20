<?php
/**
 * This file is part of the Ray.Compiler package.
 *
 * @license http://opensource.org/licenses/bsd-license.php MIT
 *
 * taken from BuilderAbstract::PhpParser() and modified for object
 */
namespace Ray\Compiler;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use Ray\Compiler\Exception\NotCompiled;
use Ray\Di\Argument;
use Ray\Di\Exception\Unbound;
use Ray\Di\InjectorInterface;
use Ray\Di\SetterMethod;

class OnDemandDependencyCompiler
{
    /**
     * @var InjectorInterface
     */
    private $injector;

    /**
     * @var Normalizer
     */
    private $normalizer;

    /**
     * @var FactoryCompiler
     */
    private $factoryCompiler;

    public function __construct(
        Normalizer $normalizer,
        FactoryCompiler $factoryCompiler,
        InjectorInterface $injector = null
    ) {
        $this->injector = $injector;
        $this->normalizer = $normalizer;
        $this->factoryCompiler = $factoryCompiler;
    }

    /**
     * Return on-demand dependency pull code for not compiled
     *
     * @param Argument $argument
     *
     * @return Expr|Expr\FuncCall
     */
    public function getOnDemandDependency(Argument $argument)
    {
        $dependencyIndex = (string) $argument;
        if (! $this->injector instanceof ScriptInjector) {
            return $this->getDefault($argument);
        }
        try {
            $isSingleton = $this->injector->isSingleton($dependencyIndex);
        } catch (NotCompiled $e) {
            return $this->getDefault($argument);
        }
        $func = $isSingleton ? 'singleton' : 'prototype';
        $args = $this->getInjectionProviderParams($argument);
        $node = new Expr\FuncCall(new Expr\Variable($func), $args);

        return $node;
    }

    /**
     * Return default argument value
     *
     * @param Argument $argument
     *
     * @return Expr
     */
    private function getDefault(Argument $argument)
    {
        if ($argument->isDefaultAvailable()) {
            $default = $argument->getDefaultValue();
            $node = $this->normalizer->normalizeValue($default);

            return $node;
        }

        throw new Unbound((string) $argument);
    }

    /**
     * @param Expr\Variable  $instance
     * @param SetterMethod[] $setterMethods
     *
     * @return Expr\MethodCall[]
     */
    public function setterInjection(Expr\Variable $instance, array $setterMethods)
    {
        $setters = [];
        foreach ($setterMethods as $setterMethod) {
            $isOptional = $this->getPrivateProperty($setterMethod, 'isOptional');
            $method = $this->getPrivateProperty($setterMethod, 'method');
            $argumentsObject = $this->getPrivateProperty($setterMethod, 'arguments');
            $arguments = $this->getPrivateProperty($argumentsObject, 'arguments');
            $args = $this->getSetterParams($arguments, $isOptional);
            if (! $args) {
                continue;
            }
            $setters[] = new Expr\MethodCall($instance, $method, $args);
        }

        return $setters;
    }

    /**
     * @param Expr\Variable $instance
     * @param string        $postConstruct
     */
    public function postConstruct(Expr\Variable $instance, $postConstruct)
    {
        return new Expr\MethodCall($instance, $postConstruct);
    }

    /**
     * @param object $object
     * @param string $prop
     * @param mixed  $default
     *
     * @return mixed|null
     */
    private function getPrivateProperty($object, $prop, $default = null)
    {
        try {
            $refProp = (new \ReflectionProperty($object, $prop));
        } catch (\Exception $e) {
            return $default;
        }
        $refProp->setAccessible(true);
        $value = $refProp->getValue($object);

        return $value;
    }

    /**
     * Return code for provider
     *
     * "$provider" needs [class, method, parameter] for InjectionPoint (Contextual Dependency Injection)
     *
     * @param Argument $argument
     *
     * @return array
     */
    private function getInjectionProviderParams(Argument $argument)
    {
        $param = $argument->get();

        return [
            new Node\Arg(new Scalar\String_((string) $argument)),
            new Expr\Array_([
                new Node\Arg(new Scalar\String_($param->getDeclaringClass()->name)),
                new Node\Arg(new Scalar\String_($param->getDeclaringFunction()->name)),
                new Node\Arg(new Scalar\String_($param->name))
            ])
        ];
    }

    /**
     * Return setter method parameters
     *
     * Return false when no dependency given and @ Inject(optional=true) annotated to setter method.
     *
     * @param Argument[] $arguments
     * @param bool       $isOptional
     *
     * @return Node\Arg[]
     */
    private function getSetterParams($arguments, $isOptional)
    {
        $args = [];
        foreach ($arguments as $argument) {
            try {
                $args[] = $this->factoryCompiler->getArgStmt($argument);
            } catch (Unbound $e) {
                if ($isOptional) {
                    return false;
                }
            }
        }

        return $args;
    }
}