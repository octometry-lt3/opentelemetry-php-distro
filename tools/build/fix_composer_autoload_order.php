<?php

/**
 * Fixes the Composer files-autoload order in a vendor directory so that instrumentations which
 * eagerly resolve the OTel SDK during `require vendor/autoload.php` (e.g.
 * open-telemetry/opentelemetry-metrics-runtime, which calls Globals::meterProvider() unconditionally
 * inside its register()) cannot run before the distro or the OTLP exporter are ready:
 *
 * 1. Prepends earlySetup.php as the first files-autoload entry, so
 *    PhpPartFacade::earlySetup() - which registers the distro's resource-attribute override and its
 *    native OTLP serializer/transport shadowing - runs before sdk/_autoload.php or any
 *    instrumentation's _register.php file.
 *
 * 2. Reorders open-telemetry/exporter-otlp/_register.php and
 *    open-telemetry/transport-grpc/_register.php before open-telemetry/sdk/_autoload.php.
 *    Composer's natural ordering puts a package's own dependencies first, so sdk/_autoload.php (a
 *    dependency of exporter-otlp) normally runs before exporter-otlp/_register.php. If an
 *    instrumentation then triggers eager SDK initialization, the OTLP span/metric/log exporter
 *    factories are not yet registered and initialization fails with "Span exporter factory not
 *    defined for: otlp".
 *
 * Needed for BOTH scoped and not_scoped vendors - the relative path from a vendor's composer/
 * directory up to OpenTelemetry/Distro/earlySetup.php is identical in both layouts (vendor is
 * always a sibling of OpenTelemetry/), and the exporter-otlp/sdk ordering problem is not
 * scoping-specific either. This script is called against each vendor directory separately and does
 * not depend on scoping.
 *
 * Usage: php tools/build/fix_composer_autoload_order.php <vendor_dir>
 */

declare(strict_types=1);

if ($_SERVER['argc'] !== 2) {
    fwrite(STDERR, "Usage: php tools/build/fix_composer_autoload_order.php <vendor_dir>\n");
    exit(1);
}

