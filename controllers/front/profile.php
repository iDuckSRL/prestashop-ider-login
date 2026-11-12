<?php
/**
 * IDer Login for PrestaShop - pagina "Profilo IDer" nell'area cliente.
 *
 * @author    IDer <plugins@jlm.srl>
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class IderloginProfileModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = true;
    public $authRedirection = 'my-account';

    /** @var IderLogin */
    public $module;

    public function initContent()
    {
        parent::initContent();

        $link = IderLink::getByCustomer((int) $this->context->customer->id);
        $rows = array();
        $updated = array();
        if ($link) {
            $claims = json_decode((string) $link['claims'], true) ?: array();
            $labels = $this->labels();
            foreach ($labels as $key => $label) {
                if ($key === 'sex' && !empty($claims['gender'])) {
                    continue;
                }
                if (isset($claims[$key]) && $claims[$key] !== '') {
                    $rows[] = array('label' => $label, 'value' => $this->format($key, $claims[$key]));
                }
            }
            $updated = array_filter(explode(',', (string) $link['updated_fields']));
        }

        $this->context->smarty->assign(array(
            'ider_linked' => (bool) $link,
            'ider_rows' => $rows,
            'ider_last_login' => $link ? Tools::displayDate($link['last_login'], true) : '',
            'ider_since' => $link ? Tools::displayDate($link['date_add']) : '',
            'ider_updated' => $updated,
        ));
        $this->setTemplate('module:iderlogin/views/templates/front/profile.tpl');
    }

    private function labels()
    {
        $m = $this->module;

        return array(
            'email' => $m->l('Email', 'profile'),
            'given_name' => $m->l('First name', 'profile'),
            'family_name' => $m->l('Last name', 'profile'),
            'phone_number' => $m->l('Phone', 'profile'),
            'birthdate' => $m->l('Date of birth', 'profile'),
            'gender' => $m->l('Gender', 'profile'),
            'sex' => $m->l('Gender', 'profile'),
            'nationality' => $m->l('Nationality', 'profile'),
            'address.street_address' => $m->l('Address', 'profile'),
            'address.postal_code' => $m->l('Postcode', 'profile'),
            'address.locality' => $m->l('City', 'profile'),
            'address.region' => $m->l('Province / State', 'profile'),
            'address.country' => $m->l('Country', 'profile'),
            'company_name' => $m->l('Company', 'profile'),
            'website' => $m->l('Website', 'profile'),
        );
    }

    private function format($key, $value)
    {
        if ($key === 'birthdate') {
            $date = IderCustomerSync::parseDate($value);

            return $date ? Tools::displayDate($date) : $value;
        }
        if ($key === 'gender' || $key === 'sex') {
            $g = Tools::strtoupper(Tools::substr(trim($value), 0, 1));
            if ($g === 'M') {
                return $this->module->l('Male', 'profile');
            }
            if ($g === 'F') {
                return $this->module->l('Female', 'profile');
            }

            return $value;
        }
        if ($key === 'nationality' || $key === 'address.country') {
            // codice ISO -> nome nella lingua del negozio, es. "Italia (IT)"
            $iso = Tools::strtoupper(trim($value));
            if (preg_match('/^[A-Z]{2}$/', $iso)) {
                $id = (int) Country::getByIso($iso);
                if ($id) {
                    return Country::getNameById((int) $this->context->language->id, $id) . ' (' . $iso . ')';
                }
            }
        }

        return $value;
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] = array(
            'title' => $this->module->l('IDer profile', 'profile'),
            'url' => $this->context->link->getModuleLink('iderlogin', 'profile', array(), true),
        );

        return $breadcrumb;
    }
}
