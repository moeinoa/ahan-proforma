/**
 * Ahan Proforma — client-side cart logic (no jQuery; jQuery exists in
 * WordPress, but we use vanilla JS to keep the plugin lightweight).
 *
 * Responsibilities:
 *  1) Listen for clicks on the .ahp-add-btn buttons that Phase 1-b injects into tables
 *  2) Fully render the cart in every [data-ahp-cart-root] element (both [ahp_cart] and the drawer)
 *  3) Update the cart counter in every [data-ahp-cart-count] instance, with a pulse animation
 *  4) Open/close the slide-out drawer ([ahp_cart_icon] + cart-drawer.php)
 *  5) After a successful add, directly open the cart drawer (no toast)
 */
( function () {
    'use strict';

    if ( typeof AHP_CART === 'undefined' ) return;

    function getCookie( name ) {
        var match = document.cookie.match( '(?:^|; )' + name + '=([^;]*)' );
        return match ? decodeURIComponent( match[1] ) : null;
    }

    /** Is the user currently logged in? Same lightweight cookie ahp-auth.js uses to switch the header icon. */
    function isLoggedIn() {
        return getCookie( 'ahp_logged_in' ) === '1';
    }

    /**
     * Fills an empty tab/window (preOpenedTab) with a minimal loading page,
     * so that instead of a blank white screen, the user knows to wait a
     * moment until the real proforma-invoice URL arrives. The spinner color
     * is the plugin's default brand value (not the admin panel's custom
     * --ahp-primary: this is a standalone about:blank document and has no
     * access to the site's main CSS — which AHP_Admin::print_color_overrides
     * injects in wp_head; reading the custom color here would need an extra
     * AJAX request before opening the tab, which isn't justified just for a
     * spinner shown for a fraction of a second). write() + close() are
     * needed so the browser is sure the document was fully written (without
     * these two, some browsers keep the tab's spinner running until the
     * next page finishes loading).
     */
    function showLoadingInTab( win ) {
        if ( ! win || win.closed ) return;
        try {
            win.document.open();
            win.document.write(
                '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">' +
                '<title>' + escapeHtml( AHP_CART.i18n.issuing ) + '</title>' +
                '<style>' +
                'html,body{height:100%;margin:0;}' +
                'body{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;' +
                'font-family:Tahoma,"Vazirmatn",sans-serif;color:#4b5563;background:#fff;}' +
                '.ahp-loading-spinner{width:40px;height:40px;border-radius:50%;' +
                'border:4px solid #e5e7eb;border-top-color:#0f9d58;' +
                'animation:ahp-spin 0.8s linear infinite;}' +
                '@keyframes ahp-spin{to{transform:rotate(360deg);}}' +
                '</style></head><body>' +
                '<div class="ahp-loading-spinner" aria-hidden="true"></div>' +
                '<p>' + escapeHtml( AHP_CART.i18n.issuing ) + '</p>' +
                '</body></html>'
            );
            win.document.close();
        } catch ( e ) {
            // If writing to the other tab's document wasn't possible for
            // any reason (e.g. a specific browser's security policy), the
            // page just stays plain white; location.href will still be set
            // correctly afterward.
        }
    }

    function refreshCartNonce() {
        return fetch( AHP_CART.ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams( { action: 'ahp_cart_refresh_nonce' } ).toString(),
        } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( res && res.success && res.data && res.data.nonce ) {
                    AHP_CART.nonce = res.data.nonce;
                }
            } );
    }

    /**
     * Like postAjax in ahp-auth.js: if the nonce is invalid (a raw "-1" or
     * "0" output, not JSON — exactly the sign check_ajax_referer gives on
     * failure), it fetches a fresh nonce once and resends the request. This
     * is the second line of defense; the first (an explicit nonce refresh
     * after ahp:loggedIn) is deliberately kept as well since it's faster
     * (no need for one failed round-trip).
     */
    function ajax( action, payload, retry ) {
        var body = new URLSearchParams( Object.assign( { action: action, nonce: AHP_CART.nonce }, payload || {} ) );
        return fetch( AHP_CART.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        } ).then( function ( res ) {
            return res.text().then( function ( text ) {
                var isNonceFailure = ( text.trim() === '-1' || text.trim() === '0' );
                if ( isNonceFailure && retry !== false ) {
                    return refreshCartNonce().then( function () {
                        return ajax( action, payload, false );
                    } );
                }
                try {
                    return JSON.parse( text );
                } catch ( e ) {
                    return { success: false, data: { message: AHP_CART.i18n.error } };
                }
            } );
        } );
    }

    function updateAllCounts( count, animate ) {
        document.querySelectorAll( '[data-ahp-cart-count]' ).forEach( function ( el ) {
            el.textContent = String( count );
            el.setAttribute( 'data-ahp-cart-count', String( count ) );
            if ( animate ) {
                el.classList.remove( 'ahp-pulse' );
                // Force a reflow so the animation restarts from scratch even on rapid repeated clicks
                void el.offsetWidth;
                el.classList.add( 'ahp-pulse' );
            }
        } );
        if ( animate ) {
            document.querySelectorAll( '.ahp-cart-icon-btn' ).forEach( function ( btn ) {
                btn.classList.remove( 'ahp-shake' );
                void btn.offsetWidth;
                btn.classList.add( 'ahp-shake' );
            } );
        }
    }

    /* ── Drawer ────────────────────────────────────────────────────────── */

    function openDrawer( preloadedData ) {
        var drawer  = document.querySelector( '[data-ahp-drawer]' );
        var overlay = document.querySelector( '[data-ahp-drawer-overlay]' );
        if ( ! drawer ) return;

        overlay.hidden = false;
        requestAnimationFrame( function () {
            overlay.classList.add( 'ahp-visible' );
            drawer.classList.add( 'ahp-open' );
        } );
        drawer.setAttribute( 'aria-hidden', 'false' );

        if ( preloadedData ) {
            // The data is already available (e.g. right after adding an
            // item); no need for a duplicate AJAX request to fetch the same data.
            renderCart( drawer, preloadedData );
        } else {
            loadCartInto( drawer );
        }
    }

    function closeDrawer() {
        var drawer  = document.querySelector( '[data-ahp-drawer]' );
        var overlay = document.querySelector( '[data-ahp-drawer-overlay]' );
        if ( ! drawer ) return;

        drawer.classList.remove( 'ahp-open' );
        overlay.classList.remove( 'ahp-visible' );
        drawer.setAttribute( 'aria-hidden', 'true' );

        setTimeout( function () { overlay.hidden = true; }, 380 );
    }

    document.addEventListener( 'click', function ( e ) {
        if ( e.target.closest( '[data-ahp-open-drawer]' ) ) {
            e.preventDefault();
            openDrawer();
            return;
        }
        if ( e.target.closest( '[data-ahp-close-drawer]' ) || e.target.closest( '[data-ahp-drawer-overlay]' ) ) {
            closeDrawer();
        }
    } );

    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key === 'Escape' ) {
            var drawer = document.querySelector( '[data-ahp-drawer].ahp-open' );
            if ( drawer ) closeDrawer();
        }
    } );

    /* ── SVG icons used on the add button (cart+ / loading checkmark) ────── */

    var ICON_CART_ADD =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none">' +
        '<path d="M3 4h1.8l1.2 2M7 6h13l-1.6 8.2a2 2 0 0 1-2 1.6H8.9a2 2 0 0 1-2-1.65L5 6Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>' +
        '<circle cx="9.5" cy="20" r="1.3" fill="currentColor"/><circle cx="16.5" cy="20" r="1.3" fill="currentColor"/>' +
        '<path d="M15 2v5M12.5 4.5h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

    var ICON_CHECK =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none">' +
        '<path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function setBtnIcon( btn, svg ) {
        var iconWrap = btn.querySelector( '.ahp-btn-icon' );
        if ( iconWrap ) iconWrap.innerHTML = svg;
    }

    function setBtnTooltip( btn, text ) {
        var tooltip = btn.querySelector( '.ahp-btn-tooltip' );
        if ( tooltip ) tooltip.textContent = text;
    }

    /**
     * When the description column in the table was empty
     * (data-ahp-desc-fallback="1" on the button), we walk up the page DOM
     * from the button's location and return the first h3, else h2, else h1
     * found preceding this button as the table's title heading. If the
     * heading text contains the word "قیمت" (price), that part is stripped
     * and the rest of the text is used.
     * Returns '' if no heading is found.
     */
    function findHeadingTitle( btn ) {
        var heading = findPrecedingHeading( btn, 'h3' ) ||
                      findPrecedingHeading( btn, 'h2' ) ||
                      findPrecedingHeading( btn, 'h1' );
        if ( ! heading ) return '';

        var text = ( heading.textContent || '' ).trim();
        if ( text.indexOf( 'قیمت' ) !== -1 ) {
            // Strip the word "قیمت" (price) and any number/character next
            // to it; only the remaining text is kept
            text = text
                .split( 'قیمت' )
                .map( function ( part ) { return part.trim(); } )
                .filter( function ( part ) { return part !== ''; } )
                .join( ' ' )
                .trim();
        }
        return text;
    }

    /**
     * Builds the final description when the desc column was empty
     * (data-ahp-desc-fallback="1"). The server already produced a
     * size/thickness based description (via build_description() in PHP);
     * here we prepend the table's title heading (found by walking up the
     * page DOM — something only doable client-side) so the customer sees
     * something like "قیمت لوله API تست گاز — سایز: 1/2 — ضخامت: 2.8"
     * instead of just the size/thickness fragment.
     */
    function buildFallbackDescription( btn, serverDescription ) {
        var headingTitle = findHeadingTitle( btn );
        if ( ! headingTitle ) return serverDescription;
        if ( ! serverDescription ) return headingTitle;
        return headingTitle + ' — ' + serverDescription;
    }

    /**
     * Walks up from the given element in the DOM: first among each level's
     * previous siblings, then moves one level up; returns the first tag
     * matching tagName that's found.
     */
    function findPrecedingHeading( el, tagName ) {
        var node = el;
        while ( node && node !== document.body ) {
            var sibling = node.previousElementSibling;
            while ( sibling ) {
                if ( sibling.tagName && sibling.tagName.toLowerCase() === tagName ) {
                    return sibling;
                }
                // If the sibling itself contains such a heading inside it (e.g. a wrapper)
                var nested = sibling.querySelector ? sibling.querySelector( tagName ) : null;
                if ( nested ) return nested;
                sibling = sibling.previousElementSibling;
            }
            node = node.parentElement;
        }
        return null;
    }

    /* ── Adding from the buttons on the price table ────────────────────────── */

    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '[data-ahp-action="add-to-proforma"]' );
        if ( ! btn ) return;

        if ( btn.disabled ) return;
        btn.disabled = true;

        var description = btn.getAttribute( 'data-ahp-description' ) || '';
        if ( btn.getAttribute( 'data-ahp-desc-fallback' ) === '1' ) {
            description = buildFallbackDescription( btn, description );
        }

        ajax( 'ahp_cart_add', {
            table_id:    btn.getAttribute( 'data-ahp-table-id' ),
            row_hash:    btn.getAttribute( 'data-ahp-row-hash' ),
            description: description,
            code:        btn.getAttribute( 'data-ahp-code' ),
            unit_price:  btn.getAttribute( 'data-ahp-price' ),
            unit:        btn.getAttribute( 'data-ahp-unit' ),
            quantity:    1,
        } ).then( function ( res ) {
            btn.disabled = false;
            if ( res.success ) {
                updateAllCounts( res.data.count, true );

                // The cart+ icon temporarily switches to a checkmark and the tooltip text becomes "added"
                setBtnIcon( btn, ICON_CHECK );
                setBtnTooltip( btn, AHP_CART.i18n.added );
                btn.classList.add( 'ahp-added' );
                setTimeout( function () {
                    setBtnIcon( btn, ICON_CART_ADD );
                    setBtnTooltip( btn, AHP_CART.i18n.addToProforma );
                    btn.classList.remove( 'ahp-added' );
                }, 1200 );

                refreshOpenWidgets( res.data );
                openDrawer( res.data );
            } else {
                alert( ( res.data && res.data.message ) || AHP_CART.i18n.error );
            }
        } ).catch( function () {
            btn.disabled = false;
            alert( AHP_CART.i18n.error );
        } );
    } );

    /* ── Full cart render ([ahp_cart]) ─────────────────────────────────────── */

    function renderCart( root, data ) {
        var body   = root.querySelector( '[data-ahp-cart-body]' );
        var footer = root.querySelector( '[data-ahp-cart-footer]' );
        var totalEl = root.querySelector( '[data-ahp-cart-total]' );

        if ( ! data.items.length ) {
            body.innerHTML = '<p class="ahp-cart-empty">' + AHP_CART.i18n.emptyCart + '</p>';
            footer.style.display = 'none';
            return;
        }

        var table = document.createElement( 'table' );
        table.className = 'ahp-cart-table';
        table.innerHTML =
            '<thead><tr>' +
            '<th></th>' +
            '<th>' + escapeHtml( labelDescription() ) + '</th>' +
            '<th>' + escapeHtml( labelUnit() ) + '</th>' +
            '<th>' + escapeHtml( labelQty() ) + '</th>' +
            '<th>' + escapeHtml( labelUnitPrice() ) + '</th>' +
            '<th>' + escapeHtml( labelLineTotal() ) + '</th>' +
            '</tr></thead>';

        var tbody = document.createElement( 'tbody' );
        var total = 0;

        data.items.forEach( function ( item ) {
            total += parseFloat( item.line_total );
            var tr = document.createElement( 'tr' );
            tr.setAttribute( 'data-ahp-item-key', item.item_key );
            tr.innerHTML =
                '<td><button type="button" class="ahp-cart-remove" data-ahp-remove title="' + escapeHtml( AHP_CART.i18n.confirmRemove ) + '">×</button></td>' +
                '<td>' + escapeHtml( item.description ) + ( item.code ? ' <span class="ahp-cart-code">(' + escapeHtml( item.code ) + ')</span>' : '' ) + '</td>' +
                '<td>' + escapeHtml( item.unit ) + '</td>' +
                '<td>' +
                    '<div class="ahp-qty-stepper" data-ahp-qty-stepper>' +
                        '<button type="button" class="ahp-qty-btn ahp-qty-dec" data-ahp-qty-dec aria-label="' + escapeHtml( labelDecrease() ) + '">−</button>' +
                        '<input type="number" class="ahp-cart-qty" min="1" step="1" value="' + parseInt( item.quantity, 10 ) + '" data-ahp-qty-input>' +
                        '<button type="button" class="ahp-qty-btn ahp-qty-inc" data-ahp-qty-inc aria-label="' + escapeHtml( labelIncrease() ) + '">+</button>' +
                    '</div>' +
                '</td>' +
                '<td>' + escapeHtml( item.unit_price_fa ) + '</td>' +
                '<td data-ahp-line-total>' + escapeHtml( item.line_total_fa ) + '</td>';
            tbody.appendChild( tr );
        } );

        table.appendChild( tbody );
        body.innerHTML = '';
        body.appendChild( table );

        footer.style.display = '';
        totalEl.textContent = data.items.length ? formatToman( total ) : '0';
    }

    // Labels come from AHP_CART.i18n (localized from PHP via wp_localize_script),
    // so they stay in sync with the plugin's .po translation file instead of
    // being hardcoded here.
    function labelIncrease()    { return AHP_CART.i18n.increaseQty; }
    function labelDecrease()    { return AHP_CART.i18n.decreaseQty; }
    function labelDescription() { return AHP_CART.i18n.colDescription; }
    function labelUnit()        { return AHP_CART.i18n.colUnit; }
    function labelQty()         { return AHP_CART.i18n.colQty; }
    function labelUnitPrice()   { return AHP_CART.i18n.colUnitPrice; }
    function labelLineTotal()   { return AHP_CART.i18n.colLineTotal; }

    function escapeHtml( str ) {
        var div = document.createElement( 'div' );
        div.textContent = str == null ? '' : String( str );
        return div.innerHTML;
    }

    function formatToman( n ) {
        try {
            return new Intl.NumberFormat( 'fa-IR' ).format( Math.round( n ) );
        } catch ( e ) {
            return String( Math.round( n ) );
        }
    }

    function loadCartInto( root ) {
        ajax( 'ahp_cart_get', {} ).then( function ( res ) {
            if ( res.success ) {
                renderCart( root, res.data );
                updateAllCounts( res.data.count );
            }
        } );
    }

    /**
     * After a successful add, re-renders every active cart root on the page
     * with fresh data (both [ahp_cart] if it's on the page, and the drawer
     * if it's already rendered). The last newly-added row in each flashes for a moment.
     */
    function refreshOpenWidgets( data ) {
        document.querySelectorAll( '[data-ahp-cart-root]' ).forEach( function ( root ) {
            renderCart( root, data );
            var lastRow = root.querySelector( '[data-ahp-cart-body] tbody tr:last-child' );
            if ( lastRow ) {
                lastRow.classList.add( 'ahp-row-flash' );
                setTimeout( function () { lastRow.classList.remove( 'ahp-row-flash' ); }, 1200 );
            }
        } );
    }

    document.addEventListener( 'click', function ( e ) {
        var removeBtn = e.target.closest( '[data-ahp-remove]' );
        if ( ! removeBtn ) return;
        var row = removeBtn.closest( '[data-ahp-item-key]' );
        if ( ! row ) return;
        if ( ! confirm( AHP_CART.i18n.confirmRemove ) ) return;

        var root = removeBtn.closest( '[data-ahp-cart-root]' );
        ajax( 'ahp_cart_remove', { item_key: row.getAttribute( 'data-ahp-item-key' ) } ).then( function ( res ) {
            if ( res.success ) {
                renderCart( root, res.data );
                updateAllCounts( res.data.count );
            }
        } );
    } );

    /* ── Quantity change (integer input + / − buttons) ──────────────────── */

    function setRowLoading( row, loading ) {
        if ( ! row ) return;
        row.classList.toggle( 'ahp-row-loading', !! loading );
        var controls = row.querySelectorAll( '[data-ahp-qty-input], [data-ahp-qty-inc], [data-ahp-qty-dec]' );
        controls.forEach( function ( el ) { el.disabled = !! loading; } );
    }

    function sendQtyUpdate( root, row, itemKey, quantity ) {
        setRowLoading( row, true );
        return ajax( 'ahp_cart_update_qty', { item_key: itemKey, quantity: quantity } ).then( function ( res ) {
            if ( res.success ) {
                renderCart( root, res.data );
                updateAllCounts( res.data.count );
            } else {
                setRowLoading( row, false );
            }
        } ).catch( function () {
            setRowLoading( row, false );
        } );
    }

    var qtyDebounce = {};
    document.addEventListener( 'input', function ( e ) {
        var input = e.target.closest( '[data-ahp-qty-input]' );
        if ( ! input ) return;
        var row = input.closest( '[data-ahp-item-key]' );
        if ( ! row ) return;
        var itemKey = row.getAttribute( 'data-ahp-item-key' );

        // Integers only: any invalid entered value is immediately rounded
        var raw = parseInt( input.value, 10 );
        if ( isNaN( raw ) || raw < 1 ) raw = 1;
        input.value = raw;

        clearTimeout( qtyDebounce[ itemKey ] );
        qtyDebounce[ itemKey ] = setTimeout( function () {
            var root = input.closest( '[data-ahp-cart-root]' );
            sendQtyUpdate( root, row, itemKey, raw );
        }, 500 );
    } );

    document.addEventListener( 'click', function ( e ) {
        var stepBtn = e.target.closest( '[data-ahp-qty-inc], [data-ahp-qty-dec]' );
        if ( ! stepBtn ) return;
        if ( stepBtn.disabled ) return;

        var row = stepBtn.closest( '[data-ahp-item-key]' );
        if ( ! row ) return;
        var itemKey = row.getAttribute( 'data-ahp-item-key' );
        var input   = row.querySelector( '[data-ahp-qty-input]' );
        if ( ! input ) return;

        var current = parseInt( input.value, 10 );
        if ( isNaN( current ) || current < 1 ) current = 1;

        var next = stepBtn.hasAttribute( 'data-ahp-qty-inc' ) ? current + 1 : current - 1;
        if ( next < 1 ) next = 1; // the minimum allowed value is 1; use the × button to remove instead

        input.value = next;

        var root = stepBtn.closest( '[data-ahp-cart-root]' );
        var itemKeyForRow = itemKey;
        clearTimeout( qtyDebounce[ itemKeyForRow ] );
        sendQtyUpdate( root, row, itemKeyForRow, next );
    } );

    /* ── Issuing the proforma invoice (the .ahp-checkout-btn button in cart.php and cart-drawer.php) ── */

    // If the last issue attempt was rejected because the user wasn't logged
    // in, this same button and cart root are kept, so that after the
    // ahp:loggedIn event (Phase 3, fired by ahp-auth.js after a successful
    // login/sign-up in that same popup) the issuance is automatically
    // retried; the user doesn't have to click the button manually again after logging in.
    var pendingIssueAfterLogin = null;

    function issueInvoice( issueBtn, msgEl, preOpenedTab ) {
        issueBtn.disabled = true;
        if ( msgEl ) {
            msgEl.textContent = AHP_CART.i18n.issuing;
            msgEl.classList.remove( 'is-error' );
        }

        ajax( 'ahp_cart_issue_invoice', {} ).then( function ( res ) {
            if ( res.success ) {
                pendingIssueAfterLogin = null;
                // Opens in a new tab (not a redirect of the same tab) so the
                // user doesn't lose their current cart/page; the output page
                // is a standalone, printable HTML page.
                //
                // Important note about pop-up blockers: since this code runs
                // inside a fetch's .then() (asynchronous), if window.open()
                // were called right here, the browser no longer recognizes
                // it as "directly in response to a user click" and blocks it
                // as a pop-up — especially in the automatic flow after login,
                // where several fetch/setTimeout calls have created a gap
                // between the user's original click and this moment. Fix: an
                // empty tab (preOpenedTab) is opened in advance, exactly at
                // the synchronous moment of the user's click, and here we
                // just set its location to the real URL — no browser treats this as a pop-up.
                if ( preOpenedTab && ! preOpenedTab.closed ) {
                    preOpenedTab.location.href = res.data.url;
                } else {
                    // Fallback (e.g. if preOpenedTab came back null from a
                    // blocker right from the start): a direct attempt, which may be blocked.
                    window.open( res.data.url, '_blank', 'noopener,noreferrer' );
                }
                issueBtn.disabled = false;

                // A successful issue means the server-side cart has been
                // cleared (AHP_Cart::clear inside AHP_Invoice::issue_from_cart);
                // the UI should sync with this reality right now, rather than
                // leaving the user confused looking at a cart that still
                // looks full. This matters especially when this function ran
                // automatically after login (not from the user directly
                // clicking the button), since at that moment the user's
                // attention isn't necessarily on the cart and they might not notice it emptied.
                document.querySelectorAll( '[data-ahp-cart-root]' ).forEach( function ( r ) {
                    renderCart( r, { items: [] } );
                } );
                updateAllCounts( 0 );

                if ( msgEl ) {
                    msgEl.innerHTML = escapeHtml( res.data.message || '' ) +
                        ( res.data.accountUrl
                            ? ' <a href="' + escapeHtml( res.data.accountUrl ) + '" target="_blank" rel="noopener noreferrer">' +
                              escapeHtml( AHP_CART.i18n.viewMyInvoices || '' ) + '</a>'
                            : '' );
                    msgEl.classList.remove( 'is-error' );
                }
                return;
            }

            issueBtn.disabled = false;
            var message = ( res.data && res.data.message ) || AHP_CART.i18n.error;
            if ( msgEl ) {
                msgEl.textContent = message;
                msgEl.classList.add( 'is-error' );
            } else {
                alert( message );
            }

            // If the error was due to not being logged in, open the login
            // modal (if it's on the page) and keep the issuance "pending" for after a successful login.
            if ( res.data && res.data.needsLogin ) {
                pendingIssueAfterLogin = { btn: issueBtn, msgEl: msgEl, tab: preOpenedTab };
                var openAuthBtn = document.querySelector( '[data-ahp-open-auth]' );
                if ( openAuthBtn ) openAuthBtn.click();
            } else {
                pendingIssueAfterLogin = null;
                // Login wasn't needed but the issuance failed for another
                // reason (e.g. an empty cart); the already-opened empty tab is no longer needed.
                if ( preOpenedTab && ! preOpenedTab.closed ) preOpenedTab.close();
            }
        } ).catch( function () {
            issueBtn.disabled = false;
            if ( msgEl ) {
                msgEl.textContent = AHP_CART.i18n.error;
                msgEl.classList.add( 'is-error' );
            }
            if ( preOpenedTab && ! preOpenedTab.closed ) preOpenedTab.close();
        } );
    }

    document.addEventListener( 'click', function ( e ) {
        var issueBtn = e.target.closest( '[data-ahp-issue-invoice]' );
        if ( ! issueBtn ) return;

        var root = issueBtn.closest( '[data-ahp-cart-root]' );
        var msgEl = root ? root.querySelector( '[data-ahp-issue-msg]' ) : null;

        // The empty tab is only opened right now when we already know the
        // user is logged in — meaning it will genuinely fill in soon (after
        // a fetch). If we always opened this tab (even for a guest), a user
        // who just had the login modal opened for them would see a mysterious
        // blank tab next to the modal that stays blank until login fully
        // completes — a confusing experience that looks broken. For a guest
        // user, the empty tab is opened later, in response to the real click
        // on the "verify code" button (verifyCode in ahp-auth.js), not here.
        var preOpenedTab = isLoggedIn() ? window.open( '', '_blank' ) : null;
        showLoadingInTab( preOpenedTab );

        issueInvoice( issueBtn, msgEl, preOpenedTab );
    } );

    document.addEventListener( 'ahp:loggedIn', function () {
        if ( ! pendingIssueAfterLogin ) return;
        var pending = pendingIssueAfterLogin;
        pendingIssueAfterLogin = null;

        // AHP_CART.nonce was created at page-load time, based on the uid at
        // that moment (guest=0); after a successful login (even without a
        // page reload) it's no longer valid for the new uid. We refresh it
        // here in advance so the retried issuance isn't rejected with a 403;
        // ajax() itself also has a second defensive layer (automatic retry
        // on nonce failure), but an explicit refresh here is faster.
        refreshCartNonce().catch( function () { /* ajax() still retries as the second layer */ } ).then( function () {
            // A small delay so the login modal closes and the server-side
            // login state is fully established (same 900ms delay pattern as ahp-account.js).
            setTimeout( function () {
                issueInvoice( pending.btn, pending.msgEl, pending.tab );
            }, 500 );
        } );
    } );

    // For a guest user who has the login modal open: the empty tab destined
    // for the proforma invoice is opened *here*, exactly at the moment the
    // "verify code" form is submitted (not at the moment of the initial
    // click on the "issue proforma invoice" button, since at that moment
    // it's still unknown whether the user will complete their login in a
    // few seconds/minutes or give up entirely — opening a blank tab right
    // then, next to the login modal, looks confusing and like the site is
    // broken). This submit is itself a direct user action, so the synchronous
    // window.open here isn't treated as a pop-up by the browser either.
    document.addEventListener( 'submit', function ( e ) {
        if ( ! pendingIssueAfterLogin || pendingIssueAfterLogin.tab ) return;
        var form = e.target;
        if ( ! form || form.getAttribute( 'data-ahp-auth-step' ) !== 'code' ) return;

        pendingIssueAfterLogin.tab = window.open( '', '_blank' );
        showLoadingInTab( pendingIssueAfterLogin.tab );
    } );

    // If the user closes the login modal without completing login (clicking
    // ×, clicking outside the modal, or Escape), the preOpenedTab empty tab
    // opened at the initial "issue proforma invoice" click must be closed;
    // otherwise a useless about:blank tab is left for the user. This listens
    // directly on the same modal-close buttons (not a dedicated event from
    // ahp-auth.js) so no change to ahp-auth.js is needed.
    document.addEventListener( 'click', function ( e ) {
        if ( ! pendingIssueAfterLogin ) return;
        if ( ! e.target.closest( '[data-ahp-auth-close]' ) && ! e.target.closest( '[data-ahp-auth-overlay]' ) ) return;

        var tab = pendingIssueAfterLogin.tab;
        if ( tab && ! tab.closed ) tab.close();
        pendingIssueAfterLogin = null;
    } );

    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key !== 'Escape' || ! pendingIssueAfterLogin ) return;
        var tab = pendingIssueAfterLogin.tab;
        if ( tab && ! tab.closed ) tab.close();
        pendingIssueAfterLogin = null;
    } );

    document.addEventListener( 'DOMContentLoaded', function () {
        var root = document.querySelector( '[data-ahp-cart-root]' );
        if ( root ) {
            loadCartInto( root ); // this also updates the count itself
        } else {
            ajax( 'ahp_cart_get', {} ).then( function ( res ) {
                if ( res.success ) updateAllCounts( res.data.count );
            } );
        }
    } );
} )();