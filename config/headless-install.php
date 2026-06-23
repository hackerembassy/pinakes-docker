<?php
/**
 * Pinakes headless installer for Docker.
 *
 * Drives the REAL installer (installer/classes/Installer.php) so there is zero
 * drift from the canonical wizard — we only orchestrate its public methods,
 * we never re-implement schema/seed SQL.
 *
 * Behaviour:
 *   - Idempotent: exits 0 immediately if already installed (.installed marker).
 *   - Creates the database if missing (the Installer connects WITH a dbname,
 *     so the DB must exist first).
 *   - Imports schema + locale data + triggers + optimisation indexes, seeds
 *     default settings, installs bundled plugins.
 *   - If ADMIN_EMAIL and ADMIN_PASSWORD are provided -> creates the admin and
 *     writes the .installed lock (fully headless, no wizard).
 *   - Otherwise -> leaves the DB pre-filled but does NOT lock, so the operator
 *     lands in the web wizard with only the admin-user step remaining.
 *
 * Exit codes: 0 = installed or already-installed or wizard-fallback prepared;
 *             1 = hard failure.
 */

declare(strict_types=1);

$baseDir = '/var/www/html';

function out(string $msg): void { fwrite(STDOUT, '[headless-install] ' . $msg . "\n"); }
function fail(string $msg): void { fwrite(STDERR, '[headless-install] ERROR: ' . $msg . "\n"); exit(1); }

require $baseDir . '/installer/classes/Installer.php';

$installer = new Installer($baseDir);

// 1) Idempotency guard.
if ($installer->isInstalled()) {
    out('Already installed (.installed present) — skipping.');
    exit(0);
}

// 2) Read DB config from the environment (the entrypoint already wrote .env).
$dbHost   = getenv('DB_HOST') ?: 'db';
$dbPort   = (int)(getenv('DB_PORT') ?: 3306);
$dbName   = getenv('DB_NAME') ?: 'pinakes';
$dbUser   = getenv('DB_USER') ?: 'pinakes';
$dbPass   = getenv('DB_PASS') ?: '';
$dbSocket = getenv('DB_SOCKET') ?: '';

// 3) Create the database if it doesn't exist (connect WITHOUT a dbname).
try {
    if ($dbSocket !== '') {
        $dsn = "mysql:unix_socket={$dbSocket};charset=utf8mb4";
    } else {
        $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
    }
    $root = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec(
        "CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $dbName) . "` " .
        "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $root = null;
    out("Database `{$dbName}` ready.");
} catch (\Throwable $e) {
    fail('Could not create/verify database: ' . $e->getMessage());
}

// 4) Load .env into the Installer's config and run the canonical steps.
try {
    $installer->loadEnvConfig();

    out('Importing schema…');
    $installer->importSchema();

    out('Importing locale data…');
    $installer->importData();

    try {
        out('Importing triggers…');
        $installer->importTriggers();
        foreach ($installer->getTriggerWarnings() as $w) {
            out('  trigger warning (non-fatal): ' . $w);
        }
    } catch (\Throwable $e) {
        out('  triggers skipped (non-fatal): ' . $e->getMessage());
    }

    try {
        out('Applying optimisation indexes…');
        $installer->importOptimizationIndexes();
    } catch (\Throwable $e) {
        out('  indexes skipped (non-fatal): ' . $e->getMessage());
    }

    out('Populating default settings…');
    $installer->populateDefaultSettings();

    out('Installing bundled plugins…');
    try {
        $installer->installPluginsFromZip();
    } catch (\Throwable $e) {
        out('  plugin install warning (non-fatal): ' . $e->getMessage());
    }
} catch (\Throwable $e) {
    fail('Install step failed: ' . $e->getMessage());
}

// 5) Admin user + lock, or wizard fallback.
$adminEmail = getenv('ADMIN_EMAIL') ?: '';
$adminPass  = getenv('ADMIN_PASSWORD') ?: '';
$adminName  = getenv('ADMIN_NAME') ?: 'Admin';
$adminSurn  = getenv('ADMIN_SURNAME') ?: 'User';

if ($adminEmail !== '' && $adminPass !== '') {
    try {
        out("Creating admin user {$adminEmail}…");
        $installer->createAdminUser($adminName, $adminSurn, $adminEmail, $adminPass);
    } catch (\Throwable $e) {
        fail('Admin creation failed: ' . $e->getMessage());
    }
    if (!$installer->createLockFile()) {
        fail('Could not write .installed lock file.');
    }
    out('✓ Headless install complete — Pinakes is ready (no wizard needed).');
    exit(0);
}

out('✓ Database prepared. ADMIN_EMAIL/ADMIN_PASSWORD not set — finish the');
out('  admin-user step at /installer/ (everything else is already done).');
exit(0);
