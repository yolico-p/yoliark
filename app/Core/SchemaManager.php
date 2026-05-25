<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * SchemaManager — 独立的数据库 schema 管理类。
 * 
 * 从 Database 中拆分而出，职责单一：
 * - 建表、加列、建索引、初始化全文搜索
 * - 跨数据库（SQLite / MySQL / PostgreSQL）的 schema 差异适配
 * 
 * Database 不再接触任何 CREATE / ALTER 逻辑。
 */
class SchemaManager
{
    private PDO $pdo;
    private string $dbType;
    private array $dbConfig;

    public function __construct(PDO $pdo, string $dbType, array $dbConfig = [])
    {
        $this->pdo = $pdo;
        $this->dbType = $dbType;
        $this->dbConfig = $dbConfig;
    }

    /**
     * 入口：初始化所有表结构。
     */
    public function initTables(): void
    {
        switch ($this->dbType) {
            case 'sqlite':
                $this->initTablesSQLite();
                break;
            case 'mysql':
                $this->initTablesMySQL();
                break;
            case 'pgsql':
                $this->initTablesPgSQL();
                break;
        }
        $this->migrateExistingUsersToAdmin();
    }

    // ========================================================================
    //  SQLite
    // ========================================================================

    private function initTablesSQLite(): void
    {
        // page_size 和 auto_vacuum 只能在空库设置，放在任何建表之前
        // 如果数据库已存在表，SQLite 静默忽略这些 PRAGMA
        try {
            $this->pdo->exec('PRAGMA page_size=4096');
            $this->pdo->exec('PRAGMA auto_vacuum=INCREMENTAL');
        } catch (\PDOException $e) {
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            email TEXT DEFAULT '',
            avatar TEXT DEFAULT '',
            role TEXT DEFAULT 'user',
            storage_limit INTEGER DEFAULT 10737418240,
            storage_used INTEGER DEFAULT 0,
            encryption_key TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            last_login INTEGER DEFAULT 0
        )");

        $this->addColumnSQLite('users', 'role', "TEXT DEFAULT 'user'");
        $this->addColumnSQLite('users', 'encryption_key', "TEXT DEFAULT ''");
        $this->addColumnSQLite('operation_logs', 'category', "TEXT DEFAULT ''");
        $this->addColumnSQLite('operation_logs', 'severity', "TEXT DEFAULT 'info'");
        $this->addColumnSQLite('operation_logs', 'user_agent', "TEXT DEFAULT ''");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            filename TEXT NOT NULL,
            filepath TEXT NOT NULL,
            filesize INTEGER DEFAULT 0,
            file_type TEXT DEFAULT '',
            mime_type TEXT DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT 0,
            path_hash TEXT NOT NULL,
            description TEXT DEFAULT '',
            is_favorite INTEGER DEFAULT 0,
            is_locked INTEGER DEFAULT 0,
            is_encrypted INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0,
            tags TEXT DEFAULT '',
            content_hash TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->addColumnSQLite('files', 'tags', "TEXT DEFAULT ''");
        $this->addColumnSQLite('files', 'is_locked', "INTEGER DEFAULT 0");
        $this->addColumnSQLite('files', 'sort_order', "INTEGER DEFAULT 0");
        $this->addColumnSQLite('files', 'is_encrypted', "INTEGER DEFAULT 0");
        $this->addColumnSQLite('files', 'content_hash', "TEXT DEFAULT ''");

        // 预防并发上传竞态的数据库级唯一约束
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_files_user_parent_filename ON files(user_id, parent_id, filename)");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS trash (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            filename TEXT NOT NULL,
            filepath TEXT NOT NULL,
            filesize INTEGER DEFAULT 0,
            file_type TEXT DEFAULT '',
            mime_type TEXT DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT 0,
            original_path TEXT DEFAULT '',
            deleted_at INTEGER NOT NULL,
            expire_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            share_token TEXT NOT NULL UNIQUE,
            share_password TEXT DEFAULT '',
            download_count INTEGER DEFAULT 0,
            max_downloads INTEGER DEFAULT 0,
            expire_at INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL,
            is_active INTEGER DEFAULT 1,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS share_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            share_id INTEGER NOT NULL,
            ip TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            referer TEXT DEFAULT '',
            visit_type TEXT DEFAULT 'view',
            country TEXT DEFAULT '',
            city TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS upload_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            upload_id TEXT NOT NULL,
            filename TEXT NOT NULL,
            total_size INTEGER DEFAULT 0,
            total_chunks INTEGER DEFAULT 0,
            uploaded_chunks TEXT DEFAULT '[]',
            file_md5 TEXT DEFAULT '',
            parent_id INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->addColumnSQLite('upload_tasks', 'file_md5', "TEXT DEFAULT ''");
        $this->addColumnSQLite('upload_tasks', 'parent_id', "INTEGER DEFAULT 0");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            category TEXT DEFAULT '',
            severity TEXT DEFAULT 'info',
            target TEXT DEFAULT '',
            detail TEXT DEFAULT '',
            ip TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS recent_access (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            filename TEXT NOT NULL DEFAULT '',
            filepath TEXT NOT NULL DEFAULT '',
            filesize INTEGER DEFAULT 0,
            file_type TEXT DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            accessed_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->createIndex('idx_files_user_parent', 'files', 'user_id, parent_id');
        $this->createIndex('idx_files_path_hash', 'files', 'path_hash');
        $this->createIndex('idx_files_is_favorite', 'files', 'is_favorite');
        $this->createIndex('idx_files_content_hash', 'files', 'content_hash');
        $this->createIndex('idx_shares_token', 'shares', 'share_token');
        $this->createIndex('idx_shares_active', 'shares', 'is_active, expire_at');
        $this->createIndex('idx_trash_user', 'trash', 'user_id');
        $this->createIndex('idx_trash_expire', 'trash', 'expire_at');
        $this->createIndex('idx_upload_tasks_uid', 'upload_tasks', 'upload_id');
        $this->createIndex('idx_logs_user', 'operation_logs', 'user_id');
        $this->createIndex('idx_logs_created', 'operation_logs', 'created_at');
        $this->createIndex('idx_logs_category', 'operation_logs', 'category');
        $this->createIndex('idx_logs_severity', 'operation_logs', 'severity');
        $this->createIndex('idx_recent_user', 'recent_access', 'user_id, accessed_at');
        $this->createIndex('idx_share_visits_share', 'share_visits', 'share_id');
        $this->createIndex('idx_share_visits_created', 'share_visits', 'created_at');

        $this->initFTS5Search();
    }

    // ========================================================================
    //  MySQL
    // ========================================================================

    private function initTablesMySQL(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT '',
            avatar VARCHAR(500) DEFAULT '',
            role VARCHAR(50) DEFAULT 'user',
            storage_limit BIGINT DEFAULT 10737418240,
            storage_used BIGINT DEFAULT 0,
            encryption_key TEXT,
            created_at INT NOT NULL,
            updated_at INT NOT NULL,
            last_login INT DEFAULT 0,
            INDEX idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addColumnMySQL('users', 'role', "VARCHAR(50) DEFAULT 'user'");
        $this->addColumnMySQL('users', 'encryption_key', "TEXT");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS files (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            filename VARCHAR(500) NOT NULL,
            filepath VARCHAR(1000) NOT NULL,
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            mime_type VARCHAR(200) DEFAULT '',
            is_dir TINYINT DEFAULT 0,
            parent_id INT DEFAULT 0,
            path_hash VARCHAR(64) NOT NULL,
            description TEXT,
            is_favorite TINYINT DEFAULT 0,
            is_locked TINYINT DEFAULT 0,
            is_encrypted TINYINT DEFAULT 0,
            sort_order INT DEFAULT 0,
            tags TEXT,
            content_hash VARCHAR(64) DEFAULT '',
            created_at INT NOT NULL,
            updated_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_parent (user_id, parent_id),
            INDEX idx_path_hash (path_hash),
            INDEX idx_is_favorite (is_favorite),
            INDEX idx_content_hash (content_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addColumnMySQL('files', 'tags', "TEXT");
        $this->addColumnMySQL('files', 'is_locked', "TINYINT DEFAULT 0");
        $this->addColumnMySQL('files', 'sort_order', "INT DEFAULT 0");
        $this->addColumnMySQL('files', 'is_encrypted', "TINYINT DEFAULT 0");
        $this->addColumnMySQL('files', 'content_hash', "VARCHAR(64) DEFAULT ''");
        $this->createIndexMySQL('idx_files_user_parent_filename', 'files', 'user_id, parent_id, filename');

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS trash (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            file_id INT NOT NULL,
            filename VARCHAR(500) NOT NULL,
            filepath VARCHAR(1000) NOT NULL,
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            mime_type VARCHAR(200) DEFAULT '',
            is_dir TINYINT DEFAULT 0,
            parent_id INT DEFAULT 0,
            original_path TEXT,
            deleted_at INT NOT NULL,
            expire_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_trash_user (user_id),
            INDEX idx_trash_expire (expire_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS shares (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            file_id INT NOT NULL,
            share_token VARCHAR(64) NOT NULL UNIQUE,
            share_password VARCHAR(255) DEFAULT '',
            download_count INT DEFAULT 0,
            max_downloads INT DEFAULT 0,
            expire_at INT DEFAULT 0,
            created_at INT NOT NULL,
            is_active TINYINT DEFAULT 1,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_shares_token (share_token),
            INDEX idx_shares_active (is_active, expire_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS share_visits (
            id INT PRIMARY KEY AUTO_INCREMENT,
            share_id INT NOT NULL,
            ip VARCHAR(50) DEFAULT '',
            user_agent TEXT,
            referer TEXT,
            visit_type VARCHAR(50) DEFAULT 'view',
            country VARCHAR(50) DEFAULT '',
            city VARCHAR(100) DEFAULT '',
            created_at INT NOT NULL,
            FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE,
            INDEX idx_share_visits_share (share_id),
            INDEX idx_share_visits_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS upload_tasks (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            upload_id VARCHAR(64) NOT NULL,
            filename VARCHAR(500) NOT NULL,
            total_size BIGINT DEFAULT 0,
            total_chunks INT DEFAULT 0,
            uploaded_chunks TEXT,
            file_md5 VARCHAR(64) DEFAULT '',
            parent_id INT DEFAULT 0,
            created_at INT NOT NULL,
            updated_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_upload_tasks_uid (upload_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addColumnMySQL('upload_tasks', 'file_md5', "VARCHAR(64) DEFAULT ''");
        $this->addColumnMySQL('upload_tasks', 'parent_id', "INT DEFAULT 0");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS operation_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            category VARCHAR(50) DEFAULT '',
            severity VARCHAR(20) DEFAULT 'info',
            target TEXT,
            detail TEXT,
            ip VARCHAR(50) DEFAULT '',
            user_agent TEXT,
            created_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_logs_user (user_id),
            INDEX idx_logs_created (created_at),
            INDEX idx_logs_category (category),
            INDEX idx_logs_severity (severity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS recent_access (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            file_id INT NOT NULL,
            filename VARCHAR(500) NOT NULL DEFAULT '',
            filepath VARCHAR(1000) NOT NULL DEFAULT '',
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            is_dir TINYINT DEFAULT 0,
            accessed_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_recent_user (user_id, accessed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->initFullTextSearchMySQL();
    }

    // ========================================================================
    //  PostgreSQL
    // ========================================================================

    private function initTablesPgSQL(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT '',
            avatar VARCHAR(500) DEFAULT '',
            role VARCHAR(50) DEFAULT 'user',
            storage_limit BIGINT DEFAULT 10737418240,
            storage_used BIGINT DEFAULT 0,
            encryption_key TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            last_login INTEGER DEFAULT 0
        )");

        $this->addColumnPgSQL('users', 'role', "VARCHAR(50) DEFAULT 'user'");
        $this->addColumnPgSQL('users', 'encryption_key', "TEXT DEFAULT ''");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS files (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            filename VARCHAR(500) NOT NULL,
            filepath VARCHAR(1000) NOT NULL,
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            mime_type VARCHAR(200) DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT 0,
            path_hash VARCHAR(64) NOT NULL,
            description TEXT DEFAULT '',
            is_favorite INTEGER DEFAULT 0,
            is_locked INTEGER DEFAULT 0,
            is_encrypted INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0,
            tags TEXT DEFAULT '',
            content_hash VARCHAR(64) DEFAULT '',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )");

        $this->addColumnPgSQL('files', 'tags', "TEXT DEFAULT ''");
        $this->addColumnPgSQL('files', 'is_locked', "INTEGER DEFAULT 0");
        $this->addColumnPgSQL('files', 'sort_order', "INTEGER DEFAULT 0");
        $this->addColumnPgSQL('files', 'is_encrypted', "INTEGER DEFAULT 0");
        $this->addColumnPgSQL('files', 'content_hash', "VARCHAR(64) DEFAULT ''");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_files_user_parent_filename ON files(user_id, parent_id, filename)");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS trash (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            file_id INTEGER NOT NULL,
            filename VARCHAR(500) NOT NULL,
            filepath VARCHAR(1000) NOT NULL,
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            mime_type VARCHAR(200) DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT 0,
            original_path TEXT DEFAULT '',
            deleted_at INTEGER NOT NULL,
            expire_at INTEGER NOT NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS shares (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
            share_token VARCHAR(64) NOT NULL UNIQUE,
            share_password VARCHAR(255) DEFAULT '',
            download_count INTEGER DEFAULT 0,
            max_downloads INTEGER DEFAULT 0,
            expire_at INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL,
            is_active INTEGER DEFAULT 1
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS share_visits (
            id SERIAL PRIMARY KEY,
            share_id INTEGER NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
            ip VARCHAR(50) DEFAULT '',
            user_agent TEXT DEFAULT '',
            referer TEXT DEFAULT '',
            visit_type VARCHAR(50) DEFAULT 'view',
            country VARCHAR(50) DEFAULT '',
            city VARCHAR(100) DEFAULT '',
            created_at INTEGER NOT NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS upload_tasks (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            upload_id VARCHAR(64) NOT NULL,
            filename VARCHAR(500) NOT NULL,
            total_size BIGINT DEFAULT 0,
            total_chunks INTEGER DEFAULT 0,
            uploaded_chunks JSONB DEFAULT '[]',
            file_md5 VARCHAR(64) DEFAULT '',
            parent_id INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )");

        $this->addColumnPgSQL('upload_tasks', 'file_md5', "VARCHAR(64) DEFAULT ''");
        $this->addColumnPgSQL('upload_tasks', 'parent_id', "INTEGER DEFAULT 0");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS operation_logs (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            action VARCHAR(50) NOT NULL,
            category VARCHAR(50) DEFAULT '',
            severity VARCHAR(20) DEFAULT 'info',
            target TEXT DEFAULT '',
            detail TEXT DEFAULT '',
            ip VARCHAR(50) DEFAULT '',
            user_agent TEXT DEFAULT '',
            created_at INTEGER NOT NULL
        )");

        $this->addColumnPgSQL('operation_logs', 'category', "VARCHAR(50) DEFAULT ''");
        $this->addColumnPgSQL('operation_logs', 'severity', "VARCHAR(20) DEFAULT 'info'");
        $this->addColumnPgSQL('operation_logs', 'user_agent', "TEXT DEFAULT ''");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS recent_access (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            file_id INTEGER NOT NULL,
            filename VARCHAR(500) NOT NULL DEFAULT '',
            filepath VARCHAR(1000) NOT NULL DEFAULT '',
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            accessed_at INTEGER NOT NULL
        )");

        $this->createIndex('idx_files_user_parent', 'files', 'user_id, parent_id');
        $this->createIndex('idx_files_path_hash', 'files', 'path_hash');
        $this->createIndex('idx_files_is_favorite', 'files', 'is_favorite');
        $this->createIndex('idx_files_content_hash', 'files', 'content_hash');
        $this->createIndex('idx_shares_token', 'shares', 'share_token');
        $this->createIndex('idx_shares_active', 'shares', 'is_active, expire_at');
        $this->createIndex('idx_trash_user', 'trash', 'user_id');
        $this->createIndex('idx_trash_expire', 'trash', 'expire_at');
        $this->createIndex('idx_upload_tasks_uid', 'upload_tasks', 'upload_id');
        $this->createIndex('idx_logs_user', 'operation_logs', 'user_id');
        $this->createIndex('idx_logs_created', 'operation_logs', 'created_at');
        $this->createIndex('idx_logs_category', 'operation_logs', 'category');
        $this->createIndex('idx_logs_severity', 'operation_logs', 'severity');
        $this->createIndex('idx_recent_user', 'recent_access', 'user_id, accessed_at');
        $this->createIndex('idx_share_visits_share', 'share_visits', 'share_id');
        $this->createIndex('idx_share_visits_created', 'share_visits', 'created_at');

        $this->initFullTextSearchPgSQL();
    }

    // ========================================================================
    //  通用辅助
    // ========================================================================

    private function createIndex(string $name, string $table, string $columns): void
    {
        try {
            if ($this->dbType === 'sqlite') {
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
            } elseif ($this->dbType === 'pgsql') {
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
            }
            // MySQL 的索引已经在 CREATE TABLE 时内联定义，或通过 createIndexMySQL 创建
        } catch (PDOException $e) {
            // 索引已存在或并发创建冲突，静默忽略
        }
    }

    private function createIndexMySQL(string $name, string $table, string $columns): void
    {
        try {
            $stmt = $this->pdo->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$name}'");
            if ($stmt->rowCount() === 0) {
                $this->pdo->exec("CREATE UNIQUE INDEX {$name} ON {$table} ({$columns})");
            }
        } catch (PDOException $e) {
            // 索引已存在或表不存在，静默忽略
        }
    }

    // ---------- 加列辅助 ----------

    private function addColumnSQLite(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->pdo->query("PRAGMA table_info({$table})");
            foreach ($stmt->fetchAll() as $col) {
                if ($col['name'] === $column) {
                    return;
                }
            }
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (PDOException $e) {
        }
    }

    private function addColumnMySQL(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
            if ($stmt->rowCount() === 0) {
                $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } catch (PDOException $e) {
        }
    }

    private function addColumnPgSQL(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ? AND table_schema = 'public'");
            $stmt->execute([$table, $column]);
            if ($stmt->rowCount() === 0) {
                $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } catch (PDOException $e) {
        }
    }

    // ---------- 迁移辅助 ----------

    private function migrateExistingUsersToAdmin(): void
    {
        try {
            $sql = match ($this->dbType) {
                'sqlite' => "UPDATE users SET role = 'admin' WHERE role = '' OR role IS NULL",
                'mysql'  => "UPDATE users SET role = 'admin' WHERE role = '' OR role IS NULL",
                'pgsql'  => "UPDATE users SET role = 'admin' WHERE role = '' OR role IS NULL",
                default  => null,
            };
            if ($sql !== null) {
                $this->pdo->exec($sql);
            }
        } catch (PDOException $e) {
        }
    }

    // ---------- 全文搜索 ----------

    private function initFTS5Search(): void
    {
        try {
            $this->pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS files_fts USING fts5(
                filename,
                description,
                tags,
                content='files',
                content_rowid='id'
            )");

            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS files_ai AFTER INSERT ON files BEGIN
                INSERT INTO files_fts(rowid, filename, description, tags)
                VALUES (new.id, new.filename, new.description, new.tags);
            END");

            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS files_ad AFTER DELETE ON files BEGIN
                INSERT INTO files_fts(files_fts, rowid, filename, description, tags)
                VALUES('delete', old.id, old.filename, old.description, old.tags);
            END");

            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS files_au AFTER UPDATE ON files BEGIN
                INSERT INTO files_fts(files_fts, rowid, filename, description, tags)
                VALUES('delete', old.id, old.filename, old.description, old.tags);
                INSERT INTO files_fts(rowid, filename, description, tags)
                VALUES (new.id, new.filename, new.description, new.tags);
            END");

            $this->rebuildFTS5IfStale();
        } catch (PDOException $e) {
        }
    }

    private function initFullTextSearchMySQL(): void
    {
        try {
            $this->pdo->exec("ALTER TABLE files ADD FULLTEXT INDEX idx_files_fulltext (filename, description, tags)");
        } catch (PDOException $e) {
        }
    }

    private function initFullTextSearchPgSQL(): void
    {
        try {
            $this->pdo->exec("CREATE EXTENSION IF NOT EXISTS pg_trgm");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_trgm ON files USING gin (filename gin_trgm_ops)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_desc_trgm ON files USING gin (description gin_trgm_ops)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_tags_trgm ON files USING gin (tags gin_trgm_ops)");
        } catch (PDOException $e) {
        }
    }

    /**
     * 重建 FTS5 索引——当 files 表行数与 files_fts 不一致时触发。
     */
    private function rebuildFTS5IfStale(): void
    {
        try {
            $count = $this->pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
            $ftsCount = $this->pdo->query("SELECT COUNT(*) FROM files_fts")->fetchColumn();

            if ($count !== false && $ftsCount !== false && $count != $ftsCount) {
                $this->pdo->exec("INSERT INTO files_fts(files_fts) VALUES('rebuild')");
            }
        } catch (PDOException $e) {
        }
    }

    /**
     * 手动触发 FTS5 重建（可从外部调用）。
     */
    public function rebuildFTS5(): void
    {
        try {
            $this->pdo->exec("INSERT INTO files_fts(files_fts) VALUES('rebuild')");
        } catch (PDOException $e) {
        }
    }
}
