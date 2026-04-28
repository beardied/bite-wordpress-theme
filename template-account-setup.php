<?php
/**
 * Template Name: BITE Account Setup
 *
 * Consolidated page for managing all external connections:
 * Google Search Console, Google Analytics 4, and future integrations.
 *
 * @package BITE-theme
 */

if ( ! is_user_logged_in() ) {
    wp_redirect( wp_login_url() );
    exit;
}

get_header();

global $wpdb;
$current_user_id = get_current_user_id();

// User connection states
$user_connected   = bite_user_has_google_connection( $current_user_id );
$user_has_ga4_scope = bite_user_has_ga4_scope( $current_user_id );
$has_auth_errors  = ! empty( bite_get_user_auth_error_sites( $current_user_id ) ) || (bool) get_user_meta( $current_user_id, 'bite_google_auth_failed', true );

// Get user's sites
$user_site_ids = bite_get_user_sites( $current_user_id );
$user_sites = array();
if ( ! empty( $user_site_ids ) ) {
    $sites_table = $wpdb->prefix . 'bite_sites';
    $placeholders = implode( ',', array_fill( 0, count( $user_site_ids ), '%d' ) );
    $user_sites = $wpdb->get_results( $wpdb->prepare(
        "SELECT site_id, name, domain, gsc_property, ga4_property_id FROM $sites_table WHERE site_id IN ($placeholders) ORDER BY name ASC",
        $user_site_ids
    ) );
}

// GSC auth URL (basic scope)
$gsc_auth_url = bite_get_google_auth_url_with_scope( $current_user_id, false );
$gsc_auth_url_valid = ! is_wp_error( $gsc_auth_url );

// GA4 auth URL (enhanced scope)
$ga4_auth_url = bite_get_google_auth_url_with_scope( $current_user_id, true );
$ga4_auth_url_valid = ! is_wp_error( $ga4_auth_url );

// Fetch GA4 properties if user has scope
$ga4_properties = array();
if ( $user_connected && $user_has_ga4_scope ) {
    $props = bite_fetch_ga4_properties( $current_user_id );
    if ( ! is_wp_error( $props ) ) {
        $ga4_properties = $props;
    }
}

?>

