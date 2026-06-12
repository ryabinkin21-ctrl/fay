<?php
/* ── FAY · lightweight runtime migrations ────────────────────────
   Adds the media_type discriminator that lets the same tables hold
   both movies and TV series (TMDB movie ids and tv ids overlap, so
   type must be part of a row's identity).

   Runs once per request, is idempotent, and never fatals — if a
   migration step fails the app keeps working with the old schema.   */

function fay_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function fay_index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

function fay_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function fay_ensure_media_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        /* movies.media_type — defaults 'movie' so all existing rows stay movies */
        if (fay_table_exists($pdo, 'movies') && !fay_column_exists($pdo, 'movies', 'media_type')) {
            $pdo->exec("ALTER TABLE movies ADD COLUMN media_type VARCHAR(10) NOT NULL DEFAULT 'movie'");
        }

        /* wishlist.media_type + widen the unique key to include type */
        if (fay_table_exists($pdo, 'wishlist') && !fay_column_exists($pdo, 'wishlist', 'media_type')) {
            $pdo->exec("ALTER TABLE wishlist ADD COLUMN media_type VARCHAR(10) NOT NULL DEFAULT 'movie'");
            if (fay_index_exists($pdo, 'wishlist', 'uniq_user_movie')) {
                $pdo->exec("ALTER TABLE wishlist DROP INDEX uniq_user_movie");
            }
            if (!fay_index_exists($pdo, 'wishlist', 'uniq_user_media')) {
                $pdo->exec("ALTER TABLE wishlist ADD UNIQUE KEY uniq_user_media (user_id, tmdb_id, media_type)");
            }
        }
    } catch (PDOException $e) {
        /* non-fatal: keep serving with whatever schema is present */
    }
}
