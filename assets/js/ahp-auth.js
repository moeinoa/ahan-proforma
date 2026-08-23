/**
 * AHP Auth — two-step login/sign-up form with OTP, as a popup (modal) or a
 * standalone page. No dependency on jQuery; just the raw Fetch API.
 *
 * Key behavior: after a successful login/sign-up in popup mode, no
 * refresh or redirect happens. The user stays exactly where they were; only
 * 1) the modal closes  2) the header icon switches from "log in" to
 * "account"  3) the ahp:loggedIn event is fired so that if other code (e.g.
 * the proforma-invoice issuance form in Phase 4) was waiting for this moment, it continues right away:
 *
 *   document.addEventListener( 'ahp:loggedIn', function ( e ) {
 *       // e.detail.isNewUser is available
 *   } );
 *
 * To open the popup from outside (e.g. from ahp-cart.js when a guest user
 * wants to issue a final proforma invoice):
 *
 *   document.dispatchEvent( new CustomEvent( 'ahp:openAuth' ) );
 */
( function () {
    'use strict';

    function ready( fn ) {
        if ( document.readyState !== 'loading' ) fn();
        else document.addEventListener( 'DOMContentLoaded', fn );
    }

    function getCookie( name ) {
        var match = document.cookie.match( '(?:^|; )' + name + '=([^;]*)' );
        return match ? decodeURIComponent( match[1] ) : null;
    }

    ready( function () {
        if ( typeof AHP_AUTH === 'undefined' ) return;

        /* ── Header icon switch (guest ↔ account) ──────────────────────────── */

        // If this page's HTML was served from cache and says "guest", but
        // the lightweight ahp_logged_in cookie says the user is really
        // logged in (e.g. the user logged in a few pages ago and this page
        // came from an old cache), we fix the icon right at the start;
        // without this fix, a logged-in user would forever see the cached "log in" icon.
        var iconWraps = document.querySelectorAll( '[data-ahp-auth-icon-wrap]' );
        var reallyLoggedIn = getCookie( 'ahp_logged_in' ) === '1';

        function applyAuthState( wrap, loggedIn ) {
            wrap.setAttribute( 'data-ahp-auth-state', loggedIn ? 'in' : 'out' );
            var guestBtn   = wrap.querySelector( '[data-ahp-open-auth]' );
            var accountBox = wrap.querySelector( '[data-ahp-account-menu-root]' );
            if ( guestBtn )   guestBtn.style.display   = loggedIn ? 'none' : '';
            if ( accountBox ) accountBox.style.display = loggedIn ? '' : 'none';
        }

        iconWraps.forEach( function ( wrap ) {
            var currentState = wrap.getAttribute( 'data-ahp-auth-state' );
            if ( reallyLoggedIn && currentState !== 'in' ) {
                applyAuthState( wrap, true );
            }
        } );

        /* ── Account menu (a minimal dropdown on the icon) ─────────────────── */

        document.addEventListener( 'click', function ( e ) {
            var toggleBtn = e.target.closest( '[data-ahp-toggle-account-menu]' );
            if ( toggleBtn ) {
                e.preventDefault();
                var root = toggleBtn.closest( '[data-ahp-account-menu-root]' );
                var menu = root.querySelector( '[data-ahp-account-menu]' );
                var willOpen = menu.hidden;

                // Close every other open menu (in case the icon appears in several places on the page)
                document.querySelectorAll( '[data-ahp-account-menu]' ).forEach( function ( m ) {
                    m.hidden = true;
                } );
                document.querySelectorAll( '[data-ahp-toggle-account-menu]' ).forEach( function ( b ) {
                    b.setAttribute( 'aria-expanded', 'false' );
                } );

                if ( willOpen ) {
                    menu.hidden = false;
                    toggleBtn.setAttribute( 'aria-expanded', 'true' );
                }
                return;
            }

            // A click outside the menu → close
            if ( ! e.target.closest( '[data-ahp-account-menu]' ) ) {
                document.querySelectorAll( '[data-ahp-account-menu]' ).forEach( function ( m ) {
                    m.hidden = true;
                } );
                document.querySelectorAll( '[data-ahp-toggle-account-menu]' ).forEach( function ( b ) {
                    b.setAttribute( 'aria-expanded', 'false' );
                } );
            }
        } );

        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' ) {
                document.querySelectorAll( '[data-ahp-account-menu]' ).forEach( function ( m ) {
                    m.hidden = true;
                } );
            }
        } );

        /* ── Login/sign-up modal management ────────────────────────────────── */

        var modal   = document.querySelector( '[data-ahp-auth-modal]' );
        var overlay = document.querySelector( '[data-ahp-auth-overlay]' );
        var isModalOpen = false;

        function openAuthModal() {
            if ( ! modal || ! overlay ) return;
            modal.setAttribute( 'aria-hidden', 'false' );
            overlay.hidden = false;
            requestAnimationFrame( function () {
                overlay.classList.add( 'ahp-visible' );
                modal.classList.add( 'ahp-open' );
            } );
            document.body.classList.add( 'ahp-modal-open' );
            isModalOpen = true;
            resetFormToFirstStep();

            // Since this modal is injected into the footer of every page
            // (even pages cached by LiteSpeed), the nonce inside the HTML
            // may be stale/expired. So we don't have to make the whole site
            // no-cache, we fetch a fresh nonce every time the modal opens;
            // ajax_refresh_nonce is public and itself needs no valid nonce, so this request always succeeds.
            fetch( AHP_AUTH.ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams( { action: 'ahp_refresh_nonce' } ).toString(),
            } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( res ) {
                    if ( res && res.success && res.data && res.data.nonce ) {
                        AHP_AUTH.nonce = res.data.nonce;
                    }
                } )
                .catch( function () { /* worst case, the retry mechanism inside postAjax still has us covered */ } );

            var mobileInput = modal.querySelector( '[data-ahp-auth-mobile]' );
            if ( mobileInput ) setTimeout( function () { mobileInput.focus(); }, 150 );
        }

        function closeAuthModal() {
            if ( ! modal || ! overlay ) return;
            modal.classList.remove( 'ahp-open' );
            overlay.classList.remove( 'ahp-visible' );
            modal.setAttribute( 'aria-hidden', 'true' );
            document.body.classList.remove( 'ahp-modal-open' );
            isModalOpen = false;
            setTimeout( function () { overlay.hidden = true; }, 280 );
        }

        document.addEventListener( 'click', function ( e ) {
            if ( e.target.closest( '[data-ahp-open-auth]' ) ) {
                e.preventDefault();
                openAuthModal();
            }
            if ( e.target.closest( '[data-ahp-auth-close]' ) || e.target.closest( '[data-ahp-auth-overlay]' ) ) {
                closeAuthModal();
            }
        } );

        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && isModalOpen ) closeAuthModal();
        } );

        // Lets other parts of the plugin (e.g. ahp-cart.js when a guest
        // user tries to issue a proforma invoice) open the popup directly.
        document.addEventListener( 'ahp:openAuth', function () {
            openAuthModal();
        } );

        /* ── Form logic (whether inside the modal or on a standalone page) ─── */

        var root = document.querySelector( '[data-ahp-auth-root]' );
        if ( ! root ) return;

        var stepMobile     = root.querySelector( '[data-ahp-auth-step="mobile"]' );
        var stepCode       = root.querySelector( '[data-ahp-auth-step="code"]' );
        var stepSuccess    = root.querySelector( '[data-ahp-auth-step="success"]' );
        var mobileInput    = root.querySelector( '[data-ahp-auth-mobile]' );
        var nameFields     = root.querySelector( '[data-ahp-auth-name-fields]' );
        var firstNameInput = root.querySelector( '[data-ahp-auth-first-name]' );
        var lastNameInput  = root.querySelector( '[data-ahp-auth-last-name]' );
        var codeInput      = root.querySelector( '[data-ahp-auth-code]' );
        var mobileDisplay  = root.querySelector( '[data-ahp-auth-mobile-display]' );
        var stepTitle      = root.querySelector( '[data-ahp-auth-step-title]' );
        var modalTitle     = document.querySelector( '[data-ahp-auth-modal-title]' );
        var errorMobile    = root.querySelector( '[data-ahp-auth-error-mobile]' );
        var errorCode      = root.querySelector( '[data-ahp-auth-error-code]' );
        var resendBtn      = root.querySelector( '[data-ahp-auth-resend]' );
        var resendTimerEl  = root.querySelector( '[data-ahp-auth-resend-timer]' );
        var backBtn        = root.querySelector( '[data-ahp-auth-back]' );
        var successMsg     = root.querySelector( '[data-ahp-auth-success-message]' );

        // If the user refreshes the page between getting the code and
        // entering it, a plain in-memory JS variable would be lost and
        // currentMobile would end up empty; the server would then reject
        // the request with mobile='' (the "no code was found" error —
        // exactly what was seen in the reported bug). Keeping it in
        // sessionStorage also covers this case.
        var STORAGE_KEY    = 'ahp_auth_pending_mobile';
        var currentMobile  = sessionStorage.getItem( STORAGE_KEY ) || '';
        var needsName      = false;
        var resendInterval = null;

        function showError( el, msg ) {
            el.textContent = msg;
            el.style.display = msg ? 'block' : 'none';
        }

        function setStepTitle( isNew ) {
            var text = isNew ? AHP_AUTH.i18n.registerTitle : AHP_AUTH.i18n.loginTitle;
            if ( stepTitle ) stepTitle.textContent = text;
            if ( modalTitle ) modalTitle.textContent = text;
        }

        function resetFormToFirstStep() {
            stepSuccess.style.display = 'none';
            if ( currentMobile ) {
                mobileDisplay.textContent = currentMobile;
                stepMobile.style.display  = 'none';
                stepCode.style.display    = '';
            } else {
                stepMobile.style.display  = '';
                stepCode.style.display    = 'none';
                setStepTitle( false );
            }
        }

        /**
         * Calls ajax with one automatic retry, only if the error is genuinely
         * an "invalid nonce" type. check_ajax_referer() exits with
         * wp_die(-1) when the nonce is invalid, whose response body is the
         * plain string "-1" or "0" (not JSON) — this is the only reliable
         * sign. In contrast, our own logical errors (like "name is
         * required" or "wrong code") are sent via wp_send_json_error and
         * return valid JSON with success:false; these must never be retried.
         */
        function postAjax( payload, retry ) {
            return fetch( AHP_AUTH.ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams( payload ).toString(),
            } ).then( function ( r ) {
                return r.text().then( function ( text ) {
                    var isNonceFailure = ( text.trim() === '-1' || text.trim() === '0' );
                    if ( isNonceFailure && retry !== false ) {
                        return fetch( AHP_AUTH.ajaxUrl, {
                            method:  'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body:    new URLSearchParams( { action: 'ahp_refresh_nonce' } ).toString(),
                        } )
                            .then( function ( rr ) { return rr.json(); } )
                            .then( function ( nonceRes ) {
                                if ( nonceRes.success && nonceRes.data && nonceRes.data.nonce ) {
                                    AHP_AUTH.nonce = nonceRes.data.nonce;
                                    payload.nonce  = nonceRes.data.nonce;
                                }
                                return postAjax( payload, false );
                            } );
                    }
                    try {
                        return JSON.parse( text );
                    } catch ( e ) {
                        return { success: false, data: { message: AHP_AUTH.i18n.genericError } };
                    }
                } );
            } );
        }

        function setLoading( form, loading ) {
            var btn = form.querySelector( 'button[type="submit"]' );
            if ( btn ) {
                btn.disabled = loading;
                btn.classList.toggle( 'is-loading', loading );
            }
        }

        function startResendTimer( seconds ) {
            clearInterval( resendInterval );
            resendBtn.disabled = true;
            var remaining = seconds;

            function tick() {
                var m = Math.floor( remaining / 60 );
                var s = remaining % 60;
                resendTimerEl.textContent =
                    ( m < 10 ? '0' + m : m ) + ':' + ( s < 10 ? '0' + s : s );
                if ( remaining <= 0 ) {
                    clearInterval( resendInterval );
                    resendBtn.disabled = false;
                    resendTimerEl.textContent = '';
                }
                remaining--;
            }
            tick();
            resendInterval = setInterval( tick, 1000 );
        }

        /** Go back from the code step to the mobile-number step, to fix a wrong number */
        function goBackToMobileStep() {
            clearInterval( resendInterval );
            currentMobile = '';
            sessionStorage.removeItem( STORAGE_KEY );
            showError( errorCode, '' );
            codeInput.value = '';
            stepCode.style.display   = 'none';
            stepMobile.style.display = '';
            setStepTitle( false );
            mobileInput.focus();
            mobileInput.select();
        }

        function requestCode( isResend ) {
            var errTarget = isResend ? errorCode : errorMobile;
            showError( errTarget, '' );

            var mobile = isResend ? currentMobile : mobileInput.value.trim();
            if ( ! mobile ) {
                showError( errTarget, AHP_AUTH.i18n.invalidMobile );
                return;
            }

            var payload = {
                action: 'ahp_request_code',
                nonce:  AHP_AUTH.nonce,
                mobile: mobile,
            };
            if ( needsName ) {
                payload.first_name = firstNameInput.value.trim();
                payload.last_name  = lastNameInput.value.trim();
            }

            if ( isResend ) resendBtn.disabled = true;
            else setLoading( stepMobile, true );

            postAjax( payload )
                .then( function ( res ) {
                    if ( ! isResend ) setLoading( stepMobile, false );

                    if ( ! res.success ) {
                        if ( ! isResend && res.data && res.data.needsName ) {
                            needsName = true;
                            nameFields.style.display = '';
                            setStepTitle( true );
                            return;
                        }
                        showError( errTarget, ( res.data && res.data.message ) || AHP_AUTH.i18n.genericError );
                        if ( isResend ) resendBtn.disabled = false;
                        return;
                    }

                    currentMobile = ( res.data && res.data.mobile ) || mobile;
                    sessionStorage.setItem( STORAGE_KEY, currentMobile );
                    mobileDisplay.textContent = currentMobile;
                    setStepTitle( !! ( res.data && res.data.isNewUser ) );

                    if ( ! isResend ) {
                        stepMobile.style.display = 'none';
                        stepCode.style.display   = '';
                        codeInput.value = '';
                        codeInput.focus();
                    }

                    var ttl = ( res.data && res.data.ttlSeconds ) || 120;
                    startResendTimer( ttl );
                } )
                .catch( function () {
                    if ( ! isResend ) setLoading( stepMobile, false );
                    else resendBtn.disabled = false;
                    showError( errTarget, AHP_AUTH.i18n.genericError );
                } );
        }

        function verifyCode() {
            showError( errorCode, '' );

            var code = codeInput.value.trim();
            if ( ! code ) return;

            setLoading( stepCode, true );

            postAjax( {
                action: 'ahp_verify_code',
                nonce:  AHP_AUTH.nonce,
                mobile: currentMobile,
                code:   code,
            } )
                .then( function ( res ) {
                    setLoading( stepCode, false );

                    if ( ! res.success ) {
                        showError( errorCode, ( res.data && res.data.message ) || AHP_AUTH.i18n.genericError );
                        return;
                    }

                    sessionStorage.removeItem( STORAGE_KEY );
                    var isNewUser = !! ( res.data && res.data.isNewUser );

                    // A brief success message, then close the modal — with
                    // no refresh or redirect at all. The user stays exactly where they were.
                    stepCode.style.display    = 'none';
                    stepSuccess.style.display = '';
                    successMsg.textContent = isNewUser
                        ? AHP_AUTH.i18n.registerSuccess
                        : AHP_AUTH.i18n.loginSuccess;

                    // Switch the header icon from "log in" to "account",
                    // in every instance of the shortcode on this page (if used more than once).
                    document.querySelectorAll( '[data-ahp-auth-icon-wrap]' ).forEach( function ( wrap ) {
                        applyAuthState( wrap, true );
                    } );

                    // So that if this same user opens the page in another
                    // tab or refreshes (without logging in again), the icon
                    // stays correct — the server's cookie response takes a
                    // moment to arrive, so we also set it immediately in JS,
                    // so there's no issue even if the user refreshes right
                    // away (though the real value also comes from the server).
                    document.cookie = 'ahp_logged_in=1; path=/; max-age=' + ( 60 * 60 * 24 * 365 );

                    document.dispatchEvent( new CustomEvent( 'ahp:loggedIn', {
                        detail: { isNewUser: isNewUser },
                    } ) );

                    setTimeout( function () {
                        closeAuthModal();
                        // Clear the pending number so that next time the
                        // modal opens, it starts over from the mobile-number step.
                        currentMobile = '';
                    }, 900 );
                } )
                .catch( function () {
                    setLoading( stepCode, false );
                    showError( errorCode, AHP_AUTH.i18n.genericError );
                } );
        }

        if ( currentMobile ) {
            mobileDisplay.textContent = currentMobile;
            stepMobile.style.display = 'none';
            stepCode.style.display   = '';
        }

        stepMobile.addEventListener( 'submit', function ( e ) {
            e.preventDefault();
            requestCode( false );
        } );

        stepCode.addEventListener( 'submit', function ( e ) {
            e.preventDefault();
            verifyCode();
        } );

        resendBtn.addEventListener( 'click', function () {
            if ( resendBtn.disabled ) return;
            requestCode( true );
        } );

        if ( backBtn ) {
            backBtn.addEventListener( 'click', function () {
                goBackToMobileStep();
            } );
        }
    } );
} )();
