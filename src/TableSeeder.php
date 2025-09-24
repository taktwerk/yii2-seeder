<?php

namespace taktwerk\seeder;

use taktwerk\seeder\helpers\CreatedAtUpdatedAt;
use Faker\Factory;
use Faker\Generator;
use Yii;
use yii\base\NotSupportedException;
use yii\db\Exception;
use yii\db\Migration;
use yii\helpers\ArrayHelper;

/**
 * Class TableSeeder
 * @package console\seeder
 *
 * @property Generator|Factory $faker
 * @property boolean $skipTruncateTables
 */
abstract class TableSeeder extends Migration
{
    use CreatedAtUpdatedAt;

    public $faker;
    public $skipTruncateTables = false;
    protected $insertedColumns = [];
    protected $batch = [];

    /**
     * TableSeeder constructor.
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->faker = Factory::create(str_replace('-', '_', Yii::$app->language));

        parent::__construct($config);
    }

    /**
     * @throws Exception
     * @throws NotSupportedException
     */
    public function __destruct()
    {
        if (!$this->skipTruncateTables) {
            $this->disableForeignKeyChecks();
            foreach ($this->batch as $table => $values) {
                $this->truncateTable($table);
            }
            $this->enableForeignKeyChecks();
        }

        foreach ($this->batch as $table => $values) {
            $total = 0;
            foreach ($values as $columns => $rows) {
                $total += count($rows);
                parent::batchInsert($table, explode(',', $columns), $rows);
            }
            echo "      $total row" . ($total > 1 ? 's' : null) . " inserted  in $table" . "\n";
        }

        $this->checkMissingColumns($this->insertedColumns);
    }

    abstract function run($count = 10, $skipTruncate = false);

    /**
     * Override truncateTable to add CASCADE for PostgreSQL
     * @param string $table
     */
    public function truncateTable($table)
    {
        if ($this->db->driverName === 'pgsql') {
            // Use CASCADE for PostgreSQL to handle foreign key constraints
            echo "    > truncate table $table with CASCADE...";
            $time = microtime(true);
            $this->db->createCommand("TRUNCATE TABLE " . $this->db->quoteTableName($table) . " CASCADE")->execute();
            echo ' done (time: ' . sprintf('%.3f', microtime(true) - $time) . "s)\n";
        } else {
            // Use parent method for other databases
            parent::truncateTable($table);
        }
    }

    /**
     * @throws Exception
     * @throws NotSupportedException
     */
    public function disableForeignKeyChecks()
    {
        // PostgreSQL doesn't support disabling triggers without superuser privileges
        // So we skip this for PostgreSQL
        if ($this->db->driverName !== 'pgsql') {
            $this->db->createCommand()->checkIntegrity(false)->execute();
        }
    }

    /**
     * @throws Exception
     * @throws NotSupportedException
     */
    public function enableForeignKeyChecks()
    {
        // PostgreSQL doesn't support enabling triggers without superuser privileges
        // So we skip this for PostgreSQL
        if ($this->db->driverName !== 'pgsql') {
            $this->db->createCommand()->checkIntegrity(true)->execute();
        }
    }

    public function insert($table, $columns)
    {
        $columnNames = $this->db->getTableSchema($table)->columnNames;

        $this->generate();

        if (!array_key_exists('created_at', $columns) && in_array('created_at', $columnNames, true)) {
            $columns['created_at'] = $this->createdAt;
        }

        if (!array_key_exists('updated_at', $columns) && in_array('updated_at', $columnNames, true)) {
            $columns['updated_at'] = $this->updatedAt;
        }

        $this->insertedColumns[$table] = ArrayHelper::merge(
            array_keys($columns),
            isset($this->insertedColumns[$table]) ? $this->insertedColumns[$table] : []
        );

        $this->batch[$table][implode(',', array_keys($columns))][] = array_values($columns);
    }

    /**
     * {@inheritDoc}
     */
    public function batchInsert($table, $columns, $rows)
    {
        $columnNames = $this->db->getTableSchema($table)->columnNames;

        $this->generate();

        if (!in_array('created_at', $columns, true) && in_array('created_at', $columnNames, true)) {
            $columns[] = 'created_at';
            for ($i = 0, $max = count($rows); $i < $max; $i++) {
                $rows[$i][] = $this->createdAt;
            }
        }

        if (!in_array('updated_at', $columns, true) && in_array('updated_at', $columnNames, true)) {
            $columns[] = 'updated_at';
            for ($i = 0, $max = count($rows); $i < $max; $i++) {
                $rows[$i][] = $this->updatedAt;
            }
        }

        $this->insertedColumns[$table] = ArrayHelper::merge(
            $columns,
            isset($this->insertedColumns[$table]) ? $this->insertedColumns[$table] : []
        );

        foreach ($rows as $row) {
            $this->batch[$table][implode(',', $columns)][] = $row;
        }
    }

    protected function checkMissingColumns($insertedColumns)
    {
        $missingColumns = [];

        foreach ($insertedColumns as $table => $columns) {
            $tableColumns = $this->db->getTableSchema($table)->columns;

            foreach ($tableColumns as $tableColumn) {
                if (!$tableColumn->autoIncrement && !in_array($tableColumn->name, $columns, true)) {
                    $missingColumns[$table][] = [$tableColumn->name, $tableColumn->dbType];
                }
            }
        }

        if (count($missingColumns)) {
            echo "    > " . str_pad(' MISSING COLUMNS ', 70, '#', STR_PAD_BOTH) . "\n";
            foreach ($missingColumns as $table => $columns) {
                echo "    > " . str_pad("# TABLE: $table", 69, ' ') . "#\n";
                foreach ($columns as [$tableColumn, $type]) {
                    echo "    > " . str_pad("#    $tableColumn => $type", 69, ' ') . "#\n";
                }
            }
            echo "    > " . str_pad('', 70, '#') . "\n";
        }
    }

    public static function create()
    {
        return new static();
    }

    public function getUniqueManyManyId($count, $numberOfFK) : array {
        $allFKList = [];

        for ($i=1; $i <= $count; $i++) { 
            $allFKList[$i] = $this->fillFKArray($count, $numberOfFK, $allFKList);
        }

        return $allFKList;
    }

    private function fillFKArray($count, $numberOfFK, $allFKList) {
        $fkSequence = [];
        for ($j=0; $j < $numberOfFK; $j++) { 
            $fkSequence[$j] = $this->faker->numberBetween(1, $count);
        }
        
        if ($this->isFKSequenceExist($fkSequence, $allFKList)) {
            $fkSequence = $this->fillFKArray($count, $numberOfFK, $allFKList);
        }

        return $fkSequence;
    }

    private function isFKSequenceExist($FKArray, $allFKList) {
        foreach ($allFKList as $item) {
            if (join(',', $FKArray) == join(',', $item)) {
                return true;
            }
        }

        return false;
    }
}
