<?php

namespace App\Core;

use PDO;

/**
 * Model 基类 — 轻量 Active Record。
 *
 * 提供基础的 CRUD 封装，降低 Service 层与裸 Database 的耦合。
 * 每个子类对应一张数据库表，通过 `$table` 属性声明。
 *
 * 用法：
 *   $user = User::find(1);
 *   $users = User::where('role = ?', ['admin'])->get();
 *   $user->fill(['email' => 'a@b.com'])->save();
 *   $user->delete();
 *
 * 注意：这不是完整的 ORM，不做关联、延迟加载、变更追踪。
 * 但足够把 "裸 SQL 写在 Service 里" 的情况减少 80%。
 */
abstract class Model
{
    /** 子类必须覆盖 → 表名 */
    protected static string $table = '';

    /** 主键列名 */
    protected static string $primaryKey = 'id';

    /** 是否使用自增主键（false 表示手动赋值） */
    protected static bool $autoIncrement = true;

    /** 属性白名单 — 空数组表示允许全部 */
    protected static array $fillable = [];

    /** 只读属性 — 写入时跳过 */
    protected static array $guarded = [];

    /** 当前实例的属性数据 */
    protected array $attributes = [];

    /** 原始属性数据（用于判断是否被修改） */
    protected array $original = [];

    /** 标记是否为新记录 */
    protected bool $exists = false;

    /** 查询构建器暂存 */
    private static array $_query = [];

