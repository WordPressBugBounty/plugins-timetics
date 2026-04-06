<?php
namespace UninstallerForm\Support;

/**
 * GoogleSheetClient class for the uninstaller form.
 *
 * @since 1.0.0
 *
 * @package UNINSTALLER_FORM
 */
class GoogleSheetClient {
    protected $credentials;
    protected $spreadsheetId;
    protected $sheetName;
    protected $accessToken;

    /**
     * GoogleSheetClient Constructor.
     *
     * @param string $credentialsPath The path to the credentials file.
     * @param string $spreadsheetId The ID of the Google Sheet.
     * @param string $sheetName The name of the sheet to write to. Defaults to 'Sheet1'.
     *
     * @since 1.0.0
     */
    public function __construct( $credentialsPath, $spreadsheetId, $sheetName = 'Sheet1' ) {
        $this->credentials   = json_decode( file_get_contents( $credentialsPath ), true );
        $this->spreadsheetId = $spreadsheetId;
        $this->sheetName     = $sheetName;
        $this->accessToken   = $this->generateAccessToken();
    }

    /**
     * Generate an access token for the Google Sheets API.
     *
     * @since 1.0.0
     */
    protected function generateAccessToken() {
        $header = [
            "alg" => "RS256",
            "typ" => "JWT",
        ];

        $now      = time();
        $claimSet = [
            "iss"   => $this->credentials['client_email'],
            "scope" => "https://www.googleapis.com/auth/spreadsheets",
            "aud"   => "https://oauth2.googleapis.com/token",
            "iat"   => $now,
            "exp"   => $now + 3600,
        ];

        $jwtHeader      = $this->base64UrlEncode( json_encode( $header ) );
        $jwtClaims      = $this->base64UrlEncode( json_encode( $claimSet ) );
        $signatureInput = "$jwtHeader.$jwtClaims";

        // Sign the JWT
        openssl_sign( $signatureInput, $signature, $this->credentials['private_key'], 'sha256WithRSAEncryption' );
        $jwtSignature = $this->base64UrlEncode( $signature );
        $jwt          = "$signatureInput.$jwtSignature";

        // Exchange JWT for access token
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ] );

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return $body['access_token'] ?? null;
    }

    /**
     * Base64 URL encode a string.
     *
     * @since 1.0.0
     *
     * @param string $data The string to encode.
     *
     * @return string The base64 URL encoded string.
     * */
    protected function base64UrlEncode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Append a row to the Google Sheet.
     *
     * @since 1.0.0
     *
     * @param array $values The values to append to the sheet.
     *
     * @return array The response from the Google Sheets API.
     **/
    public function appendRow( array $values ) {
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$this->sheetName}!A1:append?valueInputOption=RAW";

        $body = [
            'values' => [$values],
        ];

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \Exception( 'Google Sheets API request failed: ' . $response->get_error_message() );
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}