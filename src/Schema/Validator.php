<?php
declare(strict_types=1);

namespace SEOVendor\Evaluator\Schema;

use Opis\JsonSchema\Validator as OpisValidator;

class Validator
{
    private OpisValidator $validator;
    private array $schema;

    public function __construct()
    {
        $this->validator = new OpisValidator();
        $this->schema = [
            'type' => 'object',
            'properties' => [
                'brand' => ['type' => 'string','minLength'=>1],
                'site' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
                'locale' => ['type' => 'string','minLength'=>2,'maxLength'=>5],
            ],
            'required' => ['brand','site','locale'],
            'additionalProperties' => false
        ];
    }

    public function validateList(array $items): bool
    {
        foreach ($items as $item) {
            $result = $this->validator->schemaValidation((object)$item, (object)$this->schema);
            if (!$result->isValid()) {
                return false;
            }
        }
        return true;
    }
}
