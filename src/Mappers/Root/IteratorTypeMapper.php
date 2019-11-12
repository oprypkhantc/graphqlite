<?php

declare(strict_types=1);

namespace TheCodingMachine\GraphQLite\Mappers\Root;

use Closure;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\OutputType;
use GraphQL\Type\Definition\Type as GraphQLType;
use Iterator;
use IteratorAggregate;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Nullable;
use phpDocumentor\Reflection\Types\Object_;
use ReflectionClass;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Mappers\CannotMapTypeExceptionInterface;
use TheCodingMachine\GraphQLite\Mappers\RecursiveTypeMapperInterface;
use TheCodingMachine\GraphQLite\TypeMappingRuntimeException;
use TheCodingMachine\GraphQLite\TypeRegistry;
use TheCodingMachine\GraphQLite\Types\UnionType;
use Webmozart\Assert\Assert;
use function count;
use function iterator_to_array;

/**
 * This root type mapper is used when one of the types (in a compound type) is an iterator.
 * In this case, if the other types are arrays, they are passed as subTypes. For instance: ResultIterator|User[] => ResultIterator<User>
 */
class IteratorTypeMapper implements RootTypeMapperInterface
{
    /** @var RootTypeMapperInterface */
    private $topRootTypeMapper;
    /** @var TypeRegistry */
    private $typeRegistry;
    /** @var RecursiveTypeMapperInterface */
    private $recursiveTypeMapper;

    public function __construct(RootTypeMapperInterface $topRootTypeMapper, TypeRegistry $typeRegistry, RecursiveTypeMapperInterface $recursiveTypeMapper)
    {
        $this->topRootTypeMapper = $topRootTypeMapper;
        $this->typeRegistry = $typeRegistry;
        $this->recursiveTypeMapper = $recursiveTypeMapper;
    }

    /**
     * @param (OutputType&GraphQLType)|null $subType
     *
     * @return (OutputType&GraphQLType)|null
     */
    public function toGraphQLOutputType(Type $type, ?OutputType $subType, ReflectionMethod $refMethod, DocBlock $docBlockObj): ?OutputType
    {
        if (! $type instanceof Compound) {
            return null;
        }

        $result = $this->toGraphQLType($type, function (Type $type, ?OutputType $subType) use ($refMethod, $docBlockObj) {
            return $this->topRootTypeMapper->toGraphQLOutputType($type, $subType, $refMethod, $docBlockObj);
        }, true);
        Assert::nullOrIsInstanceOf($result, OutputType::class);

        return $result;
    }

    /**
     * @param (InputType&GraphQLType)|null $subType
     *
     * @return (InputType&GraphQLType)|null
     */
    public function toGraphQLInputType(Type $type, ?InputType $subType, string $argumentName, ReflectionMethod $refMethod, DocBlock $docBlockObj): ?InputType
    {
        if (! $type instanceof Compound) {
            return null;
        }

        $result = $this->toGraphQLType($type, function (Type $type, ?InputType $subType) use ($refMethod, $docBlockObj, $argumentName) {
            return $this->topRootTypeMapper->toGraphQLInputType($type, $subType, $argumentName, $refMethod, $docBlockObj);
        }, true);
        Assert::nullOrIsInstanceOf($result, InputType::class);

        return $result;
    }

    /**
     * Returns a GraphQL type by name.
     * If this root type mapper can return this type in "toGraphQLOutputType" or "toGraphQLInputType", it should
     * also map these types by name in the "mapNameToType" method.
     *
     * @param string $typeName The name of the GraphQL type
     */
    public function mapNameToType(string $typeName): ?NamedType
    {
        // TODO: how to handle this? Do we need?
        return null;
    }

    /**
     * Resolves a list type.
     */
    private function getTypeInArray(Type $typeHint): ?Type
    {
        if (! $typeHint instanceof Array_) {
            return null;
        }

        return $this->dropNullableType($typeHint->getValueType());
    }

