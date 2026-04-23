<?php
/**
 * Error Handling & Notification System for BITE GSC Integration
 *
 * Prevents email spam by:
 * - Tracking which errors have been reported
 * - Classifying errors as auth vs retryable
 * - Implementing backoff logic
 * - Showing admin/user notices
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Error type classifications
 */
define( 'BITE_ERROR_AUTH', 'auth' );           // Token revoked/invalid - requires user action
define( 'BITE_ERROR_RETRYABLE', 'retryable' ); // Temporary errors - can retry with backoff
define( 'BITE_ERROR_FATAL', 'fatal' );         // Configuration errors - requires admin action

/**
 * Classify an error from the Google API
 *
 * @param WP_Error $error The error to classify
 * @return string Error type (BITE_ERROR_AUTH, BITE_ERROR_RETRYABLE, BITE_ERROR_FATAL)
 */
function bite_classify_api_error( $error ) {
    $message = strtolower( $error->get_error_message() );
    $code = $error->get_error_code();
    $data = $error->get_error_data();
    
    // Auth errors - token revoked or invalid
    $auth_patterns = array(
        'token has been expired or revoked',
        'invalid_grant',
        'unauthorized_client',
        'access_denied',
        'invalid_token',
        'token_expired',
        'refresh_error',
        'insufficient_permission',
        'unauthorized',
    );
    
    foreach ( $auth_patterns as $pattern ) {
        if ( strpos( $message, $pattern ) !== false ) {
            return BITE_ERROR_AUTH;
        }
    }
    
    // Check HTTP status code if available
    if ( is_array( $data ) && isset( $data['error'] ) ) {
        $google_error = $data['error'];
        
        // Handle OAuth error format: { "error": "invalid_grant", "error_description": "..." }
        if ( is_string( $google_error ) ) {
            $oauth_error = strtolower( $google_error );
            $oauth_auth_errors = array( 'invalid_grant', 'unauthorized_client', 'access_denied', 'invalid_token', 'insufficient_permission' );
            if ( in_array( $oauth_error, $oauth_auth_errors, true ) ) {
                return BITE_ERROR_AUTH;
            }
        }
        
        // Handle GSC API error format: { "error": { "code": 401, "status": "...", "message": "..." } }
        if ( is_array( $google_error ) ) {
            if ( isset( $google_error['code'] ) ) {
                $http_code = intval( $google_error['code'] );
                
                // 401/403 = auth errors
                if ( $http_code === 401 || $http_code === 403 ) {
                    return BITE_ERROR_AUTH;
                }
                
                // 5xx = retryable server errors
                if ( $http_code >= 500 && $http_code < 600 ) {
                    return BITE_ERROR_RETRYABLE;
                }
                
                // 429 = rate limit (retryable with backoff)
                if ( $http_code === 429 ) {
                    return BITE_ERROR_RETRYABLE;
                }
            }
            
            // Check error status
            if ( isset( $google_error['status'] ) ) {
                $status = strtolower( $google_error['status'] );
                if ( in_array( $status, array( 'unauthenticated', 'permission_denied' ), true ) ) {
                    return BITE_ERROR_AUTH;
                }
                if ( in_array( $status, array( 'unavailable', 'deadline_exceeded', 'resource_exhausted' ), true ) ) {
                    return BITE_ERROR_RETRYABLE;
                }
            }
        }
    }
    
    // Network/timeout errors are retryable
    if ( strpos( $message, 'timeout' ) !== false ||
         strpos( $message, 'connection' ) !== false ||
         strpos( $message, 'could not resolve' ) !== false ||
         strpos( $message, 'operation timed out' ) !== false ) {
        return BITE_ERROR_RETRYABLE;
    }
    
    // Default to retryable for unknown errors
    return BITE_ERROR_RETRYABLE;
}

/**
 * Check if we should send an email for this error
 * Uses transients to prevent duplicate emails
 *
 * @param string $error_key Unique identifier for this error type
 * @param int $site_id The site ID
 * @param int $min_hours Minimum hours between emails (default: 24)
 * @return bool True if email should be sent
 */
