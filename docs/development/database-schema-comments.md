# 数据库中文注释规范

## 适用范围

核心业务表、关系表、安装与升级记录，以及插件自有表，均须为表和全部字段设置中文 `COMMENT`。字段标识保持英文。ORM 字段说明、PHPDoc 和接口文档分别服务于不同边界，不能代替数据库注释。

## 注释内容

| 字段类别 | 说明要求 | 示例 |
| --- | --- | --- |
| 普通字段 | 明确业务对象和含义 | 会员登录手机号码 |
| 状态和类型 | 写明当前确定的值及含义，不虚构未实现状态 | 审核状态：pending待审核，approved已通过，rejected已拒绝 |
| 金额 | 整数最小货币单位、必要时说明符号 | 资金变动金额，整数最小货币单位；正数入账，负数支出 |
| 数量和性能 | 写明核数、MiB、GiB、Mbps、MB/s、MHz、毫秒等单位 | 内存容量，单位MiB |
| 关联字段 | 关联对象、NULL/0 等特殊值含义 | 首次交付订单单位标识，关联order_item_units.id；历史无来源服务可为NULL |
| JSON/快照 | 内容及用途、是否不可变 | 不可变销售配置及价格政策快照，JSON对象 |
| 敏感字段 | 标明密文、检索摘要或脱敏用途 | 个人身份证件号码密文 |

注释不得包含真实用户资料、数据库密码、令牌、支付密钥及凭证样例。只读展示、预留处理状态和已经落地的办理功能应在业务文档中分开标记，数据库注释不构成功能已经可用的声明。

## 新增和修改结构

使用同一套 Phinx 迁移支持首次安装与后续升级。新表和每个新增字段必须直接携带注释，例如：

```php
$this->table('example_records', [
    'id' => false,
    'primary_key' => 'id',
    'engine' => 'InnoDB',
    'comment' => '示例业务记录',
])
    ->addColumn('id', 'integer', [
        'signed' => false, 'identity' => true,
        'comment' => '业务记录主键标识',
    ])
    ->addColumn('amount_minor', 'biginteger', [
        'signed' => false, 'default' => 0,
        'comment' => '业务金额，整数最小货币单位；CNY单位为分',
    ])
    ->create();
```

必须涵盖自动主键及关系表字段。修改字段同步保留或更新注释；涉及状态、单位和关联语义变化时，先完成兼容设计，再新增前向迁移。已经执行或发布过的迁移不可改写。

MySQL 的 `MODIFY COLUMN` 必须包含需要保留的全部原属性，缺少的属性不会自动继承。仅补注释时，应保留原字段定义，不凭 ORM 转换结果重新构建类型。[MySQL ALTER TABLE 文档](https://dev.mysql.com/doc/refman/8.0/en/alter-table.html)

## 现有结构补齐

迁移 `20261003000300_add_core_database_comments.php` 固化了当前 63 张核心表、581 个字段的中文释义，包括迁移版本记录本身。首次安装执行完整序列后也会得到这些注释。

- 只补充空白或不含中文的核心注释；保留已有中文注释。
- 使用配置的 `DB_PREFIX` 定位明确的核心表，不处理清单外的插件/客户表；核心表中的客户扩展字段保持原样，由其维护者提供释义。
- 从 `SHOW CREATE TABLE` 保留原字段完整定义，只更新注释。预先解析全部目标结构，核心表/字段缺失或无法完整解析时停止，避免猜测客户结构。
- 每张表合并一次 `ALTER TABLE`，指定 `ALGORITHM=INPLACE, LOCK=NONE`，不自动降级为复制整表。仍可能短暂取得元数据锁；等待上限为 5 秒，执行后恢复原会话设置。
- MySQL DDL 可能逐表提交。中断后已经补齐的中文注释会跳过，重新执行可处理剩余表；失败时不伪装整库原子回滚。排除长事务、权限或结构问题后，通过原升级命令重试。
- SQLite 没有原生表/字段注释，本迁移在 SQLite 中不修改结构；实际注释验收使用 MySQL。

该迁移是历史补齐快照。以后新增表/字段必须在其新迁移中直接提供注释，不能继续修改该文件或依赖它自动生成释义。

## 检查与升级

迁移前备份数据库，保留表/字段属性、索引、外键、行数、业务数据校验摘要和金额汇总基线。迁移后对比，原有业务数据及结构应保持一致。使用受控升级命令时，除新增迁移版本记录外，系统还会正常新增一条 `system_update_runs` 升级执行记录；对账必须单独确认这两项预期变化，不能忽略整个记录表。

```bash
cd backend
php webman system:migration-status
php webman system:migrate --force
php webman system:migration-status
HOWSTACK_MYSQL_TEST=1 vendor/bin/phpunit tests/integration/DatabaseSchemaCommentsMigrationTest.php
```

MySQL 检查示例（把前缀替换为当前 `DB_PREFIX`，查询结果仅含结构信息）：

```sql
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND LEFT(TABLE_NAME, CHAR_LENGTH('hlyq_')) = 'hlyq_'
  AND NOT REGEXP_LIKE(COLUMN_COMMENT, '[一-龥]');

SELECT TABLE_NAME, TABLE_COMMENT
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND LEFT(TABLE_NAME, CHAR_LENGTH('hlyq_')) = 'hlyq_'
  AND NOT REGEXP_LIKE(TABLE_COMMENT, '[一-龥]');
```

空结果表示所选前缀下的注释均包含常用汉字。若包含第三方表或客户扩展字段，应按各自归属补齐，不能通过删表、删字段或覆盖数据库来消除检查结果。
