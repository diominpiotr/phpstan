<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\TrinaryLogic;

class UnionType implements CompoundType, StaticResolvableType
{

	/** @var \PHPStan\Type\Type[] */
	private $types;

	/**
	 * @param Type[] $types
	 */
	public function __construct(array $types)
	{
		$throwException = function () use ($types): void {
			throw new \PHPStan\ShouldNotHappenException(sprintf(
				'Cannot create %s with: %s',
				self::class,
				implode(', ', array_map(function (Type $type): string {
					return $type->describe();
				}, $types))
			));
		};
		if (count($types) < 2) {
			$throwException();
		}
		foreach ($types as $type) {
			if ($type instanceof UnionType) {
				$throwException();
			}
		}
		$this->types = UnionTypeHelper::sortTypes($types);
	}

	/**
	 * @return \PHPStan\Type\Type[]
	 */
	public function getTypes(): array
	{
		return $this->types;
	}

	/**
	 * @return string[]
	 */
	public function getReferencedClasses(): array
	{
		return UnionTypeHelper::getReferencedClasses($this->getTypes());
	}

	public function accepts(Type $type): bool
	{
		if ($type instanceof CompoundType) {
			return CompoundTypeHelper::accepts($type, $this);
		}

		if (TypeCombinator::shouldSkipUnionTypeAccepts($this)) {
			return true;
		}

		foreach ($this->getTypes() as $otherType) {
			if ($otherType->accepts($type)) {
				return true;
			}
		}

		return false;
	}

	public function isSuperTypeOf(Type $otherType): TrinaryLogic
	{
		if ($otherType instanceof self || $otherType instanceof IterableType) {
			return $otherType->isSubTypeOf($this);
		}

		$results = [];
		foreach ($this->getTypes() as $innerType) {
			$results[] = $innerType->isSuperTypeOf($otherType);
		}

		return TrinaryLogic::createNo()->or(...$results);
	}

	public function isSubTypeOf(Type $otherType): TrinaryLogic
	{
		$results = [];
		foreach ($this->getTypes() as $innerType) {
			$results[] = $otherType->isSuperTypeOf($innerType);
		}

		return TrinaryLogic::extremeIdentity(...$results);
	}

	public function describe(): string
	{
		$typeNames = [];

		foreach ($this->types as $type) {
			if ($type instanceof IntersectionType) {
				$typeNames[] = sprintf('(%s)', $type->describe());
			} else {
				$typeNames[] = $type->describe();
			}
		}

		return implode('|', array_unique($typeNames));
	}

	public function canAccessProperties(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->canAccessProperties();
		});
	}

	public function hasProperty(string $propertyName): bool
	{
		foreach ($this->types as $type) {
			if ($type instanceof NullType) {
				continue;
			}
			if (!$type->hasProperty($propertyName)) {
				return false;
			}
		}

		return true;
	}

	public function getProperty(string $propertyName, Scope $scope): PropertyReflection
	{
		foreach ($this->types as $type) {
			if ($type instanceof NullType) {
				continue;
			}
			return $type->getProperty($propertyName, $scope);
		}

		throw new \PHPStan\ShouldNotHappenException();
	}

	public function canCallMethods(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->canCallMethods();
		});
	}

	public function hasMethod(string $methodName): bool
	{
		foreach ($this->types as $type) {
			if ($type instanceof NullType) {
				continue;
			}
			if (!$type->hasMethod($methodName)) {
				return false;
			}
		}

		return true;
	}

	public function getMethod(string $methodName, Scope $scope): MethodReflection
	{
		foreach ($this->types as $type) {
			if ($type instanceof NullType) {
				continue;
			}
			return $type->getMethod($methodName, $scope);
		}

		throw new \PHPStan\ShouldNotHappenException();
	}

	public function canAccessConstants(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->canAccessConstants();
		});
	}

	public function hasConstant(string $constantName): bool
	{
		foreach ($this->types as $type) {
			if ($type instanceof NullType) {
				continue;
			}
			if (!$type->hasConstant($constantName)) {
				return false;
			}
		}

		return true;
	}

	public function getConstant(string $constantName): ClassConstantReflection
	{
		foreach ($this->types as $type) {
			if ($type instanceof NullType) {
				continue;
			}
			return $type->getConstant($constantName);
		}

		throw new \PHPStan\ShouldNotHappenException();
	}

	public function resolveStatic(string $className): Type
	{
		return new self(UnionTypeHelper::resolveStatic($className, $this->getTypes()));
	}

	public function changeBaseClass(string $className): StaticResolvableType
	{
		return new self(UnionTypeHelper::changeBaseClass($className, $this->getTypes()));
	}

	public function isIterable(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->isIterable();
		});
	}

	public function getIterableKeyType(): Type
	{
		return $this->unionTypes(function (Type $type): Type {
			return $type->getIterableKeyType();
		});
	}

	public function getIterableValueType(): Type
	{
		return $this->unionTypes(function (Type $type): Type {
			return $type->getIterableValueType();
		});
	}

	public function isOffsetAccessible(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->isOffsetAccessible();
		});
	}

	public function getOffsetValueType(Type $offsetType): Type
	{
		return $this->unionTypes(function (Type $type) use ($offsetType): Type {
			return $type->getOffsetValueType($offsetType);
		});
	}

	public function setOffsetValueType(?Type $offsetType, Type $valueType): Type
	{
		return $this->unionTypes(function (Type $type) use ($offsetType, $valueType): Type {
			return $type->setOffsetValueType($offsetType, $valueType);
		});
	}

	public function isCallable(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->isCallable();
		});
	}

	public function isCloneable(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->isCloneable();
		});
	}

	public function toBoolean(): BooleanType
	{
		/** @var BooleanType $type */
		$type = $this->unionTypes(function (Type $type): BooleanType {
			return $type->toBoolean();
		});

		return $type;
	}

	public static function __set_state(array $properties): Type
	{
		return new self($properties['types']);
	}

	private function unionResults(callable $getResult): TrinaryLogic
	{
		return TrinaryLogic::extremeIdentity(...array_map($getResult, $this->types));
	}

	private function unionTypes(callable $getType): Type
	{
		return TypeCombinator::union(...array_map($getType, $this->types));
	}

}
