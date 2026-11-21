<?php

declare(strict_types=1);

/**
 * Elimina U+FFFD y normaliza separadores rotos en PHP/HTML del proyecto.
 * Uso: php database/fix_encoding.php
 */

$root = dirname(__DIR__);
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$fixed = 0;
foreach ($iter as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $base = basename($path);
    if (str_contains($base, 'cna14865_budgetflow')) {
        continue;
    }
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['php', 'txt', 'css'], true)) {
        continue;
    }
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }

    $text = file_get_contents($path);
    if ($text === false || !str_contains($text, "\u{FFFD}")) {
        continue;
    }

    $out = str_replace("\u{FFFD}", '', $text);
    $out = preg_replace(
        '/<title>OC\s+Login<\/title>/',
        '<title>OC &mdash; Login</title>',
        $out
    ) ?? $out;

    file_put_contents($path, $out);
    echo "Fixed: {$path}\n";
    $fixed++;
}

echo $fixed > 0 ? "Listo ({$fixed} archivos).\n" : "Sin caracteres de reemplazo.\n";
