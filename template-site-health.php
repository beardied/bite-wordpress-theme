<?php
/**
 * Template Name: BITE Site Health
 *
 * URL Inspection and sitemap index monitoring dashboard.
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
$is_admin        = current_user_can( 'manage_options' );

// Get user's sites
$user_site_ids = bite_get_user_sites( $current_user_id );
$user_sites = array();
if ( ! empty( $user_site_ids ) ) {
    $sites_table = $wpdb->prefix . 'bite_sites';
    $placeholders = implode( ',', array_fill( 0, count( $user_site_ids ), '%d' ) );
    $user_sites = $wpdb->get_results( $wpdb->prepare(
        "SELECT site_id, name, domain, gsc_property, sitemap_url FROM $sites_table WHERE site_id IN ($placeholders) ORDER BY name ASC",
        $user_site_ids
    ) );
}

$selected_site_id = isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : ( $user_sites[0]->site_id ?? 0 );
$selected_site = null;
foreach ( $user_sites as $s ) {
    if ( $s->site_id == $selected_site_id ) {
        $selected_site = $s;
        break;
    }
}

// Load stats if a site is selected
$sitemap_summary = array();
$inspection_summary = array();
$recent_inspections = array();
$not_indexed_urls = array();
$security_headers = null;
$ssl_labs = null;

if ( $selected_site ) {
    if ( function_exists( 'bite_get_sitemap_summary' ) ) {
        $sitemap_summary = bite_get_sitemap_summary( $selected_site_id );
    }
    if ( function_exists( 'bite_get_url_inspection_summary' ) ) {
        $inspection_summary = bite_get_url_inspection_summary( $selected_site_id );
    }
    if ( function_exists( 'bite_get_latest_inspections' ) ) {
        $recent_inspections = bite_get_latest_inspections( $selected_site_id, 'recent', 20 );
    }
    if ( function_exists( 'bite_get_sitemap_urls' ) ) {
        $not_indexed_urls = bite_get_sitemap_urls( $selected_site_id, 'not_indexed' );
    }
    if ( function_exists( 'bite_get_latest_security_headers' ) ) {
        $security_headers = bite_get_latest_security_headers( $selected_site_id );
    }
    if ( function_exists( 'bite_get_latest_ssl_labs' ) ) {
        $ssl_labs = bite_get_latest_ssl_labs( $selected_site_id );
    }
}

?>

<div class="bite-dashboard-wrapper">
    <?php get_template_part( 'includes/dashboard-sidebar' ); ?>

    <main id="main" class="bite-dashboard-main-content" role="main">

        <section class="bite-dashboard-welcome">
            <div class="bite-welcome-content">
                <h1 class="bite-welcome-title">Site Health</h1>
                <p class="bite-welcome-subtitle">Index coverage, sitemap monitoring, and URL inspection results</p>
            </div>
        </section>

        <section class="bite-dashboard-section">
            <form method="GET" action="" class="bite-stats-filters" style="background: var(--card-bg); border: 1px solid var(--border-light); padding: 20px 24px; border-radius: var(--radius-lg); margin-bottom: 25px;">
                <div class="bite-filters-row" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: center;">
                    <div class="bite-filter-group">
                        <label for="health_site">Site</label>
                        <select id="health_site" name="site_id" onchange="this.form.submit()">
                            <?php foreach ( $user_sites as $s ) : ?>
                                <option value="<?php echo esc_attr( $s->site_id ); ?>" <?php selected( $selected_site_id, $s->site_id ); ?>>
                                    <?php echo esc_html( $s->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </section>

        <?php if ( $selected_site ) : ?>

            <!-- Overview Cards -->
            <section class="bite-dashboard-section">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #2271b1;"><?php echo number_format( $sitemap_summary['total'] ?? 0 ); ?></div>
                        <div style="color: #666; font-size: 0.85em; margin-top: 4px;">URLs in Sitemap</div>
                    </div>
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #00a32a;"><?php echo number_format( $inspection_summary['pass'] ?? 0 ); ?></div>
                        <div style="color: #666; font-size: 0.85em; margin-top: 4px;">Indexed</div>
                    </div>
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #d63638;"><?php echo number_format( $inspection_summary['fail'] ?? 0 ); ?></div>
                        <div style="color: #666; font-size: 0.85em; margin-top: 4px;">Not Indexed</div>
                    </div>
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #f9ab00;"><?php echo number_format( $sitemap_summary['recently_added'] ?? 0 ); ?></div>
                        <div style="color: #666; font-size: 0.85em; margin-top: 4px;">New (7d)</div>
                    </div>
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #9c27b0;"><?php echo number_format( $inspection_summary['total_inspected'] ?? 0 ); ?></div>
                        <div style="color: #666; font-size: 0.85em; margin-top: 4px;">Inspected</div>
                    </div>
                </div>
            </section>

            <!-- Security Scorecards -->
            <section class="bite-dashboard-section">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <!-- Security Header Score -->
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                            <span class="material-icons" style="color: #2271b1;">shield</span>
                            <strong style="color: #333;">Security Headers</strong>
                        </div>
                        <?php if ( $security_headers ) : ?>
                            <div style="text-align: center; margin-bottom: 12px;">
                                <div style="font-size: 2.5em; font-weight: bold; color: <?php echo $security_headers->overall_score >= 80 ? '#00a32a' : ( $security_headers->overall_score >= 50 ? '#f9ab00' : '#d63638' ); ?>;">
                                    <?php echo intval( $security_headers->overall_score ); ?>/100
                                </div>
                                <div style="color: #888; font-size: 0.8em;">Scanned <?php echo esc_html( date( 'M j', strtotime( $security_headers->scanned_at ) ) ); ?></div>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; font-size: 0.75em; text-align: center;">
                                <div style="padding: 4px; border-radius: 4px; background: <?php echo $security_headers->hsts ? '#e7f5e7' : '#ffeaea'; ?>; color: <?php echo $security_headers->hsts ? '#00a32a' : '#d63638'; ?>;">
                                    <span class="material-icons" style="font-size: 14px; display: block;"><?php echo $security_headers->hsts ? 'check' : 'close'; ?></span>HSTS
                                </div>
                                <div style="padding: 4px; border-radius: 4px; background: <?php echo $security_headers->csp ? '#e7f5e7' : '#ffeaea'; ?>; color: <?php echo $security_headers->csp ? '#00a32a' : '#d63638'; ?>;">
                                    <span class="material-icons" style="font-size: 14px; display: block;"><?php echo $security_headers->csp ? 'check' : 'close'; ?></span>CSP
                                </div>
                                <div style="padding: 4px; border-radius: 4px; background: <?php echo $security_headers->x_frame_options ? '#e7f5e7' : '#ffeaea'; ?>; color: <?php echo $security_headers->x_frame_options ? '#00a32a' : '#d63638'; ?>;">
                                    <span class="material-icons" style="font-size: 14px; display: block;"><?php echo $security_headers->x_frame_options ? 'check' : 'close'; ?></span>X-Frame
                                </div>
                                <div style="padding: 4px; border-radius: 4px; background: <?php echo $security_headers->x_content_type_options ? '#e7f5e7' : '#ffeaea'; ?>; color: <?php echo $security_headers->x_content_type_options ? '#00a32a' : '#d63638'; ?>;">
                                    <span class="material-icons" style="font-size: 14px; display: block;"><?php echo $security_headers->x_content_type_options ? 'check' : 'close'; ?></span>X-CTO
                                </div>
                                <div style="padding: 4px; border-radius: 4px; background: <?php echo $security_headers->referrer_policy ? '#e7f5e7' : '#ffeaea'; ?>; color: <?php echo $security_headers->referrer_policy ? '#00a32a' : '#d63638'; ?>;">
                                    <span class="material-icons" style="font-size: 14px; display: block;"><?php echo $security_headers->referrer_policy ? 'check' : 'close'; ?></span>Referrer
                                </div>
                                <div style="padding: 4px; border-radius: 4px; background: <?php echo $security_headers->permissions_policy ? '#e7f5e7' : '#ffeaea'; ?>; color: <?php echo $security_headers->permissions_policy ? '#00a32a' : '#d63638'; ?>;">
                                    <span class="material-icons" style="font-size: 14px; display: block;"><?php echo $security_headers->permissions_policy ? 'check' : 'close'; ?></span>Perms
                                </div>
                            </div>
                        <?php else : ?>
                            <div style="text-align: center; padding: 20px; color: #888;">
                                <span class="material-icons" style="font-size: 32px;">pending</span>
                                <p style="margin: 8px 0 0; font-size: 0.85em;">Awaiting first scan</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- SSL Labs Grade -->
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                            <span class="material-icons" style="color: #2271b1;">lock</span>
                            <strong style="color: #333;">SSL/TLS Grade</strong>
                        </div>
                        <?php if ( $ssl_labs ) : ?>
                            <?php
                            $grade_color = '#00a32a';
                            if ( $ssl_labs->grade === 'B' || $ssl_labs->grade === 'C' ) $grade_color = '#f9ab00';
                            if ( $ssl_labs->grade === 'D' || $ssl_labs->grade === 'E' || $ssl_labs->grade === 'F' || $ssl_labs->grade === 'T' || $ssl_labs->grade === 'M' ) $grade_color = '#d63638';
                            ?>
                            <div style="text-align: center; margin-bottom: 12px;">
                                <div style="font-size: 2.5em; font-weight: bold; color: <?php echo esc_attr( $grade_color ); ?>;">
                                    <?php echo esc_html( $ssl_labs->grade ?: '?' ); ?>
                                </div>
                                <div style="color: #888; font-size: 0.8em;">Scanned <?php echo esc_html( date( 'M j', strtotime( $ssl_labs->scanned_at ) ) ); ?></div>
                            </div>
                            <?php if ( $ssl_labs->cert_expiry ) : ?>
                                <div style="font-size: 0.8em; color: #666; text-align: center;">
                                    <span class="material-icons" style="font-size: 14px; vertical-align: middle;">event</span>
                                    Cert expires <?php echo esc_html( date( 'M j, Y', strtotime( $ssl_labs->cert_expiry ) ) ); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ( ! empty( $ssl_labs->vulnerabilities ) ) : ?>
                                <div style="font-size: 0.75em; color: #d63638; text-align: center; margin-top: 6px;">
                                    <span class="material-icons" style="font-size: 14px; vertical-align: middle;">warning</span>
                                    <?php echo esc_html( $ssl_labs->vulnerabilities ); ?>
                                </div>
                            <?php endif; ?>
                        <?php else : ?>
                            <div style="text-align: center; padding: 20px; color: #888;">
                                <span class="material-icons" style="font-size: 32px;">pending</span>
                                <p style="margin: 8px 0 0; font-size: 0.85em;">Awaiting first scan</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Sitemap Info -->
            <section class="bite-dashboard-section">
                <div class="bite-section-header">
                    <h2>
                        <span class="material-icons" style="vertical-align: middle; margin-right: 8px; color: #2271b1;">account_tree</span>
                        Sitemap
                    </h2>
                </div>
                <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 24px;">
                    <?php if ( ! empty( $selected_site->sitemap_url ) ) : ?>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                            <span class="material-icons" style="color: #00a32a;">check_circle</span>
                            <strong style="color: #333;">Sitemap detected:</strong>
                            <a href="<?php echo esc_url( $selected_site->sitemap_url ); ?>" target="_blank" style="word-break: break-all;"><?php echo esc_html( $selected_site->sitemap_url ); ?></a>
                        </div>
                        <p style="margin: 0; color: #666; font-size: 0.9em;">
                            BITE monitors this sitemap daily and tracks which URLs are indexed by Google.
                            <?php if ( ! empty( $sitemap_summary['total'] ) ) : ?>
                                <strong><?php echo number_format( $sitemap_summary['total'] ); ?></strong> URLs currently tracked.
                            <?php else : ?>
                                Sitemap will be parsed during the next daily update.
                            <?php endif; ?>
                        </p>
                    <?php else : ?>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                            <span class="material-icons" style="color: #888;">help_outline</span>
                            <strong style="color: #666;">No sitemap configured</strong>
                        </div>
                        <p style="margin: 0 0 12px 0; color: #666; font-size: 0.9em;">
                            Go to <strong>Account Setup</strong> to auto-detect or manually set the sitemap URL for this site.
                        </p>
                        <a href="<?php echo esc_url( get_permalink( get_page_by_path( 'account-setup' ) ) ?: home_url( '/account-setup/' ) ); ?>" class="bite-button bite-button-primary" style="font-size: 0.85em;">
                            Configure Sitemap →
                        </a>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Not Indexed URLs -->
            <?php if ( ! empty( $not_indexed_urls ) ) : ?>
            <section class="bite-dashboard-section">
                <div class="bite-section-header">
                    <h2>
                        <span class="material-icons" style="vertical-align: middle; margin-right: 8px; color: #d63638;">error</span>
                        Not Indexed URLs <span style="font-size: 0.7em; color: #888; font-weight: 400;">(<?php echo count( $not_indexed_urls ); ?>)</span>
                    </h2>
                </div>
                <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 0; margin-bottom: 24px; overflow: hidden;">
                    <div style="max-height: 400px; overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
                            <thead style="position: sticky; top: 0; background: var(--bg-color); z-index: 1;">
                                <tr style="border-bottom: 1px solid var(--border-light);">
                                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555;">URL</th>
                                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 140px;">First Seen</th>
                                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 140px;">Last Inspected</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( array_slice( $not_indexed_urls, 0, 50 ) as $url_row ) : ?>
                                    <tr style="border-bottom: 1px solid var(--border-light);">
                                        <td style="padding: 10px 16px; word-break: break-all;">
                                            <a href="<?php echo esc_url( $url_row->url ); ?>" target="_blank" style="color: #2271b1;"><?php echo esc_html( $url_row->url ); ?></a>
                                        </td>
                                        <td style="padding: 10px 16px; color: #666;"><?php echo esc_html( date( 'M j, Y', strtotime( $url_row->first_seen ) ) ); ?></td>
                                        <td style="padding: 10px 16px; color: #666;"><?php echo $url_row->last_inspected ? esc_html( date( 'M j, Y', strtotime( $url_row->last_inspected ) ) ) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ( count( $not_indexed_urls ) > 50 ) : ?>
                        <div style="padding: 12px 16px; background: var(--bg-color); color: #666; font-size: 0.85em; text-align: center;">
                            Showing 50 of <?php echo number_format( count( $not_indexed_urls ) ); ?> not indexed URLs
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Recent Inspections -->
            <?php if ( ! empty( $recent_inspections ) ) : ?>
            <section class="bite-dashboard-section">
                <div class="bite-section-header">
                    <h2>
                        <span class="material-icons" style="vertical-align: middle; margin-right: 8px; color: #2271b1;">manage_search</span>
                        Recent URL Inspections
                    </h2>
                </div>
                <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 0; margin-bottom: 24px; overflow: hidden;">
                    <div style="max-height: 500px; overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
                            <thead style="position: sticky; top: 0; background: var(--bg-color); z-index: 1;">
                                <tr style="border-bottom: 1px solid var(--border-light);">
                                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555;">URL</th>
                                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 100px;">Status</th>
                                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 160px;">Coverage</th>
                                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 100px;">Mobile</th>
                                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 120px;">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $recent_inspections as $ins ) :
                                    $is_pass = in_array( strtoupper( $ins->verdict ), array( 'PASS', 'Pass' ) );
                                    $status_color = $is_pass ? '#00a32a' : '#d63638';
                                    $status_icon = $is_pass ? 'check_circle' : 'cancel';
                                    $mobile_pass = in_array( strtoupper( $ins->mobile_usability ), array( 'PASS', 'Pass' ) );
                                    $mobile_color = $mobile_pass ? '#00a32a' : '#d63638';
                                ?>
                                    <tr style="border-bottom: 1px solid var(--border-light);">
                                        <td style="padding: 10px 16px; word-break: break-all;">
                                            <a href="<?php echo esc_url( $ins->url ); ?>" target="_blank" style="color: #2271b1; font-size: 0.9em;"><?php echo esc_html( $ins->url ); ?></a>
                                        </td>
                                        <td style="padding: 10px 16px;">
                                            <span style="display: inline-flex; align-items: center; gap: 4px; color: <?php echo esc_attr( $status_color ); ?>; font-weight: 600; font-size: 0.85em;">
                                                <span class="material-icons" style="font-size: 16px;"><?php echo esc_html( $status_icon ); ?></span>
                                                <?php echo esc_html( $ins->verdict ?: 'Unknown' ); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 10px 16px; color: #666; font-size: 0.85em;"><?php echo esc_html( $ins->coverage_state ?: '—' ); ?></td>
                                        <td style="padding: 10px 16px;">
                                            <span style="color: <?php echo esc_attr( $mobile_color ); ?>; font-size: 0.85em;">
                                                <?php echo esc_html( $ins->mobile_usability ?: '—' ); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 10px 16px; color: #666; font-size: 0.85em;"><?php echo esc_html( date( 'M j', strtotime( $ins->inspected_at ) ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            <?php endif; ?>

        <?php else : ?>
            <section class="bite-dashboard-section">
                <div style="text-align: center; padding: 60px 20px;">
                    <p style="font-size: 1.2em; color: #666;">Select a site to view its health data.</p>
                </div>
            </section>
        <?php endif; ?>

    </main>
</div>

<?php get_footer(); ?>
