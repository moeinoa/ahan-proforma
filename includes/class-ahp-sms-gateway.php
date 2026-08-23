<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_SMS_Gateway
 *
 * Abstraction layer for sending OTP SMS. Currently only the Farapayamak
 * service (rest.payamak-panel.com) is implemented, but send_otp() is
 * designed so a provider swap in the future (without changing AHP_Auth) is possible.
 *
 * Settings (currently read from wp_options; a settings-panel UI was built
 * for it in Phase 5 - until then it could also be set manually via
 * update_option() or the ahp_sms_settings filter):
 *   - ahp_sms_username
 *   - ahp_sms_password   (Farapayamak API key - never logged)
 *   - ahp_sms_from       (sender line number)
 *
 * Reference docs: https://docs.farapayamak.ir/webservice-rest/rest-sending/rest-send-otp
 * Endpoint: POST https://rest.payamak-panel.com/api/SendSMS/SendOTP
 * Body (JSON): { username, password, to, from, code }
 * Response: { Value, RetStatus, StrRetStatus }  (RetStatus = 1 means success)
 * Note: error responses also come back with HTTP 200; the decision is made based on RetStatus only.
 */
class AHP_SMS_Gateway {

    private const API_URL      = 'https://rest.payamak-panel.com/api/SendSMS/SendOTP';
    // For free-form text (not the fixed-pattern OTP code) — e.g. the admin
    // notification SMS sent when a new proforma invoice is issued.
    // Docs: rest-send-sms (SendSMS method).
    private const API_URL_TEXT = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';

    /**
     * Send a verification code to a mobile number.
     * @return array{ok:bool, error?:string, raw?:mixed}
     */
    public static function send_otp( string $mobile, string $code ): array {
        $settings = self::get_settings();

        if ( empty( $settings['username'] ) || empty( $settings['password'] ) ) {
            return [ 'ok' => false, 'error' => 'gateway_not_configured' ];
        }

        $body = apply_filters( 'ahp_sms_otp_request_body', [
            'username' => $settings['username'],
            'password' => $settings['password'],
            'to'       => $mobile,
            'from'     => $settings['from'],
            'code'     => $code,
        ], $mobile, $code );

        $response = wp_remote_post( self::API_URL, [
            'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => 'http_error:' . $response->get_error_message() ];
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );
        $data   = json_decode( $raw, true );

        // Some Farapayamak application-level errors come back with a
        // successful HTTP response; so regardless of whether the code is
        // 200, we check the RetStatus field. (RetStatus=1 means success; 0
        // means wrong username/password; negative means an IP/API-access
        // security restriction; other positive numbers are application-level
        // sending errors)
        $ret_status = is_array( $data ) ? ( $data['RetStatus'] ?? null ) : null;

        if ( $status >= 200 && $status < 300 && (int) $ret_status === 1 ) {
            return [ 'ok' => true, 'raw' => $data ];
        }

        // If the Farapayamak server-side configuration is wrong, a more
        // detailed error is kept for the admin log (not for direct display
        // to the end user).
        $str_status = is_array( $data ) ? ( $data['StrRetStatus'] ?? '' ) : '';
        if ( $str_status ) {
            error_log( sprintf(
                '[Ahan Proforma] SMS gateway rejected (RetStatus=%s, StrRetStatus=%s)',
                $ret_status,
                $str_status
            ) );
        }

        return [
            'ok'    => false,
            'error' => 'gateway_rejected',
            'raw'   => $data ?? $raw,
        ];
    }

    /**
     * Send a free-form text SMS (unlike send_otp, which only fills the
     * {code} placeholder in Farapayamak's fixed SMS template). Used for the
     * plugin's internal notifications (currently: notifying the admin when a
     * new proforma invoice is issued). This is a different endpoint from
     * SendOTP (docs: rest-send-sms).
     *
     * @return array{ok:bool, error?:string, raw?:mixed}
     */
    public static function send_text( string $mobile, string $text ): array {
        $settings = self::get_settings();

        if ( empty( $settings['username'] ) || empty( $settings['password'] ) ) {
            return [ 'ok' => false, 'error' => 'gateway_not_configured' ];
        }

        $body = apply_filters( 'ahp_sms_text_request_body', [
            'username' => $settings['username'],
            'password' => $settings['password'],
            'to'       => $mobile,
            'from'     => $settings['from'],
            'text'     => $text,
            'isflash'  => false,
        ], $mobile, $text );

        $response = wp_remote_post( self::API_URL_TEXT, [
            'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => 'http_error:' . $response->get_error_message() ];
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );
        $data   = json_decode( $raw, true );

        // Same logic as send_otp: application-level errors also come back
        // with HTTP 200; the decision is made based on RetStatus only, not
        // the HTTP code.
        $ret_status = is_array( $data ) ? ( $data['RetStatus'] ?? null ) : null;

        if ( $status >= 200 && $status < 300 && (int) $ret_status === 1 ) {
            return [ 'ok' => true, 'raw' => $data ];
        }

        $str_status = is_array( $data ) ? ( $data['StrRetStatus'] ?? '' ) : '';
        if ( $str_status ) {
            error_log( sprintf(
                '[Ahan Proforma] SMS text gateway rejected (RetStatus=%s, StrRetStatus=%s)',
                $ret_status,
                $str_status
            ) );
        }

        return [
            'ok'    => false,
            'error' => 'gateway_rejected',
            'raw'   => $data ?? $raw,
        ];
    }

    private static function get_settings(): array {
        $defaults = [
            'username' => '',
            'password' => '',
            'from'     => '',
        ];
        $settings = [
            'username' => get_option( 'ahp_sms_username', '' ),
            'password' => get_option( 'ahp_sms_password', '' ),
            'from'     => get_option( 'ahp_sms_from', '' ),
        ];
        // Allows temporary override via a wp-config.php constant (optional, for testing without the panel UI)
        if ( defined( 'AHP_SMS_USERNAME' ) ) $settings['username'] = AHP_SMS_USERNAME;
        if ( defined( 'AHP_SMS_PASSWORD' ) ) $settings['password'] = AHP_SMS_PASSWORD;
        if ( defined( 'AHP_SMS_FROM' ) )     $settings['from']     = AHP_SMS_FROM;

        return apply_filters( 'ahp_sms_settings', array_merge( $defaults, $settings ) );
    }
}