function bite_should_send_error_email( $error_key, $site_id = 0, $min_hours = 24 ) {
    $transient_key = 'bite_error_email_' . md5( $error_key . '_' . $site_id );
    
    if ( get_transient( $transient_key ) ) {
        return false; // Email already sent recently
    }
    
    // Set transient to prevent future emails for this error
    set_transient( $transient_key, true, $min_hours * HOUR_IN_SECONDS );
    
    return true;
}

/**
 * Clear the error email transient (call when issue is resolved)
 *
 * @param string $error_key Unique identifier for this error type
 * @param int $site_id The site ID
 */
function bite_clear_error_email_flag( $error_key, $site_id = 0 ) {
    $transient_key = 'bite_error_email_' . md5( $error_key . '_' . $site_id );
    delete_transient( $transient_key );
}

/**
 * Get retry backoff time for a site
 *
 * @param int $site_id The site ID
 * @return int|false Retry after timestamp, or false if should not retry
 */
function bite_get_retry_after( $site_id ) {
    $option_key = 'bite_backfill_retry_' . $site_id;
    $retry_data = get_option( $option_key, array() );
    
    if ( empty( $retry_data ) ) {
        return false;
    }
    
    return isset( $retry_data['retry_after'] ) ? intval( $retry_data['retry_after'] ) : false;
}

/**
 * Set retry backoff for a site (exponential backoff)
 *
 * @param int $site_id The site ID
 * @param int $attempt_attempt Optional attempt number (auto-increments if not set)
 * @return int The retry_after timestamp
 */
function bite_set_retry_backoff( $site_id, $attempt = null ) {
    $option_key = 'bite_backfill_retry_' . $site_id;
    $retry_data = get_option( $option_key, array(
        'attempt' => 0,
        'first_error' => time(),
    ) );
    
    if ( $attempt !== null ) {
        $retry_data['attempt'] = $attempt;
    } else {
        $retry_data['attempt']++;
    }
    
    // Exponential backoff: 30min, 60min, 2hrs, 4hrs, max 24hrs
    $backoff_hours = min( 24, 0.5 * pow( 2, $retry_data['attempt'] - 1 ) );
    $retry_after = time() + ( $backoff_hours * HOUR_IN_SECONDS );
    
    $retry_data['retry_after'] = $retry_after;
    $retry_data['last_error'] = time();
    
    update_option( $option_key, $retry_data, false );
    
    return $retry_after;
}

/**
 * Clear retry backoff for a site (call on success)
 *
 * @param int $site_id The site ID
 */
function bite_clear_retry_backoff( $site_id ) {
    $option_key = 'bite_backfill_retry_' . $site_id;
    delete_option( $option_key );
    
    // Also clear any auth error flags
    delete_option( 'bite_auth_error_' . $site_id );
    
    // Clear email flags for this site
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bite_error_email_%'" );
}

/**
 * Check if we should skip processing due to backoff
 *
 * @param int $site_id The site ID
 * @return bool True if should skip (respect backoff)
 */
function bite_should_respect_backoff( $site_id ) {
    $retry_after = bite_get_retry_after( $site_id );
    
    if ( ! $retry_after ) {
        return false; // No backoff set
    }
    
    if ( time() < $retry_after ) {
        $wait_minutes = ceil( ( $retry_after - time() ) / 60 );
        error_log( "BITE Backoff: Site $site_id backed off. Resuming in $wait_minutes minutes." );
        return true;
    }
    
    return false; // Backoff period expired
}

/**
 * Record an auth error for a site
 *
 * @param int $site_id The site ID
 * @param string $error_message The error message
 */
function bite_record_auth_error( $site_id, $error_message ) {
    $error_data = array(
        'site_id' => $site_id,
        'error_message' => $error_message,
        'error_time' => current_time( 'mysql' ),
        'status' => 'pending', // pending, notified, resolved
    );
    
    update_option( 'bite_auth_error_' . $site_id, $error_data, false );
    
    // Mark site as having auth issues
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'bite_sites',
        array( 'backfill_status' => 'auth_error' ),
        array( 'site_id' => $site_id ),
        array( '%s' ),
        array( '%d' )
    );
}

/**
 * Get all sites with auth errors
 *
 * @return array Array of site IDs with auth errors
 */
