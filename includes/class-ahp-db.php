<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_DB
 *
 * All database interactions for the proforma-invoice plugin. Tables are
 * created with a dedicated ahp_ prefix so they never conflict with apt_
 * (the price-table plugin) or WordPress core tables.
 *
 * Tables:
 *  - {$prefix}ahp_invoices        header for each proforma invoice (one record = one document)
 *  - {$prefix}ahp_invoice_items   line items for each proforma invoice (a full snapshot, independent of later source-table changes)
 *
 * Architectural decision: no changes are made to the Ahan Price Tables plugin's tables (apt_*).
 */
class AHP_DB {

    /** Full table name with the WordPress prefix + our dedicated prefix */
    public static function table_invoices(): string {
        global $wpdb;
        return $wpdb->prefix . 'ahp_invoices';
    }

    public static function table_items(): string {
        global $wpdb;
        return $wpdb->prefix . 'ahp_invoice_items';
    }

    public static function table_otp(): string {
        global $wpdb;
        return $wpdb->prefix . 'ahp_otp_requests';
    }

    /* ── Installing/upgrading the database schema ─────────────────────────── */

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $invoices = self::table_invoices();
        $items    = self::table_items();
        $otp      = self::table_otp();

        /*
         * ahp_invoices
         * ─────────────
         * invoice_number   : final display number, e.g. 1405/A/0001 (generated and stored at issuance time, immutable)
         * seq_number       : raw global incrementing counter (never resets per year) - the source used to generate invoice_number
         * jalali_year      : Jalali year of issuance, stored separately so reports/filters don't need to recompute it
         * user_id          : the issuing WordPress user (ahan_customer role); must never be recorded as zero, since issuing requires login
         * buyer_name       : buyer's name at issuance time (a snapshot from the user profile - doesn't change if the profile is edited later)
         * buyer_phone      : buyer's contact number at issuance time (snapshot)
         * subtotal         : grand total (Rials) - sum of all line totals; there's no discount, so subtotal = total
         * total            : final amount (Rials) - currently equal to subtotal (kept for future discount/tax development)
         * status           : pending | approved | rejected | expired  (default: pending - changed only manually by the admin)
         * access_token     : a random 32-character string used to build the
         *                    unguessable public URL for the proforma
         *                    invoice's HTML output page (Phase 4); the raw
         *                    row id alone is not used in the URL, since it's
         *                    guessable/sequentially enumerable
         * admin_note       : internal admin note (optional, for Phase 5)
         * created_at       : time the customer's request was recorded
         * reviewed_at      : time of the admin's last status change (nullable)
         * reviewed_by      : user_id of the admin who changed the status (nullable)
         */
        $sql_invoices = "CREATE TABLE IF NOT EXISTS {$invoices} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_number VARCHAR(50)     NOT NULL,
            seq_number     BIGINT UNSIGNED NOT NULL,
            jalali_year    SMALLINT UNSIGNED NOT NULL,
            user_id        BIGINT UNSIGNED NOT NULL,
            buyer_name     VARCHAR(190)    NOT NULL,
            buyer_phone    VARCHAR(20)     NOT NULL,
            subtotal       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total          BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status         VARCHAR(20)     NOT NULL DEFAULT 'pending',
            access_token   VARCHAR(64)     NULL,
            admin_note     TEXT            NULL,
            created_at     DATETIME        NOT NULL,
            reviewed_at    DATETIME        NULL,
            reviewed_by    BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_invoice_number (invoice_number),
            UNIQUE KEY uniq_access_token   (access_token),
            KEY idx_user_id   (user_id),
            KEY idx_status    (status),
            KEY idx_created   (created_at)
        ) $charset;";

        /*
         * ahp_invoice_items
         * ──────────────────
         * All values are "snapshots"; i.e. the final text/numeric value is
         * stored at the exact moment the user added it to the cart, with no
         * runtime dependency on later changes to the source table (apt_tables).
         *
         * source_table_id  : apt_tables.id reference (nullable, no hard FK -
         *                    because that table may later be deleted by
         *                    another plugin and this should never cause an error)
         * source_row_hash  : hash of the source row's full text, purely for future tracking/reporting (not used by current logic)
         * product_code     : product code, if any (nullable)
         * description      : full product description (a snapshot combining the desc/code-size/code-thick columns' final text)
         * unit             : 'kg', 'shakhe' (bundle/rod), or 'adad' (piece/count) - see AHP_Helpers::allowed_units()
         * quantity          : quantity/amount entered by the user
         * unit_price        : unit price (Rials) - a snapshot of the table's price column at the moment it was added
         * line_total        : quantity * unit_price (computed and stored at the moment it was added, not at display time)
         */
        $sql_items = "CREATE TABLE IF NOT EXISTS {$items} (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id       BIGINT UNSIGNED NOT NULL,
            source_table_id  BIGINT UNSIGNED NULL,
            source_row_hash  VARCHAR(64)     NULL,
            product_code     VARCHAR(100)    NULL,
            description      VARCHAR(500)    NOT NULL,
            unit             VARCHAR(20)     NOT NULL,
            quantity         DECIMAL(14,3)   NOT NULL,
            unit_price       BIGINT UNSIGNED NOT NULL,
            line_total       BIGINT UNSIGNED NOT NULL,
            sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at       DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_invoice_id      (invoice_id),
            KEY idx_source_table_id (source_table_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_invoices );
        dbDelta( $sql_items );

        // dbDelta sometimes (especially when a UNIQUE KEY immediately
        // follows a newly added column in the same SQL string) skips adding
        // the new column in an ALTER TABLE — this actually happened on the
        // live site (the access_token column wasn't added, causing an
        // "Unknown column" error on insert). To be fully safe, the column's
        // existence is explicitly checked/guaranteed here via ALTER TABLE,
        // independent of dbDelta's behavior.
        self::ensure_access_token_column();

        /*
         * ahp_otp_requests
         * ─────────────────
         * Phase 3: customer login/sign-up using only a mobile number + a
         * one-time SMS code (no password, no classic WordPress login form).
         *
         * mobile        : normalized mobile number (09xxxxxxxxx format)
         * code_hash     : hash of the code (the raw code is never stored - like a password)
         * pending_first_name / pending_last_name : for a new user who isn't
         *                 yet verified, kept until the verify step
         * attempts      : number of wrong attempts for this code (for verification rate limiting)
         * request_count : number of codes requested from this number in the recent window (for sending rate limiting)
         * expires_at    : code expiry time (create + 2 minutes, per current setting)
         * consumed_at   : time the code was successfully consumed (nullable - prevents reuse)
         * ip_address    : for reporting/IP-level rate limiting
         * created_at    : time the code was requested
         */
        $sql_otp = "CREATE TABLE IF NOT EXISTS {$otp} (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            mobile             VARCHAR(20)     NOT NULL,
            code_hash          VARCHAR(255)    NOT NULL,
            pending_first_name VARCHAR(100)    NULL,
            pending_last_name  VARCHAR(100)    NULL,
            attempts           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at         DATETIME        NOT NULL,
            consumed_at        DATETIME        NULL,
            ip_address         VARCHAR(45)     NULL,
            created_at         DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_mobile     (mobile),
            KEY idx_created    (created_at)
        ) $charset;";

        dbDelta( $sql_otp );
    }

    /**
     * Guarantees the access_token column exists in the ahp_invoices table,
     * independent of dbDelta. Reason: on the live site it was observed that
     * dbDelta did not add this column via ALTER TABLE (on an already-existing
     * table), causing an "Unknown column access_token in field list" error
     * in insert_invoice. This method explicitly checks via
     * information_schema and, if missing, runs ALTER TABLE itself —
     * completely idempotent and safe to call on every database version upgrade.
     */
    private static function ensure_access_token_column(): void {
        global $wpdb;
        $table = self::table_invoices();

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'access_token'",
            DB_NAME,
            $table
        ) );

        if ( (int) $exists > 0 ) {
            return; // the column already exists; nothing to do
        }

        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN access_token VARCHAR(64) NULL AFTER status" );

        // Also add the UNIQUE KEY separately (if not already present); in
        // its own try so that if it already existed for some reason (e.g. a
        // race between two requests), a "Duplicate key name" error doesn't
        // halt the whole installation.
        $key_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_access_token'",
            DB_NAME,
            $table
        ) );

        if ( (int) $key_exists === 0 ) {
            $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_access_token (access_token)" );
        }
    }

    /* ── Global invoice counter (never resets per year) ────────────────────── */

    /**
     * Atomically returns the next incrementing number.
     * Uses a single row in wp_options with an atomic query (not
     * read-then-write) so concurrent load (two users issuing at the same
     * time) never produces a duplicate number.
     */
    public static function get_next_seq_number(): int {
        global $wpdb;
        $option_name = 'ahp_invoice_seq';

        // Ensure the initial row exists (only runs once in the site's whole lifetime)
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $option_name )
        );
        if ( ! $exists ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')",
                $option_name
            ) );
        }

        // Atomic increment at the database level (UPDATE ... = value + 1), then read the new value
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s",
            $option_name
        ) );
        $val = $wpdb->get_var(
            $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name )
        );
        return (int) $val;
    }

    /* ── Invoices: base CRUD (more methods added in Phase 4/5) ─────────────── */

    public static function insert_invoice( array $data ): int {
        global $wpdb;
        $wpdb->insert(
            self::table_invoices(),
            [
                'invoice_number' => $data['invoice_number'],
                'seq_number'     => $data['seq_number'],
                'jalali_year'    => $data['jalali_year'],
                'user_id'        => $data['user_id'],
                'buyer_name'     => $data['buyer_name'],
                'buyer_phone'    => $data['buyer_phone'],
                'subtotal'       => $data['subtotal'],
                'total'          => $data['total'],
                'status'         => $data['status'] ?? 'pending',
                'access_token'   => $data['access_token'],
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public static function get_invoice( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table_invoices() . " WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_invoices_for_user( int $user_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_invoices() . " WHERE user_id = %d ORDER BY id DESC",
                $user_id
            ),
            ARRAY_A
        );
    }

    /** For the public HTML proforma invoice output page (Phase 4) — via access_token, not the raw id */
    public static function get_invoice_by_token( string $token ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table_invoices() . " WHERE access_token = %s", $token ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Full list of proforma invoices for the admin panel (Phase 5), with an
     * optional status filter and pagination; unlike get_invoices_for_user,
     * this is not restricted to a specific user.
     *
     * @param array{status?:string, per_page?:int, paged?:int} $args
     * @return array{items:array, total:int}
     */
    public static function get_all_invoices( array $args = [] ): array {
        global $wpdb;

        $status   = $args['status']   ?? '';
        $per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
        $paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
        $offset   = ( $paged - 1 ) * $per_page;

        $table = self::table_invoices();
        $where = '';
        $params = [];
        if ( $status !== '' ) {
            $where = 'WHERE status = %s';
            $params[] = $status;
        }

        $total_sql = "SELECT COUNT(*) FROM {$table} {$where}";
        $total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

        $list_sql = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $list_params = array_merge( $params, [ $per_page, $offset ] );
        $items = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

        return [ 'items' => $items, 'total' => $total ];
    }

    /**
     * Change a proforma invoice's status (admin action, Phase 5); also
     * updates reviewed_at/reviewed_by. Allowed status values are enforced in class-ahp-admin.php.
     */
    public static function update_invoice_status( int $id, string $status, int $reviewer_user_id, string $admin_note = '' ): bool {
        global $wpdb;
        $data = [
            'status'      => $status,
            'reviewed_at' => current_time( 'mysql' ),
            'reviewed_by' => $reviewer_user_id,
        ];
        $formats = [ '%s', '%s', '%d' ];

        if ( $admin_note !== '' ) {
            $data['admin_note'] = $admin_note;
            $formats[] = '%s';
        }

        $result = $wpdb->update( self::table_invoices(), $data, [ 'id' => $id ], $formats, [ '%d' ] );
        return $result !== false;
    }

    /* ── Invoice Items ──────────────────────────────────────────────────── */

    public static function insert_item( array $data ): int {
        global $wpdb;
        $wpdb->insert(
            self::table_items(),
            [
                'invoice_id'      => $data['invoice_id'],
                'source_table_id' => $data['source_table_id'] ?? null,
                'source_row_hash' => $data['source_row_hash'] ?? null,
                'product_code'    => $data['product_code'] ?? null,
                'description'     => $data['description'],
                'unit'            => $data['unit'],
                'quantity'        => $data['quantity'],
                'unit_price'      => $data['unit_price'],
                'line_total'      => $data['line_total'],
                'sort_order'      => $data['sort_order'] ?? 0,
                'created_at'      => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /** A single invoice-item row by id (for validating invoice_id in the admin handler) */
    public static function get_item( int $item_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table_items() . " WHERE id = %d", $item_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_items_for_invoice( int $invoice_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_items() . " WHERE invoice_id = %d ORDER BY sort_order ASC, id ASC",
                $invoice_id
            ),
            ARRAY_A
        );
    }

    /**
     * Edit a proforma invoice line item as an admin (Phase 5 addendum) —
     * description, unit, quantity, unit price. line_total is always
     * recomputed here from scratch so it can never fall out of sync with
     * quantity*unit_price. Per asp's explicit decision, this edit is always
     * allowed regardless of the invoice's status (even approved/rejected).
     */
    public static function update_item( int $item_id, array $data ): bool {
        global $wpdb;

        $quantity   = (float) $data['quantity'];
        $unit_price = (int) $data['unit_price'];
        $line_total = (int) round( $quantity * $unit_price );

        $result = $wpdb->update(
            self::table_items(),
            [
                'description' => $data['description'],
                'unit'        => $data['unit'],
                'quantity'    => $quantity,
                'unit_price'  => $unit_price,
                'line_total'  => $line_total,
            ],
            [ 'id' => $item_id ],
            [ '%s', '%s', '%f', '%d', '%d' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /** Delete a proforma invoice line item as an admin (Phase 5 addendum) */
    public static function delete_item( int $item_id ): bool {
        global $wpdb;
        $result = $wpdb->delete( self::table_items(), [ 'id' => $item_id ], [ '%d' ] );
        return $result !== false;
    }

    /**
     * Recompute a proforma invoice's subtotal/total from the current sum of
     * its line items and the current VAT rate setting
     * (AHP_Admin::get_vat_rate) — called after every admin edit/delete of a
     * line item so the document always stays consistent with its actual items.
     */
    public static function recalculate_invoice_totals( int $invoice_id ): void {
        global $wpdb;

        $subtotal = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(line_total), 0) FROM " . self::table_items() . " WHERE invoice_id = %d",
            $invoice_id
        ) );

        $vat_rate = class_exists( 'AHP_Admin' ) ? AHP_Admin::get_vat_rate() : 0.10;
        $total    = $subtotal + ( $subtotal * $vat_rate );

        $wpdb->update(
            self::table_invoices(),
            [ 'subtotal' => (int) round( $subtotal ), 'total' => (int) round( $total ) ],
            [ 'id' => $invoice_id ],
            [ '%d', '%d' ],
            [ '%d' ]
        );
    }

    /* ── OTP Requests ────────────────────────────────────────────────────── */

    public static function insert_otp( array $data ): int {
        global $wpdb;
        $wpdb->insert(
            self::table_otp(),
            [
                'mobile'             => $data['mobile'],
                'code_hash'          => $data['code_hash'],
                'pending_first_name' => $data['pending_first_name'] ?? null,
                'pending_last_name'  => $data['pending_last_name']  ?? null,
                'attempts'           => 0,
                'expires_at'         => $data['expires_at'],
                'ip_address'         => $data['ip_address'] ?? null,
                'created_at'         => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /** Returns the latest active (unconsumed) OTP request for a number */
    public static function get_latest_active_otp( string $mobile ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_otp() . "
                 WHERE mobile = %s AND consumed_at IS NULL
                 ORDER BY id DESC LIMIT 1",
                $mobile
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** Number of code requests from a number in the last N minutes (for send-rate limiting) */
    public static function count_recent_otp_requests( string $mobile, int $minutes ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table_otp() . "
                 WHERE mobile = %s AND created_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)",
                $mobile,
                $minutes
            )
        );
    }

    public static function increment_otp_attempts( int $id ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table_otp() . " SET attempts = attempts + 1 WHERE id = %d",
            $id
        ) );
    }

    public static function mark_otp_consumed( int $id ): void {
        global $wpdb;
        $wpdb->update(
            self::table_otp(),
            [ 'consumed_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );
    }
}