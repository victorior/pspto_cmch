<?php

declare(strict_types=1);

/** Plantilla de impresion OC (tenant_settings), editable por el encargado del modulo OC. */
final class TenantPrintSettings
{
    /** @return array<string, mixed> */
    public static function load(PDO $pdo, int $tenantId): array
    {
        $st = $pdo->prepare('SELECT * FROM tenant_settings WHERE tenant_id = ? LIMIT 1');
        $st->execute([$tenantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : [];
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function saveFromPost(PDO $pdo, int $tenantId, array $post): void
    {
        $logo = mb_substr(trim((string) ($post['print_logo_path'] ?? '')), 0, 255);
        $st = $pdo->prepare(
            'UPDATE tenant_settings SET
                company_name = ?,
                address = ?,
                tax_id = ?,
                phone = ?,
                business_line = ?,
                print_contact_name = ?,
                print_contact_role = ?,
                print_contact_email = ?,
                print_delivery_hours = ?,
                print_delivery_address = ?,
                print_invoice_to = ?,
                print_footer_text = ?,
                print_logo_path = ?
             WHERE tenant_id = ?'
        );
        $st->execute([
            mb_substr(trim((string) ($post['company_name'] ?? '')), 0, 255),
            mb_substr(trim((string) ($post['address'] ?? '')), 0, 255),
            mb_substr(trim((string) ($post['tax_id'] ?? '')), 0, 64),
            mb_substr(trim((string) ($post['phone'] ?? '')), 0, 64),
            mb_substr(trim((string) ($post['business_line'] ?? '')), 0, 128),
            mb_substr(trim((string) ($post['print_contact_name'] ?? '')), 0, 120),
            mb_substr(trim((string) ($post['print_contact_role'] ?? '')), 0, 120),
            mb_substr(trim((string) ($post['print_contact_email'] ?? '')), 0, 255),
            mb_substr(trim((string) ($post['print_delivery_hours'] ?? '')), 0, 255),
            mb_substr(trim((string) ($post['print_delivery_address'] ?? '')), 0, 255),
            mb_substr(trim((string) ($post['print_invoice_to'] ?? '')), 0, 255),
            mb_substr(trim((string) ($post['print_footer_text'] ?? '')), 0, 255),
            $logo,
            $tenantId,
        ]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException('No existe configuracion para este tenant.');
        }
    }
}
