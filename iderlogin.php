<?php
/**
 * IDer Login for PrestaShop
 *
 * "Accedi con IDer": accesso e registrazione dei clienti con l'app IDer
 * (OpenID Connect), con aggiornamento di anagrafica e indirizzo.
 *
 * @author    IDer <plugins@jlm.srl>
 * @copyright IDer
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/IderOidcClient.php';
require_once __DIR__ . '/classes/IderLink.php';
require_once __DIR__ . '/classes/IderCustomerSync.php';

class IderLogin extends Module
{
    const VERSION = '1.0.1';

    /** Impostazioni => valore predefinito */
    const DEFAULTS = array(
        'IDERLOGIN_CLIENT_ID' => '',
        'IDERLOGIN_CLIENT_SECRET' => '',
        'IDERLOGIN_EXTRA_SCOPES' => '',
        'IDERLOGIN_SHOW_LOGIN' => 1,
        'IDERLOGIN_SHOW_REGISTER' => 1,
        'IDERLOGIN_SHOW_CHECKOUT' => 1,
        'IDERLOGIN_ALLOW_REGISTRATION' => 1,
        'IDERLOGIN_UPDATE_DATA' => 1,
        'IDERLOGIN_WELCOME_URL' => '',
        'IDERLOGIN_LANDING_PAGES' => '',
        'IDERLOGIN_BUTTON_CSS' => '',
    );

    const HOOKS = array(
        'displayHeader',
        'displayCustomerLoginFormAfter',
        'displayCustomerAccountFormTop',
        'displayPersonalInformationTop',
        'displayCustomerAccount',
        'displayIderLoginButton',
        'actionObjectCustomerDeleteAfter',
        'registerGDPRConsent',
        'actionDeleteGDPRCustomer',
        'actionExportGDPRData',
    );

    public function __construct()
    {
        $this->name = 'iderlogin';
        $this->tab = 'front_office_features';
        $this->version = self::VERSION;
        $this->author = 'IDer';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => '9.99.99');

        parent::__construct();

        $this->displayName = $this->l('IDer Login');
        $this->description = $this->l('Let customers sign in and sign up with the IDer app (QR code, no password).');
        $this->confirmUninstall = $this->l('IDer links between customers and IDer profiles will be deleted. Continue?');
    }

    // ------------------------------------------------------------------
    // Installazione
    // ------------------------------------------------------------------

    public function install()
    {
        if (!parent::install() || !IderLink::installSql()) {
            return false;
        }
        foreach (self::DEFAULTS as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }
        foreach (self::HOOKS as $hook) {
            $this->registerHook($hook);
        }

        return true;
    }

    public function uninstall()
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            Configuration::deleteByName($key);
        }
        IderOidcClient::clearCache();

        return IderLink::uninstallSql() && parent::uninstall();
    }

    public function isConfigured()
    {
        return trim((string) Configuration::get('IDERLOGIN_CLIENT_ID')) !== ''
            && trim((string) Configuration::get('IDERLOGIN_CLIENT_SECRET')) !== '';
    }

    public function getOidcClient()
    {
        return new IderOidcClient(Configuration::get('IDERLOGIN_CLIENT_ID'), Configuration::get('IDERLOGIN_CLIENT_SECRET'));
    }

    public function getCallbackUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'callback', array(), true);
    }

    // ------------------------------------------------------------------
    // Configurazione (back office)
    // ------------------------------------------------------------------

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitIderLogin')) {
            $output .= $this->saveConfig();
        }

        $this->context->smarty->assign(array(
            'ider_callback_url' => $this->getCallbackUrl(),
            'ider_configured' => $this->isConfigured(),
            'ider_hook_example' => "{hook h='displayIderLoginButton'}",
        ));

        return $output . $this->display(__FILE__, 'views/templates/admin/intro.tpl') . $this->renderForm();
    }

    private function saveConfig()
    {
        $errors = array();
        $clientId = trim((string) Tools::getValue('IDERLOGIN_CLIENT_ID'));
        $secret = trim((string) Tools::getValue('IDERLOGIN_CLIENT_SECRET'));
        $scopes = trim((string) Tools::getValue('IDERLOGIN_EXTRA_SCOPES'));
        $welcome = trim((string) Tools::getValue('IDERLOGIN_WELCOME_URL'));
        $landing = $this->cleanLandingPages((string) Tools::getValue('IDERLOGIN_LANDING_PAGES'), $errors);
        $css = (string) Tools::getValue('IDERLOGIN_BUTTON_CSS');

        if ($clientId !== '' && !preg_match('/^[A-Za-z0-9._:\-]{1,191}$/', $clientId)) {
            $errors[] = $this->l('The client ID contains invalid characters.');
        }
        if ($scopes !== '' && !preg_match('/^[A-Za-z0-9._:\-]{1,100}$/', $scopes)) {
            $errors[] = $this->l('Enter only the Scope Name of the IDer Login Button (one value, no spaces).');
        }
        if ($welcome !== '' && !$this->isShopUrl($welcome)) {
            $errors[] = $this->l('The page after login must be an address of this shop.');
        }
        if ($errors) {
            return $this->displayError(implode('<br>', array_map('htmlspecialchars', $errors)));
        }

        Configuration::updateValue('IDERLOGIN_CLIENT_ID', $clientId);
        // il secret si cambia solo scrivendone uno nuovo
        if ($secret !== '') {
            Configuration::updateValue('IDERLOGIN_CLIENT_SECRET', $secret);
        }
        Configuration::updateValue('IDERLOGIN_EXTRA_SCOPES', $scopes);
        foreach (array('IDERLOGIN_SHOW_LOGIN', 'IDERLOGIN_SHOW_REGISTER', 'IDERLOGIN_SHOW_CHECKOUT', 'IDERLOGIN_ALLOW_REGISTRATION', 'IDERLOGIN_UPDATE_DATA') as $key) {
            Configuration::updateValue($key, (int) (bool) Tools::getValue($key));
        }
        Configuration::updateValue('IDERLOGIN_WELCOME_URL', $welcome);
        Configuration::updateValue('IDERLOGIN_LANDING_PAGES', $landing);
        Configuration::updateValue('IDERLOGIN_BUTTON_CSS', self::cleanCss($css));
        IderOidcClient::clearCache();

        return $this->displayConfirmation($this->l('Settings saved.'));
    }

    /** "scope=URL" una per riga; solo indirizzi del negozio. */
    private function cleanLandingPages($text, array &$errors)
    {
        $lines = array();
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode('=', $line, 2);
            $scope = trim($parts[0]);
            $url = isset($parts[1]) ? trim($parts[1]) : '';
            if (!preg_match('/^[A-Za-z0-9._:\-]+$/', $scope) || !$this->isShopUrl($url)) {
                $errors[] = sprintf($this->l('Invalid landing page line: %s'), $line);
                continue;
            }
            $lines[] = $scope . '=' . $url;
        }

        return implode("\n", $lines);
    }

    public static function cleanCss($css)
    {
        $css = strip_tags((string) $css);
        $css = str_ireplace(array('</style', '<style', 'expression(', 'javascript:', '@import'), '', $css);

        return Tools::substr($css, 0, 5000);
    }

    /** Indirizzo assoluto dello stesso negozio o percorso relativo. */
    public function isShopUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }
        if ($url[0] === '/' && (strlen($url) === 1 || $url[1] !== '/') && strpos($url, '\\') === false) {
            return true;
        }
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!$host || !in_array(Tools::strtolower((string) $scheme), array('http', 'https'), true)) {
            return false;
        }
        $shopHosts = array(Tools::strtolower(Tools::getShopDomain()), Tools::strtolower(Tools::getShopDomainSsl()));
        $port = parse_url($url, PHP_URL_PORT);
        $hostWithPort = Tools::strtolower($host) . ($port ? ':' . $port : '');

        return in_array($hostWithPort, $shopHosts, true) || in_array(Tools::strtolower($host), $shopHosts, true);
    }

    private function renderForm()
    {
        $switch = function ($name, $label, $desc = null) {
            return array(
                'type' => 'switch',
                'label' => $label,
                'name' => $name,
                'desc' => $desc,
                'is_bool' => true,
                'values' => array(
                    array('id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')),
                    array('id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')),
                ),
            );
        };

        $form = array(
            'form' => array(
                'legend' => array('title' => $this->l('IDer connection'), 'icon' => 'icon-key'),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Client ID'),
                        'name' => 'IDERLOGIN_CLIENT_ID',
                        'required' => true,
                        'desc' => $this->l('From the IDer partner portal (partner.ider.com), in the details of your website.'),
                    ),
                    array(
                        'type' => 'password',
                        'label' => $this->l('Client secret'),
                        'name' => 'IDERLOGIN_CLIENT_SECRET',
                        'required' => !$this->isConfigured(),
                        'desc' => $this->isConfigured()
                            ? $this->l('Saved. Leave empty to keep it, or write a new one to replace it.')
                            : $this->l('From the IDer partner portal. It is never shown again after saving.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Scope Name'),
                        'name' => 'IDERLOGIN_EXTRA_SCOPES',
                        'required' => true,
                        'desc' => $this->l('The Scope Name of the IDer Login Button (one value). A link with ?scope= can use another campaign of the same client instead.'),
                    ),
                    $switch('IDERLOGIN_SHOW_LOGIN', $this->l('Button on the login page')),
                    $switch('IDERLOGIN_SHOW_REGISTER', $this->l('Button on the registration page')),
                    $switch('IDERLOGIN_SHOW_CHECKOUT', $this->l('Button in the checkout'), $this->l('In the "Personal information" step, for customers who are not logged in.')),
                    $switch('IDERLOGIN_ALLOW_REGISTRATION', $this->l('Create new customers'), $this->l('If disabled, only customers who already have an account with the same email can sign in with IDer.')),
                    $switch('IDERLOGIN_UPDATE_DATA', $this->l('Update customer data at every login'), $this->l('First name, last name, birth date, gender and the address named "IDer" are updated with the data the customer shares through IDer.')),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Page after login'),
                        'name' => 'IDERLOGIN_WELCOME_URL',
                        'desc' => $this->l('Optional. Address of this shop. Empty = the page the customer came from, or "My account".'),
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Landing pages by scope'),
                        'name' => 'IDERLOGIN_LANDING_PAGES',
                        'rows' => 4,
                        'desc' => $this->l('Optional, one per line: scope=address. When a login requests that scope (e.g. from an IDer campaign), the customer lands on that page.'),
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Button CSS'),
                        'name' => 'IDERLOGIN_BUTTON_CSS',
                        'rows' => 4,
                        'desc' => $this->l('Optional CSS rules for .ider-login-button.'),
                    ),
                ),
                'submit' => array('title' => $this->l('Save')),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->submit_action = 'submitIderLogin';
        $values = array();
        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = Tools::getValue($key, Configuration::get($key));
        }
        $values['IDERLOGIN_CLIENT_SECRET'] = '';
        $helper->fields_value = $values;

        return $helper->generateForm(array($form));
    }

    // ------------------------------------------------------------------
    // Front office
    // ------------------------------------------------------------------

    public function hookDisplayHeader()
    {
        $this->context->controller->registerStylesheet('module-iderlogin', 'modules/' . $this->name . '/views/css/iderlogin.css', array('media' => 'all', 'priority' => 150));
        $css = (string) Configuration::get('IDERLOGIN_BUTTON_CSS');
        if (trim($css) !== '') {
            return '<style>' . self::cleanCss($css) . '</style>';
        }

        return '';
    }

    /** Pulsante "Accedi con IDer" (anche per i temi: {hook h='displayIderLoginButton'}). */
    public function renderButton($place = 'custom')
    {
        if (!$this->isConfigured() || $this->context->customer->isLogged()) {
            return '';
        }
        $back = Tools::getValue('back');
        $params = array();
        if (is_string($back) && $back !== '' && $this->isShopUrl($back)) {
            $params['back'] = $back;
        } elseif ($place === 'checkout') {
            $params['back'] = $this->context->link->getPageLink('order', true);
        }
        $this->context->smarty->assign(array(
            'ider_login_url' => $this->context->link->getModuleLink($this->name, 'login', $params, true),
            'ider_logo_url' => $this->_path . 'views/img/ider_logo_white_128.png',
            'ider_place' => $place,
        ));

        return $this->fetch('module:' . $this->name . '/views/templates/hook/button.tpl');
    }

    public function hookDisplayIderLoginButton()
    {
        return $this->renderButton('custom');
    }

    public function hookDisplayCustomerLoginFormAfter()
    {
        return Configuration::get('IDERLOGIN_SHOW_LOGIN') ? $this->renderButton('login') : '';
    }

    public function hookDisplayCustomerAccountFormTop()
    {
        return Configuration::get('IDERLOGIN_SHOW_REGISTER') ? $this->renderButton('register') : '';
    }

    public function hookDisplayPersonalInformationTop()
    {
        return Configuration::get('IDERLOGIN_SHOW_CHECKOUT') ? $this->renderButton('checkout') : '';
    }

    public function hookDisplayCustomerAccount()
    {
        if (!IderLink::getByCustomer((int) $this->context->customer->id)) {
            return '';
        }
        $this->context->smarty->assign(array(
            'ider_profile_url' => $this->context->link->getModuleLink($this->name, 'profile', array(), true),
        ));

        return $this->fetch('module:' . $this->name . '/views/templates/hook/my-account.tpl');
    }

    public function hookActionObjectCustomerDeleteAfter($params)
    {
        if (isset($params['object']) && $params['object'] instanceof Customer) {
            IderLink::deleteByCustomer((int) $params['object']->id);
        }
    }

    // ------------------------------------------------------------------
    // GDPR (modulo psgdpr)
    // ------------------------------------------------------------------

    public function hookRegisterGDPRConsent()
    {
        // il modulo psgdpr elenca i moduli che trattano dati personali
    }

    public function hookActionDeleteGDPRCustomer($customer)
    {
        if (!empty($customer['id'])) {
            IderLink::deleteByCustomer((int) $customer['id']);
        }

        return json_encode(true);
    }

    public function hookActionExportGDPRData($customer)
    {
        if (empty($customer['id'])) {
            return json_encode($this->l('No data'));
        }
        $link = IderLink::getByCustomer((int) $customer['id']);
        if (!$link) {
            return json_encode($this->l('No data'));
        }

        return json_encode(array(array(
            'IDer ID' => $link['sub'],
            'Linked on' => $link['date_add'],
            'Last IDer login' => $link['last_login'],
            'Data received' => $link['claims'],
        )));
    }

    // ------------------------------------------------------------------
    // Utilità per i controller
    // ------------------------------------------------------------------

    /** URL di arrivo per uno degli scope richiesti, se configurato. */
    public function landingPageFor(array $scopes)
    {
        foreach (preg_split('/\R/', (string) Configuration::get('IDERLOGIN_LANDING_PAGES')) as $line) {
            $parts = explode('=', trim($line), 2);
            if (count($parts) === 2 && in_array(trim($parts[0]), $scopes, true) && $this->isShopUrl(trim($parts[1]))) {
                return trim($parts[1]);
            }
        }

        return null;
    }

    public static function log($message, $severity = 1, $idCustomer = null)
    {
        // mai token, secret o dati personali nei log
        PrestaShopLogger::addLog('IDer Login: ' . $message, $severity, null, $idCustomer ? 'Customer' : null, $idCustomer ? (int) $idCustomer : null, true);
    }
}
