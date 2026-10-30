<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';
use App\Backup\{SqlBackupFormat,StorePackageFormat};
set_exception_handler(static function(Throwable $e): void { fwrite(STDERR,$e->getMessage() . PHP_EOL); exit(1); });
$schemas=json_decode(file_get_contents(__DIR__ . '/support/portable_backup_schema.json'),true,512,JSON_THROW_ON_ERROR);
$assert=static function(bool $value,string $why): void { if (!$value) throw new RuntimeException($why); };
$reject=static function(callable $work,string $why) use ($assert): void { try { $work(); } catch (RuntimeException) { return; } $assert(false,$why); };
$sqlFor=static function(array $schema): string {
    $body=SqlBackupFormat::HEADER . "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    foreach ($schema as $name=>$definition) $body.='DROP TABLE IF EXISTS `' . $name . "`;\n" . $definition['ddl'] . ";\n";
    $body.='SET FOREIGN_KEY_CHECKS=1;';
    return $body . "\n-- SHA256: " . hash('sha256',$body) . "\n";
};
$passphrase='Portable package regression passphrase';
foreach (['mysql'=>'mariadb','mariadb'=>'mysql'] as $from=>$to) {
    $source=$schemas[$from];$target=$schemas[$to];$sql=$sqlFor($source);
    $package=StorePackageFormat::encode($sql,[],$source,$passphrase);
    $decoded=StorePackageFormat::decode($package,$passphrase,$target);
    $assert($decoded['sql']===$sql,'Existing source SQL and its authenticated fingerprint survive cross-engine decoding');
    $assert($decoded['rows']['products']===[],'Compatible source rows parse against the producer schema');
    foreach (['type'=>['`product_name` varchar(150)','`product_name` varchar(140)'],
        'collation'=>['utf8mb4_unicode_ci','utf8mb4_general_ci'],
        'foreign key'=>['ON DELETE SET NULL','ON DELETE CASCADE'],
        'check'=>['`cost_price` >= 0','`cost_price` >= 1'],
        'literal'=>["DEFAULT 'piece'","DEFAULT 'int(11) CURRENT_TIMESTAMP()'"],
        'unsigned'=>['`product_id` int','`product_id` int unsigned']] as $kind=>[$before,$after]) {
        $different=$target;$different['products']['ddl']=str_replace($before,$after,$different['products']['ddl']);
        $assert($different!==$target,'Fixture mutation must change ' . $kind);
        $reject(fn()=>StorePackageFormat::decode($package,$passphrase,$different),'Reject incompatible ' . $kind);
    }
    $different=$target;$different['scheduled_demand_drivers']['ddl']=str_replace('CHECK (json_valid(`details_json`))','',$schemas['mariadb']['scheduled_demand_drivers']['ddl']);
    $reject(fn()=>StorePackageFormat::decode($package,$passphrase,$different),'Plain longtext must not stand in for validated JSON');
}
echo "Portable Store packages: passed (MySQL/MariaDB, original encrypted format, incompatible schema rejection)\n";
