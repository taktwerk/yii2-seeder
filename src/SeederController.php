<?php

namespace taktwerk\seeder;

use Yii;
use yii\console\Controller;
use yii\console\Exception;
use yii\console\ExitCode;
use yii\db\ActiveRecord;
use yii\db\ColumnSchema;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use yii\helpers\Inflector;

/**
 * Class SeederController
 * @package taktwerk\seeder
 *
 * @property string $seederPath
 * @property string $seederNamespace
 * @property string $tablesPath
 * @property string $tableSeederNamespace
 * @property string $modelNamespace
 * @property string $templateFile
 * @property string $databaseFile
 * @property ActiveRecord $model
 */
class SeederController extends Controller
{

    /** @var string the default command action. */
    public $defaultAction = 'seed';

    public $seederPath = '@app/seeder';
    public $seederNamespace = 'app\seeder';
    public $tablesPath = '@app/seeder/tables';
    public $tableSeederNamespace = 'app\seeder\tables';
    public $seedFolder = 'seeds';
    public $modelNamespace = 'app';
    public $templateFile = '@taktwerk/seeder/views/createTableSeeder.php';
    public $databaseFile = '@taktwerk/seeder/views/DatabaseSeeder.php';
    public $numRows = 10;
    public $skipTruncate = false;

    /** Seeder on YII_ENV === 'prod' */
    public $runOnProd;

    /** @var ActiveRecord */
    protected $model = null;

    public function beforeAction($action)
    {
        $this->seederPath = $this->seederPath.DIRECTORY_SEPARATOR.$this->seedFolder;
        $this->modelNamespace = $this->seederNamespace;
        $this->seederNamespace = $this->seederNamespace.'\\'.$this->seedFolder;
        $this->tablesPath = $this->seederPath.'/tables';
        $this->tableSeederNamespace = $this->seederNamespace.'\tables';
        return parent::beforeAction($action);
    }

    public function options($actionID)
    {
        return [
            'runOnProd', 
            'modelNamespace', 
            'seederPath', 
            'tableSeederNamespace', 
            'seederNamespace', 
            'numRows', 
            'tablesPath',
            'seedFolder',
            'skipTruncate'
        ];
    }

    protected function getClass($path, $end = "\n")
    {
        if (class_exists($path)) {
            return new $path;
        }

        $this->stdout("Class $path not exists. $end");
        return null;
    }

    public function actionSeed($name = null, $numRows = 10, $skipTruncate = false)
    {
        if (YII_ENV_PROD && !$this->runOnProd) {
            $this->stdout("YII_ENV is set to 'prod'.\nUse seeder is not possible on production systems. use '--runOnProd' to ignore it.\n");
            return ExitCode::OK;
        }

        $explode = explode(':', $name);
        $name = $explode[0];
        $function = $explode[1] ?? null;
        
        if ($this->skipTruncate == false && $skipTruncate != false) {
            $this->skipTruncate = $skipTruncate;
        }

        if ($this->numRows == 10 && $numRows != 10) {
            $this->numRows = $numRows;
        }

        if ($name) {
            $seederClass = "$this->tableSeederNamespace\\{$name}TableSeeder";
            if ($seeder = $this->getClass($seederClass)) {
                $seeder->{$function ?? 'run'}($this->numRows, $this->skipTruncate);
            }
        } else {
            $databaseClass = "$this->seederNamespace\\DatabaseSeeder";
            if ($database = $this->getClass($databaseClass)) {
                $database->{$function ?? 'run'}($this->numRows, $this->skipTruncate);
            }
        }
    }

