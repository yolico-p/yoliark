# 柚舟Cloud
> 零配置部署，安全优先。为你的 NAS 补上公网分享这一层。

柚舟Cloud 不是另一个功能堆砌的网盘系统。它解决一个具体的问题：你的 NAS 系统（飞牛、群晖、Unraid 等）在局域网内体验拉满，但一旦暴露到公网，安全风险不可控。柚舟Cloud 在你的 NAS 前面站一层，专门处理公网文件分享——速率限制、Bot 防护、分享权限管理，全在这一层完成。

存储底座交给你的 NAS，对外分享交给柚舟Cloud。

**谁适合用它**

- 已有飞牛、群晖、Unraid 或其他 NAS，缺一个安全的公网分享入口
- 受够了 NextCloud 的臃肿，想要一个解压即用、不依赖 MySQL 和 Redis 的方案
- 用过 Cloudreve，觉得够轻但安全和更新频率不满意
- 自建了博客或个人站点，需要一个干净的文件分享通路

> 官方网站：https://yoliark.com &nbsp;·&nbsp; 作者：yolico（柚柠可）

---

## 目录

- [特性](#特性)
- [快速开始](#快速开始)
- [数据库支持](#数据库支持)
- [项目结构](#项目结构)
- [API 概览](#api-概览)
- [安全设计](#安全设计)
- [性能设计](#性能设计)
- [已知局限](#已知局限)
- [许可](#许可)

---

## 特性

### 核心功能

| 功能 | 说明 |
|------|------|
| 文件管理 | 上传、下载、预览、重命名、移动、复制、删除（批量操作支持） |
| 分片上传 | 大文件自动分割上传，断点续传，MD5 校验，文件名冲突解决 |
| 全文搜索 | SQLite FTS5 / MySQL FULLTEXT / PostgreSQL ILIKE，三级数据库自动适配 |
| 分享管理 | 生成分享链接，支持密码保护、过期时间、下载次数限制、访问统计 |
| 文件夹系统 | 多层目录嵌套，自定义顺序拖拽排序，面包屑导航 |
| 文件标签 | 按颜色分类的标签系统，支持搜索过滤 |
| 收藏夹 | 快速访问常用文件 |
| 回收站 | 自动过期清理，保留天数可配置 |
| 预览引擎 | 图片、视频、音频、PDF、Office 文档、代码高亮（按需加载） |

### 扩展能力

- **AI Agent 集成**：内置 AI 对话功能，可对接任意兼容 OpenAI SDK 的大模型（如 DeepSeek、通义千问），支持文件操作、信息查询、对话标题自动生成
- **云存储后端**：支持挂载 S3 兼容存储（如 MinIO、阿里云 OSS、Backblaze B2），数据可在本地与云端间迁移
- **系统监控**：实时磁盘用量、缓存命中率、操作日志审计、性能基准测试

### 部署体验

- **零配置**：下载、解压、打开浏览器，三步完成。不需要安装 MySQL、不需要配 Redis、
  不需要折腾任何外部依赖。SQLite 模式是默认策略，不是简化版
- **数据库可选**：SQLite、MySQL、PostgreSQL 三种引擎安装时可选，
  SchemaManager 自动适配表结构，无 Vendor Lock-in
- PWA 支持：可添加到手机桌面，离线图标
- 响应式布局：手机和桌面端均有适配

---

## 快速开始

### 环境要求

- PHP 8.1+
- SQLite3 / MySQL 5.7+ / PostgreSQL 12+
- `mod_rewrite`（Apache）或等效 URL 重写（Nginx）
- 推荐扩展：`bcmath`、`gd`、`mbstring`、`curl`、`fileinfo`、`brotli`（非必需）

### 安装

```bash
# 1. 解压到 Web 目录
# 2. 确保以下目录可写
chmod -R 755 storage/ uploads/ data/

# 3. Nginx 配置参考（Apache .htaccess 已内置）
```

**Nginx 配置**

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/yoliark;
    index index.php;

    # 拒绝直接访问存储目录
    location ^~ /storage/ { deny all; }
    location ^~ /uploads/  { deny all; }
    location ^~ /data/     { deny all; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 访问

浏览器打开 `http://your-domain.com/install.php`，按引导完成安装：

1. 选择数据库类型（推荐 SQLite 快速起步）
2. 创建管理员账户
3. 按提示完成目录访问保护检测

安装后访问 `index.php?page=login` 登录。

---

## 数据库支持

一套代码兼容三种数据库引擎，无需修改代码：

| 引擎 | 默认 | 适用场景 |
|------|------|----------|
| SQLite | ✓ | 单用户、低并发、快速部署 |
| MySQL | — | 多用户、高并发、已有 MySQL 服务 |
| PostgreSQL | — | 对数据完整性有严格要求的场景 |

每种引擎的 schema 由 `SchemaManager` 自动适配，全文搜索也会根据引擎切换到对应的实现（FTS5 / FULLTEXT / ILIKE）。

---

## 项目结构

```
YoliArkCloud/
├── index.php              # 应用入口（页面路由）
├── install.php            # 安装程序
├── api.php                # API 路由层
├── bootstrap/
│   └── app.php            # 应用初始化（自动加载、Session 配置、常量定义）
├── app/
│   ├── Controllers/       # 控制器层
│   │   ├── Auth/          # 登录、注册、个人资料
│   │   ├── File/          # 文件列表、上传、下载、操作
│   │   ├── Share/         # 分享管理
│   │   ├── Trash/         # 回收站
│   │   ├── System/        # 配置、日志、监控、AI Agent、云存储
│   │   └── BaseController.php
│   ├── Services/          # 业务逻辑层
│   │   ├── FileManagerService.php
│   │   ├── AuthService.php
│   │   ├── ShareService.php
│   │   ├── TrashService.php
│   │   ├── CloudStorageService.php
│   │   ├── CloudSyncService.php
│   │   ├── ThumbnailService.php
│   │   ├── AudioCoverService.php
│   │   ├── AIAgentService.php
│   │   └── ...
│   ├── Models/            # Active Record 模型层
│   │   ├── File.php
│   │   ├── User.php
│   │   ├── Share.php
│   │   ├── TrashItem.php
│   │   └── OperationLog.php
│   └── Core/              # 核心基础设施
│       ├── Security.php       # 安全头、CSRF、XSS、路径验证、速率限制
│       ├── Database.php       # PDO 封装 + 三级查询缓存 + 全文搜索
│       ├── Config.php         # 配置管理
│       ├── Model.php          # 轻量 Active Record 基类
│       ├── SchemaManager.php  # 跨数据库 Schema 管理
│       ├── AdaptiveRateLimiter.php  # Token Bucket 自适应限流
│       ├── ErrorHandler.php
│       ├── AsyncLogger.php    # 异步日志缓冲写入
│       ├── CacheWarmer.php
│       ├── PerformanceMonitor.php
│       └── ServerBenchmark.php
├── framework/             # 框架基础设施
│   ├── Support/
│   │   └── helpers.php
│   └── Exception/
├── views/                 # 视图层
│   ├── layouts/           # 布局模板（head/foot/scripts）
│   └── pages/             # 页面模板 & 内联脚本
├── assets/
│   ├── css/
│   │   ├── style.css           # 主样式（HarmonyOS + Fluent Design）
│   │   └── fluent-share.css
│   ├── js/
│   │   ├── core.js             # 基础设施 API 通信
│   │   ├── app.js              # SPA 交互逻辑
│   │   ├── files.js            # 文件列表渲染
│   │   ├── upload.js           # 上传模块
│   │   ├── share.js            # 分享交互
│   │   ├── preview.js          # 预览引擎
│   │   ├── ai.js               # AI 对话
│   │   ├── pages.js            # 设置等页面脚本
│   │   ├── cloud.js            # 云存储配置
│   │   ├── utils.js            # 工具函数
│   │   └── theme.js            # 主题切换
│   └── img/
├── storage/               # 运行时数据（应配置拒绝 Web 访问）
│   ├── data/              # 数据库文件、配置、日志
│   ├── files/             # 用户文件存储
│   └── trash/             # 回收站
├── uploads/               # 上传临时目录
├── data/                  # 数据目录（兼容旧结构）
├── sw.js                  # Service Worker（PWA）
├── manifest.json          # PWA Manifest
├── robots.txt
└── cron_storage_check.php # 定时任务：存储用量校准
```

### 架构分层

```
HTTP Request
    │
    ▼
index.php / api.php     ← 路由层
    │
    ▼
Controllers              ← 参数校验、权限验证、响应格式化
    │
    ▼
Services                 ← 业务逻辑、文件操作、分享管理
    │
    ▼
Models / Database        ← 数据持久化（Active Record / 裸 PDO）
    │
    ▼
SQLite / MySQL / PgSQL
```

---

## API 概览

所有 API 通过 `index.php?action={action}` 访问，响应格式为 JSON。

### 认证

| Action | 方法 | 说明 |
|--------|------|------|
| `login` | POST | 用户登录 |
| `logout` | POST | 退出登录 |
| `register` | POST | 注册 |
| `change_password` | POST | 修改密码 |
| `update_profile` | POST | 更新个人资料 |

### 文件操作

| Action | 方法 | 说明 |
|--------|------|------|
| `list_files` | GET | 获取文件列表（支持分页、排序） |
| `search` | GET | 全文搜索 |
| `file_info` | GET | 文件详情 |
| `breadcrumb` | GET | 面包屑路径 |
| `storage_info` | GET | 存储用量 |
| `create_folder` | POST | 创建目录 |
| `rename` | POST | 重命名 |
| `move` / `copy` | POST | 移动 / 复制 |
| `delete` / `batch_delete` | POST | 删除 |
| `toggle_favorite` | POST | 切换收藏 |
| `upload` | POST | 上传文件 |
| `upload_chunk` | POST | 分片上传 |
| `download` | GET | 下载文件 |
| `preview` | GET | 预览文件 |
| `thumbnail` | GET | 获取缩略图 |

### 分享

| Action | 方法 | 说明 |
|--------|------|------|
| `create_share` | POST | 创建分享链接 |
| `list_shares` | GET | 分享列表 |
| `share_info` | GET | 公开分享信息 |
| `share_download` | GET | 通过分享下载 |

### 系统

| Action | 说明 |
|--------|------|
| `get_config` / `update_config` | 系统配置 |
| `system_info` / `disk_info` | 系统信息 / 磁盘状态 |
| `list_logs` | 操作日志 |
| `ai_agent_chat` / `ai_agent_chat_stream` | AI 对话 |
| `cloud_storage_*` | 云存储配置与管理 |

---

## 安全设计

柚舟Cloud在安全方面的投入超过功能开发，以下是核心安全机制的摘要：

### 传输层与浏览器安全

- **CSP**：限制脚本、样式、字体、图片、连接的目标源
- **安全响应头**：`X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`、`Permissions-Policy`、`Cross-Origin-Opener-Policy`
- **Server 信息隐藏**：移除 `X-Powered-By`、`Server` 头

### 认证与会话

- **Session 固定攻击防护**：登录时完全销毁旧 Session，重新生成 ID，附带浏览器指纹验证
- **CSRF Token**：覆盖所有写操作的 POST/PUT/DELETE 请求，支持 JSON body 提取
- **bcrypt 加密**：密码哈希 cost=12，分享密码同样加密存储
- **速率限制**：登录失败锁定（文件锁 + JSON 持久化）

### 文件安全

- **路径穿越防御**：`resolvePath()` 同时处理空字节、`../`、`realpath()` 边界检测、未创建路径的目录级验证
- **上传验证**：三重过滤——扩展名黑名单 → 扩展名白名单 → 文件内容扫描（PHP 签名检测 + finfo MIME 校验）
- **存储隔离**：`storage/files/`、`storage/trash/`、`uploads/` 均通过 `.htaccess` 和 Nginx 配置禁止 Web 访问

### 反爬与滥用

- **无直链暴露**：所有下载和预览均经过应用层路由（`index.php?action=download`），不暴露真实文件路径，从源头杜绝迅雷等工具直接拉取
- **Bot Challenge**：JavaScript Cookie 验证，自动化工具（python-requests、curl 等）UA 检测
- **自适应速率限制**：基于 Token Bucket 算法，请求成本按文件大小分档（0.1~5 tokens），服务器基准测试自动校准配额

### 主动探测

- **安装引导**：安装完成后自动检测各存储目录是否可通过 URL 直接访问，并提供 Nginx 配置参考

---

## 性能设计

### 自适应速率限制（Adaptive Rate Limiting）

Token Bucket 算法实现，核心参数（max_tokens、refill_rate）由 `ServerBenchmark` 在安装时自动校准：

- 根据磁盘 I/O、哈希计算、数据库读写性能生成 `performance_score`
- 高性能服务器获得更高并发配额，低配 VPS 自动降级
- 支持按文件大小分档：tiny（256KB）、small（1MB）、medium（10MB）、large（100MB）
- 历史滑动窗口（180 秒）动态调整 token 消耗系数

### 数据库查询缓存

三级缓存架构（hot / warm / cold），基于访问频率自动升降级：

- Hot：最近频繁访问的查询（最多 100 条）
- Warm：曾经热访问的查询（最多 200 条）
- Cold：待淘汰的查询（总计上限 500 条）
- 缓存按表名标签自动失效，写入操作会清空关联表的缓存

### SQLite 深度优化

```sql
PRAGMA journal_mode=WAL;      -- 写前日志，避免读写锁冲突
PRAGMA synchronous=NORMAL;    -- 平衡安全与性能
PRAGMA cache_size=-16000;     -- 16MB 页面缓存
PRAGMA temp_store=MEMORY;     -- 临时表放内存
PRAGMA mmap_size=268435456;   -- 256MB 内存映射
PRAGMA page_size=4096;        -- 4KB 页面大小
PRAGMA auto_vacuum=INCREMENTAL; -- 增量回收空间
```

### 响应压缩

自动协商客户端支持的压缩算法，优先级：Brotli > Gzip > Deflate，内容小于 1KB 不压缩避免浪费。

### 异步日志

日志写入先存入内存缓冲区（50 条），请求结束时一次性写入磁盘，避免每次日志调用都触发 I/O。

---

## 已知局限

- 前端目前是纯 JavaScript，未使用 TypeScript 或模块打包工具（Webpack/Vite），JS 文件通过全局变量共享状态
- `FileManagerService.php` 和 `AIAgentService.php` 单文件体积较大，后续可以考虑拆分
- 部分第三方资源通过 CDN 加载（Font Awesome、highlight.js 等），离线场景受限
- 无单元测试 / 集成测试覆盖
- 界面语言目前仅支持中文

---

## 许可

柚舟Cloud 使用 **GNU Affero General Public License v3.0** 发布。

你可以自由使用、修改和分发本软件，但任何以网络服务形式向用户提供修改版的行为，
都必须同时公开修改后的完整源代码。本软件按「原样」提供，不附带任何明示或暗示的保证。

---

**柚舟Cloud** —— 你的 NAS 缺的那层公网安全壳。
#