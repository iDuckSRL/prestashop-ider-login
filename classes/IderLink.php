<?php
/**
 * IDer Login for PrestaShop
 *
 * Collegamento ID IDer (sub) ↔ cliente PrestaShop, con l'ultima copia dei
 * dati ricevuti (mostrata nella pagina "Profilo IDer" del cliente).
 *
 * @author    IDer <plugins@jlm.srl>
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class IderLink
{
    const TABLE = 'iderlogin_customer';

    public static function installSql()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_iderlogin_customer` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sub` VARCHAR(191) NOT NULL,
            `id_customer` INT UNSIGNED NOT NULL,
            `claims` MEDIUMTEXT NULL,
            `updated_fields` TEXT NULL,
            `date_add` DATETIME NOT NULL,
            `last_login` DATETIME NOT NULL,
            PRIMARY KEY (`id_iderlogin_customer`),
            UNIQUE KEY `sub` (`sub`),
            KEY `id_customer` (`id_customer`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public static function uninstallSql()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    public static function getBySub($sub)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . "` WHERE sub = '" . pSQL($sub) . "'");

        return $row ?: null;
    }

    public static function getByCustomer($idCustomer)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_customer = ' . (int) $idCustomer
            . ' ORDER BY last_login DESC');

        return $row ?: null;
    }

    public static function deleteBySub($sub)
    {
        return Db::getInstance()->delete(self::TABLE, "sub = '" . pSQL($sub) . "'");
    }

    public static function deleteByCustomer($idCustomer)
    {
        return Db::getInstance()->delete(self::TABLE, 'id_customer = ' . (int) $idCustomer);
    }

    public static function save($sub, $idCustomer, array $claims, array $updatedFields)
    {
        $now = date('Y-m-d H:i:s');
        // non conservare indicatori tecnici
        unset($claims['sub']);
        $data = array(
            'id_customer' => (int) $idCustomer,
            'claims' => pSQL(json_encode($claims, JSON_UNESCAPED_UNICODE)),
            'updated_fields' => pSQL(implode(',', array_unique($updatedFields))),
            'last_login' => $now,
        );
        if (self::getBySub($sub)) {
            return Db::getInstance()->update(self::TABLE, $data, "sub = '" . pSQL($sub) . "'");
        }
        // un cliente = un ID IDer
        self::deleteByCustomer($idCustomer);
        $data['sub'] = pSQL($sub);
        $data['date_add'] = $now;

        return Db::getInstance()->insert(self::TABLE, $data);
    }
}