<div class="bite-dashboard-wrapper">
    <?php get_template_part( 'includes/dashboard-sidebar' ); ?>

    <main id="main" class="bite-dashboard-main-content" role="main">

        <section class="bite-dashboard-welcome">
            <div class="bite-welcome-content">
                <h1 class="bite-welcome-title">Account Setup</h1>
                <p class="bite-welcome-subtitle">Connect your accounts to unlock powerful insights</p>
            </div>
        </section>

        <!-- Google Search Console Section -->
        <section class="bite-dashboard-section">
            <div class="bite-section-header">
                <h2>
                    <span class="material-icons" style="vertical-align: middle; margin-right: 8px; color: #4285f4;">search</span>
                    Google Search Console
                </h2>
            </div>

            <div class="bite-setup-card" style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 28px; margin-bottom: 24px;">
                <div style="display: flex; align-items: flex-start; gap: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 280px;">
                        <h3 style="margin: 0 0 8px 0; font-size: 1.15em;">Status</h3>
                        <?php if ( $user_connected && ! $has_auth_errors ) : ?>
                            <div style="display: flex; align-items: center; gap: 8px; color: #00a32a; font-weight: 500; margin-bottom: 12px;">
                                <span class="material-icons">check_circle</span>
                                Connected
                            </div>
                            <p style="margin: 0; color: #666; font-size: 0.9em;">
                                Your Google account is connected. You can add and monitor sites from your Search Console.
                            </p>
                        <?php elseif ( $has_auth_errors ) : ?>
                            <div style="display: flex; align-items: center; gap: 8px; color: #c0392b; font-weight: 500; margin-bottom: 12px;">
                                <span class="material-icons">error</span>
                                Authorization Expired
                            </div>
                            <p style="margin: 0; color: #666; font-size: 0.9em;">
                                Your Google access token has expired. Please reconnect to resume data syncing.
                            </p>
                        <?php else : ?>
                            <div style="display: flex; align-items: center; gap: 8px; color: #888; font-weight: 500; margin-bottom: 12px;">
                                <span class="material-icons">link_off</span>
                                Not Connected
                            </div>
                            <p style="margin: 0; color: #666; font-size: 0.9em;">
                                Connect your Google account to import Search Console data.
                            </p>
                        <?php endif; ?>

                        <div style="margin-top: 14px; padding: 12px 14px; background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4;">
                            <p style="margin: 0; font-size: 0.85em; color: #444; line-height: 1.5;">
                                <strong style="color: #4285f4;">What you get:</strong> Track clicks, impressions, CTR, and average position for all your sites. BITE automatically imports 16 months of historical data and keeps everything up to date daily.
                            </p>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 10px; min-width: 220px;">
                        <?php if ( $has_auth_errors ) : ?>
                            <?php if ( $gsc_auth_url_valid ) : ?>
                                <a href="<?php echo esc_url( $gsc_auth_url ); ?>" class="bite-button bite-button-primary">
                                    <span class="material-icons" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">refresh</span>
                                    Reconnect
                                </a>
                            <?php endif; ?>
                            <?php if ( $user_connected ) : ?>
                                <button type="button" class="bite-button bite-button-secondary" id="bite-disconnect-gsc" style="font-size: 0.9em;">
                                    <span class="material-icons" style="vertical-align: middle; margin-right: 4px; font-size: 16px;">link_off</span>
                                    Disconnect
                                </button>
                            <?php endif; ?>
                        <?php elseif ( $user_connected ) : ?>
                            <button type="button" class="bite-button bite-button-secondary" id="bite-disconnect-gsc">
                                <span class="material-icons" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">link_off</span>
                                Disconnect
                            </button>
                        <?php else : ?>
                            <?php if ( $gsc_auth_url_valid ) : ?>
                                <a href="<?php echo esc_url( $gsc_auth_url ); ?>" class="bite-button bite-button-primary">
                                    <span class="material-icons" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">login</span>
                                    Connect Google
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- Google Analytics 4 Section -->
        <section class="bite-dashboard-section">
            <div class="bite-section-header">
                <h2>
                    <span class="material-icons" style="vertical-align: middle; margin-right: 8px; color: #f9ab00;">analytics</span>
                    Google Analytics 4
                </h2>
            </div>

            <div class="bite-setup-card" style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 28px; margin-bottom: 24px;">
                <div style="display: flex; align-items: flex-start; gap: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 280px;">
                        <h3 style="margin: 0 0 8px 0; font-size: 1.15em;">Status</h3>
                        <?php if ( $user_connected && $user_has_ga4_scope ) : ?>
                            <div style="display: flex; align-items: center; gap: 8px; color: #00a32a; font-weight: 500; margin-bottom: 12px;">
                                <span class="material-icons">check_circle</span>
                                Connected
                            </div>
                            <p style="margin: 0 0 12px 0; color: #666; font-size: 0.9em;">
                                GA4 access is granted. Select a property for each site below to overlay traffic data on your charts.
                            </p>
                        <?php elseif ( $user_connected && ! $user_has_ga4_scope ) : ?>
                            <div style="display: flex; align-items: center; gap: 8px; color: #f9ab00; font-weight: 500; margin-bottom: 12px;">
                                <span class="material-icons">lock</span>
                                Available — One More Step
                            </div>
                            <p style="margin: 0 0 12px 0; color: #666; font-size: 0.9em;">
                                Your Google account is connected. Grant GA4 access to unlock traffic insights.
                            </p>
                        <?php else : ?>
                            <div style="display: flex; align-items: center; gap: 8px; color: #888; font-weight: 500; margin-bottom: 12px;">
                                <span class="material-icons">link_off</span>
                                Not Connected
                            </div>
                            <p style="margin: 0 0 12px 0; color: #666; font-size: 0.9em;">
                                Connect your Google account to enable GA4 tracking alongside Search Console data.
                            </p>
                        <?php endif; ?>

                        <div style="margin-top: 14px; padding: 12px 14px; background: #fff8e1; border-radius: var(--radius-sm); border-left: 3px solid #f9ab00;">
                            <p style="margin: 0; font-size: 0.85em; color: #444; line-height: 1.5;">
                                <strong style="color: #f9ab00;">What you get:</strong> Overlay real traffic data (sessions, users, pageviews) on the same charts as your Search Console metrics. See how search performance actually translates into website visits — all in one view.
                            </p>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 10px; min-width: 220px;">
                        <?php if ( $user_connected && $user_has_ga4_scope ) : ?>
                            <button type="button" class="bite-button bite-button-secondary" id="bite-disconnect-ga4-scope">
                                <span class="material-icons" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">link_off</span>
                                Revoke GA4 Access
                            </button>
                        <?php elseif ( $user_connected && ! $user_has_ga4_scope ) : ?>
                            <?php if ( $ga4_auth_url_valid ) : ?>
                                <a href="<?php echo esc_url( $ga4_auth_url ); ?>" class="bite-button bite-button-primary">
                                    <span class="material-icons" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">add_link</span>
                                    Grant GA4 Access
                                </a>
                            <?php endif; ?>
                        <?php else : ?>
                            <?php if ( $ga4_auth_url_valid ) : ?>
                                <a href="<?php echo esc_url( $ga4_auth_url ); ?>" class="bite-button bite-button-primary">
                                    <span class="material-icons" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">login</span>
                                    Connect GA4
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $user_connected && $user_has_ga4_scope && ! empty( $user_sites ) ) : ?>
                    <div style="margin-top: 24px; border-top: 1px solid var(--border-light); padding-top: 20px;">
                        <h4 style="margin: 0 0 16px 0; font-size: 1em; color: #555;">GA4 Property per Site</h4>
                        <div style="display: grid; gap: 12px;">
                            <?php foreach ( $user_sites as $site ) : ?>
                                <div class="bite-ga4-site-row" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px; background: var(--bg-color); border-radius: var(--radius-sm);" data-site-id="<?php echo esc_attr( $site->site_id ); ?>">
                                    <div style="flex: 1; min-width: 200px;">
                                        <strong><?php echo esc_html( $site->name ); ?></strong>
                                        <span style="color: #888; font-size: 0.85em; margin-left: 8px;"><?php echo esc_html( $site->domain ); ?></span>
                                    </div>
                                    <div style="min-width: 280px;">
                                        <select class="bite-ga4-property-select" data-site-id="<?php echo esc_attr( $site->site_id ); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-light); border-radius: var(--radius-sm); background: var(--card-bg); font-size: 0.9em;">
                                            <option value="">-- No GA4 property --</option>
                                            <?php foreach ( $ga4_properties as $prop ) : 
                                                $prop_id = bite_extract_ga4_property_id( $prop['property'] );
                                                $selected = ( $site->ga4_property_id === $prop_id );
                                            ?>
                                                <option value="<?php echo esc_attr( $prop['property'] ); ?>" <?php selected( $selected ); ?>>
                                                    <?php echo esc_html( $prop['displayName'] ); ?> (<?php echo esc_html( $prop_id ); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div style="min-width: 80px;">
                                        <button type="button" class="bite-button bite-button-primary bite-save-ga4-btn" data-site-id="<?php echo esc_attr( $site->site_id ); ?>" style="font-size: 0.85em; padding: 6px 14px;">
                                            Save
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Bing Webmaster Tools (Coming Soon) -->
        <section class="bite-dashboard-section">
            <div class="bite-section-header">
                <h2>
                    <span class="material-icons" style="vertical-align: middle; margin-right: 8px; color: #008373;">web</span>
                    Bing Webmaster Tools
                </h2>
            </div>

            <div class="bite-setup-card" style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 28px; margin-bottom: 24px; opacity: 0.7;">
                <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 280px;">
                        <h3 style="margin: 0 0 8px 0; font-size: 1.15em;">Coming in Phase 2</h3>
                        <p style="margin: 0; color: #666; font-size: 0.9em;">
                            Bing Webmaster Tools integration will allow you to track Bing search performance alongside Google data. This will use API key authentication (no OAuth required).
                        </p>
                    </div>
                    <div style="min-width: 120px;">
                        <span class="bite-button bite-button-secondary" style="cursor: default; opacity: 0.6;">
                            <span class="material-icons" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">schedule</span>
                            Coming Soon
                        </span>
                    </div>
                </div>
            </div>
        </section>



    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const nonce = '<?php echo wp_create_nonce( 'bite_ga4_nonce' ); ?>';

    // Disconnect GSC
    var disconnectGscBtn = document.getElementById('bite-disconnect-gsc');
    if ( disconnectGscBtn ) {
        disconnectGscBtn.addEventListener('click', function() {
            if ( ! confirm( 'Are you sure you want to disconnect your Google account? All site data syncing will stop.' ) ) {
                return;
            }
            disconnectGscBtn.disabled = true;
            disconnectGscBtn.innerHTML = '<span class="material-icons" style="vertical-align:middle;margin-right:4px;font-size:16px;">hourglass_empty</span> Disconnecting...';

            fetch( '<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=bite_disconnect_google&nonce=<?php echo wp_create_nonce( 'bite_disconnect_google' ); ?>'
            })
            .then( function(r) { return r.json(); } )
            .then( function(data) {
                if ( data.success ) {
                    window.location.reload();
                } else {
                    alert( 'Error: ' + ( data.data || 'Failed to disconnect' ) );
                    disconnectGscBtn.disabled = false;
                    disconnectGscBtn.innerHTML = '<span class="material-icons" style="vertical-align:middle;margin-right:4px;font-size:16px;">link_off</span> Disconnect GSC';
                }
            })
            .catch( function() {
                alert( 'Network error. Please try again.' );
                disconnectGscBtn.disabled = false;
                disconnectGscBtn.innerHTML = '<span class="material-icons" style="vertical-align:middle;margin-right:4px;font-size:16px;">link_off</span> Disconnect GSC';
            });
        });
    }

    // Revoke GA4 scope (disconnect Google entirely since we can't selectively revoke scopes)
    var disconnectGa4Btn = document.getElementById('bite-disconnect-ga4-scope');
    if ( disconnectGa4Btn ) {
        disconnectGa4Btn.addEventListener('click', function() {
            if ( ! confirm( 'This will disconnect your entire Google account (both GSC and GA4). Are you sure?' ) ) {
                return;
            }
            disconnectGa4Btn.disabled = true;
            disconnectGa4Btn.innerHTML = '<span class="material-icons" style="vertical-align:middle;margin-right:4px;font-size:16px;">hourglass_empty</span> Revoking...';

            fetch( '<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=bite_disconnect_google&nonce=<?php echo wp_create_nonce( 'bite_disconnect_google' ); ?>'
            })
            .then( function(r) { return r.json(); } )
            .then( function(data) {
                if ( data.success ) {
                    window.location.reload();
                } else {
                    alert( 'Error: ' + ( data.data || 'Failed' ) );
                    disconnectGa4Btn.disabled = false;
                    disconnectGa4Btn.innerHTML = '<span class="material-icons" style="vertical-align:middle;margin-right:4px;font-size:16px;">link_off</span> Revoke GA4 Access';
                }
            })
            .catch( function() {
                alert( 'Network error. Please try again.' );
                disconnectGa4Btn.disabled = false;
                disconnectGa4Btn.innerHTML = '<span class="material-icons" style="vertical-align:middle;margin-right:4px;font-size:16px;">link_off</span> Revoke GA4 Access';
            });
        });
    }

    // Save GA4 property per site
    document.querySelectorAll('.bite-save-ga4-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var siteId = this.dataset.siteId;
            var select = document.querySelector('.bite-ga4-property-select[data-site-id="' + siteId + '"]');
            var propertyId = select ? select.value : '';

            btn.disabled = true;
            var originalText = btn.textContent;
            btn.textContent = 'Saving...';

            var formData = new FormData();
            formData.append('action', 'bite_save_ga4_property');
            formData.append('nonce', nonce);
            formData.append('site_id', siteId);
            formData.append('property_id', propertyId);

            fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                if (data.success) {
                    btn.textContent = 'Saved!';
                    btn.style.backgroundColor = '#00a32a';
                    setTimeout(function() {
                        btn.textContent = originalText;
                        btn.style.backgroundColor = '';
                    }, 1500);
                } else {
                    btn.textContent = 'Error';
                    alert('Error: ' + (data.data || 'Failed to save'));
                    setTimeout(function() {
                        btn.textContent = originalText;
                    }, 1500);
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.textContent = 'Error';
                alert('Network error. Please try again.');
                setTimeout(function() {
                    btn.textContent = originalText;
                }, 1500);
            });
        });
    });
});
</script>

<?php get_footer(); ?>
