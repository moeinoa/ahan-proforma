<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_Helpers
 *
 * Shared utility functions with no dependency on a heavy Jalali-date library
 * (Gregorian→Jalali conversion using the standard, lightweight algorithm,
 * with no need for a composer package or third-party plugin).
 */
class AHP_Helpers {

    /**
     * Convert a Gregorian date to Jalali (standard algorithm, no external dependency).
     * @return array{0:int,1:int,2:int} [year, month, day]
     */
    public static function gregorian_to_jalali( int $gy, int $gm, int $gd ): array {
        // Cumulative day offset for the start of each Gregorian month (non-leap year)
        $g_d_m = [ 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 ];

        if ( $gy > 1600 ) {
            $jy = 979;
            $gy -= 1600;
        } else {
            $jy = 0;
            $gy -= 621;
        }

        $gy2 = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;

        $days = ( 365 * $gy )
            + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 )
            - 80 + $gd + $g_d_m[ $gm - 1 ];

        $jy += 33 * intdiv( $days, 12053 );
        $days %= 12053;
        $jy += 4 * intdiv( $days, 1461 );
        $days %= 1461;
        if ( $days > 365 ) {
            $jy += intdiv( $days - 1, 365 );
            $days = ( $days - 1 ) % 365;
        }

        if ( $days < 186 ) {
            $jm = 1 + intdiv( $days, 31 );
            $jd = 1 + ( $days % 31 );
        } else {
            $jm = 7 + intdiv( $days - 186, 30 );
            $jd = 1 + ( ( $days - 186 ) % 30 );
        }

        return [ $jy, $jm, $jd ];
    }

    /** Today's Jalali date (based on WordPress current_time, accounting for the site timezone) */
    public static function today_jalali(): array {
        $gy = (int) current_time( 'Y' );
        $gm = (int) current_time( 'n' );
        $gd = (int) current_time( 'j' );
        return self::gregorian_to_jalali( $gy, $gm, $gd );
    }

    /** Latin digits to Persian digits */
    public static function to_persian_digits( string $str ): string {
        $fa = [ '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' ];
        $en = [ '0','1','2','3','4','5','6','7','8','9' ];
        return str_replace( $en, $fa, $str );
    }

    /** Today's Jalali date as a display string, e.g. "۱۴۰۵/۰۵/۰۳" */
    public static function today_jalali_display(): string {
        [ $jy, $jm, $jd ] = self::today_jalali();
        $str = sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
        return self::to_persian_digits( $str );
    }

    /**
     * Build the display invoice number: {Jalali year}/A/{4-digit, zero-padded, incrementing, never reset counter}.
     * The middle letter can be changed via the ahp_invoice_number_prefix filter (default A).
     *
     * Note: seq_number comes from AHP_DB::get_next_seq_number(), which is
     * atomic at the database level; this function only does display formatting.
     */
    public static function build_invoice_number( int $seq_number, int $jalali_year ): string {
        $middle = apply_filters( 'ahp_invoice_number_prefix', 'A' );
        $padded = str_pad( (string) $seq_number, 4, '0', STR_PAD_LEFT );
        return sprintf( '%d/%s/%s', $jalali_year, $middle, $padded );
    }

    /** Clean up and convert a Persian/English numeric string into a PHP number (for amounts) */
    public static function parse_number( string $raw ): ?float {
        $raw = trim( $raw );
        if ( $raw === '' ) return null;
        $clean = str_replace(
            [ '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' ],
            [ '0','1','2','3','4','5','6','7','8','9' ],
            $raw
        );
        $clean = str_replace( [ ',', '،', "\u{066C}", ' ', "\u{00A0}" ], '', $clean );
        return is_numeric( $clean ) ? (float) $clean : null;
    }

    /** Display format for an amount, with thousands separators and Persian digits */
    public static function format_toman( float $amount ): string {
        return self::to_persian_digits( number_format( $amount, 0, '.', ',' ) );
    }

    /** Translated label for a proforma invoice status (ahp_invoices.status) — Phase 6 */
    public static function invoice_status_label( string $status ): string {
        $labels = [
            'pending'  => __( 'Pending review', 'ahan-proforma' ),
            'approved' => __( 'Approved', 'ahan-proforma' ),
            'rejected' => __( 'Rejected', 'ahan-proforma' ),
            'expired'  => __( 'Expired', 'ahan-proforma' ),
        ];
        return $labels[ $status ] ?? $status;
    }

    /**
     * Single source of truth for the item measurement unit: 'kg' (weight),
     * 'shakhe' (bundle/rod), or 'adad' (count/piece — the default used
     * whenever the source price table has no unit column at all).
     * Every place in the codebase that validates or displays a unit must
     * go through this list/label map instead of hardcoding the values.
     */
    public static function allowed_units(): array {
        return [ 'kg', 'shakhe', 'adad' ];
    }

    /** Translated label for a cart/invoice item unit */
    public static function unit_label( string $unit ): string {
        $labels = [
            'kg'     => __( 'kg', 'ahan-proforma' ),
            'shakhe' => __( 'bundle', 'ahan-proforma' ),
            'adad'   => __( 'piece', 'ahan-proforma' ),
        ];
        return $labels[ $unit ] ?? $unit;
    }
}