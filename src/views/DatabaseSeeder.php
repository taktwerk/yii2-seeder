<?php
/** @var string $namespace */
echo "<?php\n";
?>
namespace <?= $namespace ?>;

use taktwerk\seeder\TableSeeder;

class DatabaseSeeder extends TableSeeder
{

    public function run($count = 10)
    {
        //ModelTableSeeder::create()->run();
    }

}