function bite_get_sites_with_auth_errors() {
    global $wpdb;
    
    // Get sites marked with auth_error status
    $sites = $wpdb->get_col(
        "SELECT site_id FROM {$wpdb->prefix}bite_sites WHERE backfill_status = 'auth_error'"
    );
    
    return $sites ? $sites : array();
}

/**
 * Clear auth error for a site (call when token is renewed)
 *
 * @param int $site_id The site ID
 */
function bite_clear_auth_error( $site_id ) {
    delete_option( 'bite_auth_error_' . $site_id );
    
    // Clear retry backoff as well
    bite_clear_retry_backoff( $site_id );
    
    // Reset site status if it was stuck
    global $wpdb;
    $site = $wpdb->get_row( $wpdb->prepare(
        "SELECT backfill_status FROM {$wpdb->prefix}bite_sites WHERE site_id = %d",
        $site_id
    ) );
    
    if ( $site && $site->backfill_status === 'auth_error' ) {
        // Resume from where we left off
        $wpdb->update(
            $wpdb->prefix . 'bite_sites',
            array( 'backfill_status' => 'in_progress' ),
            array( 'site_id' => $site_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        error_log( "BITE Auth: Site $site_id token renewed, resuming backfill" );
    }
}

/**
 * Send error notification with rate limiting
 *
 * @param string $subject Email subject
 * @param string $message Email message
 * @param string $error_key Unique key for this error type
 * @param int $site_id The site ID
 * @param string $error_type Error classification
 * @return bool Whether email was sent
 */
function bite_send_error_notification( $subject, $message, $error_key, $site_id = 0, $error_type = BITE_ERROR_RETRYABLE ) {
    
    // Auth errors: send immediately but only once per 24h
    // Retryable errors: send after multiple failures, once per 6h
    $min_hours = ( $error_type === BITE_ERROR_AUTH ) ? 24 : 6;
    
    if ( ! bite_should_send_error_email( $error_key, $site_id, $min_hours ) ) {
        return false;
    }
    
    $admin_email = get_option( 'admin_email' );
    
    // Add error type context to subject
    if ( $error_type === BITE_ERROR_AUTH ) {
        $subject = '[ACTION REQUIRED] ' . $subject;
        $message .= "\n\n=== ACTION REQUIRED ===\n";
        $message .= "This is an authentication error. Please have the site owner reconnect their Google account.\n";
        $message .= "The system will automatically resume data collection once the token is renewed.";
    } elseif ( $error_type === BITE_ERROR_RETRYABLE ) {
        $message .= "\n\n=== RETRY INFO ===\n";
        $message .= "This is a temporary error. The system will retry with exponential backoff (30-60 min intervals).\n";
        $message .= "You will only receive another email if the issue persists after multiple retry attempts.";
    }
    
    $message .= "\n\n---\nBITE GSC Integration\n" . home_url();
    
    wp_mail( $admin_email, $subject, $message );
    
    return true;
}

/**
 * Handle API error with proper classification and notification
 *
 * @param WP_Error $error The error
 * @param string $context Context description (e.g., "fetching totals")
 * @param int $site_id The site ID
 * @return string Error type (BITE_ERROR_AUTH, BITE_ERROR_RETRYABLE, BITE_ERROR_FATAL)
 */
function bite_handle_api_error( $error, $context, $site_id ) {
    $error_type = bite_classify_api_error( $error );
    $error_message = $error->get_error_message();
    $log_message = "BITE API Error [{$context}]: Site $site_id - $error_message";
    
    error_log( $log_message );
    
    switch ( $error_type ) {
        case BITE_ERROR_AUTH:
            // Record auth error and mark site
            bite_record_auth_error( $site_id, $error_message );
            
            // Send single notification
            bite_send_error_notification(
                'Google OAuth Token Expired/Revoked',
                "Site ID: $site_id\nContext: $context\nError: $error_message",
                'auth_error_' . $site_id,
                $site_id,
                BITE_ERROR_AUTH
            );
            break;
            
        case BITE_ERROR_RETRYABLE:
            // Set exponential backoff
            $retry_after = bite_set_retry_backoff( $site_id );
            $wait_minutes = ceil( ( $retry_after - time() ) / 60 );
            
            // Only send email after multiple failures
            $retry_data = get_option( 'bite_backfill_retry_' . $site_id, array() );
            $attempt = isset( $retry_data['attempt'] ) ? $retry_data['attempt'] : 1;
            
            if ( $attempt >= 3 ) {
                bite_send_error_notification(
                    'GSC API Temporary Error (Persistent)',
                    "Site ID: $site_id\nContext: $context\nError: $error_message\nAttempts: $attempt",
                    'retryable_error_' . $site_id,
                    $site_id,
                    BITE_ERROR_RETRYABLE
                );
            }
            break;
            
        case BITE_ERROR_FATAL:
            bite_send_error_notification(
                'GSC API Fatal Error',
                "Site ID: $site_id\nContext: $context\nError: $error_message",
                'fatal_error_' . $site_id,
                $site_id,
                BITE_ERROR_FATAL
            );
            break;
    }
    
    return $error_type;
}

/**
 * Check if a site should be processed (respects backoff and auth errors)
 *
 * @param int $site_id The site ID
 * @return bool|WP_Error True if can proceed, WP_Error if blocked
 */
function bite_can_process_site( $site_id ) {
    // Check for auth error
    $auth_error = get_option( 'bite_auth_error_' . $site_id );
    if ( $auth_error ) {
        return new WP_Error( 
            'auth_error', 
            'Site has authentication issues. Token needs renewal.',
            array( 'site_id' => $site_id )
        );
    }
    
    // Check backoff
    if ( bite_should_respect_backoff( $site_id ) ) {
        $retry_after = bite_get_retry_after( $site_id );
        return new WP_Error(
            'backoff',
            'Site is in backoff period.',
            array( 'site_id' => $site_id, 'retry_after' => $retry_after )
        );
    }
    
    return true;
}

/**
 * Resume backfill after token renewal for a user
 * Called when OAuth is successful
 *
 * @param int $user_id The user ID whose token was renewed
 */
function bite_resume_after_token_renewal( $user_id ) {
    global $wpdb;
    
    // Find all sites owned by this user with auth errors (via user_sites table)
    $sites = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.site_id 
         FROM {$wpdb->prefix}bite_sites s 
         INNER JOIN {$wpdb->prefix}bite_user_sites us ON s.site_id = us.site_id 
         WHERE us.user_id = %d AND s.backfill_status = 'auth_error'",
        $user_id
    ) );
    
    if ( ! $sites ) {
        return;
    }
    
    foreach ( $sites as $site ) {
        $site_id = $site->site_id;
        
        // Clear auth error
        bite_clear_auth_error( $site_id );
        
        error_log( "BITE Recovery: Site $site_id backfill resuming after token renewal for user $user_id" );
    }
    
    // Trigger immediate backfill run
    if ( ! wp_next_scheduled( 'bite_backfill_hook' ) ) {
        wp_schedule_single_event( time() + 5, 'bite_backfill_hook' );
    }
}
add_action( 'bite_google_connected', 'bite_resume_after_token_renewal' );

