<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static $instance = null;
    private $pdo;
    private $queryCache;
    private $dbType = 'sqlite';
    private $dbConfig = [];

    private function __construct()
    {
        $config = Config::getInstance();
        $this->dbType = $config->get('database.type', 'sqlite');
        $this->dbConfig = $config->get('database.config', []);
        $this->queryCache = new QueryCache();

        if (!is_dir(DATA_PATH)) {
            mkdir(DATA_PATH, 0755, true);
        }

        if ($this->dbType === 'sqlite') {
            $dsn = 'sqlite:' . DB_PATH;
            $this->pdo = new PDO($dsn);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            // 运行时 PRAGMA：每次连接都设置，安全可重复
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');
            $this->pdo->exec('PRAGMA busy_timeout=15000');
            $this->pdo->exec('PRAGMA synchronous=NORMAL');
            $this->pdo->exec('PRAGMA cache_size=-16000');
            $this->pdo->exec('PRAGMA temp_store=MEMORY');

            // mmap_size 非关键，部分环境不支持，静默忽略失败
            try {
                $this->pdo->exec('PRAGMA mmap_size=268435456');
            } catch (\PDOException $e) {
            }

            // page_size 和 auto_vacuum 只能在空库设置，移入 SchemaManager 在首次建表前完成
        } elseif ($this->dbType === 'mysql') {
            $host = $this->dbConfig['host'] ?? '127.0.0.1';
            $port = $this->dbConfig['port'] ?? 3306;
            $database = $this->dbConfig['database'] ?? 'pancloud';
            $username = $this->dbConfig['username'] ?? 'root';
            $password = $this->dbConfig['password'] ?? '';
            $charset = $this->dbConfig['charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ];

            $this->pdo = new PDO($dsn, $username, $password, $options);
            $this->pdo->exec("SET NAMES '{$charset}'");
            $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        } elseif ($this->dbType === 'pgsql') {
            $host = $this->dbConfig['host'] ?? '127.0.0.1';
            $port = $this->dbConfig['port'] ?? 5432;
            $database = $this->dbConfig['database'] ?? 'pancloud';
            $username = $this->dbConfig['username'] ?? 'postgres';
            $password = $this->dbConfig['password'] ?? '';

            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ];

            $this->pdo = new PDO($dsn, $username, $password, $options);
            $this->pdo->exec("SET NAMES 'UTF8'");
        }

        // Schema 初始化委托给独立的 SchemaManager
        (new SchemaManager($this->pdo, $this->dbType, $this->dbConfig))->initTables();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    public function getDbType()
    {
        return $this->dbType;
    }

    public function getQueryCache()
    {
        return $this->queryCache;
    }

    // ====================================================================
    //  PDO 查询执行
    // ====================================================================

    public function getPdo()
    {
        return $this->pdo;
    }

    public function query($sql, $params = [])
    {
        $maxRetries = 5;
        $baseDelay = 50000; // 50ms
        $attempt = 0;

        while (true) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (PDOException $e) {
                $attempt++;
                if ($attempt >= $maxRetries || strpos($e->getMessage(), 'database is locked') === false) {
                    throw $e;
                }
                // 指数退避：50ms, 100ms, 200ms, 400ms, 800ms
                usleep($baseDelay * pow(2, $attempt - 1));
            }
        }
    }

    public function fetch($sql, $params = [])
    {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = [])
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchCached($sql, $params = [], $tags = [])
    {
        if (!$this->queryCache->isEnabled()) {
            return $this->fetchAll($sql, $params);
        }

        $cacheKey = md5($sql . json_encode($params));
        $cached = $this->queryCache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetchAll($sql, $params);
        $this->queryCache->set($cacheKey, $result, $tags);
        return $result;
    }

    public function fetchOneCached($sql, $params = [], $tags = [])
    {
        if (!$this->queryCache->isEnabled()) {
            return $this->fetch($sql, $params);
        }

        $cacheKey = md5($sql . json_encode($params));
        $cached = $this->queryCache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetch($sql, $params);
        if (!empty($result)) {
            $this->queryCache->set($cacheKey, $result, $tags);
        }

        return $result;
    }

    // ====================================================================
    //  缓存管理委托给 QueryCache
    // ====================================================================

    public function getCacheStats()
    {
        return $this->queryCache->getStats();
    }

    public function setCacheEnabled($enabled)
    {
        $this->queryCache->setEnabled($enabled);
    }

    public function clearQueryCache($pattern = null)
    {
        $this->queryCache->clear($pattern);
    }

    public function clearCacheByTags($tags)
    {
        $this->queryCache->clearByTags($tags);
    }

    public function invalidateTableCache($tableName)
    {
        $tags = [$tableName, 'table:' . $tableName];
        $this->queryCache->clearByTags($tags);
    }

    public function getCacheInfo()
    {
        return $this->queryCache->getInfo();
    }

    public function clearAllCache()
    {
        $this->queryCache->clear();
    }

    // ====================================================================
    //  CRUD 辅助
    // ====================================================================

    public function insert($table, $data)
    {
        $fields = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";
        $result = $this->query($sql, array_values($data));
        $this->invalidateTableCache($table);
        return $this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = [])
    {
        $setParts = [];
        foreach (array_keys($data) as $key) {
            $setParts[] = "{$key} = ?";
        }
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
        $params = array_merge(array_values($data), $whereParams);
        $result = $this->query($sql, $params)->rowCount();
        $this->invalidateTableCache($table);
        return $result;
    }

    public function delete($table, $where, $params = [])
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $result = $this->query($sql, $params)->rowCount();
        $this->invalidateTableCache($table);
        return $result;
    }

    public function beginTransaction()
    {
        $maxRetries = 5;
        $baseDelay = 50000; // 50ms
        $attempt = 0;

        while (true) {
            try {
                // ── SQLite 使用 BEGIN IMMEDIATE，立即获取写锁，避免锁升级失败 ──
                if ($this->dbType === 'sqlite') {
                    return $this->pdo->exec('BEGIN IMMEDIATE');
                }
                return $this->pdo->beginTransaction();
            } catch (PDOException $e) {
                $attempt++;
                if ($attempt >= $maxRetries || strpos($e->getMessage(), 'database is locked') === false) {
                    throw $e;
                }
                // 指数退避：50ms, 100ms, 200ms, 400ms, 800ms
                usleep($baseDelay * pow(2, $attempt - 1));
            }
        }
    }

    public function commit()
    {
        return $this->pdo->commit();
    }

    public function rollBack()
    {
        return $this->pdo->rollBack();
    }
}
