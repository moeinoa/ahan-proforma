<?php
/**
 * Standalone login/sign-up page template (fallback) — for when you want to
 * show the form on its own page (not a popup), e.g. via the [ahp_login] shortcode.
 *
 * The actual form content is shared (templates/auth-form.php) so no logic
 * is written twice; this file is just a page shell around it.
 */
defined( 'ABSPATH' ) || exit;

AHP_Auth::render_auth_form();
