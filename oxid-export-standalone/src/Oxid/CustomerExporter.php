<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Mojo\OxidExport\Db;

/**
 * Exports OXID customers (oxuser) with billing address (on the user record),
 * delivery addresses (oxaddress) and raw password material (PHP 7.2).
 *
 * Password storage:
 *   - modern users: OXPASSWORD = bcrypt ($2y$...), OXPASSSALT empty
 *   - legacy users: OXPASSWORD = sha512, OXPASSSALT = hex salt
 * Exported verbatim; the Shopware OxidLegacy encoder verifies either on login.
 */
final class CustomerExporter
{
    /** @var Connection */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function export(): array
    {
        $addressesByUser = $this->deliveryAddresses();

        $rows = $this->db->fetchAllAssociative(
            "SELECT u.*, c.OXISOALPHA2 AS BILL_ISO
             FROM oxuser u
             LEFT JOIN oxcountry c ON c.OXID = u.OXCOUNTRYID
             WHERE COALESCE(u.OXRIGHTS, '') <> 'malladmin'
               AND TRIM(COALESCE(u.OXUSERNAME, '')) <> ''
             ORDER BY u.OXCUSTNR ASC"
        );

        $out = [];
        foreach ($rows as $r) {
            $oxid  = (string) $r['OXID'];
            $email = $this->str($r, 'OXUSERNAME');
            if ($email === null) {
                continue;
            }

            $out[] = [
                'oxid'           => $oxid,
                'customerNumber' => $this->str($r, 'OXCUSTNR') !== null ? $this->str($r, 'OXCUSTNR') : ('OX-' . $oxid),
                'email'          => $email,
                'active'         => (int) (isset($r['OXACTIVE']) ? $r['OXACTIVE'] : 1) === 1,
                'salutation'     => $this->str($r, 'OXSAL'),
                'firstName'      => $this->str($r, 'OXFNAME'),
                'lastName'       => $this->str($r, 'OXLNAME'),
                'company'        => $this->str($r, 'OXCOMPANY'),
                'birthday'       => $this->date($this->str($r, 'OXBIRTHDATE')),
                'createdAt'      => $this->iso($this->str($r, 'OXCREATE')),
                'passwordHash'   => $this->str($r, 'OXPASSWORD'),
                'passwordSalt'   => $this->str($r, 'OXPASSSALT'),
                'billing'        => [
                    'salutation'     => $this->str($r, 'OXSAL'),
                    'firstName'      => $this->str($r, 'OXFNAME'),
                    'lastName'       => $this->str($r, 'OXLNAME'),
                    'company'        => $this->str($r, 'OXCOMPANY'),
                    'street'         => trim((string) (isset($r['OXSTREET']) ? $r['OXSTREET'] : '') . ' ' . (string) (isset($r['OXSTREETNR']) ? $r['OXSTREETNR'] : '')),
                    'additionalLine' => $this->str($r, 'OXADDINFO'),
                    'zipcode'        => $this->str($r, 'OXZIP'),
                    'city'           => $this->str($r, 'OXCITY'),
                    'countryIso'     => $this->str($r, 'BILL_ISO'),
                    'phone'          => $this->str($r, 'OXFON'),
                ],
                'addresses'      => isset($addressesByUser[$oxid]) ? $addressesByUser[$oxid] : [],
            ];
        }

        return $out;
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function deliveryAddresses(): array
    {
        if (!$this->tableExists('oxaddress')) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT a.*, c.OXISOALPHA2 AS ADDR_ISO
             FROM oxaddress a
             LEFT JOIN oxcountry c ON c.OXID = a.OXCOUNTRYID
             ORDER BY a.OXUSERID ASC, a.OXID ASC'
        );

        $map = [];
        foreach ($rows as $r) {
            $userId = (string) (isset($r['OXUSERID']) ? $r['OXUSERID'] : '');
            if ($userId === '') {
                continue;
            }

            $map[$userId][] = [
                'oxid'           => (string) $r['OXID'],
                'salutation'     => $this->str($r, 'OXSAL'),
                'firstName'      => $this->str($r, 'OXFNAME'),
                'lastName'       => $this->str($r, 'OXLNAME'),
                'company'        => $this->str($r, 'OXCOMPANY'),
                'street'         => trim((string) (isset($r['OXSTREET']) ? $r['OXSTREET'] : '') . ' ' . (string) (isset($r['OXSTREETNR']) ? $r['OXSTREETNR'] : '')),
                'additionalLine' => $this->str($r, 'OXADDINFO'),
                'zipcode'        => $this->str($r, 'OXZIP'),
                'city'           => $this->str($r, 'OXCITY'),
                'countryIso'     => $this->str($r, 'ADDR_ISO'),
                'phone'          => $this->str($r, 'OXFON'),
            ];
        }

        return $map;
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->db->fetchOne("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function date(?string $value): ?string
    {
        if ($value === null || strncmp($value, '0000-00-00', 10) === 0) {
            return null;
        }
        $ts = strtotime($value);

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private function iso(?string $value): ?string
    {
        if ($value === null || strncmp($value, '0000-00-00', 10) === 0) {
            return null;
        }
        $ts = strtotime($value);

        return $ts !== false ? date('c', $ts) : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function str(array $row, string $key): ?string
    {
        $v = isset($row[$key]) ? $row[$key] : null;
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
