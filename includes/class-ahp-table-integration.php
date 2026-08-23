<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_Table_Integration
 *
 * Phase 1-b: a "soft" connection to the Ahan Price Tables plugin via the
 * apt_table_rendered_html filter (the only connection point between the two
 * plugins, with no direct call to any APT_* class). Adds a new column
 * titled "Buy" to each table's final HTML: for each row, depending on
 * whether the price is valid, either an "Add to invoice" button or a "Call
 * us" button is placed.
 *
 * This class only generates HTML/attributes; the cart logic (AJAX, storage)
 * is implemented in Phase 2 (class-ahp-cart.php). The buttons carry the
 * data-* attributes needed for Phase 2's JavaScript right here, so that
 * phase doesn't need to change the HTML structure again.
 */
class AHP_Table_Integration {

    public static function init(): void {
        // The default priority is enough; this filter is only called by the source plugin.
        add_filter( 'apt_table_rendered_html', [ __CLASS__, 'inject_proforma_column' ], 10, 3 );
    }

    /**
     * @param string $html      The table's final rendered HTML (after the source plugin applies its column rules)
     * @param int    $table_id  The apt_tables record id (source_table_id for the snapshot)
     * @param array  $col_rules The source plugin's column rules (not used in this phase; only kept for the filter signature)
     */
    public static function inject_proforma_column( string $html, int $table_id, array $col_rules ): string {
        if ( $html === '' || ! str_contains( $html, '<table' ) ) {
            return $html;
        }

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
        libxml_clear_errors();

        $table = $dom->getElementsByTagName( 'table' )->item( 0 );
        if ( ! $table ) {
            return $html;
        }

        $xpath = new DOMXPath( $dom );

        // If no column has data-col="price", it means the source plugin
        // couldn't determine the columns' roles; in that case we return the
        // table unchanged so no incorrect behavior occurs (like showing the
        // button on the wrong column).
        $price_cells_probe = $xpath->query( './/tbody//td[@data-col="price"]', $table );
        if ( ! $price_cells_probe || $price_cells_probe->length === 0 ) {
            return $html;
        }

        // 1. Add a new column at the end of the header
        $header_row = $xpath->query( './/thead/tr[1]', $table )->item( 0 );
        if ( $header_row ) {
            $th = $dom->createElement( 'th' );
            $th->setAttribute( 'class', 'ahp-col-header' );
            $th->appendChild( $dom->createTextNode( __( 'Buy', 'ahan-proforma' ) ) );
            $header_row->appendChild( $th );
        }

        // 2. Process every data row
        $body_rows = $xpath->query( './/tbody/tr', $table );
        if ( ! $body_rows || $body_rows->length === 0 ) {
            // No explicit tbody: treat every row except the first (header) as a data row
            $all_trs   = $xpath->query( './/tr', $table );
            $body_rows = [];
            if ( $all_trs ) {
                foreach ( $all_trs as $ri => $tr ) {
                    if ( $ri === 0 ) continue;
                    $body_rows[] = $tr;
                }
            }
        }

        foreach ( $body_rows as $row ) {
            self::inject_row_cell( $dom, $xpath, $row, $table_id );
        }

        return $dom->saveHTML( $table );
    }

