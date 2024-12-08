<?php

/*
 *
 *     _____ _           _ _             _____ _             _ _
 *    / ____| |         | | |           / ____| |           | (_)
 *   | (___ | |__  _   _| | | _____ _ _| (___ | |_ _   _  __| |_  ___
 *    \___ \| '_ \| | | | | |/ / _ \ '__\___ \| __| | | |/ _` | |/ _ \
 *    ____) | | | | |_| | |   <  __/ |  ____) | |_| |_| | (_| | | (_) |
 *   |_____/|_| |_|\__,_|_|_|\_\___|_| |_____/ \__|\__,_|\__,_|_|\___/
 *
 *
 *   @author     ShulkerStudio
 *   @developer  Refaltor
 *   @discord    https://shulkerstudio.com/discord
 *   @website    https://shulkerstudio.com
 *
 */

namespace refaltor\efficiencySql;

use Illuminate\Database\Capsule\Manager as Capsule;
use mysqli;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\thread\NonThreadSafeValue;
use stdClass;

class EfficiencySQL
{
    private static array $connection = [];

    public static function createConnection(
        string $hostname,
        string $username,
        string $password,
        string $database,
    ): void
    {
        self::$connection[$hostname] = [
            'username' => $username,
            'password' => $password,
            'database' => $database,
            'hostname' => $hostname,
        ];
    }

    public static function sql(): object
    {
        $sql = new stdClass;

        $connection = self::$connection;
        $sql->hostname = $connection['hostname'];
        $sql->username = $connection['username'];
        $sql->password = $connection['password'];
        $sql->database = $connection['database'];

        return $sql;
    }

    public static function get(): object
    {
        $sql = new stdClass;
        $connection = self::$connection;
        $sql->hostname = $connection['hostname'];
        $sql->username = $connection['username'];
        $sql->password = $connection['password'];
        $sql->database = $connection['database'];

        return new mysqli($sql->hostname, $sql->username, $sql->password, $sql->database);
    }

    public static function migrate(): void
    {
        $migrationsPath = __DIR__.'/migrations';

        foreach (glob($migrationsPath.'/*.php') as $file) {
            $content = file_get_contents($file);
            if (preg_match('/namespace\s+(.+?);/', $content, $matches)) {
                $namespace = $matches[1];
                $fileName = basename($file, '.php');
                $class = $namespace.'\\'.$fileName;
                $object = new $class;
                $object->up();
                $object->hydrate();
                Server::getInstance()->getLogger()->info("§a[MIGRATION] §fRunning migration `$fileName`");
            }
        }
    }

    public static function init(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => env('PM_DB_HOST'),
            'database' => env('PM_DB_NAME'),
            'username' => env('PM_DB_USER'),
            'password' => env('PM_DB_PASSWORD'),
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
    }

    public static function chargeCaches(): void {}

    public static function fresh(): void
    {
        $migrationsPath = __DIR__.'/migrations';

        $files = array_reverse(glob($migrationsPath.'/*.php'));
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match('/namespace\s+(.+?);/', $content, $matches)) {
                $namespace = $matches[1];
                $fileName = basename($file, '.php');
                $class = $namespace.'\\'.$fileName;
                $object = new $class;
                $object->down();
                Server::getInstance()->getLogger()->info("§c[MIGRATION] §fRunning down migration `$fileName`");
            }
        }
    }

    public static function async(callable $async, ?callable $server = null): AsyncTask
    {
        $informationsConnection = new NonThreadSafeValue(static::sql());
        $async = new RequestAsync($async, $server, $informationsConnection);
        Server::getInstance()->getAsyncPool()->submitTask($async);

        return $async;
    }
}
