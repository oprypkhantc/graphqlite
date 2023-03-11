<?php

namespace TheCodingMachine\GraphQLite\Fixtures;

use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * @Type(class=TestObject::class)
 */
class TestTypeWithInvalidPrefetchParameter
{
    /**
     * @Field(prefetchMethod="prefetch")
     */
    public function test(TestObject $testObject): string
    {
        return 'foo';
    }

    public function prefetch()
    {
    }
}
