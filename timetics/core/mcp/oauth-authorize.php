<?php
/**
 * Sign-in Allow page
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The page where a logged-in owner or staff member lets an AI app connect.
 *
 * Uses the site's own WordPress login, and lives on a front-end URL so a renamed
 * or hidden wp-admin does not break it.
 */
class Oauth_Authorize {

    const QUERY_VAR = 'timetics-mcp-authorize';
    const NONCE     = 'timetics_mcp_authorize';
    const FIELDS    = array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' );

    /**
     * Answer the page: send a visitor to log in, show Allow, or act on the answer.
     *
     * @return  void
     */
    public static function handle() {
        nocache_headers();
        send_frame_options_header();

        // The OAuth request itself is checked by check(); the answer form carries a nonce.
        $posted = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
        $query  = self::pick( $posted ? wp_unslash( $_POST ) : wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification

        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( add_query_arg( array_merge( array( self::QUERY_VAR => 1 ), $query ), home_url( '/' ) ) ) );
            exit;
        }

        $user    = get_current_user_id();
        $checked = self::check( $query, $user );
        if ( is_wp_error( $checked ) ) {
            self::page( $checked->get_error_message() );
        }

        if ( $posted ) {
            check_admin_referer( self::NONCE );
            $allow  = 'allow' === sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
            $change = ! empty( $_POST['allow_changes'] );

            // The URI was matched against the app's own list, so leaving the site is safe here.
            wp_redirect( self::decide( $query, $user, $allow, $change ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
            exit;
        }

        self::page( '', $checked, $query );
    }

    /**
     * Check a sign-in request before showing Allow.
     *
     * @param   array  $query  The OAuth request.
     * @param   int    $user   Who is logged in.
     *
     * @return  array|WP_Error  client_name, redirect_uri, scopes; or why it cannot go on.
     */
    public static function check( array $query, int $user ) {
        $client = Oauth_Clients::find( (string) ( $query['client_id'] ?? '' ) );
        if ( is_wp_error( $client ) ) {
            return $client;
        }

        // Checked here too, so every kind of client meets the same rules, whatever its list says.
        $redirect = (string) ( $query['redirect_uri'] ?? '' );
        if ( ! in_array( $redirect, $client['redirect_uris'], true ) || ! Oauth_Clients::safe_redirect( $redirect ) ) {
            return new WP_Error( 'invalid_redirect_uri', __( 'This app asked to return to an address it did not register.', 'timetics' ) );
        }

        if ( 'code' !== ( $query['response_type'] ?? '' ) || 'S256' !== ( $query['code_challenge_method'] ?? '' ) || strlen( (string) ( $query['code_challenge'] ?? '' ) ) < 43 ) {
            return new WP_Error( 'invalid_request', __( 'This sign-in request is not complete. Try connecting again.', 'timetics' ) );
        }

        if ( ! empty( $query['resource'] ) && Oauth_Server::mcp_url() !== $query['resource'] ) {
            return new WP_Error( 'invalid_target', __( 'This app asked for an address that is not this site\'s WPTimetics MCP URL.', 'timetics' ) );
        }

        if ( ! Mcp_Access::can_use_tools( $user ) ) {
            return new WP_Error( 'access_denied', __( 'Only the site owner and staff can connect AI apps to WPTimetics.', 'timetics' ) );
        }

        return array(
            'client_name'  => $client['client_name'] ? $client['client_name'] : wp_parse_url( $query['redirect_uri'], PHP_URL_HOST ),
            'redirect_uri' => $query['redirect_uri'],
            'scopes'       => self::scopes( (string) ( $query['scope'] ?? '' ) ),
        );
    }

    /**
     * Turn the user's answer into the address to send the browser back to.
     *
     * @param   array  $query   The OAuth request.
     * @param   int    $user    Who answered.
     * @param   bool   $allow   Whether they allowed.
     * @param   bool   $change  Whether they allowed changes too.
     *
     * @return  string|WP_Error
     */
    public static function decide( array $query, int $user, bool $allow, bool $change ) {
        $checked = self::check( $query, $user );
        if ( is_wp_error( $checked ) ) {
            return $checked;
        }

        $back = array( 'state' => (string) ( $query['state'] ?? '' ) );
        if ( ! $allow ) {
            return add_query_arg( rawurlencode_deep( $back + array( 'error' => 'access_denied' ) ), $checked['redirect_uri'] );
        }

        $scopes = $change ? $checked['scopes'] : array_values( array_diff( $checked['scopes'], array( Oauth_Tokens::WRITE ) ) );
        $code   = Oauth_Tokens::issue_code(
            array(
                'client_id'    => (string) $query['client_id'],
                'client_name'  => $checked['client_name'],
                'user'         => $user,
                'scope'        => implode( ' ', $scopes ),
                'redirect_uri' => $checked['redirect_uri'],
                'challenge'    => (string) $query['code_challenge'],
                'resource'     => Oauth_Server::mcp_url(),
            )
        );

        return add_query_arg( rawurlencode_deep( $back + array( 'code' => $code ) ), $checked['redirect_uri'] );
    }

    /**
     * Permissions offered. Viewing is always part of a connection.
     *
     * @param   string  $asked  Space separated scopes the app asked for. Empty means both.
     *
     * @return  string[]
     */
    private static function scopes( string $asked ): array {
        $asked = array_filter( explode( ' ', $asked ) );

        return ( ! $asked || in_array( Oauth_Tokens::WRITE, $asked, true ) )
            ? array( Oauth_Tokens::READ, Oauth_Tokens::WRITE )
            : array( Oauth_Tokens::READ );
    }

    /**
     * Keep only the OAuth fields of a request.
     *
     * @param   array  $input  Raw request.
     *
     * @return  array
     */
    public static function pick( array $input ): array {
        // Some apps (Grok) add "?" to our address, which already has a query, so the
        // first field arrives inside ours: "1?response_type=code".
        $ours = (string) ( $input[ self::QUERY_VAR ] ?? '' );
        if ( false !== strpos( $ours, '?' ) ) {
            parse_str( substr( $ours, strpos( $ours, '?' ) + 1 ), $first );
            $input += $first;
        }

        $query = array();
        foreach ( self::FIELDS as $name ) {
            if ( isset( $input[ $name ] ) && is_string( $input[ $name ] ) ) {
                $query[ $name ] = $input[ $name ];
            }
        }

        return $query;
    }

    /**
     * What "Make changes" lets the app do for this user, as their write tools allow.
     *
     * @param   int  $user  Who is allowing.
     *
     * @return  string
     */
    public static function changes_text( int $user ): string {
        $preview = __( 'The app shows a preview of each change before it saves.', 'timetics' );

        if ( ! Mcp_Access::is_owner( $user ) ) {
            return __( 'Create, move and cancel your bookings, and mark them approved or completed.', 'timetics' ) . ' ' . $preview;
        }

        $coupons = isset( Abilities::tools()['timetics/create-coupon'] );

        return ( $coupons
            ? __( 'Create, move and cancel bookings. Add and change meetings, categories, working hours, customers and coupons, and change staff details. Hide meetings, and delete categories and coupons for good.', 'timetics' )
            : __( 'Create, move and cancel bookings. Add and change meetings, categories, working hours and customers, and change staff details. Hide meetings, and delete categories for good.', 'timetics' )
        ) . ' ' . $preview;
    }

    /**
     * Print the Allow page, or an error, and stop.
     *
     * @param   string  $error    Error to show instead of the form.
     * @param   array   $checked  From check().
     * @param   array   $query    The OAuth request, sent back with the answer.
     *
     * @return  void
     */
    private static function page( string $error, array $checked = array(), array $query = array() ) {
        status_header( $error ? 400 : 200 );
        header( 'Content-Type: text/html; charset=utf-8' );
        $user = wp_get_current_user();
        $site = get_bloginfo( 'name' );
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $error ? __( 'Cannot connect', 'timetics' ) : __( 'Connect an AI app', 'timetics' ) ); ?></title>
<style>
:root{--bg:#f6f7f9;--card:#fff;--text:#1d2327;--muted:#646970;--line:#dcdcde;--accent:#2271b1;--accent-text:#fff}
@media (prefers-color-scheme:dark){:root{--bg:#16181d;--card:#20242b;--text:#f0f0f1;--muted:#a7aaad;--line:#3c434a;--accent:#72aee6;--accent-text:#0b1a29}}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
main{max-width:440px;margin:48px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:24px}
h1{font-size:20px;margin:0 0 8px}
p{margin:0 0 12px;color:var(--muted)}
label{display:flex;gap:10px;align-items:flex-start;padding:12px 0;border-top:1px solid var(--line)}
label span small{display:block;color:var(--muted)}
.actions{display:flex;gap:12px;margin-top:20px}
button{flex:1;padding:10px 14px;border-radius:6px;font:inherit;cursor:pointer;border:1px solid var(--line);background:transparent;color:var(--text)}
button.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-text)}
</style>
</head>
<body>
<main>
<div class="card">
        <?php if ( $error ) : ?>
<h1><?php esc_html_e( 'This app cannot connect', 'timetics' ); ?></h1>
<p><?php echo esc_html( $error ); ?></p>
        <?php else : ?>
<h1>
            <?php
            /* translators: 1: AI app name, 2: site name */
            echo esc_html( sprintf( __( '%1$s wants to connect to %2$s', 'timetics' ), $checked['client_name'], $site ) );
            ?>
</h1>
<p>
            <?php
            /* translators: %s: user display name */
            echo esc_html( sprintf( __( 'It will work as %s, with the same access to WPTimetics.', 'timetics' ), $user->display_name ) );
            ?>
</p>
<form method="post">
            <?php wp_nonce_field( self::NONCE ); ?>
            <?php foreach ( $query as $name => $value ) : ?>
<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
            <?php endforeach; ?>
<label><input type="checkbox" checked disabled><span><?php esc_html_e( 'See bookings, meetings, staff, free times and customers', 'timetics' ); ?></span></label>
            <?php if ( in_array( Oauth_Tokens::WRITE, $checked['scopes'], true ) ) : ?>
<label><input type="checkbox" name="allow_changes" value="1" checked><span><?php esc_html_e( 'Make changes', 'timetics' ); ?><small><?php echo esc_html( self::changes_text( $user->ID ) ); ?></small></span></label>
            <?php endif; ?>
<p>
            <?php
            /* translators: %s: address the browser returns to */
            echo esc_html( sprintf( __( 'You will go back to %s. Changing your password or turning MCP off in WPTimetics ends this connection.', 'timetics' ), wp_parse_url( $checked['redirect_uri'], PHP_URL_HOST ) ) );
            ?>
</p>
<div class="actions">
<button type="submit" name="decision" value="deny"><?php esc_html_e( 'Cancel', 'timetics' ); ?></button>
<button type="submit" name="decision" value="allow" class="primary"><?php esc_html_e( 'Allow', 'timetics' ); ?></button>
</div>
</form>
        <?php endif; ?>
</div>
</main>
</body>
</html>
        <?php
        exit;
    }
}