/**
 * Admin notices for auth errors
 */
function bite_admin_notices() {
    // Only show to admins
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    $sites_with_errors = bite_get_sites_with_auth_errors();
    
    if ( empty( $sites_with_errors ) ) {
        return;
    }
    
    global $wpdb;
    $sites_table = $wpdb->prefix . 'bite_sites';
    
    echo '<div class="notice notice-error is-dismissible">';
    echo '<p><strong>BITE GSC Alert:</strong> The following sites have Google authentication issues and need token renewal:</p>';
    echo '<ul style="list-style:disc;margin-left:20px;">';
    
    foreach ( $sites_with_errors as $site_id ) {
        $site = $wpdb->get_row( $wpdb->prepare(
            "SELECT name FROM $sites_table WHERE site_id = %d",
            $site_id
        ) );
        
        // Get site owner from user_sites table
        $owner_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}bite_user_sites WHERE site_id = %d ORDER BY assigned_at ASC LIMIT 1",
            $site_id
        ) );
        
        if ( $site ) {
            $user = $owner_id ? get_userdata( $owner_id ) : false;
            $user_name = $user ? $user->display_name : "Unknown";
            $error_data = get_option( 'bite_auth_error_' . $site_id );
            $error_time = $error_data ? human_time_diff( strtotime( $error_data['error_time'] ), current_time( 'timestamp' ) ) . ' ago' : 'recently';
            
            echo '<li>';
            echo esc_html( $site->name );
            echo ' (Owner: ' . esc_html( $user_name ) . ', Error: ' . esc_html( $error_time ) . ')';
            echo '</li>';
        }
    }
    
    echo '</ul>';
    echo '<p>The affected users should reconnect their Google account from their dashboard. Data collection will resume automatically once the token is renewed.</p>';
    echo '</div>';
}
add_action( 'admin_notices', 'bite_admin_notices' );

