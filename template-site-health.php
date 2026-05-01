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
$all_sitemap_urls = array();
$security_headers = null;
$ssl_labs = null;

// Table display settings (client-side JS filtering)
$not_indexed_per_page = 50;
$inspections_per_page = 25;

if ( $selected_site ) {
    if ( function_exists( 'bite_get_sitemap_summary' ) ) {
        $sitemap_summary = bite_get_sitemap_summary( $selected_site_id );
    }
    if ( function_exists( 'bite_get_url_inspection_summary' ) ) {
        $inspection_summary = bite_get_url_inspection_summary( $selected_site_id );
    }
    if ( function_exists( 'bite_get_all_sitemap_urls_with_inspection' ) ) {
        $recent_inspections = bite_get_all_sitemap_urls_with_inspection( $selected_site_id );
    }
    // GSC-discovered URLs not in sitemap
    $gsc_orphan_urls = array();
    if ( function_exists( 'bite_get_gsc_orphan_urls' ) ) {
        $gsc_orphan_urls = bite_get_gsc_orphan_urls( $selected_site_id );
    }
    if ( function_exists( 'bite_get_sitemap_urls' ) ) {
        $not_indexed_urls = bite_get_sitemap_urls( $selected_site_id, 'not_indexed' );
        $all_sitemap_urls = bite_get_sitemap_urls( $selected_site_id, 'all' );
    }
    if ( function_exists( 'bite_get_latest_security_headers' ) ) {
        $security_headers = bite_get_latest_security_headers( $selected_site_id );
    }
    if ( function_exists( 'bite_get_latest_ssl_labs' ) ) {
        $ssl_labs = bite_get_latest_ssl_labs( $selected_site_id );
    }
}

$total_not_indexed = count( $not_indexed_urls );

