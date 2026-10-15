<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\infrastructure;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\SystemUpdatePdoFactory;
use app\modules\official_platform\exception\OfficialPlatformException;
use PDO;

/** 在维护模式中导出当前实例前缀下的InnoDB表；私有文件包含敏感数据。 */
final readonly class DatabaseBackup
{
    /** @param SystemUpdatePdoFactory $connections 专用PDO，不依赖业务模型 */
    public function __construct(private SystemUpdatePdoFactory $connections)
    {
    }

    /**
     * @param MigrationDatabaseConfiguration $configuration 当前连接
     * @param string $destination 固定私有备份路径
     * @return void 失败禁止覆盖代码
     */
    public function create(MigrationDatabaseConfiguration $configuration, string $destination): void
    {
        $pdo = $this->connections->connect($configuration);
        if ($configuration->adapter === 'sqlite') {
            $pdo->exec('VACUUM INTO ' . $pdo->quote($destination));
            chmod($destination, 0600);
            return;
        }
        $tables = $pdo->query('SHOW TABLE STATUS')->fetchAll(PDO::FETCH_ASSOC);
        $tables = array_values(array_filter($tables, static fn (array $table): bool => str_starts_with($table['Name'], $configuration->tablePrefix)));
        foreach ($tables as $table) {
            if ($table['Engine'] !== 'InnoDB') {
                throw new OfficialPlatformException('DATABASE_BACKUP_FAILED');
            }
        }
        $handle = fopen($destination, 'x');
        if ($handle === false || $tables === []) {
            throw new OfficialPlatformException('DATABASE_BACKUP_FAILED');
        }
        chmod($destination, 0600);
        $write = static function (string $text) use ($handle): void {
            if (fwrite($handle, $text) !== strlen($text)) {
                throw new OfficialPlatformException('DATABASE_BACKUP_FAILED');
            }
        };
        try {
            $sqlMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE=" . $pdo->quote($sqlMode) . ";\n");
            foreach ($tables as $table) {
                $name = '`' . str_replace('`', '``', $table['Name']) . '`';
                $schema = $pdo->query('SHOW CREATE TABLE ' . $name);
                $create = $schema->fetch(PDO::FETCH_NUM)[1];
                $schema->closeCursor();
                $write('DROP TABLE IF EXISTS ' . $name . ";\n" . $create . ";\n");
                $statement = $pdo->query('SELECT * FROM ' . $name);
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    $columns = implode(',', array_map(static fn (string $key): string => '`' . str_replace('`', '``', $key) . '`', array_keys($row)));
                    $values = implode(',', array_map(static fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values($row)));
                    $write('INSERT INTO ' . $name . '(' . $columns . ') VALUES (' . $values . ");\n");
                }
                $statement->closeCursor();
            }
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
            $pdo->commit();
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new OfficialPlatformException('DATABASE_BACKUP_FAILED');
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fclose($handle);
        }
    }
}