    /**
     * Creates a new seeder.
     *
     * This command creates a new seeder using the available seeder template.
     * After using this command, developers should modify the created seeder
     * skeleton by filling up the actual seeder logic.
     *
     * ```
     * yii seeder/create model_name
     * ```
     *
     * For example:
     *
     * ```
     * yii seeder/create user
     * ```
     * or
     * ```
     * yii seeder/create example/user
     * ```
     * if User's Model directory is "common\models\example\User"
     *
     * @param string $modelName the name of the new seeder. This should only contain
     * letters, digits, underscores and/or slashes.
     *
     * @return int ExitCode::OK
     * @throws Exception if the name argument is invalid.
     */
    public function actionCreate($modelName = null)
    {
        $this->createDataBaseSeederFile();
        $modelNamespace = $this->modelNamespace;

        if (strpos($modelName, '/')) {
            $_ = explode('/', $modelName);
            $modelName = ucfirst(array_pop($_));
            $modelNamespace .= '\\' . implode('\\', $_);
            $file = "$modelNamespace\\$modelName";
        } else {
            $modelName = ucfirst($modelName);
            $file = "$modelNamespace\\$modelName";
        }

        $this->model = $this->getClass($file);

        if ($this->model === null) {
            return ExitCode::OK;
        }

        $_ = explode('\\', get_class($this->model));
        $className = last($_) . 'TableSeeder';

        $file = Yii::getAlias("$this->tablesPath/$className.php");
        if ($this->confirm("Create new seeder '$file'?")) {
            $content = $this->generateSeederSourceCode([
                'className' => $className,
                'namespace' => $this->tableSeederNamespace,
                'table' => ($this->model)::tableName(),
                'fields' => $this->generateFields(),
                'modelName' => array_pop($_),
                'modelNamespace' => implode('\\', $_)
            ]);
            FileHelper::createDirectory(Yii::getAlias($this->tablesPath));

            if (!file_exists($file) || $this->confirm("\n'$className' already exists, overwrite?\nAll data will be lost irreversibly!")) {
                file_put_contents($file, $content, LOCK_EX);
                $this->stdout("New seeder created successfully.\n", Console::FG_GREEN);
            }
        }


        return ExitCode::OK;
    }

    protected function generateSeederSourceCode($params)
    {
        return $this->renderFile(Yii::getAlias($this->templateFile), $params);
    }

    public function generateFields()
    {
        $schema = $this->model->tableSchema;

        $columns = $schema->columns;
        $foreignKeys = $schema->foreignKeys;
        $fields = [];

        foreach ($foreignKeys as $fk_str => $foreignKey) {
            unset($foreignKeys[$fk_str]);
            $table = array_shift($foreignKey);
            $column = array_keys($foreignKey)[0];

            $errorMsg = "Foreign Key for '$column' column will be ignored and a common column will be generated.\n";

            $model = $this->getClass($this->modelNamespace . '\\' . Inflector::camelize($table), $errorMsg);
            $foreignKeys[$column] = $model;
        }

        /* @var ColumnSchema $data */
        foreach ($columns as $column => $data) {
            if (in_array($column, ['created_at', 'updated_at']) || $data->autoIncrement) continue;

            $foreign = null;
            $ref_table_id = null;
            $faker = null;

            if (isset($foreignKeys[$column])) {
                $foreign = $foreignKeys[$column];
                $ref_table_id = $foreign->tableSchema->primaryKey[0];
            }

            switch ($data->name) {
                case 'name':
                    $faker = 'name';
                    break;
                case 'description':
                    $faker = 'realText()';
                    break;
                case 'business_name':
                    $faker = 'company';
                    break;
                case 'cnpj':
                    $faker = 'cnpj()';
                    break;
                case 'cpf':
                    $faker = 'cpf()';
                    break;
                case 'email':
                    $faker = 'email';
                    break;
            }

            if (!$faker) {
                switch ($data->type) {
                    case 'integer':
                    case 'smallint':
                    case 'tinyint':
                        if ($data->dbType === 'tinyint(1)') {
                            $faker = 'boolean';
                            break;
                        }
                    case 'mediumint':
                    case 'int':
                    case 'bigint':
                        $faker = 'numberBetween(1, $count)';
                        break;
                    case 'date':
                        $faker = 'date()';
                        break;
                    case 'datetime':
                    case 'timestamp':
                        $faker = 'dateTime()->format("Y-m-d H:i:s")';
                        break;
                    case 'year':
                        $faker = 'year()';
                        break;
                    case 'time':
                        $faker = 'time()';
                        break;
                    default:
                        $faker = 'text';
                }
            }

            $fields[$column] = (object)[
                'faker' => $faker,
                'foreign' => $foreign,
                'ref_table_id' => $ref_table_id
            ];
        }
        return (object)$fields;
    }

    protected function createDataBaseSeederFile()
    {
        $file = Yii::getAlias($this->seederPath . '/DatabaseSeeder.php');

        if (!file_exists($file)) {
            FileHelper::createDirectory(Yii::getAlias($this->seederPath));
            $content = $this->renderFile($this->databaseFile, [
                'namespace' => $this->seederNamespace,
            ]);

            $this->stdout("\nDatabaseSeeder created in $file\n");

            file_put_contents($file, $content, LOCK_EX);
        }
    }
}
