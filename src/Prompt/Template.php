<?php
declare(strict_types=1);

namespace SEOVendor\Evaluator\Prompt;

class Template
{
    public static function build(string $entity, string $locale, int $n): string
    {
        $prompt = 'For the entity "' . $entity . '" in "' . $locale . '", list the top ' . $n . ' brands/sites most relevant to it. '
                . 'Return JSON only matching the schema: [{"brand":string, "site":uri, "locale":string, "reason"?:string}].';
        return $prompt;
    }
}
