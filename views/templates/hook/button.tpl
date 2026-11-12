{**
 * IDer Login - pulsante "Accedi con IDer"
 *}
<div class="ider-login ider-login--{$ider_place|escape:'html':'UTF-8'}">
  {if $ider_place != 'custom'}
    <p class="ider-login__or"><span>{l s='or' mod='iderlogin'}</span></p>
  {/if}
  <a class="ider-login-button" href="{$ider_login_url|escape:'html':'UTF-8'}" rel="nofollow">
    <img src="{$ider_logo_url|escape:'html':'UTF-8'}" alt="" width="22" height="22">
    <span>{l s='Sign in with IDer' mod='iderlogin'}</span>
  </a>
  <p class="ider-login__hint">{l s='Scan the QR code with the IDer app: no password needed.' mod='iderlogin'}</p>
</div>
