<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_User_Fields
 *
 * Displays the customer's mobile number (already stored in usermeta under
 * the ahp_mobile key in AHP_Auth::create_customer_user) as:
 *   1) a dedicated column in the dashboard's user list (wp-admin/users.php)
 *   2) a dedicated, read-only field on the user profile edit page (both for
 *      the user themselves and for an admin via user-edit.php)
 *
 * Important note: this class does not put the number into the role/nickname.
 * Nickname is a standard WordPress field that, if left empty during
 * wp_insert_user, defaults to user_login (which is why the number used to
 * show up in the nickname). AHP_Auth::create_customer_user now explicitly
 * sets this field to the customer's full name so this conflict doesn't happen.
 */
class AHP_User_Fields {

    private const USERMETA_MOBILE = 'ahp_mobile';

    public static function init(): void {
        // Mobile-number column in the user list
        add_filter( 'manage_users_columns',       [ __CLASS__, 'add_mobile_column' ] );
        add_filter( 'manage_users_custom_column',  [ __CLASS__, 'render_mobile_column' ], 10, 3 );
        add_filter( 'manage_users_sortable_columns', [ __CLASS__, 'make_mobile_column_sortable' ] );
        add_action( 'pre_get_users',               [ __CLASS__, 'handle_mobile_column_sorting' ] );

        // Mobile-number field on the profile page (both one's own profile and another user's, edited by an admin)
        add_action( 'show_user_profile', [ __CLASS__, 'render_mobile_profile_field' ] );
        add_action( 'edit_user_profile', [ __CLASS__, 'render_mobile_profile_field' ] );
    }

    /* ── Column in the user list ───────────────────────────────────────────── */

    public static function add_mobile_column( array $columns ): array {
        // Added right after the username column so it's visible at a glance
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'username' ) {
                $new['ahp_mobile'] = __( 'Mobile number', 'ahan-proforma' );
            }
        }
        // If the username column wasn't found for some reason (other customizations), add it at the end
        if ( ! isset( $new['ahp_mobile'] ) ) {
            $new['ahp_mobile'] = __( 'Mobile number', 'ahan-proforma' );
        }
        return $new;
    }

    public static function render_mobile_column( $output, string $column_name, int $user_id ) {
        if ( $column_name !== 'ahp_mobile' ) {
            return $output;
        }
        $mobile = get_user_meta( $user_id, self::USERMETA_MOBILE, true );
        return $mobile ? esc_html( $mobile ) : '&#8212;';
    }

    public static function make_mobile_column_sortable( array $columns ): array {
        $columns['ahp_mobile'] = 'ahp_mobile';
        return $columns;
    }

    public static function handle_mobile_column_sorting( WP_User_Query $query ): void {
        if ( ! is_admin() ) {
            return;
        }
        if ( $query->get( 'orderby' ) !== 'ahp_mobile' ) {
            return;
        }
        $query->set( 'meta_key', self::USERMETA_MOBILE );
        $query->set( 'orderby', 'meta_value' );
    }

    /* ── Field on the profile page ─────────────────────────────────────────── */

    public static function render_mobile_profile_field( WP_User $user ): void {
        $mobile = get_user_meta( $user->ID, self::USERMETA_MOBILE, true );
        if ( ! $mobile ) {
            return; // only shown for users who signed up via OTP
        }
        ?>
        <h2><?php esc_html_e( 'Ahan Shahr Customer Information', 'ahan-proforma' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="ahp_mobile_display"><?php esc_html_e( 'Mobile number', 'ahan-proforma' ); ?></label></th>
                <td>
                    <input type="text" id="ahp_mobile_display" value="<?php echo esc_attr( $mobile ); ?>" class="regular-text" readonly />
                    <p class="description">
                        <?php esc_html_e( 'This number is used for SMS-code login and cannot be changed from this page.', 'ahan-proforma' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }
}
