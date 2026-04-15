<?php
/**
 * OAuth 2.0 Handler for Google Search Console API
 *
 * Handles OAuth authentication flow, token storage, and refresh.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get Google OAuth Client ID from settings
 */
function bite_get_google_client_id() {
    return get_option( 'bite_google_client_id', '' );
}

/**
 * Get Google OAuth Client Secret from settings
 */
function bite_get_google_client_secret() {
    return get_option( 'bite_google_client_secret', '' );
}

/**
 * Check if OAuth is configured (both Client ID and Secret are set)
 */
function bite_is_oauth_configured() {
    $client_id = bite_get_google_client_id();
    $client_secret = bite_get_google_client_secret();
    return ! empty( $client_id ) && ! empty( $client_secret );
}

/**
 * Get the OAuth redirect URI
 */
function bite_get_oauth_redirect_uri() {
    return admin_url( 'admin-ajax.php?action=bite_google_oauth_callback' );
}

/**
 * Generate the Google OAuth authorization URL
 * 
 * @param int $user_id The user ID to authorize
 * @return string|WP_Error The authorization URL or error
 */
function bite_get_google_auth_url( $user_id = null ) {
    if ( ! bite_is_oauth_configured() ) {
        return new WP_Error( 'oauth_not_configured', 'Google OAuth is not configured. Please contact the administrator.' );
    }

    $client_id = bite_get_google_client_id();
    $redirect_uri = bite_get_oauth_redirect_uri();
    
    // Generate and store state parameter for security
    $state = wp_create_nonce( 'bite_google_oauth_' . $user_id );
    update_user_meta( $user_id, 'bite_oauth_state', $state );
    
    // Build authorization URL
    $params = array(
        'client_id'     => $client_id,
        'redirect_uri'  => $redirect_uri,
        'response_type' => 'code',
        'scope'         => 'https://www.googleapis.com/auth/webmasters.readonly',
        'access_type'   => 'offline', // Request refresh token
        'prompt'        => 'consent', // Force consent screen to get refresh token
        'state'         => base64_encode( $user_id . ':' . $state ),
    );
    
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params );
}

/**
 * Handle OAuth callback from Google
 */
function bite_handle_google_oauth_callback() {
    // Check for errors
    if ( isset( $_GET['error'] ) ) {
        $error = sanitize_text_field( $_GET['error'] );
        $error_description = isset( $_GET['error_description'] ) ? sanitize_text_field( $_GET['error_description'] ) : '';
        
        error_log( "BITE OAuth Error: $error - $error_description" );
        
        wp_redirect( home_url( '/dashboard/?oauth_error=' . urlencode( $error ) ) );
        exit;
    }
    
    // Verify we have the authorization code
    if ( ! isset( $_GET['code'] ) || ! isset( $_GET['state'] ) ) {
        wp_redirect( home_url( '/dashboard/?oauth_error=missing_params' ) );
        exit;
    }
    
    // Decode state parameter
    $state_data = base64_decode( sanitize_text_field( $_GET['state'] ) );
    if ( ! $state_data || strpos( $state_data, ':' ) === false ) {
        wp_redirect( home_url( '/dashboard/?oauth_error=invalid_state' ) );
        exit;
    }
    
    list( $user_id, $nonce ) = explode( ':', $state_data, 2 );
    $user_id = absint( $user_id );
    
    // Verify the nonce
    if ( ! wp_verify_nonce( $nonce, 'bite_google_oauth_' . $user_id ) ) {
        wp_redirect( home_url( '/dashboard/?oauth_error=invalid_nonce' ) );
        exit;
    }
    
    // Verify user exists and is logged in
    $user = get_userdata( $user_id );
    if ( ! $user || $user_id !== get_current_user_id() ) {
        wp_redirect( home_url( '/dashboard/?oauth_error=unauthorized' ) );
        exit;
    }
    
    // Exchange authorization code for tokens
    $code = sanitize_text_field( $_GET['code'] );
    $tokens = bite_exchange_code_for_tokens( $code );
    
    if ( is_wp_error( $tokens ) ) {
        error_log( 'BITE OAuth Token Exchange Error: ' . $tokens->get_error_message() );
        wp_redirect( home_url( '/dashboard/?oauth_error=token_exchange_failed' ) );
        exit;
    }
    
    // Store tokens in database
    $stored = bite_store_oauth_tokens( $user_id, $tokens );
    
    if ( is_wp_error( $stored ) ) {
        error_log( 'BITE OAuth Store Error: ' . $stored->get_error_message() );
        wp_redirect( home_url( '/dashboard/?oauth_error=storage_failed' ) );
        exit;
    }
    
    // Clear any pending OAuth errors
    delete_user_meta( $user_id, 'bite_oauth_state' );
    
    // Redirect back to dashboard with success
    wp_redirect( home_url( '/dashboard/?oauth_success=1' ) );
    exit;
}
add_action( 'wp_ajax_bite_google_oauth_callback', 'bite_handle_google_oauth_callback' );
add_action( 'wp_ajax_nopriv_bite_google_oauth_callback', 'bite_handle_google_oauth_callback' );

