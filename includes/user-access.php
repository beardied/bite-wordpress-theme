<?php
/**
 * User Access Control
 *
 * Functions for managing user-site access relationships
 * and filtering data based on user permissions.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Check if a user has access to a specific site.
 *
 * @param int $user_id The user ID to check.
 * @param int $site_id The site ID to check access for.
 * @return bool True if user has access, false otherwise.
 */
function bite_user_has_site_access( $user_id, $site_id ) {
    // Admins can access all sites
    if ( user_can( $user_id, 'manage_options' ) ) {
        return true;
    }
    
    global $wpdb;
    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    
    $has_access = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $user_sites_table WHERE user_id = %d AND site_id = %d",
        $user_id,
        $site_id
    ) );
    
    return ( $has_access > 0 );
}

/**
 * Get all site IDs that a user has access to.
 *
 * @param int $user_id The user ID.
 * @return array Array of site IDs the user can access.
 */
function bite_get_user_sites( $user_id ) {
    // Admins get all sites
    if ( user_can( $user_id, 'manage_options' ) ) {
        global $wpdb;
        $sites_table = $wpdb->prefix . 'bite_sites';
        return $wpdb->get_col( "SELECT site_id FROM $sites_table ORDER BY name ASC" );
    }
    
    global $wpdb;
    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    
    $site_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT site_id FROM $user_sites_table WHERE user_id = %d ORDER BY site_id ASC",
        $user_id
    ) );
    
    return $site_ids ? $site_ids : array();
}

/**
 * Get all users who have access to a specific site.
 *
 * @param int $site_id The site ID.
 * @return array Array of user IDs.
 */
function bite_get_site_users( $site_id ) {
    global $wpdb;
    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    
    $user_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id FROM $user_sites_table WHERE site_id = %d ORDER BY user_id ASC",
        $site_id
    ) );
    
    return $user_ids ? $user_ids : array();
}

/**
 * Grant a user access to a site.
 *
 * @param int $user_id The user ID to grant access to.
 * @param int $site_id The site ID to grant access to.
 * @param int $assigned_by The user ID who is assigning (for audit).
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function bite_grant_user_site_access( $user_id, $site_id, $assigned_by ) {
    global $wpdb;
    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    
    // Check if already assigned
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $user_sites_table WHERE user_id = %d AND site_id = %d",
        $user_id,
        $site_id
    ) );
    
    if ( $exists > 0 ) {
        return new WP_Error( 'already_assigned', 'User already has access to this site.' );
    }
    
    $result = $wpdb->insert(
        $user_sites_table,
        array(
            'user_id'     => $user_id,
            'site_id'     => $site_id,
            'assigned_by' => $assigned_by,
        ),
        array( '%d', '%d', '%d' )
    );
    
    if ( $result === false ) {
        return new WP_Error( 'insert_failed', 'Failed to grant site access.' );
    }
    
    return true;
}

/**
 * Revoke a user's access to a site.
 *
 * @param int $user_id The user ID.
 * @param int $site_id The site ID.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function bite_revoke_user_site_access( $user_id, $site_id ) {
    global $wpdb;
    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    
    $result = $wpdb->delete(
        $user_sites_table,
        array(
            'user_id' => $user_id,
            'site_id' => $site_id,
        ),
        array( '%d', '%d' )
    );
    
    if ( $result === false ) {
        return new WP_Error( 'delete_failed', 'Failed to revoke site access.' );
    }
    
    return true;
}

/**
 * Get all niches that a user has access to (based on their sites).
 *
 * @param int $user_id The user ID.
 * @return array Array of niche objects.
 */
function bite_get_user_niches( $user_id ) {
    $user_site_ids = bite_get_user_sites( $user_id );
    
    if ( empty( $user_site_ids ) ) {
        return array();
    }
    
    global $wpdb;
    $sites_table = $wpdb->prefix . 'bite_sites';
    $niches_table = $wpdb->prefix . 'bite_niches';
    
    $placeholders = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
    
    $niches = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT n.niche_id, n.niche_name 
         FROM $niches_table n
         INNER JOIN $sites_table s ON n.niche_id = s.niche_id
         WHERE s.site_id IN ($placeholders)
         ORDER BY n.niche_name ASC",
        $user_site_ids
    ) );
    
    return $niches ? $niches : array();
}

/**
 * Check if current user can view a specific site.
 * Helper function for templates.
 *
 * @param int $site_id The site ID to check.
 * @return bool True if current user has access.
 */
function bite_current_user_can_view_site( $site_id ) {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    
    return bite_user_has_site_access( get_current_user_id(), $site_id );
}

/**
 * Redirect user if they don't have access to a site.
 *
 * @param int $site_id The site ID being accessed.
 */
function bite_require_site_access( $site_id ) {
    if ( ! bite_current_user_can_view_site( $site_id ) ) {
        wp_redirect( home_url() );
        exit;
    }
}