    // ========================================================================
    //  构造 / 填充
    // ========================================================================

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * 批量赋值。被 $fillable / $guarded 过滤。
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->attributes[$key] = $value;
            }
        }
        return $this;
    }

    /**
     * 将数据库行数据注入实例（跳过 fillable 过滤，标记为已持久化）。
     */
    public function forceFill(array $attributes): static
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        $this->original = $this->attributes;
        $this->exists = true;
        return $this;
    }

    // ========================================================================
    //  查询构建
    // ========================================================================

    /**
     * 按主键查找。
     */
    public static function find(int|string $id): ?static
    {
        $table = static::getTable();
        $pk = static::$primaryKey;
        $row = static::db()->fetch("SELECT * FROM {$table} WHERE {$pk} = ?", [$id]);
        if (!$row) {
            return null;
        }
        return (new static())->forceFill($row);
    }

    /**
     * 按条件查找第一条。
     */
    public static function where(string $where, array $params = []): static
    {
        static::$_query = [
            'table'  => static::getTable(),
            'where'  => $where,
            'params' => $params,
            'order'  => '',
            'limit'  => 0,
            'offset' => 0,
        ];
        return new static();
    }

    /**
     * 设置排序（链式）。
     */
    public static function orderBy(string $column, string $direction = 'ASC'): static
    {
        static::$_query['order'] = "{$column} {$direction}";
        return new static();
    }

    /**
     * 设置偏移（链式）。
     */
    public static function offset(int $offset): static
    {
        static::$_query['offset'] = $offset;
        return new static();
    }

    /**
     * 获取多条结果。
     */
    public static function get(): array
    {
        $q = static::$_query;
        $sql = "SELECT * FROM {$q['table']} WHERE {$q['where']}";
        if ($q['order']) {
            $sql .= " ORDER BY {$q['order']}";
        }
        if ($q['limit'] > 0) {
            $sql .= " LIMIT {$q['limit']}";
        }
        if ($q['offset'] > 0) {
            $sql .= " OFFSET {$q['offset']}";
        }

        static::$_query = [];

        $rows = static::db()->fetchAll($sql, $q['params']);
        return array_map(fn ($row) => (new static())->forceFill($row), $rows);
    }

    /**
     * 限制数量（链式）。
     */
    public static function limit(int $limit): static
    {
        static::$_query['limit'] = $limit;
        return new static();
    }

    /**
     * 获取第一条匹配结果。
     */
    public static function first(): ?static
    {
        $q = static::$_query;
        $sql = "SELECT * FROM {$q['table']} WHERE {$q['where']}";
        if ($q['order']) {
            $sql .= " ORDER BY {$q['order']}";
        }
        $sql .= " LIMIT 1";

        static::$_query = [];

        $row = static::db()->fetch($sql, $q['params']);
        if (!$row) {
            return null;
        }
        return (new static())->forceFill($row);
    }

    /**
     * 根据任意条件判断记录是否存在。
     */
    public static function existsWhere(string $where, array $params = []): bool
    {
        $table = static::getTable();
        return (bool) static::db()->fetch(
            "SELECT 1 FROM {$table} WHERE {$where} LIMIT 1",
            $params
        );
    }

    /**
     * 统计条数。
     */
    public static function count(string $where = '1=1', array $params = []): int
    {
        $table = static::getTable();
        $row = static::db()->fetch(
            "SELECT COUNT(*) AS cnt FROM {$table} WHERE {$where}",
            $params
        );
        return (int) ($row['cnt'] ?? 0);
    }

    // ========================================================================
    //  持久化
    // ========================================================================

    /**
     * 保存（INSERT 或 UPDATE）。
     */
    public function save(): bool
    {
        if ($this->exists) {
            return $this->performUpdate();
        }
        return $this->performInsert();
    }

    /**
     * 删除当前记录。
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $table = static::getTable();
        $pk = static::$primaryKey;
        $id = $this->getKey();

        static::db()->delete($table, "{$pk} = ?", [$id]);

        $this->exists = false;
        static::db()->invalidateTableCache($table);
        return true;
    }

    // ========================================================================
    //  访问器
    // ========================================================================

    /**
     * 获取主键值。
     */
    public function getKey(): int|string|null
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    /**
     * 获取所有属性。
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * 获取单个属性。
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * 设置单个属性。
     */
    public function set(string $key, mixed $value): static
    {
        if ($this->isFillable($key)) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    /**
     * 判断属性是否被修改过。
     */
    public function isDirty(?string $key = null): bool
    {
        if ($key === null) {
            return $this->attributes !== $this->original;
        }
        return ($this->attributes[$key] ?? null) !== ($this->original[$key] ?? null);
    }

    /**
     * 获取变更的属性。
     */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (($this->original[$key] ?? null) !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    // ========================================================================
    //  内部
    // ========================================================================

    protected function performInsert(): bool
    {
        $table = static::getTable();
        $data = $this->attributes;

        // 移除 guarded 属性
        foreach (static::$guarded as $g) {
            unset($data[$g]);
        }

        if (empty($data)) {
            return false;
        }

        $id = static::db()->insert($table, $data);
        if ($id && static::$autoIncrement) {
            $this->attributes[static::$primaryKey] = (int) $id;
        }
        $this->original = $this->attributes;
        $this->exists = true;
        return true;
    }

    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();
        if (empty($dirty)) {
            return true;
        }

        $table = static::getTable();
        $pk = static::$primaryKey;
        $id = $this->getKey();

        // 移除 guarded 属性
        foreach (static::$guarded as $g) {
            unset($dirty[$g]);
        }
        // 不允许修改主键
        unset($dirty[$pk]);

        if (empty($dirty)) {
            return true;
        }

        static::db()->update($table, $dirty, "{$pk} = ?", [$id]);
        $this->original = $this->attributes;
        return true;
    }

    protected function isFillable(string $key): bool
    {
        if (in_array($key, static::$guarded, true)) {
            return false;
        }
        if (empty(static::$fillable)) {
            return true;
        }
        return in_array($key, static::$fillable, true);
    }

    // ========================================================================
    //  静态工具
    // ========================================================================

    public static function getTable(): string
    {
        if (empty(static::$table)) {
            // 按类名自动推断表名
            $class = basename(str_replace('\\', '/', static::class));
            static::$table = strtolower(preg_replace('/(?<=[a-z])([A-Z])/', '_$1', $class)) . 's';
        }
        return static::$table;
    }

    protected static function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * 开始链式调用的静态入口。
     * User::query()->where(...)->orderBy(...)->get()
     */
    public static function query(): static
    {
        static::$_query = [
            'table'  => static::getTable(),
            'where'  => '1=1',
            'params' => [],
            'order'  => '',
            'limit'  => 0,
            'offset' => 0,
        ];
        return new static();
    }
}