assert(is_array($_SERVER['argv']));
/** @var string $vendorDir */
$vendorDir = $_SERVER['argv'][1];
$composerDir = rtrim($vendorDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR;

// --- Fix 1: Prepend earlySetup.php as the first entry in the files autoload loop ---

// $insertAfterPattern is a regex (without delimiters/flags) matching the array-opening line. php-scoper
// and plain Composer format this slightly differently ("array(" vs "array (" with a space), so this
// matches on the pattern and inserts right after whatever was actually matched, rather than assuming
// one exact literal string.
$prependEarlySetupEntry = static function (
    string $filePath,
    string $entry,
    string $insertAfterPattern
): void {
    if (!is_file($filePath)) {
        fwrite(STDERR, "File does not exist (skipping early setup prepend): $filePath\n");
        return;
    }

    $content = file_get_contents($filePath);
    if (!is_string($content)) {
        fwrite(STDERR, "Failed to read: $filePath\n");
        exit(1);
    }

    if (str_contains($content, 'earlySetup.php')) {
        fwrite(STDOUT, "Early setup entry already present in: $filePath\n");
        return;
    }

    if (!preg_match('/' . $insertAfterPattern . '/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        fwrite(STDERR, "Failed to find insertion point matching '$insertAfterPattern' in: $filePath\n");
        exit(1);
    }
    $insertPos = $matches[0][1] + strlen($matches[0][0]);
    $newContent = substr($content, 0, $insertPos) . $entry . substr($content, $insertPos);

    if (file_put_contents($filePath, $newContent) === false) {
        fwrite(STDERR, "Failed to write: $filePath\n");
        exit(1);
    }

    fwrite(STDOUT, "Prepended early setup entry in: $filePath\n");
};

$earlySetupHash = md5('otel-distro:OpenTelemetry/Distro/earlySetup.php');
$prependEarlySetupEntry(
    $composerDir . 'autoload_files.php',
    "'" . $earlySetupHash . "' => \$baseDir . '/OpenTelemetry/Distro/earlySetup.php', ",
    "return array\\s*\\("
);
$prependEarlySetupEntry(
    $composerDir . 'autoload_static.php',
    "'" . $earlySetupHash . "' => __DIR__ . '/../../OpenTelemetry/Distro/earlySetup.php', ",
    "public static \\\$files = array\\s*\\("
);

// --- Fix 2: Reorder provider registrations before sdk/_autoload.php ---

// Locates the array entry ('<key>' => <expr>,) whose value contains $marker and returns
// [entryStartOffset, sourceEntryText, insertionEntryText], or null if not found. Composer emits these
// files with one entry per line, but php-scoper's own generator emits the whole array as a single
// line - so this works off entry boundaries (the preceding quoted key and the following comma; the
// expressions themselves never contain a literal comma) rather than assuming a fixed
// line/separator structure. The key is intentionally not restricted to Composer's usual md5 shape:
// the scoped gRPC repair inserts a synthetic files-autoload entry, and this keeps the ordering fix
// coupled to the actual Composer entry shape rather than to how its key was generated.
$findEntryContaining = static function (string $content, string $marker): ?array {
    $markerPos = strpos($content, $marker);
    if ($markerPos === false) {
        return null;
    }

    if (!preg_match_all("/(['\"])[^'\"]+\\1\\s*=>/", $content, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $entryStart = null;
    foreach ($matches[0] as $match) {
        $offset = (int) $match[1];
        if ($offset > $markerPos) {
            break;
        }
        $entryStart = $offset;
    }
    if ($entryStart === null) {
        return null;
    }

    // Bound the search to the current files array. A final php-scoper entry may have no comma,
    // while an unbounded search would otherwise consume a comma from a later static array.
    $nextEntryStart = null;
    foreach ($matches[0] as $match) {
        $offset = (int) $match[1];
        if ($offset > $entryStart) {
            $nextEntryStart = $offset;
            break;
        }
    }
    $arrayEnd = strpos($content, ');', $markerPos + strlen($marker));
    if ($arrayEnd === false) {
        return null;
    }

    $entryBoundary = $nextEntryStart === null
        ? $arrayEnd
        : min($nextEntryStart, $arrayEnd);
    $commaPos = strpos($content, ',', $markerPos + strlen($marker));
    if ($commaPos !== false && $commaPos < $entryBoundary) {
        $entryEnd = $commaPos + 1; // include the trailing comma
    } else {
        // PHP permits the final array entry to omit its comma. Add one because this entry is
        // inserted before another entry below.
        $entryText = rtrim(substr($content, $entryStart, $entryBoundary - $entryStart));
        return [$entryStart, $entryText, $entryText . ','];
    }

    $entryText = substr($content, $entryStart, $entryEnd - $entryStart);
    return [$entryStart, $entryText, $entryText];
};

$reorderAutoloadEntries = static function (string $filePath, string $pathToMove, string $insertBefore, bool $required = false) use ($findEntryContaining): void {
    if (!is_file($filePath)) {
        fwrite(STDERR, "File does not exist (skipping reorder): $filePath\n");
        return;
    }

    $content = file_get_contents($filePath);
    if (!is_string($content)) {
        fwrite(STDERR, "Failed to read: $filePath\n");
        exit(1);
    }

    $pathToMoveMarker = $pathToMove . "'";
    $insertBeforeMarker = $insertBefore . "'";

    $pathToMovePos = strpos($content, $pathToMoveMarker);
    $insertBeforePos = strpos($content, $insertBeforeMarker);

    if ($pathToMovePos === false || $insertBeforePos === false) {
        if ($required) {
            fwrite(STDERR, "Required autoload markers '$pathToMove' and '$insertBefore' not found in: $filePath\n");
            exit(1);
        }
        fwrite(STDOUT, "Skipping reorder (markers not found) in: $filePath\n");
        return;
    }

    if ($pathToMovePos < $insertBeforePos) {
        fwrite(STDOUT, "No reorder needed in: $filePath\n");
        return;
    }

    $movedEntry = $findEntryContaining($content, $pathToMoveMarker);
    if ($movedEntry === null) {
        fwrite(STDERR, "Cannot find entry bounds for '$pathToMove' in: $filePath\n");
        exit(1);
    }
    [$movedEntryStart, $movedEntrySourceText, $movedEntryInsertionText] = $movedEntry;

    $newContent = substr($content, 0, $movedEntryStart) . substr($content, $movedEntryStart + strlen($movedEntrySourceText));

    $insertBeforeEntry = $findEntryContaining($newContent, $insertBeforeMarker);
    if ($insertBeforeEntry === null) {
        fwrite(STDERR, "Cannot find entry bounds for '$insertBefore' after removal in: $filePath\n");
        exit(1);
    }
    [$insertBeforeEntryStart] = $insertBeforeEntry;

    $newContent = substr($newContent, 0, $insertBeforeEntryStart) . $movedEntryInsertionText . substr($newContent, $insertBeforeEntryStart);

    if ($newContent === $content) {
        fwrite(STDERR, "Reorder had no effect in: $filePath\n");
        exit(1);
    }

    if (file_put_contents($filePath, $newContent) === false) {
        fwrite(STDERR, "Failed to write reordered file: $filePath\n");
        exit(1);
    }

    fwrite(STDOUT, "Reordered: '$pathToMove' now runs before '$insertBefore' in: $filePath\n");
};

$reorderAutoloadEntries(
    $composerDir . 'autoload_files.php',
    '/open-telemetry/exporter-otlp/_register.php',
    '/open-telemetry/sdk/_autoload.php'
);
$reorderAutoloadEntries(
    $composerDir . 'autoload_static.php',
    '/open-telemetry/exporter-otlp/_register.php',
    '/open-telemetry/sdk/_autoload.php'
);
$reorderAutoloadEntries(
    $composerDir . 'autoload_files.php',
    '/open-telemetry/transport-grpc/_register.php',
    '/open-telemetry/sdk/_autoload.php',
    true
);
$reorderAutoloadEntries(
    $composerDir . 'autoload_static.php',
    '/open-telemetry/transport-grpc/_register.php',
    '/open-telemetry/sdk/_autoload.php',
    true
);