/**
 * Exchange authorization code for access and refresh tokens
 * 
 * @param string $code The authorization code
 * @return array|WP_Error Token data or error
 */
function bite_exchange_code_for_tokens( $code ) {
    $client_id = bite_get_google_client_id();
    $client_secret = bite_get_google_client_secret();
    $redirect_uri = bite_get_oauth_redirect_uri();
    
    $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
        'body' => array(
            'code'          => $code,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri'  => $redirect_uri,
            'grant_type'    => 'authorization_code',
        ),
        'headers' => array(
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
        'timeout' => 30,
    ) );
    
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( isset( $data['error'] ) ) {
        return new WP_Error( 'token_error', $data['error_description'] ?? $data['error'], $data );
    }
    
    if ( ! isset( $data['access_token'] ) ) {
        return new WP_Error( 'no_token', 'No access token received from Google' );
    }
    
    return $data;
}

/**
 * Store OAuth tokens in database
 * 
 * @param int $user_id The user ID
 * @param array $tokens Token data from Google
 * @return bool|WP_Error Success or error
 */
function bite_store_oauth_tokens( $user_id, $tokens ) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'bite_user_oauth';
    
    // Encrypt the refresh token before storing
    $refresh_token = $tokens['refresh_token'] ?? '';
    if ( empty( $refresh_token ) ) {
        return new WP_Error( 'no_refresh_token', 'No refresh token received. Please revoke access and try again.' );
    }
    
    $encrypted_token = bite_encrypt_token( $refresh_token );
    
    // Calculate token expiration
    $expires_in = $tokens['expires_in'] ?? 3600;
    $expires_at = date( 'Y-m-d H:i:s', time() + $expires_in );
    
    // Check if user already has tokens
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT oauth_id FROM $table_name WHERE user_id = %d",
        $user_id
    ) );
    
    if ( $existing ) {
        // Update existing record
        $result = $wpdb->update(
            $table_name,
            array(
                'refresh_token'    => $encrypted_token,
                'access_token'     => bite_encrypt_token( $tokens['access_token'] ),
                'token_expires_at' => $expires_at,
                'connected_at'     => current_time( 'mysql' ),
            ),
            array( 'user_id' => $user_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    } else {
        // Insert new record
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id'          => $user_id,
                'refresh_token'    => $encrypted_token,
                'access_token'     => bite_encrypt_token( $tokens['access_token'] ),
                'token_expires_at' => $expires_at,
                'connected_at'     => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );
    }
    
    if ( $result === false ) {
        return new WP_Error( 'db_error', 'Failed to store OAuth tokens: ' . $wpdb->last_error );
    }
    
    return true;
}

/**
 * Check if user has connected their Google account
 * 
 * @param int $user_id The user ID (defaults to current user)
 * @return bool True if connected
 */
function bite_user_has_google_connection( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'bite_user_oauth';
    
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT oauth_id FROM $table_name WHERE user_id = %d",
        $user_id
    ) );
    
    return ! empty( $exists );
}

/**
 * Get user's refresh token
 * 
 * @param int $user_id The user ID
 * @return string|WP_Error The decrypted refresh token or error
 */
function bite_get_user_refresh_token( $user_id ) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'bite_user_oauth';
    
    $encrypted_token = $wpdb->get_var( $wpdb->prepare(
        "SELECT refresh_token FROM $table_name WHERE user_id = %d",
        $user_id
    ) );
    
    if ( ! $encrypted_token ) {
        return new WP_Error( 'no_token', 'No OAuth connection found for this user' );
    }
    
    return bite_decrypt_token( $encrypted_token );
}

/**
 * Get access token for a user (refreshing if necessary)
 * 
 * @param int $user_id The user ID
 * @return string|WP_Error The access token or error
 */