// Coverage stats
$total_sitemap = $sitemap_summary['total'] ?? 0;
$total_inspected = $inspection_summary['total_inspected'] ?? 0;
$pending_inspection = max( 0, $total_sitemap - $total_inspected );
$has_inspection_data = $total_inspected > 0;

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

            <!-- Coverage Notice -->
            <?php if ( $total_sitemap > 0 && ! $has_inspection_data ) : ?>
            <section class="bite-dashboard-section">
                <div style="background: #e8f4fd; border-radius: var(--radius-lg); border-left: 3px solid #4285f4; padding: 14px 18px; margin-bottom: 20px; font-size: 0.9em; color: #444;">
                    <span class="material-icons" style="vertical-align: middle; margin-right: 6px; color: #4285f4;">info</span>
                    <strong>URL Inspection is starting.</strong> BITE found <?php echo number_format( $total_sitemap ); ?> URLs in your sitemap. Google URL Inspection runs during the daily morning update (6am UTC) and checks up to 2,000 URLs per day. Check back tomorrow for index coverage data.
                </div>
            </section>
            <?php endif; ?>

            <!-- Overview Cards -->
            <section class="bite-dashboard-section">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #2271b1;"><?php echo number_format( $total_sitemap ); ?></div>
                        <div style="color: #666; font-size: 0.85em; margin-top: 4px;">URLs in Sitemap</div>
                    </div>
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #00a32a;"><?php echo number_format( $inspection_summary['pass'] ?? 0 ); ?></div>
                        <div style="color: #666; font-size: 0.85em; margin-top: 4px;">Indexed by Google</div>
                    </div>
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #d63638;"><?php echo number_format( $inspection_summary['fail'] ?? 0 ); ?></div>
                        <div style="color: #666; font-size: 0.85em; margin-top: 4px;">Not Indexed by Google</div>
                    </div>
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #f9ab00;"><?php echo number_format( $sitemap_summary['recently_added'] ?? 0 ); ?></div>
                        <div style="color: #666; font-size: 0.85em; margin-top: 4px;">New (7d)</div>
                    </div>
                    <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #9c27b0;"><?php echo number_format( $total_inspected ); ?></div>
                        <div style="color: #666; font-size: 0.85em; margin-top: 4px;">Inspected by Google</div>
                    </div>
                </div>
            </section>

            <!-- Coverage Comparison -->
            <?php if ( $total_sitemap > 0 ) : ?>
            <section class="bite-dashboard-section">
                <div class="bite-section-header">
                    <h2>
                        <span class="material-icons" style="vertical-align: middle; margin-right: 8px; color: #2271b1;">compare_arrows</span>
                        Coverage Comparison
                    </h2>
                </div>
                <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 24px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
                        <div style="text-align: center; padding: 16px; background: var(--bg-color); border-radius: var(--radius-sm);">
                            <div style="font-size: 1.8em; font-weight: bold; color: #2271b1;"><?php echo number_format( $total_sitemap ); ?></div>
                            <div style="color: #666; font-size: 0.8em; margin-top: 4px;">In Sitemap</div>
                            <div style="font-size: 0.75em; color: #888; margin-top: 2px;">What you tell search engines you have</div>
                        </div>
                        <div style="text-align: center; padding: 16px; background: var(--bg-color); border-radius: var(--radius-sm);">
                            <div style="font-size: 1.8em; font-weight: bold; color: #00a32a;"><?php echo number_format( $total_inspected ); ?></div>
                            <div style="color: #666; font-size: 0.8em; margin-top: 4px;">Inspected by Google</div>
                            <div style="font-size: 0.75em; color: #888; margin-top: 2px;">URLs BITE has asked Google about</div>
                        </div>
                        <div style="text-align: center; padding: 16px; background: var(--bg-color); border-radius: var(--radius-sm);">
                            <div style="font-size: 1.8em; font-weight: bold; color: <?php echo $pending_inspection > 0 ? '#f9ab00' : '#00a32a'; ?>;"><?php echo number_format( $pending_inspection ); ?></div>
                            <div style="color: #666; font-size: 0.8em; margin-top: 4px;">Pending Inspection</div>
                            <div style="font-size: 0.75em; color: #888; margin-top: 2px;">In sitemap but not yet checked</div>
                        </div>
                    </div>
                    <?php if ( $pending_inspection > 0 ) : ?>
                        <div style="width: 100%; height: 8px; background: var(--bg-color); border-radius: 4px; overflow: hidden;">
                            <?php
                            $pct_inspected = $total_sitemap > 0 ? round( ( $total_inspected / $total_sitemap ) * 100 ) : 0;
                            ?>
                            <div style="width: <?php echo esc_attr( $pct_inspected ); ?>%; height: 100%; background: linear-gradient(90deg, #00a32a, #34a853); border-radius: 4px;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.75em; color: #888; margin-top: 4px;">
                            <span>0%</span>
                            <span><?php echo esc_html( $pct_inspected ); ?>% inspected</span>
                            <span>100%</span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Security Scorecards -->
            <section class="bite-dashboard-section">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 24px;">
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
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; font-size: 0.75em; text-align: center; margin-bottom: 14px;">
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
                            <details style="font-size: 0.85em; color: #444;">
                                <summary style="cursor: pointer; color: #2271b1; font-weight: 500;">What do these mean?</summary>
                                <div style="margin-top: 12px; display: flex; flex-direction: column; gap: 12px;">

                                    <div style="background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4; padding: 12px 14px;">
                                        <strong style="color: #1a73e8;">HSTS</strong> <span style="font-size: 0.75em; background: #f9ab00; color: #fff; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">Advanced</span>
                                        <p style="margin: 6px 0 0;"><strong>What it does:</strong> Tells browsers to <em>always</em> use HTTPS for your domain — even if someone types <code>http://</code> or follows an old link.</p>
                                        <p style="margin: 6px 0 0;"><strong>Risk without it:</strong> Attackers can downgrade connections to HTTP (SSL-stripping attacks), exposing passwords and session cookies.</p>
                                        <p style="margin: 6px 0 0;"><strong>How to fix:</strong>
                                            <br>• <strong>Server config:</strong> Add <code>Strict-Transport-Security: max-age=31536000; includeSubDomains</code> in <code>.htaccess</code>, <code>nginx.conf</code>, or your hosting panel.
                                            <br>• <strong>Plugin:</strong> Wordfence, Sucuri, or Cloudflare can inject this header.
                                            <br>• <strong>Hosting:</strong> Some managed hosts (Kinsta, WP Engine, SiteGround) let you toggle HSTS in their dashboard.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>Pros &amp; cons:</strong> Excellent protection once enabled, <span style="color: #d63638;"><strong>but</strong></span> it is very hard to undo. If you ever need to serve HTTP again (e.g. dev/staging sites, mixed-content issues), visitors may be locked out until the max-age expires. Test thoroughly on a staging domain first and start with a short max-age (e.g. 300 seconds) before ramping up.</p>
                                    </div>

                                    <div style="background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4; padding: 12px 14px;">
                                        <strong style="color: #1a73e8;">CSP</strong> <span style="font-size: 0.75em; background: #f9ab00; color: #fff; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">Advanced</span>
                                        <p style="margin: 6px 0 0;"><strong>What it does:</strong> Content Security Policy tells the browser exactly which sources (scripts, styles, images, fonts) are allowed to load. It blocks injected malicious code (XSS).</p>
                                        <p style="margin: 6px 0 0;"><strong>Risk without it:</strong> If an attacker slips JavaScript into a comment form or plugin vulnerability, the browser will run it — stealing cookies, redirecting users, or defacing pages.</p>
                                        <p style="margin: 6px 0 0;"><strong>How to fix:</strong>
                                            <br>• <strong>Plugin:</strong> Use a dedicated CSP plugin (e.g. "Content Security Policy Pro" or Sucuri) to build rules without touching code.
                                            <br>• <strong>Server config:</strong> Add <code>Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline';</code> — but <em>you must customise this per site</em> or you will break embeds, Google Analytics, fonts, etc.
                                            <br>• <strong>Cloudflare:</strong> Enterprise plans can add CSP headers via Transform Rules.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>Pros &amp; cons:</strong> One of the most powerful defences against XSS. The downside is it can easily break functionality (analytics, ad scripts, social widgets, page builders) if rules are too strict. Start with <code>Content-Security-Policy-Report-Only</code> to collect violations before enforcing.</p>
                                    </div>

                                    <div style="background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4; padding: 12px 14px;">
                                        <strong style="color: #1a73e8;">X-Frame-Options</strong> <span style="font-size: 0.75em; background: #00a32a; color: #fff; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">Easy</span>
                                        <p style="margin: 6px 0 0;"><strong>What it does:</strong> Prevents your site being embedded inside another site’s <code>&lt;iframe&gt;</code>.</p>
                                        <p style="margin: 6px 0 0;"><strong>Risk without it:</strong> Clickjacking — attackers can load your login or checkout page invisibly over their own UI, tricking users into clicking buttons they cannot see.</p>
                                        <p style="margin: 6px 0 0;"><strong>How to fix:</strong>
                                            <br>• <strong>Server config:</strong> Add <code>X-Frame-Options: SAMEORIGIN</code> in <code>.htaccess</code> or <code>nginx.conf</code>.
                                            <br>• <strong>Plugin:</strong> Most security plugins (Wordfence, iThemes Security, Sucuri) have a one-click toggle.
                                            <br>• <strong>Modern alternative:</strong> Use CSP <code>frame-ancestors 'self'</code> instead — it does the same job and is the newer standard.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>Pros &amp; cons:</strong> Very safe to enable and almost zero risk of breaking anything unless you intentionally embed your own site elsewhere. Use <code>DENY</code> instead of <code>SAMEORIGIN</code> if you never need framing at all.</p>
                                    </div>

                                    <div style="background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4; padding: 12px 14px;">
                                        <strong style="color: #1a73e8;">X-Content-Type-Options</strong> <span style="font-size: 0.75em; background: #00a32a; color: #fff; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">Easy</span>
                                        <p style="margin: 6px 0 0;"><strong>What it does:</strong> Tells browsers not to "guess" what type a file is (MIME-sniffing). They must trust the <code>Content-Type</code> header sent by the server.</p>
                                        <p style="margin: 6px 0 0;"><strong>Risk without it:</strong> An attacker could upload a file that looks harmless (e.g. an image) but contains JavaScript. The browser might sniff it as a script and execute it.</p>
                                        <p style="margin: 6px 0 0;"><strong>How to fix:</strong>
                                            <br>• <strong>Server config:</strong> Add <code>X-Content-Type-Options: nosniff</code> in <code>.htaccess</code> or <code>nginx.conf</code>.
                                            <br>• <strong>Plugin:</strong> Enabled by default in most WordPress security plugins.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>Pros &amp; cons:</strong> Zero downside. Safe for every site and should be enabled globally.</p>
                                    </div>

                                    <div style="background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4; padding: 12px 14px;">
                                        <strong style="color: #1a73e8;">Referrer-Policy</strong> <span style="font-size: 0.75em; background: #00a32a; color: #fff; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">Easy</span>
                                        <p style="margin: 6px 0 0;"><strong>What it does:</strong> Controls how much of your URL leaks to third-party sites when a visitor clicks an external link.</p>
                                        <p style="margin: 6px 0 0;"><strong>Risk without it:</strong> Sensitive data in URLs (order IDs, reset tokens, search terms) can end up in another site's analytics logs or referrer headers.</p>
                                        <p style="margin: 6px 0 0;"><strong>How to fix:</strong>
                                            <br>• <strong>Server config:</strong> Add <code>Referrer-Policy: strict-origin-when-cross-origin</code> in <code>.htaccess</code> or <code>nginx.conf</code>.
                                            <br>• <strong>Plugin:</strong> Wordfence, Sucuri, or "HTTP Headers" plugins can set this in one click.
                                            <br>• <strong>WordPress core:</strong> As of WP 6.4, WordPress can output this header automatically if your server does not.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>Pros &amp; cons:</strong> Recommended default (<code>strict-origin-when-cross-origin</code>) is safe for nearly all sites. If you run an affiliate programme that depends on full referrer paths, you may need <code>no-referrer-when-downgrade</code> instead — but weigh privacy trade-offs.</p>
                                    </div>

                                    <div style="background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4; padding: 12px 14px;">
                                        <strong style="color: #1a73e8;">Permissions-Policy</strong> <span style="font-size: 0.75em; background: #00a32a; color: #fff; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">Easy</span>
                                        <p style="margin: 6px 0 0;"><strong>What it does:</strong> Declares which browser features (camera, microphone, geolocation, autoplay, etc.) your site and any embedded iframes are allowed to use.</p>
                                        <p style="margin: 6px 0 0;"><strong>Risk without it:</strong> Malicious or compromised third-party scripts (ads, chat widgets, analytics) could silently access the user's camera, location, or clipboard.</p>
                                        <p style="margin: 6px 0 0;"><strong>How to fix:</strong>
                                            <br>• <strong>Server config:</strong> Add <code>Permissions-Policy: camera=(), microphone=(), geolocation=()</code> in <code>.htaccess</code> or <code>nginx.conf</code>.
                                            <br>• <strong>Plugin:</strong> Security plugins often let you tick-box which features to disable.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>Pros &amp; cons:</strong> Very safe for most brochure or blog sites. If your site legitimately needs geolocation (store locators), video chat, or autoplay media, you will need to adjust the policy to allowlist those specific origins — otherwise those features will break.</p>
                                    </div>

                                </div>
                            </details>
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
                                <div style="font-size: 0.8em; color: #666; text-align: center; margin-bottom: 8px;">
                                    <span class="material-icons" style="font-size: 14px; vertical-align: middle;">event</span>
                                    Cert expires <?php echo esc_html( date( 'M j, Y', strtotime( $ssl_labs->cert_expiry ) ) ); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ( ! empty( $ssl_labs->vulnerabilities ) ) : ?>
                                <div style="font-size: 0.75em; color: #d63638; text-align: center; margin-bottom: 8px;">
                                    <span class="material-icons" style="font-size: 14px; vertical-align: middle;">warning</span>
                                    <?php echo esc_html( $ssl_labs->vulnerabilities ); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ( ! empty( $ssl_labs->protocols ) ) : ?>
                                <div style="font-size: 0.75em; color: #666; text-align: center; margin-bottom: 12px;">
                                    <span class="material-icons" style="font-size: 14px; vertical-align: middle;">settings_ethernet</span>
                                    <?php echo esc_html( $ssl_labs->protocols ); ?>
                                </div>
                            <?php endif; ?>
                            <details style="font-size: 0.85em; color: #444;">
                                <summary style="cursor: pointer; color: #2271b1; font-weight: 500;">What does this grade mean?</summary>
                                <div style="margin-top: 12px; display: flex; flex-direction: column; gap: 12px;">

                                    <div style="background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4; padding: 12px 14px;">
                                        <strong style="color: #1a73e8;">Grade meanings</strong>
                                        <p style="margin: 6px 0 0;"><strong>A+ / A</strong> — Excellent. Modern TLS 1.2/1.3 only, strong cipher suites, no weak DH parameters, HSTS enabled with a long max-age. Your visitors' data is very well protected in transit.</p>
                                        <p style="margin: 6px 0 0;"><strong>B</strong> — Good overall, but one or more minor issues drag the score down. Common culprits: TLS 1.0/1.1 still enabled, no HSTS preloading, or slightly outdated cipher preference order. Still safe for everyday use.</p>
                                        <p style="margin: 6px 0 0;"><strong>C</strong> — Fair. Known weaknesses are present. Common reasons: weak Diffie-Hellman parameters, support for old cipher suites (e.g. CBC-mode without proper padding), or a certificate using SHA-1 signatures. Should be fixed soon.</p>
                                        <p style="margin: 6px 0 0;"><strong>D / E / F</strong> — Poor or failing. Serious vulnerabilities such as support for SSL 3.0, RC4 or 3DES ciphers, unpatched OpenSSL bugs, or certificate chain errors. Fix immediately — attackers may be able to decrypt traffic.</p>
                                        <p style="margin: 6px 0 0;"><strong>T</strong> — Certificate is not trusted. Usually self-signed, expired, or issued by an unknown authority. Browsers will show scary warnings to visitors.</p>
                                        <p style="margin: 6px 0 0;"><strong>M</strong> — Certificate hostname mismatch. The certificate was issued for a different domain (e.g. <code>www.example.com</code> but the cert only covers <code>example.com</code>). Visitors see a name-mismatch warning.</p>
                                    </div>

                                    <div style="background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4; padding: 12px 14px;">
                                        <strong style="color: #1a73e8;">Why you might get a B, C, D, or F</strong>
                                        <p style="margin: 6px 0 0;">• <strong>B:</strong> TLS 1.0/1.1 enabled, missing HSTS preload, certificate uses a 2048-bit RSA key (still secure but not "ideal"), or weak ciphers still offered as fallback.</p>
                                        <p style="margin: 6px 0 0;">• <strong>C:</strong> Weak DH parameters (less than 2048 bits), CBC ciphers without proper TLS 1.2 padding, certificate chain incomplete (missing intermediate cert), or server accepts client-initiated renegotiation.</p>
                                        <p style="margin: 6px 0 0;">• <strong>D / F:</strong> SSL 2.0 or 3.0 enabled, RC4 / 3DES / DES ciphers supported, certificate expired or revoked, Heartbleed / POODLE / BEAST vulnerabilities unpatched, or server sends no cipher suites at all.</p>
                                    </div>

                                    <div style="background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4; padding: 12px 14px;">
                                        <strong style="color: #1a73e8;">Step-by-step fixes (easiest first)</strong>
                                        <p style="margin: 6px 0 0;"><strong>1. Turn on Cloudflare or use your host's SSL manager (Easy)</strong>
                                            <br>• If you use Cloudflare, set SSL/TLS mode to <strong>Full (Strict)</strong> and enable <strong>Automatic HTTPS Rewrites</strong>. Cloudflare's edge servers handle modern TLS and cipher suites for you.
                                            <br>• Most managed WordPress hosts (Kinsta, WP Engine, SiteGround, Flywheel, Rocket.net) already enforce TLS 1.2+ and strong ciphers at the platform level. Check your hosting dashboard first.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>2. Disable old TLS versions (Easy — hosting panel)</strong>
                                            <br>• In your hosting control panel or server manager, look for "SSL/TLS Settings" and disable TLS 1.0 and 1.1. Keep 1.2 and 1.3 enabled. Restart the web server if required.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>3. Update / renew your certificate (Easy)</strong>
                                            <br>• Make sure your certificate is valid, not expired, and covers both <code>example.com</code> and <code>www.example.com</code> (or use a wildcard <code>*.example.com</code>).
                                            <br>• Let's Encrypt and most hosting providers issue free certificates that auto-renew — check that the renewal cron job is working.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>4. Enable HSTS (Medium)</strong>
                                            <br>• Add the HSTS header (see Security Headers section above). This alone can bump a B to an A on some scanners. Start with a short max-age, test thoroughly, then increase.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>5. Tune cipher suites (Advanced)</strong>
                                            <br>• In <code>nginx.conf</code>, Apache's <code>ssl.conf</code>, or your load balancer, restrict ciphers to secure sets only. Example for modern nginx:
                                            <br><code>ssl_protocols TLSv1.2 TLSv1.3;</code>
                                            <br><code>ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:...';</code>
                                            <br>• Use the <a href="https://ssl-config.mozilla.org/" target="_blank" style="color: #2271b1;">Mozilla SSL Configuration Generator</a> to get a copy-paste config for your server software.
                                        </p>
                                        <p style="margin: 6px 0 0;"><strong>6. Keep server software patched (Easy — ongoing)</strong>
                                            <br>• Ensure OpenSSL, Apache/nginx, and your OS are on maintained versions. Many critical TLS vulnerabilities (Heartbleed, POODLE) are fixed simply by updating packages.
                                        </p>
                                    </div>

                                    <div style="background: #e8f4fd; border-radius: var(--radius-sm); border-left: 3px solid #4285f4; padding: 12px 14px;">
                                        <p style="margin: 0;"><span class="material-icons" style="font-size: 16px; vertical-align: middle; color: #4285f4;">lightbulb</span> <strong>Bottom line:</strong> For most WordPress users, enabling Cloudflare or checking your hosting provider's SSL settings will bring you to an A grade with zero manual server configuration. Only dive into cipher-suite tuning if you manage your own VPS or dedicated server.</p>
                                    </div>

                                </div>
                            </details>
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
                        Not Indexed by Google <span class="not-indexed-count" style="font-size: 0.7em; color: #888; font-weight: 400;">(<?php echo number_format( $total_not_indexed ); ?>)</span>
                    </h2>
                </div>
                <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 0; margin-bottom: 24px; overflow: hidden;">
                    <!-- Toolbar: Search + Per Page -->
                    <div style="padding: 12px 16px; background: var(--bg-color); border-bottom: 1px solid var(--border-light); display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; flex: 1;">
                            <input type="text" class="not-indexed-search" placeholder="Search URLs..." style="padding: 6px 10px; border: 1px solid var(--border-light); border-radius: var(--radius-sm); font-size: 0.85em; min-width: 220px;">
                            <select class="not-indexed-per-page" style="padding: 6px 10px; border: 1px solid var(--border-light); border-radius: var(--radius-sm); font-size: 0.85em;">
                                <option value="10">10 per page</option>
                                <option value="25">25 per page</option>
                                <option value="50" selected>50 per page</option>
                                <option value="100">100 per page</option>
                                <option value="250">250 per page</option>
                            </select>
                        </div>
                    </div>
                    <div style="max-height: 500px; overflow-y: auto;">
                        <table class="not-indexed-table sortable-table" style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
                            <thead style="position: sticky; top: 0; background: #f0f4f8; z-index: 2; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <tr style="border-bottom: 1px solid var(--border-light);">
                                    <th class="sortable-header" data-sort="url" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; cursor: pointer; user-select: none;">URL ↕</th>
                                    <th class="sortable-header" data-sort="firstseen" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 140px; cursor: pointer; user-select: none;">First Seen ↕</th>
                                    <th class="sortable-header" data-sort="lastinspected" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 140px; cursor: pointer; user-select: none;">Last Inspected ↕</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $not_indexed_urls as $url_row ) : ?>
                                    <tr class="not-indexed-row" style="border-bottom: 1px solid var(--border-light);" data-url="<?php echo esc_attr( strtolower( $url_row->url ) ); ?>" data-firstseen="<?php echo esc_attr( $url_row->first_seen ); ?>" data-lastinspected="<?php echo esc_attr( $url_row->last_inspected ?: '' ); ?>">
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
                    <div class="not-indexed-footer" style="padding: 12px 16px; background: var(--bg-color); color: #666; font-size: 0.85em; text-align: center;">
                        Showing <span class="not-indexed-showing"><?php echo number_format( min( $not_indexed_per_page, $total_not_indexed ) ); ?></span> of <span class="not-indexed-total"><?php echo number_format( $total_not_indexed ); ?></span> not indexed URLs
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- All URLs (Sitemap + Inspection Data) -->
            <?php if ( ! empty( $recent_inspections ) ) : ?>
            <?php $total_inspections = count( $recent_inspections ); ?>
            <section class="bite-dashboard-section">
                <div class="bite-section-header">
                    <h2>
                        <span class="material-icons" style="vertical-align: middle; margin-right: 8px; color: #2271b1;">manage_search</span>
                        All URLs <span class="inspection-count" style="font-size: 0.7em; color: #888; font-weight: 400;">(<?php echo number_format( $total_inspections ); ?>)</span>
                    </h2>
                </div>
                <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 0; margin-bottom: 24px; overflow: hidden;">
                    <!-- Toolbar: Search + Per Page -->
                    <div style="padding: 12px 16px; background: var(--bg-color); border-bottom: 1px solid var(--border-light); display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; flex: 1;">
                            <input type="text" class="inspection-search" placeholder="Search URLs or coverage..." style="padding: 6px 10px; border: 1px solid var(--border-light); border-radius: var(--radius-sm); font-size: 0.85em; min-width: 220px;">
                            <select class="inspection-per-page" style="padding: 6px 10px; border: 1px solid var(--border-light); border-radius: var(--radius-sm); font-size: 0.85em;">
                                <option value="10">10 per page</option>
                                <option value="25" selected>25 per page</option>
                                <option value="50">50 per page</option>
                                <option value="100">100 per page</option>
                                <option value="250">250 per page</option>
                                <option value="500">500 per page</option>
                                <option value="99999">All</option>
                            </select>
                        </div>
                    </div>
                    <div style="max-height: 600px; overflow-y: auto;">
                        <table class="inspection-table sortable-table" style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
                            <thead style="position: sticky; top: 0; background: #f0f4f8; z-index: 2; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <tr style="border-bottom: 1px solid var(--border-light);">
                                    <th class="sortable-header" data-sort="url" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; cursor: pointer; user-select: none;">URL ↕</th>
                                    <th class="sortable-header" data-sort="indexed" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 90px; cursor: pointer; user-select: none;">Indexed ↕</th>
                                    <th class="sortable-header" data-sort="status" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 110px; cursor: pointer; user-select: none;">Status ↕</th>
                                    <th class="sortable-header" data-sort="coverage" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 200px; cursor: pointer; user-select: none;">Coverage ↕</th>
                                    <th class="sortable-header" data-sort="mobile" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 100px; cursor: pointer; user-select: none;">Mobile ↕</th>
                                    <th class="sortable-header" data-sort="date" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 120px; cursor: pointer; user-select: none;">Date ↕</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $recent_inspections as $ins ) :
                                    $has_inspection = ! empty( $ins->verdict );
                                    $is_pass = $has_inspection && ( in_array( strtoupper( $ins->verdict ), array( 'PASS', 'Pass' ) ) || ( strtoupper( $ins->verdict ) === 'NEUTRAL' && $ins->coverage_state === 'Submitted and indexed' ) );
                                    $status_color = $is_pass ? '#00a32a' : ( $has_inspection ? '#d63638' : '#888' );
                                    $status_icon = $is_pass ? 'check_circle' : ( $has_inspection ? 'cancel' : 'schedule' );
                                    $status_text = $has_inspection ? $ins->verdict : 'Pending';
                                    $mobile_pass = in_array( strtoupper( $ins->mobile_usability ), array( 'PASS', 'Pass' ) );
                                    $mobile_color = $mobile_pass ? '#00a32a' : '#d63638';
                                    $indexed_text = ( $ins->is_indexed ) ? 'Yes' : ( $has_inspection ? 'No' : '—' );
                                    $indexed_color = ( $ins->is_indexed ) ? '#00a32a' : ( $has_inspection ? '#d63638' : '#888' );
                                    $date_text = $ins->last_inspected ? esc_html( date( 'M j, Y', strtotime( $ins->last_inspected ) ) ) : '—';
                                ?>
                                    <tr class="inspection-row" style="border-bottom: 1px solid var(--border-light);" data-url="<?php echo esc_attr( strtolower( $ins->url ) ); ?>" data-coverage="<?php echo esc_attr( strtolower( $ins->coverage_state ?: '' ) ); ?>" data-indexed="<?php echo esc_attr( $indexed_text ); ?>" data-status="<?php echo esc_attr( strtolower( $status_text ) ); ?>" data-date="<?php echo esc_attr( $ins->last_inspected ?: '' ); ?>">
                                        <td style="padding: 10px 16px; word-break: break-all;">
                                            <a href="<?php echo esc_url( $ins->url ); ?>" target="_blank" style="color: #2271b1; font-size: 0.9em;"><?php echo esc_html( $ins->url ); ?></a>
                                        </td>
                                        <td style="padding: 10px 16px;">
                                            <span style="display: inline-flex; align-items: center; gap: 4px; color: <?php echo esc_attr( $indexed_color ); ?>; font-weight: 600; font-size: 0.85em;">
                                                <?php echo esc_html( $indexed_text ); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 10px 16px;">
                                            <span style="display: inline-flex; align-items: center; gap: 4px; color: <?php echo esc_attr( $status_color ); ?>; font-weight: 600; font-size: 0.85em;">
                                                <span class="material-icons" style="font-size: 16px;"><?php echo esc_html( $status_icon ); ?></span>
                                                <?php echo esc_html( $status_text ); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 10px 16px; color: #666; font-size: 0.85em;"><?php echo esc_html( $ins->coverage_state ?: '—' ); ?></td>
                                        <td style="padding: 10px 16px;">
                                            <span style="color: <?php echo esc_attr( $mobile_color ); ?>; font-size: 0.85em;">
                                                <?php echo esc_html( $ins->mobile_usability ?: '—' ); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 10px 16px; color: #666; font-size: 0.85em;"><?php echo $date_text; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="inspection-footer" style="padding: 12px 16px; background: var(--bg-color); color: #666; font-size: 0.85em; text-align: center;">
                        Showing <span class="inspection-showing"><?php echo number_format( min( 25, $total_inspections ) ); ?></span> of <span class="inspection-total"><?php echo number_format( $total_inspections ); ?></span> URLs
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- GSC Discovered URLs (not in sitemap) -->
            <?php if ( ! empty( $gsc_orphan_urls ) ) : ?>
            <?php $total_orphans = count( $gsc_orphan_urls ); ?>
            <section class="bite-dashboard-section">
                <div class="bite-section-header">
                    <h2>
                        <span class="material-icons" style="vertical-align: middle; margin-right: 8px; color: #f9ab00;">travel_explore</span>
                        URLs Google Knows About (Not in Sitemap) <span style="font-size: 0.7em; color: #888; font-weight: 400;">(<?php echo number_format( $total_orphans ); ?>)</span>
                    </h2>
                </div>
                <div style="background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 0; margin-bottom: 24px; overflow: hidden;">
                    <div style="padding: 12px 16px; background: #fff8e1; border-bottom: 1px solid var(--border-light); font-size: 0.85em; color: #5d4037;">
                        <span class="material-icons" style="font-size: 16px; vertical-align: middle; margin-right: 4px;">info</span>
                        These URLs were discovered via Google Search Console but are not listed in your sitemap. Consider adding them to improve index coverage.
                    </div>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <table class="orphan-table sortable-table" style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
                            <thead style="position: sticky; top: 0; background: #f0f4f8; z-index: 2; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <tr style="border-bottom: 1px solid var(--border-light);">
                                    <th class="sortable-header" data-sort="url" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; cursor: pointer; user-select: none;">URL ↕</th>
                                    <th class="sortable-header" data-sort="date" style="text-align: left; padding: 12px 16px; font-weight: 600; color: #555; width: 160px; cursor: pointer; user-select: none;">Discovered ↕</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $gsc_orphan_urls as $orphan ) : ?>
                                    <tr class="orphan-row" style="border-bottom: 1px solid var(--border-light);" data-url="<?php echo esc_attr( strtolower( $orphan->url ) ); ?>">
                                        <td style="padding: 10px 16px; word-break: break-all;">
                                            <a href="<?php echo esc_url( $orphan->url ); ?>" target="_blank" style="color: #2271b1; font-size: 0.9em;"><?php echo esc_html( $orphan->url ); ?></a>
                                        </td>
                                        <td style="padding: 10px 16px; color: #666; font-size: 0.85em;"><?php echo esc_html( date( 'M j, Y', strtotime( $orphan->first_seen ) ) ); ?></td>
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

