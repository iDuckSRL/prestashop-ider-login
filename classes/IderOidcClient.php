<?php
/**
 * IDer Login for PrestaShop
 *
 * Client OpenID Connect minimo per IDer (https://oid.ider.com/core):
 * authorization code + PKCE (S256), state e nonce, verifica RS256 dell'id_token
 * con le chiavi JWKS, userinfo. Compatibile con PHP 7.2+.
 *
 * @author    IDer <plugins@jlm.srl>
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class IderOidcException extends Exception
{
}

class IderOidcClient
{
    const DEFAULT_ISSUER = 'https://oid.ider.com/core';
    const CACHE_TTL = 86400;
    const CLOCK_SKEW = 300;
    /** PKCE: da riattivare quando il server IDer lo conferma per i client "Website". */
    const USE_PKCE = false;

    /** @var string */
    private $issuer;
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;

    public function __construct($clientId, $clientSecret, $issuer = self::DEFAULT_ISSUER)
    {
        $this->clientId = (string) $clientId;
        $this->clientSecret = (string) $clientSecret;
        $this->issuer = rtrim((string) $issuer, '/');
    }

    // ------------------------------------------------------------------
    // Flusso
    // ------------------------------------------------------------------

    /**
     * Dati casuali per una nuova richiesta di accesso.
     *
     * @return array{state:string,nonce:string,verifier:string}
     */
    public static function newRequest()
    {
        return array(
            'state' => self::randomString(32),
            'nonce' => self::randomString(32),
            'verifier' => self::randomString(64),
        );
    }

    /**
     * URL della pagina di accesso IDer.
     */
    public function authorizationUrl($redirectUri, array $scopes, array $request)
    {
        $config = $this->discovery();
        $params = array(
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', array_unique(array_merge(array('openid'), $scopes))),
            'state' => $request['state'],
            'nonce' => $request['nonce'],
        );
        if (self::USE_PKCE) {
            $params['code_challenge'] = self::base64UrlEncode(hash('sha256', $request['verifier'], true));
            $params['code_challenge_method'] = 'S256';
        }

        return $config['authorization_endpoint'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Scambia il codice, verifica l'id_token e legge i dati dell'utente.
     *
     * @return array claim di userinfo (con "sub" uguale a quello dell'id_token)
     */
    public function finish($code, $redirectUri, array $request)
    {
        $config = $this->discovery();
        $fields = array(
            'grant_type' => 'authorization_code',
            'code' => (string) $code,
            'redirect_uri' => $redirectUri,
        );
        if (self::USE_PKCE) {
            $fields['code_verifier'] = $request['verifier'];
        }
        // client_secret_basic come la libreria del plugin WordPress (provata con IDer)
        $tokens = $this->httpPostForm($config['token_endpoint'], $fields,
            array('Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret)));

        if (empty($tokens['id_token']) || empty($tokens['access_token'])) {
            throw new IderOidcException('Token response without id_token or access_token');
        }

        $idClaims = $this->verifyIdToken($tokens['id_token'], $request['nonce']);

        $userInfo = $this->httpGetJson($config['userinfo_endpoint'], array('Authorization: Bearer ' . $tokens['access_token']));
        if (!isset($userInfo['sub']) || !hash_equals((string) $idClaims['sub'], (string) $userInfo['sub'])) {
            throw new IderOidcException('userinfo subject does not match id_token');
        }

        return $userInfo;
    }

    // ------------------------------------------------------------------
    // id_token
    // ------------------------------------------------------------------

    public function verifyIdToken($jwt, $nonce)
    {
        $parts = explode('.', (string) $jwt);
        if (count($parts) !== 3) {
            throw new IderOidcException('Malformed id_token');
        }
        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $claims = json_decode(self::base64UrlDecode($parts[1]), true);
        $signature = self::base64UrlDecode($parts[2]);
        if (!is_array($header) || !is_array($claims)) {
            throw new IderOidcException('Malformed id_token');
        }
        if (!isset($header['alg']) || $header['alg'] !== 'RS256') {
            throw new IderOidcException('Unsupported id_token algorithm');
        }

        $kid = isset($header['kid']) ? (string) $header['kid'] : null;
        $key = $this->findKey($kid, false);
        if ($key === null) {
            // le chiavi possono essere cambiate: riprova senza cache
            $key = $this->findKey($kid, true);
        }
        if ($key === null) {
            throw new IderOidcException('No signing key for id_token');
        }
        $ok = openssl_verify($parts[0] . '.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new IderOidcException('Invalid id_token signature');
        }

        $now = time();
        if (!isset($claims['iss']) || rtrim($claims['iss'], '/') !== $this->issuer) {
            throw new IderOidcException('Wrong id_token issuer');
        }
        $aud = isset($claims['aud']) ? (array) $claims['aud'] : array();
        if (!in_array($this->clientId, $aud, true)) {
            throw new IderOidcException('Wrong id_token audience');
        }
        if (!isset($claims['exp']) || $claims['exp'] + self::CLOCK_SKEW < $now) {
            throw new IderOidcException('Expired id_token');
        }
        if (isset($claims['iat']) && $claims['iat'] - self::CLOCK_SKEW > $now) {
            throw new IderOidcException('id_token issued in the future');
        }
        if (!isset($claims['nonce']) || !hash_equals((string) $nonce, (string) $claims['nonce'])) {
            throw new IderOidcException('Wrong id_token nonce');
        }
        if (empty($claims['sub'])) {
            throw new IderOidcException('id_token without subject');
        }

        return $claims;
    }

    private function findKey($kid, $refresh)
    {
        $config = $this->discovery();
        $jwks = $this->cached('IDERLOGIN_JWKS', $config['jwks_uri'], $refresh);
        if (empty($jwks['keys']) || !is_array($jwks['keys'])) {
            return null;
        }
        foreach ($jwks['keys'] as $jwk) {
            if (!isset($jwk['kty']) || $jwk['kty'] !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
                continue;
            }
            if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
                continue;
            }
            if ($kid !== null && isset($jwk['kid']) && $jwk['kid'] !== $kid) {
                continue;
            }

            return self::rsaPublicKeyPem(self::base64UrlDecode($jwk['n']), self::base64UrlDecode($jwk['e']));
        }

        return null;
    }

    /**
     * Chiave pubblica RSA (modulo, esponente) in formato PEM.
     */
    public static function rsaPublicKeyPem($modulus, $exponent)
    {
        $modulus = self::derInteger($modulus);
        $exponent = self::derInteger($exponent);
        $rsaKey = self::derSequence($modulus . $exponent);
        // AlgorithmIdentifier rsaEncryption + NULL
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $bitString = "\x03" . self::derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        $der = self::derSequence($algorithm . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger($bytes)
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence($content)
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derLength($length)
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    // ------------------------------------------------------------------
    // Discovery e cache
    // ------------------------------------------------------------------

    public function discovery()
    {
        $config = $this->cached('IDERLOGIN_DISCOVERY', $this->issuer . '/.well-known/openid-configuration', false);
        foreach (array('authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri') as $key) {
            if (empty($config[$key]) || strpos($config[$key], 'https://') !== 0) {
                throw new IderOidcException('Invalid discovery document');
            }
        }

        return $config;
    }

    private function cached($configKey, $url, $refresh)
    {
        if (!$refresh) {
            $raw = Configuration::getGlobalValue($configKey);
            if ($raw) {
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data['t'], $data['v'], $data['u']) && $data['u'] === $url && $data['t'] + self::CACHE_TTL > time()) {
                    return $data['v'];
                }
            }
        }
        $value = $this->httpGetJson($url, array());
        Configuration::updateGlobalValue($configKey, json_encode(array('t' => time(), 'u' => $url, 'v' => $value)));

        return $value;
    }

    public static function clearCache()
    {
        Configuration::deleteByName('IDERLOGIN_DISCOVERY');
        Configuration::deleteByName('IDERLOGIN_JWKS');
    }

    // ------------------------------------------------------------------
    // HTTP (curl, certificati verificati)
    // ------------------------------------------------------------------

    private function httpGetJson($url, array $headers)
    {
        return $this->request($url, null, $headers);
    }

    private function httpPostForm($url, array $fields, array $headers)
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';

        return $this->request($url, http_build_query($fields, '', '&'), $headers);
    }

    private function request($url, $body, array $headers)
    {
        if (strpos($url, 'https://') !== 0) {
            throw new IderOidcException('Refusing non-HTTPS request');
        }
        $headers[] = 'Accept: application/json';
        $ch = curl_init($url);
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'IDerLogin-PrestaShop/' . IderLogin::VERSION,
        );
        // CA bundle di PrestaShop se presente (alcuni hosting non hanno quello di sistema)
        if (defined('_PS_CACHE_CA_CERT_FILE_') && is_file(_PS_CACHE_CA_CERT_FILE_)) {
            $options[CURLOPT_CAINFO] = _PS_CACHE_CA_CERT_FILE_;
        }
        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new IderOidcException('HTTP error: ' . $error);
        }
        $data = json_decode($response, true);
        if ($status < 200 || $status >= 300) {
            // solo il codice d'errore OAuth o un estratto del testo: mai token o segreti
            if (is_array($data) && isset($data['error'])) {
                $detail = (string) $data['error'] . (isset($data['error_description']) ? ': ' . $data['error_description'] : '');
            } else {
                $detail = trim(preg_replace('/\s+/', ' ', strip_tags((string) $response)));
                $detail = Tools::substr($detail, 0, 160);
            }
            $endpoint = (string) parse_url($url, PHP_URL_PATH);
            throw new IderOidcException('IDer server error ' . $status . ' on ' . $endpoint . ' (' . preg_replace('/[^\w .:,()\/-]/u', '', $detail) . ')');
        }
        if (!is_array($data)) {
            throw new IderOidcException('Invalid JSON from IDer server');
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Utilità
    // ------------------------------------------------------------------

    public static function randomString($bytes)
    {
        return self::base64UrlEncode(random_bytes($bytes));
    }

    public static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode($data)
    {
        $data = strtr((string) $data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode($data, true);
    }
}
