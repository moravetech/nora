<?php

declare(strict_types=1);

namespace Nora\Security;

use Nora\Database\MigrationInterface;
use PDO;

final class AccountLifecycleMigration implements MigrationInterface
{
    public function up(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('The built-in account lifecycle migration requires MySQL or MariaDB.');
        }

        $pdo->exec(
            'CREATE TABLE nora_accounts ('
            . 'id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,'
            . 'email VARCHAR(254) NOT NULL,'
            . 'normalized_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,'
            . 'password_hash VARCHAR(255) NOT NULL,'
            . 'email_verified_at DATETIME(6) NULL,'
            . 'enabled TINYINT(1) NOT NULL DEFAULT 1,'
            . 'session_version INT UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at DATETIME(6) NOT NULL,'
            . 'updated_at DATETIME(6) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $pdo->exec(
            'CREATE TABLE nora_account_tokens ('
            . 'token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,'
            . 'account_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'purpose VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'expires_at DATETIME(6) NOT NULL,'
            . 'created_at DATETIME(6) NOT NULL,'
            . 'UNIQUE KEY nora_account_tokens_account_purpose (account_id, purpose),'
            . 'INDEX nora_account_tokens_expiry (expires_at),'
            . 'CONSTRAINT nora_account_tokens_account_fk FOREIGN KEY (account_id)'
            . ' REFERENCES nora_accounts (id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('The built-in account lifecycle migration requires MySQL or MariaDB.');
        }

        $pdo->exec('DROP TABLE IF EXISTS nora_account_tokens');
        $pdo->exec('DROP TABLE IF EXISTS nora_accounts');
    }
}