    /**
     * Adds a new <td> at the end of the row, containing either the add
     * button or the contact button, depending on whether that row's price is valid.
     */
    private static function inject_row_cell( DOMDocument $dom, DOMXPath $xpath, DOMNode $row, int $table_id ): void {
        $price_cell = $xpath->query( './/td[@data-col="price"]', $row )->item( 0 );

        $td = $dom->createElement( 'td' );
        $td->setAttribute( 'class', 'ahp-col-action' );

        if ( ! $price_cell || self::is_row_contactable( $price_cell->textContent ) ) {
            $td->appendChild( self::build_contact_button( $dom ) );
            $row->appendChild( $td );
            return;
        }

        $price = self::extract_price( $price_cell->textContent );
        if ( $price === null || $price <= 0.0 ) {
            // A zero or unparsable price is also treated as "call us"
            // (covers the case where the price column has an invalid number like zero).
            $td->appendChild( self::build_contact_button( $dom ) );
            $row->appendChild( $td );
            return;
        }

        $desc_cell_empty = self::is_desc_cell_empty( $xpath, $row );
        $description     = self::build_description( $xpath, $row );
        $code            = self::build_code( $xpath, $row );
        $unit            = self::guess_unit( $description );
        $row_hash        = substr( hash( 'sha256', $description . '|' . $price ), 0, 32 );

        $btn = $dom->createElement( 'button' );
        $btn->setAttribute( 'type', 'button' );
        $btn->setAttribute( 'class', 'ahp-add-btn' );
        $btn->setAttribute( 'data-ahp-action', 'add-to-proforma' );
        $btn->setAttribute( 'data-ahp-table-id', (string) $table_id );
        $btn->setAttribute( 'data-ahp-row-hash', $row_hash );
        $btn->setAttribute( 'data-ahp-description', $description );
        // If the desc column was empty, the JavaScript walks up the page DOM
        // on click (h3, then h2, then h1) to find a substitute description.
        if ( $desc_cell_empty ) {
            $btn->setAttribute( 'data-ahp-desc-fallback', '1' );
        }
        $btn->setAttribute( 'data-ahp-code', $code );
        $btn->setAttribute( 'data-ahp-price', (string) $price );
        $btn->setAttribute( 'data-ahp-unit', $unit );
        $btn->setAttribute( 'aria-label', __( 'Add to invoice', 'ahan-proforma' ) );

        // Cart+ icon (raw SVG; safe since no dynamic/user content is injected into it)
        $icon_wrap = $dom->createElement( 'span' );
        $icon_wrap->setAttribute( 'class', 'ahp-btn-icon' );
        $fragment = $dom->createDocumentFragment();
        $fragment->appendXML( self::ADD_ICON_SVG );
        $icon_wrap->appendChild( $fragment );
        $btn->appendChild( $icon_wrap );

        // Custom tooltip (instead of/in addition to the browser's title, for a consistent style with the rest of the plugin)
        $tooltip = $dom->createElement( 'span' );
        $tooltip->setAttribute( 'class', 'ahp-btn-tooltip' );
        $tooltip->appendChild( $dom->createTextNode( __( 'Add to invoice', 'ahan-proforma' ) ) );
        $btn->appendChild( $tooltip );

        $td->appendChild( $btn );
        $row->appendChild( $td );
    }

    /** Cart-with-plus icon — used on the add button (Phase 1-b) */
    private const ADD_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M3 4h1.8l1.2 2M7 6h13l-1.6 8.2a2 2 0 0 1-2 1.6H8.9a2 2 0 0 1-2-1.65L5 6Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="20" r="1.3" fill="currentColor"/><circle cx="16.5" cy="20" r="1.3" fill="currentColor"/><path d="M15 2v5M12.5 4.5h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

    /** Checkmark icon — used in JS after a successful add (injected client-side, not here) */
    public const CHECK_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    /**
     * Detecting "call us" prioritizes the source plugin's own logic (if
     * available), so behavior always stays consistent with it; otherwise
     * (the source plugin is inactive), an equivalent internal fallback is used.
     */
    private static function is_row_contactable( string $raw_price ): bool {
        if ( class_exists( 'APT_Adapters' ) && method_exists( 'APT_Adapters', 'is_contactable_price' ) ) {
            return APT_Adapters::is_contactable_price( $raw_price );
        }
        $raw = trim( $raw_price );
        if ( $raw === '' ) return true;
        $clean = str_replace(
            [ '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' ],
            [ '0','1','2','3','4','5','6','7','8','9' ],
            $raw
        );
        $clean = str_replace( [ ',', '،', "\u{066C}", '.', ' ', "\u{00A0}" ], '', $clean );
        return ! is_numeric( $clean );
    }

    /** Extract the final numeric price value (Persian/English, with commas) as a float */
    private static function extract_price( string $raw_price ): ?float {
        $raw = trim( $raw_price );
        if ( $raw === '' ) return null;
        $clean = str_replace(
            [ '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' ],
            [ '0','1','2','3','4','5','6','7','8','9' ],
            $raw
        );
        $clean = str_replace( [ ',', '،', "\u{066C}", ' ', "\u{00A0}" ], '', $clean );
        return is_numeric( $clean ) ? (float) $clean : null;
    }

    /** Is this row's data-col="desc" column empty (or missing entirely)? */
    private static function is_desc_cell_empty( DOMXPath $xpath, DOMNode $row ): bool {
        $desc_cell = $xpath->query( './/td[@data-col="desc"]', $row )->item( 0 );
        if ( ! $desc_cell ) return true;
        return trim( $desc_cell->textContent ) === '';
    }

