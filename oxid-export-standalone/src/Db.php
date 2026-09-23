<?php

declare(strict_types=1);

namespace Mojo\OxidExport;

/**
 * Tiny PDO wrapper exposing the handful of methods the exporters use, so the
 * standalone build needs no Doctrine DBAL. Errors throw PDOException, which the
 * exporters rely on for optional-table detection.
 */
final class Db
{
    /** @var \PDO */
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $p
     */
    public static function fromParams(array $p): self
    {
        $host    = (string) (isset($p['host']) && $p['host'] !== '' ? $p['host'] : '127.0.0.1');
        $port    = (int) (isset($p['port']) && $p['port'] !== '' ? $p['port'] : 3306);
        $dbname  = (string) $p['dbname'];
        $charset = (string) (isset($p['charset']) && $p['charset'] !== '' ? $p['charset'] : 'utf8');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);

        $pdo = new \PDO($dsn, (string) (isset($p['user']) ? $p['user'] : ''), (string) (isset($p['password']) ? $p['password'] : ''), [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return new self($pdo);
    }

    /**
     * Read DB credentials from an OXID source/config.inc.php.
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

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchAllAssociative(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return mixed
     */
    public function fetchOne(string $sql)
    {
        $stmt = $this->pdo->query($sql);

        return $stmt !== false ? $stmt->fetchColumn(0) : false;
    }

    /**
     * @return \PDOStatement|false
     */
    public function executeQuery(string $sql)
    {
        return $this->pdo->query($sql);
    }
}
