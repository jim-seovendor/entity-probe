<?php
declare(strict_types=1);

namespace SEOVendor\Evaluator\Schema;

final class Validator
{
    private string $debugFile;

    public function __construct()
    {
        $this->debugFile = __DIR__ . '/../../data/_validate_fail.log';
        @mkdir(dirname($this->debugFile), 0775, true);
        $this->dbg('_boot_no_opis', ['mode' => 'php_only']);
    }

    public function validateList(array $items): bool
    {
        if (!is_array($items) || empty($items)) {
            $this->dbg('list_not_array_or_empty', $items);
            return false;
        }

        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                $this->dbg("item_not_array[$idx]", $item);
                return false;
            }

            $brand  = $item['brand']  ?? null;
            $site   = $item['site']   ?? null;
            $locale = $item['locale'] ?? null;

            if (!is_string($brand) || trim($brand) === '') {
                $this->dbg("brand_bad[$idx]", $item);
                return false;
            }

            if (!is_string($site) || trim($site) === '') {
                $this->dbg("site_missing[$idx]", $item);
                return false;
            }

            if (!preg_match('~^https?://~i', $site)) {
                $try = 'https://' . ltrim((string)$site, '/');
                if (filter_var($try, FILTER_VALIDATE_URL) === false) {
                    $this->dbg("site_not_url[$idx]", $item);
                    return false;
                }
            } else {
                if (filter_var($site, FILTER_VALIDATE_URL) === false) {
                    $this->dbg("site_invalid_url[$idx]", $item);
                    return false;
                }
            }

            if (!is_string($locale) || strlen($locale) < 2 || strlen($locale) > 10) {
                $this->dbg("locale_bad[$idx]", $item);
                return false;
            }
        }

        return true;
    }

    private function dbg(string $tag, $payload): void
    {
        $line = '[' . date('c') . "] $tag " . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($this->debugFile, $line, FILE_APPEND);
    }
}
