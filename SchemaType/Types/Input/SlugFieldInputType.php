<?php
/**
 * Created by PhpStorm.
 * User: stefan.kamsker
 * Date: 27.09.18
 * Time: 10:29
 */

namespace Karls\SlugBundle\SchemaType\Types\Input;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

class SlugFieldInputType extends InputObjectType
{
    public function __construct()
    {
        parent::__construct(
            [
                'fields' => [
                    'slug' => [
                        'type' => Type::string(),
                        'description' => 'The Slug for a Content Type',
                    ]
                ],
            ]
        );
    }
}