    /**
     * Drops "Nullable" types and return the core type.
     */
    private function dropNullableType(Type $typeHint): Type
    {
        if ($typeHint instanceof Nullable) {
            return $typeHint->getActualType();
        }

        return $typeHint;
    }

    /**
     * @return (OutputType&GraphQLType)|(InputType&GraphQLType)|null
     */
    private function toGraphQLType(Compound $type, Closure $topToGraphQLType, bool $isOutputType)
    {
        $types = iterator_to_array($type);

        $iteratorType = $this->splitIteratorFromOtherTypes($types);
        if ($iteratorType === null) {
            return null;
        }

        $unionTypes = [];
        $lastException = null;
        foreach ($types as $singleDocBlockType) {
            try {
                $singleDocBlockType = $this->getTypeInArray($singleDocBlockType);
                if ($singleDocBlockType !== null) {
                    $subGraphQlType = $topToGraphQLType($singleDocBlockType, null);
                    //$subGraphQlType = $this->toGraphQlType($singleDocBlockType, null, false, $refMethod, $docBlockObj);

                    // By convention, we trim the NonNull part of the "$subGraphQlType"
                    if ($subGraphQlType instanceof NonNull) {
                        /** @var OutputType&GraphQLType $subGraphQlType */
                        $subGraphQlType = $subGraphQlType->getWrappedType();
                    }
                } else {
                    $subGraphQlType = null;
                }

                $unionTypes[] = $topToGraphQLType($iteratorType, $subGraphQlType);
            } catch (TypeMappingRuntimeException | CannotMapTypeExceptionInterface $e) {
                // We have several types. It is ok not to be able to match one.
                $lastException = $e;

                if ($singleDocBlockType !== null) {
                    // The type is an array (like User[]). Let's use that.
                    $valueType = $topToGraphQLType($singleDocBlockType, null);
                    if ($valueType !== null) {
                        $unionTypes[] = new ListOfType($valueType);
                    }
                }
            }
        }

        if (empty($unionTypes) && $lastException !== null) {
            // We have an issue, let's try without the subType
            try {
                $result = $topToGraphQLType($iteratorType, null);
            } catch (TypeMappingRuntimeException | CannotMapTypeExceptionInterface $otherException) {
                // Still an issue? Let's rethrow the previous exception.
                throw $lastException;
            }

            return $result;

            //return $this->mapDocBlockType($type, $docBlockType, $isNullable, false, $refMethod, $docBlockObj);
        }

        if (count($unionTypes) === 1) {
            $graphQlType = $unionTypes[0];
        } elseif ($isOutputType) {
            $graphQlType = new UnionType($unionTypes, $this->recursiveTypeMapper);
            $graphQlType = $this->typeRegistry->getOrRegisterType($graphQlType);
            Assert::isInstanceOf($graphQlType, OutputType::class);
        } else {
            // There are no union input types. Something went wrong.
            $graphQlType = null;
        }

        return $graphQlType;
    }

    /**
     * Removes the iterator type from $types
     *
     * @param Type[] $types
     */
    private function splitIteratorFromOtherTypes(array &$types): ?Type
    {
        $iteratorType = null;
        $key = null;
        foreach ($types as $key => $singleDocBlockType) {
            if (! ($singleDocBlockType instanceof Object_)) {
                continue;
            }

            $fqcn     = (string) $singleDocBlockType->getFqsen();
            $refClass = new ReflectionClass($fqcn);
            // Note : $refClass->isIterable() is only accessible in PHP 7.2
            if (! $refClass->implementsInterface(Iterator::class) && ! $refClass->implementsInterface(IteratorAggregate::class)) {
                continue;
            }
            $iteratorType = $singleDocBlockType;
            break;
        }

        if ($iteratorType === null) {
            return null;
        }

        // One of the classes in the compound is an iterator. Let's remove it from the list and let's test all other values as potential subTypes.
        unset($types[$key]);

        return $iteratorType;
    }
}