    /**
     * Builds the product description from the combination of the desc +
     * code-size + code-thick columns (in the same order they appear in the
     * table), so the snapshot is complete and readable.
     * Example output: "Seamless pipe — Size: 1/2 — Thickness: 2"
     */
    private static function build_description( DOMXPath $xpath, DOMNode $row ): string {
        $desc_cell = $xpath->query( './/td[@data-col="desc"]', $row )->item( 0 );
        $parts     = [];

        if ( $desc_cell ) {
            $text = trim( $desc_cell->textContent );
            if ( $text !== '' ) $parts[] = $text;
        }

        $size_cell = $xpath->query( './/td[@data-col="code-size"]', $row )->item( 0 );
        if ( $size_cell ) {
            $text = trim( $size_cell->textContent );
            if ( $text !== '' ) {
                /* translators: %s: size value */
                $parts[] = sprintf( __( 'Size: %s', 'ahan-proforma' ), $text );
            }
        }

        $thick_cell = $xpath->query( './/td[@data-col="code-thick"]', $row )->item( 0 );
        if ( $thick_cell ) {
            $text = trim( $thick_cell->textContent );
            if ( $text !== '' ) {
                /* translators: %s: thickness value */
                $parts[] = sprintf( __( 'Thickness: %s', 'ahan-proforma' ), $text );
            }
        }

        if ( empty( $parts ) ) {
            // Final fallback: the row's full text (excluding the price column), to avoid an empty description
            $cells = $xpath->query( './/td', $row );
            $fallback = [];
            foreach ( $cells as $c ) {
                if ( $c->getAttribute( 'data-col' ) === 'price' ) continue;
                $t = trim( $c->textContent );
                if ( $t !== '' ) $fallback[] = $t;
            }
            return implode( ' — ', $fallback );
        }

        return implode( ' — ', $parts );
    }

    /** Returns the product code as "size×thick" if the size/thick columns exist, otherwise an empty string */
    private static function build_code( DOMXPath $xpath, DOMNode $row ): string {
        $size  = $xpath->query( './/td[@data-col="code-size"]', $row )->item( 0 );
        $thick = $xpath->query( './/td[@data-col="code-thick"]', $row )->item( 0 );

        $size_txt  = $size  ? trim( $size->textContent )  : '';
        $thick_txt = $thick ? trim( $thick->textContent ) : '';

        if ( $size_txt !== '' && $thick_txt !== '' ) {
            return $size_txt . '×' . $thick_txt;
        }
        return $size_txt !== '' ? $size_txt : $thick_txt;
    }

    /**
     * Guesses the default unit from the product description text; this is
     * merely a suggested default for pre-selecting the add form — the user
     * can change it in Phase 2. Only two values are allowed anywhere in the
     * system: 'kg' or 'shakhe' (bundle/rod).
     *
     * Note: the matched substrings below ("شاخه" = bundle/rod, "متر" =
     * meter) are intentionally kept in Persian because they match actual
     * Persian text coming from the source price-table data, not
     * user-facing plugin UI strings.
     */
    private static function guess_unit( string $description ): string {
        if ( str_contains( $description, 'شاخه' ) || str_contains( $description, 'متر' ) ) {
            return 'shakhe';
        }
        return 'kg';
    }

    /** "Call us" button — links to the store's phone number (from settings, editable in Phase 5) */
    private static function build_contact_button( DOMDocument $dom ): DOMElement {
        $phone = apply_filters( 'ahp_store_phone_number', get_option( 'ahp_store_phone', '+982149986222' ) );
        $label = __( 'Call us', 'ahan-proforma' );

        if ( $phone !== '' ) {
            $a = $dom->createElement( 'a' );
            $a->setAttribute( 'class', 'ahp-contact-btn' );
            $a->setAttribute( 'href', 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) );
            $a->setAttribute( 'aria-label', $label );
            self::append_icon_and_tooltip( $dom, $a, self::CONTACT_ICON_SVG, $label );
            return $a;
        }

        // No number configured: a disabled button with a helper tooltip (so the page isn't broken/misleading)
        $span = $dom->createElement( 'span' );
        $span->setAttribute( 'class', 'ahp-contact-btn ahp-contact-btn--disabled' );
        $span->setAttribute( 'aria-label', $label );
        self::append_icon_and_tooltip( $dom, $span, self::CONTACT_ICON_SVG, $label );
        return $span;
    }

    /** Add an SVG icon + a span.ahp-btn-tooltip to a button/link element */
    private static function append_icon_and_tooltip( DOMDocument $dom, DOMElement $el, string $svg, string $tooltip_text ): void {
        $icon_wrap = $dom->createElement( 'span' );
        $icon_wrap->setAttribute( 'class', 'ahp-btn-icon' );
        $fragment = $dom->createDocumentFragment();
        $fragment->appendXML( $svg );
        $icon_wrap->appendChild( $fragment );
        $el->appendChild( $icon_wrap );

        $tooltip = $dom->createElement( 'span' );
        $tooltip->setAttribute( 'class', 'ahp-btn-tooltip' );
        $tooltip->appendChild( $dom->createTextNode( $tooltip_text ) );
        $el->appendChild( $tooltip );
    }

    /** Phone icon — used on the "Call us" button */
    private const CONTACT_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1.1-.3 1.2.4 2.5.6 3.8.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.9 21 3 13.1 3 3.7c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.6.6 3.8.1.4 0 .8-.3 1.1L6.6 10.8Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}