function bite_get_user_access_token( $user_id ) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'bite_user_oauth';
    
    $token_data = $wpdb->get_row( $wpdb->prepare(
        "SELECT access_token, token_expires_at, refresh_token FROM $table_name WHERE user_id = %d",
        $user_id
    ) );
    
    if ( ! $token_data ) {
        return new WP_Error( 'no_token', 'No OAuth connection found for this user' );
    }
    
    // Check if access token is still valid (with 5 minute buffer)
    $expires_at = strtotime( $token_data->token_expires_at );
    if ( $expires_at > ( time() + 300 ) ) {
        return bite_decrypt_token( $token_data->access_token );
    }
    
    // Token expired or about to expire, refresh it
    $refresh_token = bite_decrypt_token( $token_data->refresh_token );
    $new_tokens = bite_refresh_access_token( $refresh_token );
    
    if ( is_wp_error( $new_tokens ) ) {
        return $new_tokens;
    }
    
    // Store new tokens
    $expires_in = $new_tokens['expires_in'] ?? 3600;
    $expires_at = date( 'Y-m-d H:i:s', time() + $expires_in );
    
    $wpdb->update(
        $table_name,
        array(
            'access_token'     => bite_encrypt_token( $new_tokens['access_token'] ),
            'token_expires_at' => $expires_at,
        ),
        array( 'user_id' => $user_id ),
        array( '%s', '%s' ),
        array( '%d' )
    );
    
    return $new_tokens['access_token'];
}

/**
 * Refresh an access token using a refresh token
 * 
 * @param string $refresh_token The refresh token
 * @return array|WP_Error New token data or error
 */
function bite_refresh_access_token( $refresh_token ) {
    $client_id = bite_get_google_client_id();
    $client_secret = bite_get_google_client_secret();
    
    $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
        'body' => array(
            'refresh_token' => $refresh_token,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'grant_type'    => 'refresh_token',
        ),
        'headers' => array(
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
        'timeout' => 30,
    ) );
    
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( isset( $data['error'] ) ) {
        return new WP_Error( 'refresh_error', $data['error_description'] ?? $data['error'], $data );
    }
    
    return $data;
}

/**
 * Disconnect a user's Google account
 * 
 * @param int $user_id The user ID
 * @return bool|WP_Error Success or error
 */
function bite_disconnect_google_account( $user_id ) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'bite_user_oauth';
    
    // Get refresh token to revoke it with Google
    $refresh_token = bite_get_user_refresh_token( $user_id );
    
    // Revoke token with Google (optional but recommended)
    if ( ! is_wp_error( $refresh_token ) ) {
        wp_remote_post( 'https://oauth2.googleapis.com/revoke', array(
            'body' => array( 'token' => $refresh_token ),
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
        ) );
    }
    
    // Delete from database
    $result = $wpdb->delete(
        $table_name,
        array( 'user_id' => $user_id ),
        array( '%d' )
    );
    
    if ( $result === false ) {
        return new WP_Error( 'db_error', 'Failed to disconnect account' );
    }
    
    return true;
}

/**
 * Encrypt a token using WordPress salts
 * 
 * @param string $token The token to encrypt
 * @return string The encrypted token
 */
function bite_encrypt_token( $token ) {
    $key = wp_salt( 'auth' );
    $iv = openssl_random_pseudo_bytes( 16 );
    $encrypted = openssl_encrypt( $token, 'AES-256-CBC', $key, 0, $iv );
    return base64_encode( $iv . $encrypted );
}

/**
 * Decrypt a token using WordPress salts
 * 
 * @param string $encrypted_token The encrypted token
 * @return string|false The decrypted token or false
 */
function bite_decrypt_token( $encrypted_token ) {
    $key = wp_salt( 'auth' );
    $data = base64_decode( $encrypted_token );
    $iv = substr( $data, 0, 16 );
    $encrypted = substr( $data, 16 );
    return openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );
}

/**
 * Fetch list of Search Console properties for a user
 * 
 * @param int $user_id The user ID
 * @return array|WP_Error List of properties or error
 */
function bite_fetch_gsc_properties( $user_id ) {
    $access_token = bite_get_user_access_token( $user_id );
    
    if ( is_wp_error( $access_token ) ) {
        return $access_token;
    }
    
    $response = wp_remote_get( 'https://searchconsole.googleapis.com/webmasters/v3/sites', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
        ),
        'timeout' => 30,
    ) );
    
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( isset( $data['error'] ) ) {
        return new WP_Error( 'api_error', $data['error']['message'] ?? 'Unknown API error', $data );
    }
    
    $properties = array();
    if ( ! empty( $data['siteEntry'] ) ) {
        foreach ( $data['siteEntry'] as $entry ) {
            $properties[] = array(
                'property'    => $entry['siteUrl'],
                'permission'  => $entry['permissionLevel'] ?? 'unknown',
            );
        }
    }
    
    return $properties;
}
