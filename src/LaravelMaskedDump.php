<?php

declare(strict_types=1);

namespace RamosHenrique\LaravelMaskedDumper;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Illuminate\Console\OutputStyle;
use RamosHenrique\LaravelMaskedDumper\TableDefinitions\TableDefinition;

class LaravelMaskedDump
{
    /** @var DumpSchema */
    protected $definition;

    /** @var OutputStyle */
    protected $output;

    public function __construct(DumpSchema $definition, OutputStyle $output)
    {
        $this->definition = $definition;
        $this->output = $output;
    }

    public function dump(): string
    {
        $tables = $this->definition->getDumpTables();

        $query = '';

        $query .= $this->disableStrictChecks($query);

        $overallTableProgress = $this->output->createProgressBar(count($tables));

        foreach ($tables as $tableName => $table) {
            $query .= "DROP TABLE IF EXISTS `$tableName`;" . PHP_EOL;
            $query .= $this->dumpSchema($table);

            if ($table->shouldDumpData()) {
                $query .= $this->lockTable($tableName);

                $query .= $this->dumpTableData($table);

                $query .= $this->unlockTable($tableName);
            }

            $overallTableProgress->advance();
        }

        $query .= $this->enableStrictChecks($query);

        return $query;
    }

    protected function transformResultForInsert($row, TableDefinition $table)
    {
        /** @var Connection $connection */
        $connection = $this->definition->getConnection()->getDoctrineConnection();

        return collect($row)->map(function ($value, $column) use ($connection, $table) {
            if ($columnDefinition = $table->findColumn($column)) {
                $value = $columnDefinition->modifyValue($value);
            }

            if ($value === null) {
                return 'NULL';
            }
            if ($value === '') {
                return '""';
            }

            return $connection->quote($value);
        })->toArray();
    }

    protected function dumpSchema(TableDefinition $table): string
    {
        $platform = $this->definition->getConnection()->getDoctrineSchemaManager()->getDatabasePlatform();

        $schema = new Schema([$table->getDoctrineTable()]);

        return implode(";", $schema->toSql($platform)) . ";" . PHP_EOL;
    }

    protected function lockTable(string $tableName): string
    {
        return "LOCK TABLES `$tableName` WRITE;" . PHP_EOL .
            "ALTER TABLE `$tableName` DISABLE KEYS;" . PHP_EOL;
    }

    protected function unlockTable(string $tableName): string
    {
        return "ALTER TABLE `$tableName` ENABLE KEYS;" . PHP_EOL .
            "UNLOCK TABLES;" . PHP_EOL;
    }

    protected function dumpTableData(TableDefinition $table): string
    {
        $query = '';

        $queryBuilder = $this->definition->getConnection()
            ->table($table->getDoctrineTable()->getName());

        $table->modifyQuery($queryBuilder);

        $queryBuilder->get()
            ->each(function ($row, $index) use ($table, &$query) {
                $row = $this->transformResultForInsert((array)$row, $table);
                $tableName = $table->getDoctrineTable()->getName();

                $query .= "INSERT INTO `" . $tableName. "` (`" . implode('`, `', array_keys($row)) . '`) VALUES ';
                $query .= "(";

                $firstColumn = true;
                foreach ($row as $value) {
                    if (!$firstColumn) {
                        $query .= ", ";
                    }
                    $query .= $value;
                    $firstColumn = false;
                }

                $query .= ");" . PHP_EOL;
            });

        return $query;
    }

    protected function disableStrictChecks(string $query): string
    {
        return "SET unique_checks=0;" . PHP_EOL .
            "SET FOREIGN_KEY_CHECKS=0;" . PHP_EOL;
    }

    protected function enableStrictChecks(string $query): string
    {
        return "SET unique_checks=1;" . PHP_EOL .
            "SET FOREIGN_KEY_CHECKS=1;" . PHP_EOL;
    }

}
