<?php
/**
 * IDer Login for PrestaShop - ritorno da IDer (redirect_uri).
 *
 * @author    IDer <plugins@jlm.srl>
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class IderloginCallbackModuleFrontController extends ModuleFrontController
{
    const REQUEST_TTL = 900;

    public $ssl = true;
    public $auth = false;

    /** @var IderLogin */
    public $module;

    public function postProcess()
    {
        // richiesta salvata all'avvio: si usa una volta sola
        $saved = json_decode((string) $this->context->cookie->iderlogin_request, true);
        unset($this->context->cookie->iderlogin_request);
        $this->context->cookie->write();

        $state = Tools::getValue('state');
        $code = Tools::getValue('code');
        $error = Tools::getValue('error');

        if (!is_array($saved) || empty($saved['state']) || !is_string($state) || !hash_equals($saved['state'], $state)) {
            IderLogin::log('callback with missing or wrong state', 2);
            $this->fail($this->module->l('The sign-in request has expired. Please try again.', 'callback'));

            return;
        }
        if (empty($saved['t']) || $saved['t'] + self::REQUEST_TTL < time()) {
            $this->fail($this->module->l('The sign-in request has expired. Please try again.', 'callback'));

            return;
        }
        if (is_string($error) && $error !== '') {
            // l'utente ha annullato o IDer ha rifiutato
            IderLogin::log('IDer returned error "' . preg_replace('/[^a-z_]/', '', $error) . '"', 1);
            $this->fail($error === 'access_denied'
                ? $this->module->l('Sign-in with IDer was cancelled.', 'callback')
                : $this->module->l('IDer could not complete the sign-in. Please try again.', 'callback'));

            return;
        }
        if (!is_string($code) || $code === '') {
            $this->fail($this->module->l('IDer could not complete the sign-in. Please try again.', 'callback'));

            return;
        }

        try {
            $claims = $this->module->getOidcClient()->finish($code, $this->module->getCallbackUrl(), $saved);
            $claims = IderCustomerSync::normalize($claims);
            $sync = new IderCustomerSync($this->context);
            $customer = $sync->syncCustomer(
                $claims,
                (bool) Configuration::get('IDERLOGIN_ALLOW_REGISTRATION'),
                (bool) Configuration::get('IDERLOGIN_UPDATE_DATA'),
                $this->module
            );
            $sync->login($customer);
            IderLogin::log('customer signed in' . ($sync->updatedFields ? ' (updated: ' . implode(', ', array_unique($sync->updatedFields)) . ')' : ''), 1, $customer->id);
        } catch (IderLoginUserException $e) {
            IderLogin::log('sign-in refused: ' . $e->getMessage(), 2);
            $this->fail($e->getMessage());

            return;
        } catch (Exception $e) {
            IderLogin::log('sign-in failed (' . get_class($e) . ': ' . $e->getMessage() . ')', 3);
            $this->fail($this->module->l('IDer could not complete the sign-in. Please try again.', 'callback'));

            return;
        }

        $scopes = isset($saved['scopes']) && is_array($saved['scopes']) ? $saved['scopes'] : array();
        $target = $this->module->landingPageFor($scopes);
        if (!$target && !empty($saved['back']) && $this->module->isShopUrl($saved['back'])) {
            $target = $saved['back'];
        }
        if (!$target) {
            $welcome = (string) Configuration::get('IDERLOGIN_WELCOME_URL');
            $target = $welcome !== '' && $this->module->isShopUrl($welcome) ? $welcome : $this->context->link->getPageLink('my-account', true);
        }
        Tools::redirect($target);
    }

    private function fail($message)
    {
        $this->errors[] = $message;
        $this->redirectWithNotifications($this->context->link->getPageLink('authentication', true));
    }
}
