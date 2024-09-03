<?php

/*
 * This file is part of the sxbrsky/dependency-injection.
 *
 * Copyright (C) 2024 Dominik Szamburski
 *
 * This software may be modified and distributed under the terms
 * of the MIT license. See the LICENSE file for details.
 */

namespace Sxbrsky\DependencyInjection;

use function array_key_exists;

use Closure;
use Exception;
use InvalidArgumentException;

use function is_object;
use function is_scalar;
use function is_string;
use function property_exists;

use Sxbrsky\DependencyInjection\Definition\Binding\Alias;
use Sxbrsky\DependencyInjection\Definition\Binding\Factory;
use Sxbrsky\DependencyInjection\Definition\Binding\Scalar;
use Sxbrsky\DependencyInjection\Definition\Binding\Shared;
use Sxbrsky\DependencyInjection\Definition\Binding\WeakReference;

use Sxbrsky\DependencyInjection\Definition\Exception\CircularDependencyException;
use Sxbrsky\DependencyInjection\Definition\Resolver\DefinitionResolver;
use Sxbrsky\DependencyInjection\Definition\State;
use Sxbrsky\DependencyInjection\Exception\EntryNotFoundException;
use Sxbrsky\DependencyInjection\Invoker\Invoker;

class Container implements ContainerInterface
{
    private State $state;
    private FactoryInterface $factory;

    public function __construct()
    {

        $this->state = new State();
        $this->factory = new DefinitionResolver($this->state, $this);

        $shared = new Alias(self::class);

        $this->state->bindings = [
            self::class => new WeakReference(\WeakReference::create($this)),
            ContainerInterface::class => $shared,
            FactoryInterface::class => $shared,
            InvokerInterface::class => $shared
        ];
    }

    /**
     * @inheritDoc
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->state->instances) || array_key_exists($id, $this->state->bindings);
    }

    /**
     * @inheritDoc
     */
    public function get(string $id): int|float|string|callable|object
    {
        try {
            return $this->make($id);
        } catch (Exception $e) {
            if ($this->has($id) || $e instanceof CircularDependencyException) {
                throw $e;
            }

            throw new EntryNotFoundException($id);
        }
    }

    /**
     * @inheritDoc
     */
    public function instance(string $id, int|float|string|callable|object $instance): int|float|string|callable|object
    {
        $this->state->instances[$id] = $instance;
        return $instance;
    }

    /**
     * @inheritDoc
     */
    public function bind(string $abstract, int|float|string|callable|object $concrete, bool $shared = false): void
    {
        $concrete = match (true) {
            $concrete instanceof Closure => new Factory($concrete, $shared),
            $concrete instanceof \WeakReference => new WeakReference($concrete),
            is_string($concrete) => new Alias($concrete, $shared),
            is_scalar($concrete) => new Scalar($concrete),
            is_object($concrete) => new Shared($concrete),
            default => throw new InvalidArgumentException(self::class . '::bind() unknown binding type.')
        };

        $this->state->bindings[$abstract] = $concrete;
    }

    /**
     * @inheritDoc
     */
    public function shared(string $abstract, int|float|string|callable|object $concrete): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * @inheritDoc
     */
    public function isShared(string $abstract): bool
    {
        if (array_key_exists($abstract, $this->state->instances)) {
            return true;
        }

        if (array_key_exists($abstract, $this->state->bindings)) {
            if (property_exists($this->state->bindings[$abstract], 'shared')) {
                return (bool) $this->state->bindings[$abstract]->shared;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function make(string $abstract, array $parameters = []): int|float|string|callable|object
    {
        return $this->factory->make($abstract, $parameters);
    }

    /**
     * @inheritDoc
     */
    public function call(callable|array|string $callable, array $parameters = []): mixed
    {
        return $this->getInvoker()->call($callable, $parameters);
    }

    private function getInvoker(): InvokerInterface
    {
        return new Invoker($this);
    }
}
