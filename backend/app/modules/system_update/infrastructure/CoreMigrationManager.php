<?php

declare(strict_types=1);
namespace app\modules\system_update\infrastructure;

use app\modules\website\infrastructure\HistoricalThemeLayout;
use Phinx\Migration\Manager;
use Phinx\Migration\MigrationInterface;

/** 历史迁移使用冻结的主题目录契约，新迁移使用当前契约。 */
final class CoreMigrationManager extends Manager
{
    public function executeMigration(string $name, MigrationInterface $migration, string $direction = MigrationInterface::UP, bool $fake = false): void
    {
        if ($migration->getVersion() <= 20261011001200) {
            HistoricalThemeLayout::run(fn () => parent::executeMigration($name, $migration, $direction, $fake));
            return;
        }
        parent::executeMigration($name, $migration, $direction, $fake);
    }
}
