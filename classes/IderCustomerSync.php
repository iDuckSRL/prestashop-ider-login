<?php
/**
 * IDer Login for PrestaShop
 *
 * Collega i dati IDer a un cliente PrestaShop: lo trova (per ID IDer o email),
 * lo crea se serve, aggiorna anagrafica e indirizzo "IDer" ed esegue l'accesso.
 *
 * @author    IDer <plugins@jlm.srl>
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class IderCustomerSync
{
    const ADDRESS_ALIAS = 'IDer';

    /** @var Context */
    private $context;

    /** @var array campi aggiornati nell'ultimo accesso */
    public $updatedFields = array();

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    // ------------------------------------------------------------------
    // Normalizzazione dei claim
    // ------------------------------------------------------------------

    /**
     * Claim IDer in forma piatta: i valori JSON vengono decodificati e gli
     * oggetti appiattiti con il punto ("address.locality").
     */
    public static function normalize(array $claims)
    {
        $decode = function ($value) use (&$decode) {
            if (is_string($value)) {
                $trim = trim($value);
                if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                    $json = json_decode($trim, true);
                    if (is_array($json)) {
                        return $decode($json);
                    }
                }

                return $value;
            }
            if (is_object($value)) {
                $value = get_object_vars($value);
            }
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $value[$k] = $decode($v);
                }
            }

            return $value;
        };
        $claims = $decode($claims);

        $flat = array();
        $walk = function ($data, $prefix) use (&$walk, &$flat) {
            foreach ($data as $key => $value) {
                $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                if (is_array($value)) {
                    $walk($value, $name);
                } elseif ($value !== null && $value !== '') {
                    $flat[$name] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                }
            }
        };
        $walk($claims, '');

        return $flat;
    }

    private static function claim(array $c, $name)
    {
        return isset($c[$name]) ? trim($c[$name]) : '';
    }

    // ------------------------------------------------------------------
    // Cliente
    // ------------------------------------------------------------------

    /**
     * Trova o crea il cliente e lo aggiorna. Lancia eccezioni con messaggi
     * mostrabili all'utente (già tradotti dal modulo).
     *
     * @return Customer
     */
    public function syncCustomer(array $claims, $allowRegistration, $updateData, IderLogin $module)
    {
        $sub = self::claim($claims, 'sub');
        $email = Tools::strtolower(self::claim($claims, 'email'));
        if ($sub === '' || $email === '' || !Validate::isEmail($email)) {
            throw new IderLoginUserException($module->l('IDer did not send the data needed to sign in (email).', 'idercustomersync'));
        }

        $customer = null;
        $link = IderLink::getBySub($sub);
        if ($link) {
            $customer = new Customer((int) $link['id_customer']);
            if (!Validate::isLoadedObject($customer) || $customer->deleted) {
                IderLink::deleteBySub($sub);
                $customer = null;
            }
        }

        if ($customer === null) {
            // stesso indirizzo email già cliente (non ospite): si collega
            $byEmail = new Customer();
            $byEmail = $byEmail->getByEmail($email, null, true);
            if ($byEmail && Validate::isLoadedObject($byEmail)) {
                $customer = $byEmail;
            }
        }

        if ($customer === null) {
            if (!$allowRegistration) {
                throw new IderLoginUserException($module->l('There is no account with your IDer email in this shop.', 'idercustomersync'));
            }
            $customer = $this->createCustomer($claims, $email);
        }

        if (!$customer->active) {
            throw new IderLoginUserException($module->l('Your account is disabled. Please contact the shop.', 'idercustomersync'));
        }

        // email cambiata in IDer: aggiorna se nessun altro cliente la usa
        if (Tools::strtolower($customer->email) !== $email) {
            $other = Customer::customerExists($email, true, true);
            if (!$other) {
                $customer->email = $email;
                $this->updatedFields[] = 'email';
            }
        }

        if ($updateData) {
            $this->updateCustomerFields($customer, $claims);
        }
        if ($this->updatedFields) {
            $customer->update();
        }
        if ($updateData) {
            $this->syncAddress($customer, $claims);
        }

        IderLink::save($sub, (int) $customer->id, $claims, $this->updatedFields);

        return $customer;
    }

    private function createCustomer(array $claims, $email)
    {
        $customer = new Customer();
        $customer->email = $email;
        $customer->firstname = $this->cleanName(self::claim($claims, 'given_name')) ?: 'IDer';
        $customer->lastname = $this->cleanName(self::claim($claims, 'family_name')) ?: 'IDer';
        $customer->passwd = $this->hashPassword(Tools::passwdGen(32));
        $customer->id_shop = (int) $this->context->shop->id;
        $customer->id_shop_group = (int) $this->context->shop->id_shop_group;
        $customer->id_lang = (int) $this->context->language->id;
        $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
        $customer->is_guest = 0;
        $customer->active = 1;
        $customer->newsletter = 0;
        $customer->optin = 0;
        $this->updateCustomerFields($customer, $claims);
        if (!$customer->add()) {
            throw new Exception('Customer creation failed');
        }
        Hook::exec('actionCustomerAccountAdd', array('newCustomer' => $customer, '_POST' => array()));
        $this->updatedFields = array('created');

        return $customer;
    }

    private function hashPassword($plain)
    {
        // PrestaShop 8/9: servizio di hashing del core
        if (class_exists('PrestaShop\PrestaShop\Adapter\ServiceLocator')) {
            $crypto = \PrestaShop\PrestaShop\Adapter\ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');

            return $crypto->hash($plain);
        }

        return password_hash($plain, PASSWORD_BCRYPT);
    }

    private function cleanName($name)
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        // PrestaShop non accetta numeri e alcuni simboli nei nomi
        $name = trim(preg_replace('/[0-9!<>,;?=+()@#"°{}_$%:¤|]/u', '', $name));
        $name = Tools::substr($name, 0, 255);

        return Validate::isCustomerName($name) ? $name : '';
    }

    private function set(Customer $customer, $field, $value)
    {
        if ($value === '' || $value === null) {
            return;
        }
        if ((string) $customer->{$field} !== (string) $value) {
            $customer->{$field} = $value;
            $this->updatedFields[] = $field;
        }
    }

    private function updateCustomerFields(Customer $customer, array $claims)
    {
        $this->set($customer, 'firstname', $this->cleanName(self::claim($claims, 'given_name')));
        $this->set($customer, 'lastname', $this->cleanName(self::claim($claims, 'family_name')));

        $birthdate = self::parseDate(self::claim($claims, 'birthdate'));
        if ($birthdate && Validate::isBirthDate($birthdate)) {
            $this->set($customer, 'birthday', $birthdate);
        }

        $gender = Tools::strtoupper(Tools::substr(self::claim($claims, 'gender') ?: self::claim($claims, 'sex'), 0, 1));
        if ($gender === 'M' || $gender === 'F') {
            $id = self::genderId($gender === 'M' ? 0 : 1);
            if ($id) {
                $this->set($customer, 'id_gender', $id);
            }
        }

        $company = self::claim($claims, 'company_name');
        if ($company !== '' && Validate::isGenericName($company)) {
            $this->set($customer, 'company', Tools::substr($company, 0, 255));
        }
        $website = self::claim($claims, 'website');
        if ($website !== '' && Validate::isUrl($website)) {
            $this->set($customer, 'website', Tools::substr($website, 0, 128));
        }
    }

    private static function genderId($type)
    {
        return (int) Db::getInstance()->getValue('SELECT id_gender FROM `' . _DB_PREFIX_ . 'gender` WHERE type = ' . (int) $type);
    }

    public static function parseDate($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        $time = strtotime($value);

        return $time ? date('Y-m-d', $time) : null;
    }

    // ------------------------------------------------------------------
    // Indirizzo "IDer"
    // ------------------------------------------------------------------

    private function syncAddress(Customer $customer, array $claims)
    {
        $street = self::claim($claims, 'address.street_address');
        $city = self::claim($claims, 'address.locality');
        $countryValue = self::claim($claims, 'address.country');
        if ($street === '' || $city === '' || $countryValue === '') {
            return;
        }

        $idCountry = (int) Country::getByIso(Tools::strtoupper($countryValue));
        if (!$idCountry) {
            $idCountry = (int) Country::getIdByName((int) $this->context->language->id, $countryValue);
        }
        if (!$idCountry) {
            PrestaShopLogger::addLog('IDer Login: unknown country in IDer address', 2, null, 'Customer', (int) $customer->id, true);

            return;
        }
        $country = new Country($idCountry);
        $postcode = self::claim($claims, 'address.postal_code');
        $region = self::claim($claims, 'address.region');
        if ($region === '' && Tools::strtoupper($country->iso_code) === 'US') {
            $region = self::usStateFromZip($postcode);
        }

        $idState = 0;
        if ($country->contains_states) {
            $idState = $this->findState($region, $idCountry);
            if (!$idState) {
                PrestaShopLogger::addLog('IDer Login: state/province not found for IDer address', 2, null, 'Customer', (int) $customer->id, true);

                return;
            }
        }
        if ($country->need_zip_code && ($postcode === '' || !$country->checkZipCode($postcode))) {
            PrestaShopLogger::addLog('IDer Login: invalid postcode in IDer address', 2, null, 'Customer', (int) $customer->id, true);

            return;
        }

        $idAddress = (int) Db::getInstance()->getValue(
            'SELECT id_address FROM `' . _DB_PREFIX_ . 'address` WHERE id_customer = ' . (int) $customer->id
            . " AND alias = '" . pSQL(self::ADDRESS_ALIAS) . "' AND deleted = 0"
        );
        $address = new Address($idAddress ?: null);
        $before = $idAddress ? array(
            $address->address1, $address->city, $address->postcode, $address->id_country, $address->id_state,
            $address->phone_mobile, $address->firstname, $address->lastname,
        ) : null;

        $address->id_customer = (int) $customer->id;
        $address->alias = self::ADDRESS_ALIAS;
        $address->firstname = $customer->firstname;
        $address->lastname = $customer->lastname;
        $address->address1 = Tools::substr($street, 0, 128);
        $address->city = Tools::substr($city, 0, 64);
        $address->postcode = Tools::substr($postcode, 0, 12);
        $address->id_country = $idCountry;
        $address->id_state = $idState;
        $phone = preg_replace('/[^0-9+\s().-]/', '', self::claim($claims, 'phone_number'));
        if ($phone !== '' && Validate::isPhoneNumber($phone)) {
            $address->phone_mobile = Tools::substr($phone, 0, 32);
        }

        $after = array(
            $address->address1, $address->city, $address->postcode, $address->id_country, $address->id_state,
            $address->phone_mobile, $address->firstname, $address->lastname,
        );
        if ($before !== null && $before == $after) {
            return;
        }
        if (($error = $address->validateFields(false, true)) !== true) {
            PrestaShopLogger::addLog('IDer Login: IDer address not valid for PrestaShop', 2, null, 'Customer', (int) $customer->id, true);

            return;
        }
        if ($address->save()) {
            $this->updatedFields[] = 'address';
        }
    }

    private function findState($region, $idCountry)
    {
        $region = trim((string) $region);
        if ($region === '') {
            return 0;
        }
        $id = (int) State::getIdByIso(Tools::strtoupper($region), $idCountry);
        if ($id) {
            return $id;
        }
        $id = (int) Db::getInstance()->getValue(
            'SELECT id_state FROM `' . _DB_PREFIX_ . 'state` WHERE id_country = ' . (int) $idCountry
            . " AND LOWER(name) = '" . pSQL(Tools::strtolower($region)) . "'"
        );

        return $id;
    }

    /**
     * Stato USA dal CAP (come la libreria IDer per WordPress).
     */
    public static function usStateFromZip($zip)
    {
        $zip = (int) $zip;
        $ranges = array(
            'AL' => array(35000, 36999), 'AK' => array(99500, 99999), 'AZ' => array(85000, 86999), 'AR' => array(71600, 72999),
            'CA' => array(90000, 96199), 'CO' => array(80000, 81699), 'CT' => array(6000, 6999), 'DE' => array(19700, 19999),
            'DC' => array(20000, 20099), 'FL' => array(32000, 34999), 'GA' => array(30000, 31999), 'HI' => array(96700, 96999),
            'ID' => array(83200, 83999), 'IL' => array(60000, 62999), 'IN' => array(46000, 47999), 'IA' => array(50000, 52999),
            'KS' => array(66000, 67999), 'KY' => array(40000, 42999), 'LA' => array(70000, 71599), 'ME' => array(3900, 4999),
            'MD' => array(20600, 21999), 'MA' => array(1000, 2799), 'MI' => array(48000, 49999), 'MN' => array(55000, 56999),
            'MS' => array(38600, 39799), 'MO' => array(63000, 65999), 'MT' => array(59000, 59999), 'NE' => array(68000, 69999),
            'NV' => array(88900, 89999), 'NH' => array(3000, 3899), 'NJ' => array(7000, 8999), 'NM' => array(87000, 88499),
            'NY' => array(10000, 14999), 'NC' => array(27000, 28999), 'ND' => array(58000, 58999), 'OH' => array(43000, 45999),
            'OK' => array(73000, 74999), 'OR' => array(97000, 97999), 'PA' => array(15000, 19699), 'RI' => array(2800, 2999),
            'SC' => array(29000, 29999), 'SD' => array(57000, 57999), 'TN' => array(37000, 38599), 'TX' => array(75000, 79999),
            'UT' => array(84000, 84999), 'VT' => array(5000, 5999), 'VA' => array(22000, 24699), 'WA' => array(98000, 99499),
            'WV' => array(24700, 26899), 'WI' => array(53000, 54999), 'WY' => array(82000, 83199),
        );
        foreach ($ranges as $state => $range) {
            if ($zip >= $range[0] && $zip <= $range[1]) {
                return $state;
            }
        }

        return '';
    }

    // ------------------------------------------------------------------
    // Accesso
    // ------------------------------------------------------------------

    public function login(Customer $customer)
    {
        $this->context->updateCustomer($customer);
        Hook::exec('actionAuthentication', array('customer' => $this->context->customer));
        CartRule::autoRemoveFromCart($this->context);
        CartRule::autoAddToCart($this->context);
    }
}

class IderLoginUserException extends Exception
{
}
