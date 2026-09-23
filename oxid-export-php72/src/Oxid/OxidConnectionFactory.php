<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Builds a Doctrine DBAL connection to the OXID database (PHP 7.2 / DBAL 2.x).
 */
final class OxidConnectionFactory
{
    /**
     * @param array<string,mixed> $params
     */
    public static function create(array $params): Connection
    {
        return DriverManager::getConnection([
            'driver'   => 'pdo_mysql',
            'host'     => (string) (isset($params['host']) ? $params['host'] : '127.0.0.1'),
            'port'     => (int) (isset($params['port']) ? $params['port'] : 3306),
            'dbname'   => (string) $params['dbname'],
            'user'     => (string) $params['user'],
            'password' => (string) (isset($params['password']) ? $params['password'] : ''),
            'charset'  => (string) (isset($params['charset']) ? $params['charset'] : 'utf8'),
        ]);
    }

    /**
     * Extract DB credentials from an OXID `source/config.inc.php`.
     *
     * @return array<string,string>
     */
    public static function fromOxidConfig(string $configPath): array
    {
        if (!is_readable($configPath)) {
            throw new \RuntimeException("Cannot read OXID config file: {$configPath}");
        }

        $src = (string) file_get_contents($configPath);

        $grab = function ($property) use ($src) {
            $pattern = '/\$this->' . preg_quote($property, '/') . '\s*=\s*([\'"])(.*?)\1\s*;/s';

            return preg_match($pattern, $src, $m) === 1 ? $m[2] : null;
        };

        return [
            'host'     => $grab('dbHost') !== null ? $grab('dbHost') : '127.0.0.1',
            'port'     => $grab('dbPort') !== null ? $grab('dbPort') : '3306',
            'dbname'   => $grab('dbName') !== null ? $grab('dbName') : '',
            'user'     => $grab('dbUser') !== null ? $grab('dbUser') : '',
            'password' => $grab('dbPwd') !== null ? $grab('dbPwd') : '',
        ];
    }
}
