<?php

namespace App\Services\Sync;

use Throwable;

/**
 * Short, user-facing sync error messages for UI toasts and sync_message columns.
 * Full RPC / stack traces stay in logs and SyncLog error_context.
 */
class SyncErrorFormatter
{
    public static function short(Throwable|string|null $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        $msg = $error instanceof Throwable ? $error->getMessage() : $error;

        if ($msg === '') {
            return null;
        }

        if (preg_match("/Invalid ERP field\\(s\\): (.+)/", $msg, $m)) {
            return 'Invalid ERP field(s): ' . trim($m[1]);
        }

        if (preg_match("/Invalid field '([^']+)'/", $msg, $m)) {
            return "Invalid ERP field: {$m[1]} — check Field Config ERP column.";
        }

        if (preg_match("/KeyError: '([^']+)'/", $msg, $m)) {
            return "Invalid ERP field: {$m[1]} — check Field Config ERP column.";
        }

        if (preg_match("/Wrong value for [^:]+: '([^']+)'/", $msg, $m)) {
            return "Invalid ERP value: {$m[1]} (use selection key, not UI label).";
        }

        foreach (['UserError', 'ValidationError', 'AccessError', 'ValueError'] as $type) {
            if (preg_match('/' . $type . ': (.+?)(?:\n|$)/s', $msg, $m)) {
                $line = trim($m[1]);

                return strlen($line) > 300 ? substr($line, 0, 300) . '…' : $line;
            }
        }

        if (str_contains($msg, 'Traceback')) {
            if (preg_match('/\n([A-Za-z_][\w.]*(?:Error|Exception)(?: \([^)]+\))?: .+)$/s', $msg, $m)) {
                $nested = self::short(trim($m[1]));
                if ($nested !== null && !str_contains($nested, 'Traceback')) {
                    return $nested;
                }
            }

            return 'ERP rejected the request — check Field Config (ERP field names and values). See entity info for full log.';
        }

        if (preg_match('/Odoo [^.]+\.[^.]+ failed: (.+)/s', $msg, $m)) {
            $line = trim($m[1]);
            if (!str_starts_with($line, 'Traceback')) {
                $nested = self::short($line);

                return $nested ?? (strlen($line) > 300 ? substr($line, 0, 300) . '…' : $line);
            }
        }

        if (str_contains($msg, 'foreign key constraint') || str_contains($msg, 'violates RESTRICT')) {
            return 'Cannot delete — this record is still linked to other data in the ERP (e.g. company, orders, stock, or deliveries). Remove those links first.';
        }

        if (str_contains($msg, 'productDelete failed:')) {
            $line = trim((string) preg_replace('/^.*productDelete failed:\s*/i', '', $msg));

            return $line !== '' ? 'Shopify: ' . (strlen($line) > 280 ? substr($line, 0, 280) . '…' : $line) : 'Shopify could not delete this product.';
        }

        if (str_contains($msg, 'customerDelete failed:')) {
            $line = trim((string) preg_replace('/^.*customerDelete failed:\s*/i', '', $msg));

            return $line !== '' ? 'Shopify: ' . (strlen($line) > 280 ? substr($line, 0, 280) . '…' : $line) : 'Shopify could not delete this customer.';
        }

        return strlen($msg) > 300 ? substr($msg, 0, 300) . '…' : $msg;
    }
}
