<?php

namespace App\Services\Config;

/**
 * Generic dot-path read/write for config-driven sync (products, orders, inventory, dispatch).
 *
 * Examples:
 *   get($data, 'line_items.0.price')
 *   get($data, 'inventoryItem.measurement.weight.value')
 *   get($data, 'metafields.0.namespace')
 *   set($out, 'metafields.0.key', 'material')
 */
class NestedFieldResolver
{
    public function get(array $data, string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        if (!str_contains($key, '.')) {
            if (!array_key_exists($key, $data)) {
                return null;
            }

            $value = $data[$key];

            return ($value === false) ? null : $value;
        }

        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current)) {
                return null;
            }

            // Many2one / list tuple: [id, "label"] — numeric segment picks index.
            if (ctype_digit($segment) && array_is_list($current) && array_key_exists((int) $segment, $current)) {
                $current = $current[(int) $segment];
                continue;
            }

            if (array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            if (ctype_digit($segment) && array_key_exists((int) $segment, $current)) {
                $current = $current[(int) $segment];
                continue;
            }

            // List of records without index: vendors.partner_id → vendors[0].partner_id
            if ($this->isListArray($current) && isset($current[0]) && is_array($current[0]) && array_key_exists($segment, $current[0])) {
                $current = $current[0][$segment];
                continue;
            }

            return null;
        }

        return ($current === false) ? null : $current;
    }

    /** @param array<mixed> $value */
    private function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    public function set(array &$target, string $key, mixed $value): void
    {
        if ($key === '') {
            return;
        }

        if (!str_contains($key, '.')) {
            $target[$key] = $value;

            return;
        }

        $parts   = explode('.', $key);
        $current = &$target;

        foreach ($parts as $i => $part) {
            $isLast = ($i === count($parts) - 1);

            if ($isLast) {
                $current[$part] = $value;
                break;
            }

            if (!isset($current[$part]) || !is_array($current[$part])) {
                $next = $parts[$i + 1] ?? '';
                $current[$part] = ctype_digit((string) $next) ? [] : [];
            }

            $current = &$current[$part];
        }
    }
}
