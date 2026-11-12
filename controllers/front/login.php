<?php
/**
 * IDer Login for PrestaShop - avvio dell'accesso con IDer.
 *
 * @author    IDer <plugins@jlm.srl>
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class IderloginLoginModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;

    /** @var IderLogin */
    public $module;

    public function postProcess()
    {
        $back = Tools::getValue('back');
        $back = is_string($back) && $this->module->isShopUrl($back) ? $back : '';

        if ($this->context->customer->isLogged()) {
            Tools::redirect($back ?: $this->context->link->getPageLink('my-account', true));
        }
        if (!$this->module->isConfigured()) {
            Tools::redirect($this->context->link->getPageLink('authentication', true));
        }

        // scope extra: impostazioni + "?scope=" (es. link di una campagna IDer)
        $scopes = array_filter(explode(' ', (string) Configuration::get('IDERLOGIN_EXTRA_SCOPES')));
        $requested = Tools::getValue('scope');
        if (is_string($requested) && preg_match('/^[A-Za-z0-9._:\- ]{1,200}$/', $requested)) {
            $scopes = array_merge($scopes, array_filter(explode(' ', $requested)));
        }
        $scopes = array_values(array_unique($scopes));

        $request = IderOidcClient::newRequest();
        $this->context->cookie->iderlogin_request = json_encode(array(
            'state' => $request['state'],
            'nonce' => $request['nonce'],
            'verifier' => $request['verifier'],
            'back' => $back,
            'scopes' => $scopes,
            't' => time(),
        ));
        $this->context->cookie->write();

        try {
            $url = $this->module->getOidcClient()->authorizationUrl($this->module->getCallbackUrl(), $scopes, $request);
        } catch (Exception $e) {
            IderLogin::log('cannot start login (' . get_class($e) . ': ' . $e->getMessage() . ')', 3);
            $this->errors[] = $this->module->l('IDer is not reachable right now. Please try again later.', 'login');
            $this->redirectWithNotifications($this->context->link->getPageLink('authentication', true));

            return;
        }
        Tools::redirect($url);
    }
}