<script>
document.addEventListener('DOMContentLoaded', function() {

    // === Generic Table Sort ===
    function initSortableTable(table) {
        if (!table) return;
        var headers = table.querySelectorAll('.sortable-header');
        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        headers.forEach(function(th) {
            th.addEventListener('click', function() {
                var sortKey = th.dataset.sort;
                var isAsc = th.dataset.order !== 'desc';
                var newOrder = isAsc ? 'desc' : 'asc';

                // Reset all headers
                headers.forEach(function(h) {
                    h.dataset.order = '';
                    h.textContent = h.textContent.replace(/ ↓| ↑/, ' ↕');
                });

                th.dataset.order = newOrder;
                th.textContent = th.textContent.replace(' ↕', newOrder === 'asc' ? ' ↑' : ' ↓');

                var rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort(function(a, b) {
                    var aVal = '', bVal = '';

                    // Try dataset first, then fall back to cell text
                    if (a.dataset[sortKey] !== undefined) {
                        aVal = a.dataset[sortKey] || '';
                        bVal = b.dataset[sortKey] || '';
                    } else {
                        var aCell = a.querySelector('[data-' + sortKey + ']');
                        var bCell = b.querySelector('[data-' + sortKey + ']');
                        if (aCell) {
                            aVal = aCell.dataset[sortKey] || '';
                            bVal = bCell.dataset[sortKey] || '';
                        }
                    }

                    if (aVal < bVal) return isAsc ? -1 : 1;
                    if (aVal > bVal) return isAsc ? 1 : -1;
                    return 0;
                });

                rows.forEach(function(row) { tbody.appendChild(row); });
            });
        });
    }

    document.querySelectorAll('.sortable-table').forEach(initSortableTable);

    // --- Not Indexed URLs: Live Search + Per Page + Sort ---
    (function() {
        var searchInput = document.querySelector('.not-indexed-search');
        var perPageSelect = document.querySelector('.not-indexed-per-page');
        var table = document.querySelector('.not-indexed-table');
        var footer = document.querySelector('.not-indexed-footer');
        var countLabel = document.querySelector('.not-indexed-count');
        if (!table) return;

        var allRows = Array.from(table.querySelectorAll('tbody tr.not-indexed-row'));

        function update() {
            var term = (searchInput ? searchInput.value : '').toLowerCase().trim();
            var perPage = perPageSelect ? parseInt(perPageSelect.value, 10) : 50;
            var visibleCount = 0;
            var matchedCount = 0;

            allRows.forEach(function(row) {
                var url = row.dataset.url || '';
                var match = !term || url.indexOf(term) !== -1;
                if (match) {
                    matchedCount++;
                    if (visibleCount < perPage) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                } else {
                    row.style.display = 'none';
                }
            });

            if (footer) {
                var showingEl = footer.querySelector('.not-indexed-showing');
                var totalEl = footer.querySelector('.not-indexed-total');
                if (showingEl) showingEl.textContent = visibleCount.toLocaleString();
                if (totalEl) totalEl.textContent = matchedCount.toLocaleString();
                footer.style.display = matchedCount > 0 ? '' : 'none';
            }
            if (countLabel && matchedCount !== allRows.length) {
                countLabel.textContent = '(' + matchedCount.toLocaleString() + ')';
            } else if (countLabel) {
                countLabel.textContent = '(' + allRows.length.toLocaleString() + ')';
            }
        }

        if (searchInput) searchInput.addEventListener('input', update);
        if (perPageSelect) perPageSelect.addEventListener('change', update);
        update();
    })();

    // --- All URLs: Live Search + Per Page ---
    (function() {
        var searchInput = document.querySelector('.inspection-search');
        var perPageSelect = document.querySelector('.inspection-per-page');
        var table = document.querySelector('.inspection-table');
        var footer = document.querySelector('.inspection-footer');
        var countLabel = document.querySelector('.inspection-count');
        if (!table) return;

        var allRows = Array.from(table.querySelectorAll('tbody tr.inspection-row'));

        function update() {
            var term = (searchInput ? searchInput.value : '').toLowerCase().trim();
            var perPage = perPageSelect ? parseInt(perPageSelect.value, 10) : 25;
            var visibleCount = 0;
            var matchedCount = 0;

            allRows.forEach(function(row) {
                var url = row.dataset.url || '';
                var coverage = row.dataset.coverage || '';
                var match = !term || url.indexOf(term) !== -1 || coverage.indexOf(term) !== -1;
                if (match) {
                    matchedCount++;
                    if (visibleCount < perPage) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                } else {
                    row.style.display = 'none';
                }
            });

            if (footer) {
                var showingEl = footer.querySelector('.inspection-showing');
                var totalEl = footer.querySelector('.inspection-total');
                if (showingEl) showingEl.textContent = visibleCount.toLocaleString();
                if (totalEl) totalEl.textContent = matchedCount.toLocaleString();
                footer.style.display = matchedCount > 0 ? '' : 'none';
            }
            if (countLabel && matchedCount !== allRows.length) {
                countLabel.textContent = '(' + matchedCount.toLocaleString() + ')';
            } else if (countLabel) {
                countLabel.textContent = '(' + allRows.length.toLocaleString() + ')';
            }
        }

        if (searchInput) searchInput.addEventListener('input', update);
        if (perPageSelect) perPageSelect.addEventListener('change', update);
        update();
    })();

});
</script>

<?php get_footer(); ?>