/**
 * AJAX handler to dismiss admin notice
 */
function bite_ajax_dismiss_auth_notice() {
    check_ajax_referer( 'bite_dismiss_notice', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    
    $site_id = intval( $_POST['site_id'] ?? 0 );
    if ( $site_id ) {
        bite_clear_auth_error( $site_id );
    }
    
    wp_send_json_success();
}
add_action( 'wp_ajax_bite_dismiss_auth_notice', 'bite_ajax_dismiss_auth_notice' );

/**
 * Check if current user has any sites with auth errors
 * Used for dashboard notice
 *
 * @param int $user_id The user ID
 * @return array Array of site objects with auth errors
 */
function bite_get_user_auth_error_sites( $user_id ) {
    global $wpdb;
    
    $user_site_ids = bite_get_user_sites( $user_id );
    if ( empty( $user_site_ids ) ) {
        return array();
    }
    
    $sites_table = $wpdb->prefix . 'bite_sites';
    $placeholders = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
    
    $error_sites = $wpdb->get_results( $wpdb->prepare(
        "SELECT site_id, name, backfill_status 
         FROM $sites_table 
         WHERE site_id IN ($placeholders) 
         AND backfill_status = 'auth_error'",
        $user_site_ids
    ) );
    
    return $error_sites ? $error_sites : array();
}

/**
 * Dashboard notice for users whose OAuth token needs renewal
 * This is displayed on the frontend dashboard template
 *
 * @return string HTML notice or empty string
 */
function bite_get_user_token_notice() {
    $current_user_id = get_current_user_id();
    if ( ! $current_user_id ) {
        return '';
    }
    
    $error_sites = bite_get_user_auth_error_sites( $current_user_id );
    if ( empty( $error_sites ) ) {
        return '';
    }
    
    $auth_url = bite_get_google_auth_url( $current_user_id );
    if ( is_wp_error( $auth_url ) ) {
        return '';
    }
    
    $site_names = array();
    foreach ( $error_sites as $site ) {
        $site_names[] = esc_html( $site->name );
    }
    
    $notice = '<div class="bite-notice bite-notice-warning" style="margin-bottom: 20px; padding: 16px 20px; background: #fff8e1; border-left: 4px solid #f57c00; border-radius: 8px;">';
    $notice .= '<div style="display: flex; align-items: flex-start; gap: 12px;">';
    $notice .= '<span class="material-icons" style="color: #f57c00; flex-shrink: 0;">warning</span>';
    $notice .= '<div style="flex: 1;">';
    $notice .= '<h4 style="margin: 0 0 8px; color: #e65100;">Google Connection Expired</h4>';
    $notice .= '<p style="margin: 0 0 12px; color: #5d4037;">';
    if ( count( $site_names ) === 1 ) {
        $notice .= 'Your Google Search Console connection for <strong>' . $site_names[0] . '</strong> has expired. Data collection has been paused.';
    } else {
        $notice .= 'Your Google Search Console connection has expired for ' . count( $site_names ) . ' sites: <strong>' . implode( ', ', $site_names ) . '</strong>. Data collection has been paused.';
    }
    $notice .= '</p>';
    $notice .= '<a href="' . esc_url( $auth_url ) . '" class="bite-button bite-button-primary" style="display: inline-block;">';
    $notice .= '<span class="material-icons" style="vertical-align: middle; margin-right: 6px;">refresh</span>';
    $notice .= 'Reconnect Google Account';
    $notice .= '</a>';
    $notice .= '</div>';
    $notice .= '</div>';
    $notice .= '</div>';
    
    return $notice;
}
