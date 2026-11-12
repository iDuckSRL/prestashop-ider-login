{**
 * IDer Login - istruzioni nella pagina di configurazione
 *}
<div class="panel">
  <h3><i class="icon-info-sign"></i> {l s='How to connect your shop to IDer' mod='iderlogin'}</h3>
  <ol>
    <li>{l s='Sign in to the IDer partner portal' mod='iderlogin'} (<a href="https://partner.ider.com" target="_blank" rel="noopener">partner.ider.com</a>) {l s='and add your website (type "Website").' mod='iderlogin'}</li>
    <li>{l s='As callback (redirect URI) enter exactly this address:' mod='iderlogin'}<br>
      <code style="user-select:all">{$ider_callback_url|escape:'html':'UTF-8'}</code></li>
    <li>{l s='Copy the client ID and the client secret of the website into the fields below and save.' mod='iderlogin'}</li>
  </ol>
  <p>{l s='The "Sign in with IDer" button appears on the login and registration pages and in the checkout. In a custom theme you can place it anywhere with:' mod='iderlogin'} <code>{$ider_hook_example|escape:'html':'UTF-8'}</code></p>
  {if !$ider_configured}
    <div class="alert alert-warning">{l s='The button is hidden until client ID and client secret are saved.' mod='iderlogin'}</div>
  {/if}
</div>
