# IDer Login for PrestaShop

"Sign in with IDer" for PrestaShop 8 and 9: customers sign in and sign up by scanning a QR code with the [IDer](https://www.ider.com) app, without a password. It works like the [IDer Login plugin for WordPress](https://wordpress.org/plugins/ider-login/).

## Features

- **"Sign in with IDer" button** on the login page, on the registration page and in the checkout ("Personal information" step). Themes can place it anywhere with `{hook h='displayIderLoginButton'}`.
- **Automatic account:** the customer is found by IDer ID or by email. If there is no account, a new one is created (you can turn this off).
- **Data kept up to date** at every IDer login: first name, last name, birth date, gender, and an address named "IDer" with province/state, postcode and mobile phone.
- **"IDer profile" page** in *My account*, showing the data the customer shared at the last login.
- **Landing pages by scope:** a login from an IDer campaign can lead to a specific page.
- **GDPR:** export and deletion through the official *psgdpr* module.

## Security

- OpenID Connect authorization code flow. `state` and `nonce` are random, valid for 15 minutes and used only once.
- RS256 signature of the `id_token` checked against the IDer JWKS keys, together with issuer, audience, expiry and nonce.
- `userinfo` must return the same subject as the `id_token`.
- HTTPS only, with certificate verification. Redirects only to addresses of the shop.
- The client secret is never shown again after saving. Tokens, secrets and personal data are never written to logs.
- No external dependencies: works with PHP 7.2 to 8.5.

## Installation

1. In the back office go to **Modules → Module Manager → Upload a module** and choose `iderlogin-x.y.z.zip`.
2. Open the module configuration. It shows the exact **callback URL** of your shop, for example `https://your-shop.com/module/iderlogin/callback`.
3. In the [IDer partner portal](https://partner.ider.com), open **Login Button → Edit**:
   - framework: **Website**;
   - authentication request URL: `https://your-shop.com/module/iderlogin/login`;
   - callback URL: the address from step 2.
4. Copy **Client Id**, **Client Secret** and **Scope Name** into the module and save. A link to the login page with `?scope=<another campaign>` uses that campaign instead (same client, its own callback).

## Requirements

- PrestaShop 8.0 – 9.x.
- PHP with `curl`, `openssl` and `json`.

Tested on PrestaShop 8.2.9 and 9.2.1 (PHP 8.4) with the IDer production server.

## License

[Academic Free License 3.0](https://opensource.org/licenses/AFL-3.0)
