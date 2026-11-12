{**
 * IDer Login - pagina "Profilo IDer"
 *}
{extends file='customer/page.tpl'}

{block name='page_title'}
  {l s='IDer profile' mod='iderlogin'}
{/block}

{block name='page_content'}
  <div class="ider-profile">
    {if $ider_linked}
      <p class="ider-profile__intro">
        {l s='Your account is linked to IDer. These are the data you shared with this shop at your last IDer login.' mod='iderlogin'}
      </p>
      <p class="ider-profile__meta">
        {l s='Linked since %s' sprintf=[$ider_since] mod='iderlogin'} &middot;
        {l s='Last IDer login: %s' sprintf=[$ider_last_login] mod='iderlogin'}
      </p>
      {if $ider_rows}
        <table class="table ider-profile__table">
          <tbody>
            {foreach from=$ider_rows item=row}
              <tr>
                <th scope="row">{$row.label|escape:'html':'UTF-8'}</th>
                <td>{$row.value|escape:'html':'UTF-8'}</td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      {/if}
      <p class="ider-profile__note">
        {l s='To change these data, update your profile in the IDer app: they will be updated here at your next IDer login.' mod='iderlogin'}
      </p>
    {else}
      <p>{l s='Your account is not linked to IDer yet. Sign in with IDer to link it.' mod='iderlogin'}</p>
    {/if}
  </div>
{/block}
