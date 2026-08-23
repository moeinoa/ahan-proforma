/**
 * ahp-account.js — Phase 6: the customer account page
 * Two independent behaviors, with no dependency on ahp-auth.js (the OTP flow
 * here is implemented from scratch, more simply, since there's no need to
 * manage a modal/cookie/icon refresh):
 *   1) Saving first/last name
 *   2) Two-step mobile-number change
 */
( function () {
    'use strict';

    if ( typeof AHP_ACCOUNT === 'undefined' ) return;

    document.addEventListener( 'DOMContentLoaded', function () {
        initNameForm();
        initMobileFlow();
    } );

    // This refresh must only happen when the login form was rendered
    // instead of the real account tabs (i.e. a guest user landed directly
    // on /account/), since the tabs' content is only rendered server-side
    // for a logged-in user. On any other page of the site (e.g. the
    // proforma cart), ahp-auth.js deliberately behaves with no
    // refresh/redirect, and this listener must not violate that behavior.
    //
    // Important note: since ahp-account.js is enqueued on every page of the
    // site (not just the account page), this decision must be made with a
    // *positive* marker (data-ahp-account-guest-login, from
    // AHP_Account::render_account_shortcode), not the absence of
    // [data-ahp-account-root] (inverted logic) — the earlier version had
    // exactly this mistake: since data-ahp-account-root also didn't exist on
    // pages like the proforma cart, this condition wrongly became true, and
    // after every login (even in the middle of issuing a proforma invoice
    // from the cart) the whole page would reload.
    document.addEventListener( 'ahp:loggedIn', function () {
        if ( ! document.querySelector( '[data-ahp-account-guest-login]' ) ) return;
        setTimeout( function () {
            window.location.reload();
        }, 900 );
    } );

    function ajaxPost( action, data ) {
        var body = new FormData();
        body.append( 'action', action );
        body.append( 'nonce', AHP_ACCOUNT.nonce );
        Object.keys( data ).forEach( function ( key ) {
            body.append( key, data[ key ] );
        } );

        return fetch( AHP_ACCOUNT.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        } ).then( function ( res ) {
            return res.json();
        } );
    }

    function setMsg( el, text, isError ) {
        if ( ! el ) return;
        el.textContent = text || '';
        el.classList.toggle( 'is-error', !! isError );
        el.classList.toggle( 'is-success', ! isError && !! text );
    }

    /* ── First/last name form ──────────────────────────────────────────────── */

    function initNameForm() {
        var form = document.querySelector( '[data-ahp-name-form]' );
        if ( ! form ) return;

        var msgEl = form.querySelector( '[data-ahp-name-msg]' );
        var submitBtn = form.querySelector( 'button[type="submit"]' );

        form.addEventListener( 'submit', function ( e ) {
            e.preventDefault();

            var first = form.querySelector( '#ahp-account-first-name' ).value.trim();
            var last  = form.querySelector( '#ahp-account-last-name' ).value.trim();

            if ( ! first ) return setMsg( msgEl, AHP_ACCOUNT.i18n.firstNameRequired, true );
            if ( ! last )  return setMsg( msgEl, AHP_ACCOUNT.i18n.lastNameRequired, true );

            submitBtn.disabled = true;
            setMsg( msgEl, AHP_ACCOUNT.i18n.saving, false );

            ajaxPost( 'ahp_account_update_name', { first_name: first, last_name: last } )
                .then( function ( res ) {
                    submitBtn.disabled = false;
                    if ( res.success ) {
                        setMsg( msgEl, res.data.message || AHP_ACCOUNT.i18n.saved, false );
                    } else {
                        setMsg( msgEl, ( res.data && res.data.message ) || AHP_ACCOUNT.i18n.genericError, true );
                    }
                } )
                .catch( function () {
                    submitBtn.disabled = false;
                    setMsg( msgEl, AHP_ACCOUNT.i18n.genericError, true );
                } );
        } );
    }

    /* ── Mobile number change (two-step) ───────────────────────────────────── */

    function initMobileFlow() {
        var block = document.querySelector( '[data-ahp-mobile-block]' );
        if ( ! block ) return;

        var step1Form = block.querySelector( '[data-ahp-mobile-step1-form]' );
        var step2Form = block.querySelector( '[data-ahp-mobile-step2-form]' );
        var step1Msg  = block.querySelector( '[data-ahp-mobile-step1-msg]' );
        var step2Msg  = block.querySelector( '[data-ahp-mobile-step2-msg]' );
        var pendingMobileLabel = block.querySelector( '[data-ahp-mobile-pending-value]' );
        var currentMobileLabel = block.querySelector( '[data-ahp-mobile-current-value]' );
        var cancelBtn = block.querySelector( '[data-ahp-mobile-cancel]' );

        var pendingMobile = null;

        step1Form.addEventListener( 'submit', function ( e ) {
            e.preventDefault();

            var mobile = step1Form.querySelector( '#ahp-account-new-mobile' ).value.trim();
            if ( ! mobile ) return setMsg( step1Msg, AHP_ACCOUNT.i18n.invalidMobile, true );

            var submitBtn = step1Form.querySelector( '[data-ahp-mobile-step1-submit]' );
            submitBtn.disabled = true;
            setMsg( step1Msg, AHP_ACCOUNT.i18n.saving, false );

            ajaxPost( 'ahp_account_request_mobile_change', { mobile: mobile } )
                .then( function ( res ) {
                    submitBtn.disabled = false;
                    if ( res.success ) {
                        pendingMobile = res.data.mobile;
                        pendingMobileLabel.textContent = pendingMobile;
                        setMsg( step1Msg, '', false );
                        step1Form.hidden = true;
                        step2Form.hidden = false;
                        step2Form.querySelector( '#ahp-account-mobile-code' ).focus();
                    } else {
                        setMsg( step1Msg, ( res.data && res.data.message ) || AHP_ACCOUNT.i18n.genericError, true );
                    }
                } )
                .catch( function () {
                    submitBtn.disabled = false;
                    setMsg( step1Msg, AHP_ACCOUNT.i18n.genericError, true );
                } );
        } );

        step2Form.addEventListener( 'submit', function ( e ) {
            e.preventDefault();
            if ( ! pendingMobile ) return;

            var code = step2Form.querySelector( '#ahp-account-mobile-code' ).value.trim();
            if ( ! code ) return;

            var submitBtn = step2Form.querySelector( 'button[type="submit"]' );
            submitBtn.disabled = true;
            setMsg( step2Msg, AHP_ACCOUNT.i18n.saving, false );

            ajaxPost( 'ahp_account_confirm_mobile_change', { mobile: pendingMobile, code: code } )
                .then( function ( res ) {
                    submitBtn.disabled = false;
                    if ( res.success ) {
                        currentMobileLabel.textContent = res.data.mobile;
                        setMsg( step2Msg, res.data.message || AHP_ACCOUNT.i18n.mobileChanged, false );
                        resetToStep1( true );
                    } else {
                        setMsg( step2Msg, ( res.data && res.data.message ) || AHP_ACCOUNT.i18n.genericError, true );
                    }
                } )
                .catch( function () {
                    submitBtn.disabled = false;
                    setMsg( step2Msg, AHP_ACCOUNT.i18n.genericError, true );
                } );
        } );

        cancelBtn.addEventListener( 'click', function () {
            resetToStep1( false );
        } );

        function resetToStep1( clearField ) {
            pendingMobile = null;
            step2Form.hidden = true;
            step1Form.hidden = false;
            step2Form.querySelector( '#ahp-account-mobile-code' ).value = '';
            setMsg( step2Msg, '', false );
            if ( clearField ) {
                step1Form.querySelector( '#ahp-account-new-mobile' ).value = '';
                setMsg( step1Msg, '', false );
            }
        }
    }
} )();
