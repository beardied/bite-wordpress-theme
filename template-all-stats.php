<?php
/**
 * Template Name: BITE All Stats
 *
 * Overlay chart combining GSC data with domain authority metrics.
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
$user_site_ids   = bite_get_user_sites( $current_user_id );
$is_admin        = current_user_can( 'manage_options' );

// Admins can see all sites; viewers only their own
if ( $is_admin ) {
    $all_sites = $wpdb->get_results( "SELECT site_id, name, domain FROM {$wpdb->prefix}bite_sites ORDER BY name ASC" );
} else {
    if ( empty( $user_site_ids ) ) {
        $all_sites = array();
    } else {
        $placeholders = implode( ',', array_fill( 0, count( $user_site_ids ), '%d' ) );
        $all_sites = $wpdb->get_results( $wpdb->prepare(
            "SELECT site_id, name, domain FROM {$wpdb->prefix}bite_sites WHERE site_id IN ($placeholders) ORDER BY name ASC",
            $user_site_ids
        ) );
    }
}

$selected_site_id = isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : ( $all_sites[0]->site_id ?? 0 );
$selected_site = null;
foreach ( $all_sites as $s ) {
    if ( $s->site_id == $selected_site_id ) {
        $selected_site = $s;
        break;
    }
}

// Default date range: last 90 days (displayed as dd-mm-yy for datepicker)
$display_end_date   = isset( $_GET['end_date'] ) && ! empty( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'd-m-Y' );
$display_start_date = isset( $_GET['start_date'] ) && ! empty( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'd-m-Y', strtotime( '-90 days' ) );

// Convert to SQL format for queries
$end_date   = date( 'Y-m-d', strtotime( $display_end_date ) );
$start_date = date( 'Y-m-d', strtotime( $display_start_date ) );

// Device filter for GSC data
$device_filter = isset( $_GET['device_filter'] ) ? sanitize_text_field( $_GET['device_filter'] ) : 'all';

// Which stats to show
$show_clicks     = isset( $_GET['show_clicks'] ) ? true : ( ! isset( $_GET['submit'] ) ? true : false );
$show_impressions= isset( $_GET['show_impressions'] ) ? true : false;
$show_auth_index = isset( $_GET['show_auth_index'] ) ? true : ( ! isset( $_GET['submit'] ) ? true : false );
$show_opr_rank   = isset( $_GET['show_opr_rank'] ) ? true : false;
$show_d_perf     = isset( $_GET['show_d_perf'] ) ? true : false;
$show_d_a11y     = isset( $_GET['show_d_a11y'] ) ? true : false;
$show_d_bp       = isset( $_GET['show_d_bp'] ) ? true : false;
$show_d_seo      = isset( $_GET['show_d_seo'] ) ? true : false;
$show_m_perf     = isset( $_GET['show_m_perf'] ) ? true : false;
$show_m_a11y     = isset( $_GET['show_m_a11y'] ) ? true : false;
$show_m_bp       = isset( $_GET['show_m_bp'] ) ? true : false;
$show_m_seo      = isset( $_GET['show_m_seo'] ) ? true : false;

$chart_data = array();
if ( $selected_site ) {
    // GSC daily summary data
    if ( $device_filter === 'all' ) {
        $summary_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, SUM(total_clicks) as clicks, SUM(total_impressions) as impressions
             FROM {$wpdb->prefix}bite_daily_summary
             WHERE site_id = %d AND date >= %s AND date <= %s
             GROUP BY date ORDER BY date ASC",
            $selected_site_id, $start_date, $end_date
        ) );
    } else {
        $summary_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, total_clicks as clicks, total_impressions as impressions
             FROM {$wpdb->prefix}bite_daily_summary
             WHERE site_id = %d AND date >= %s AND date <= %s AND device = %s
             ORDER BY date ASC",
            $selected_site_id, $start_date, $end_date, $device_filter
        ) );
    }

    // Domain metrics data
    $dm_rows = array();
    if ( function_exists( 'bite_get_domain_metrics_history' ) ) {
        $dm_rows = bite_get_domain_metrics_history( $selected_site_id, $start_date, $end_date );
    }

    // Build merged dataset keyed by date
    $dates = array();
    foreach ( $summary_rows as $row ) {
        $dates[ $row->date ] = array(
            'clicks'      => intval( $row->clicks ),
            'impressions' => intval( $row->impressions ),
        );
    }
    foreach ( $dm_rows as $row ) {
        if ( ! isset( $dates[ $row->recorded_at ] ) ) {
            $dates[ $row->recorded_at ] = array();
        }
        $dates[ $row->recorded_at ]['auth_index']  = $row->authority_index ? round( floatval( $row->authority_index ), 2 ) : null;
        $dates[ $row->recorded_at ]['opr_rank']    = $row->opr_rank ? round( floatval( $row->opr_rank ), 2 ) : null;
        $dates[ $row->recorded_at ]['opr_display'] = $row->opr_rank ? round( floatval( $row->opr_rank ) * 10, 2 ) : null;
        $dates[ $row->recorded_at ]['d_perf']      = $row->pagespeed_score ? intval( $row->pagespeed_score ) : null;
        $dates[ $row->recorded_at ]['d_a11y']      = $row->pagespeed_accessibility ? intval( $row->pagespeed_accessibility ) : null;
        $dates[ $row->recorded_at ]['d_bp']        = $row->pagespeed_best_practices ? intval( $row->pagespeed_best_practices ) : null;
        $dates[ $row->recorded_at ]['d_seo']       = $row->pagespeed_seo ? intval( $row->pagespeed_seo ) : null;
        $dates[ $row->recorded_at ]['m_perf']      = $row->mobile_score ? intval( $row->mobile_score ) : null;
        $dates[ $row->recorded_at ]['m_a11y']      = $row->mobile_accessibility ? intval( $row->mobile_accessibility ) : null;
        $dates[ $row->recorded_at ]['m_bp']        = $row->mobile_best_practices ? intval( $row->mobile_best_practices ) : null;
        $dates[ $row->recorded_at ]['m_seo']       = $row->mobile_seo ? intval( $row->mobile_seo ) : null;
    }

    ksort( $dates );
    $chart_data = $dates;
}

// Build Chart.js datasets in PHP
$chart_datasets = array();
$has_scores = $show_auth_index || $show_opr_rank || $show_d_perf || $show_d_a11y || $show_d_bp || $show_d_seo || $show_m_perf || $show_m_a11y || $show_m_bp || $show_m_seo;

$show_vars = array(
    'clicks'      => $show_clicks,
    'impressions' => $show_impressions,
    'auth_index'  => $show_auth_index,
    'opr_rank'    => $show_opr_rank,
    'd_perf'      => $show_d_perf,
    'd_a11y'      => $show_d_a11y,
    'd_bp'        => $show_d_bp,
    'd_seo'       => $show_d_seo,
    'm_perf'      => $show_m_perf,
    'm_a11y'      => $show_m_a11y,
    'm_bp'        => $show_m_bp,
    'm_seo'       => $show_m_seo,
);

$ds_config = array(
    'clicks'      => array( 'label' => 'Clicks',              'key' => 'clicks',      'color' => '#ff6b35', 'bg' => 'rgba(255,107,53,0.08)',  'axis' => 'y',  'fill' => true,  'point' => 2 ),
    'impressions' => array( 'label' => 'Impressions',         'key' => 'impressions', 'color' => '#2271b1', 'bg' => 'rgba(34,113,177,0.08)',  'axis' => 'y1', 'fill' => true,  'point' => 2 ),
    'auth_index'  => array( 'label' => 'Authority Index',     'key' => 'auth_index',  'color' => '#00a32a', 'bg' => 'rgba(0,163,42,0.08)',    'axis' => 'y2', 'fill' => false, 'point' => 4 ),
    'opr_rank'    => array( 'label' => 'OpenPageRank',        'key' => 'opr_display', 'color' => '#e91e63', 'bg' => 'rgba(233,30,99,0.08)',   'axis' => 'y2', 'fill' => false, 'point' => 4 ),
    'd_perf'      => array( 'label' => 'Desktop Performance', 'key' => 'd_perf',      'color' => '#2196f3', 'bg' => 'rgba(33,150,243,0.08)',  'axis' => 'y2', 'fill' => false, 'point' => 4 ),
    'd_a11y'      => array( 'label' => 'Desktop Accessibility','key'=> 'd_a11y',      'color' => '#9c27b0', 'bg' => 'rgba(156,39,176,0.08)',  'axis' => 'y2', 'fill' => false, 'point' => 4 ),
    'd_bp'        => array( 'label' => 'Desktop Best Practices','key'=> 'd_bp',       'color' => '#795548', 'bg' => 'rgba(121,85,72,0.08)',   'axis' => 'y2', 'fill' => false, 'point' => 4 ),
    'd_seo'       => array( 'label' => 'Desktop SEO',         'key' => 'd_seo',       'color' => '#ff5722', 'bg' => 'rgba(255,87,34,0.08)',   'axis' => 'y2', 'fill' => false, 'point' => 4 ),
    'm_perf'      => array( 'label' => 'Mobile Performance',  'key' => 'm_perf',      'color' => '#4fc3f7', 'bg' => 'rgba(79,195,247,0.08)',  'axis' => 'y2', 'fill' => false, 'point' => 4 ),
    'm_a11y'      => array( 'label' => 'Mobile Accessibility','key' => 'm_a11y',      'color' => '#ce93d8', 'bg' => 'rgba(206,147,216,0.08)', 'axis' => 'y2', 'fill' => false, 'point' => 4 ),
    'm_bp'        => array( 'label' => 'Mobile Best Practices','key'=> 'm_bp',        'color' => '#bcaaa4', 'bg' => 'rgba(188,170,164,0.08)', 'axis' => 'y2', 'fill' => false, 'point' => 4 ),
    'm_seo'       => array( 'label' => 'Mobile SEO',          'key' => 'm_seo',       'color' => '#ffab91', 'bg' => 'rgba(255,171,145,0.08)', 'axis' => 'y2', 'fill' => false, 'point' => 4 ),
);

foreach ( $ds_config as $key => $cfg ) {
    if ( ! empty( $show_vars[ $key ] ) ) {
        $data_values = array();
        foreach ( $chart_data as $date => $d ) {
            $data_values[] = isset( $d[ $cfg['key'] ] ) && $d[ $cfg['key'] ] !== null ? $d[ $cfg['key'] ] : null;
        }
        $chart_datasets[] = array(
            'label'           => $cfg['label'],
            'data'            => $data_values,
            'borderColor'     => $cfg['color'],
            'backgroundColor' => $cfg['bg'],
            'yAxisID'         => $cfg['axis'],
            'tension'         => 0,
            'fill'            => $cfg['fill'],
            'pointRadius'     => $cfg['point'],
            'spanGaps'        => true,
        );
    }
}
?>

<div class="bite-dashboard-wrapper">
    <?php get_template_part( 'includes/dashboard-sidebar' ); ?>

    <main id="main" class="bite-dashboard-main-content" role="main">
        <section class="bite-dashboard-welcome">
            <div class="bite-welcome-content">
                <h1 class="bite-welcome-title">All Stats</h1>
                <p class="bite-welcome-subtitle">Overlay GSC performance with domain authority metrics</p>
            </div>
        </section>

        <section class="bite-dashboard-section">

            <?php if ( function_exists( 'bite_render_metrics_legend' ) ) echo bite_render_metrics_legend(); ?>

            <form method="GET" action="" class="bite-stats-filters" style="background: var(--card-bg); border: 1px solid var(--border-light); padding: 24px; border-radius: var(--radius-lg); margin-bottom: 25px;">
                <div class="bite-filters-row" style="display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
                    <div class="bite-filter-group">
                        <label for="allstats_site">Site</label>
                        <select id="allstats_site" name="site_id">
                            <?php foreach ( $all_sites as $s ) : ?>
                                <option value="<?php echo esc_attr( $s->site_id ); ?>" <?php selected( $selected_site_id, $s->site_id ); ?>>
                                    <?php echo esc_html( $s->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bite-filter-group">
                        <label for="device_filter">GSC Device</label>
                        <select id="device_filter" name="device_filter">
                            <option value="all" <?php selected( $device_filter, 'all' ); ?>>All Devices</option>
                            <option value="desktop" <?php selected( $device_filter, 'desktop' ); ?>>Desktop</option>
                            <option value="mobile" <?php selected( $device_filter, 'mobile' ); ?>>Mobile</option>
                            <option value="tablet" <?php selected( $device_filter, 'tablet' ); ?>>Tablet</option>
                        </select>
                    </div>
                    <div class="bite-filter-group">
                        <label for="allstats_start">Start Date</label>
                        <input type="text" id="allstats_start" name="start_date" class="bite-datepicker" value="<?php echo esc_attr( $display_start_date ); ?>">
                    </div>
                    <div class="bite-filter-group">
                        <label for="allstats_end">End Date</label>
                        <input type="text" id="allstats_end" name="end_date" class="bite-datepicker" value="<?php echo esc_attr( $display_end_date ); ?>">
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 10px;">
                        <span style="font-size: 0.75em; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.5px; min-width: 140px;">GSC Metrics</span>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_clicks" <?php checked( $show_clicks ); ?>> <span>Clicks</span>
                        </label>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_impressions" <?php checked( $show_impressions ); ?>> <span>Impressions</span>
                        </label>
                    </div>

                    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 10px;">
                        <span style="font-size: 0.75em; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.5px; min-width: 140px;">Authority</span>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_auth_index" <?php checked( $show_auth_index ); ?>> <span>Authority Index</span>
                        </label>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_opr_rank" <?php checked( $show_opr_rank ); ?>> <span>OpenPageRank</span>
                        </label>
                    </div>

                    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 10px;">
                        <span style="font-size: 0.75em; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.5px; min-width: 140px;">Desktop PageSpeed</span>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_d_perf" <?php checked( $show_d_perf ); ?>> <span>Performance</span>
                        </label>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_d_a11y" <?php checked( $show_d_a11y ); ?>> <span>Accessibility</span>
                        </label>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_d_bp" <?php checked( $show_d_bp ); ?>> <span>Best Practices</span>
                        </label>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_d_seo" <?php checked( $show_d_seo ); ?>> <span>SEO</span>
                        </label>
                    </div>

                    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 16px;">
                        <span style="font-size: 0.75em; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.5px; min-width: 140px;">Mobile PageSpeed</span>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_m_perf" <?php checked( $show_m_perf ); ?>> <span>Performance</span>
                        </label>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_m_a11y" <?php checked( $show_m_a11y ); ?>> <span>Accessibility</span>
                        </label>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_m_bp" <?php checked( $show_m_bp ); ?>> <span>Best Practices</span>
                        </label>
                        <label class="bite-toggle-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; padding: 5px 10px; background: var(--bg-color); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
                            <input type="checkbox" name="show_m_seo" <?php checked( $show_m_seo ); ?>> <span>SEO</span>
                        </label>
                    </div>
                </div>

                <button type="submit" name="submit" class="bite-button bite-button-primary">
                    <span class="material-icons" style="vertical-align: middle; margin-right: 6px;">refresh</span>
                    Update Chart
                </button>
            </form>

            <?php if ( ! empty( $chart_data ) ) : ?>
                <div class="bite-widget-container">
                    <div class="bite-chart-wrapper">
                        <canvas id="all-stats-chart"></canvas>
                    </div>
                </div>

                <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const labels = <?php echo wp_json_encode( array_keys( $chart_data ) ); ?>;
                    const datasets = <?php echo wp_json_encode( $chart_datasets ); ?>;
                    new Chart(document.getElementById('all-stats-chart'), {
                        type: 'line',
                        data: { labels: labels, datasets: datasets },
                        options: {
                            responsive: true,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { position: 'top' },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) label += ': ';
                                            if (context.parsed.y !== null) {
                                                if (label.indexOf('OpenPageRank') !== -1) {
                                                    label += (context.parsed.y / 10).toFixed(2);
                                                } else {
                                                    label += context.parsed.y.toLocaleString();
                                                }
                                            } else {
                                                label += 'No data';
                                            }
                                            return label;
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: { grid: { display: false } },
                                y: {
                                    type: 'linear',
                                    display: <?php echo json_encode( $show_clicks ); ?>,
                                    position: 'left',
                                    title: { display: true, text: 'Clicks' },
                                    beginAtZero: true,
                                },
                                y1: {
                                    type: 'linear',
                                    display: <?php echo json_encode( $show_impressions ); ?>,
                                    position: 'left',
                                    title: { display: true, text: 'Impressions' },
                                    grid: { drawOnChartArea: false },
                                    beginAtZero: true,
                                },
                                y2: {
                                    type: 'linear',
                                    display: <?php echo json_encode( $has_scores ); ?>,
                                    position: 'right',
                                    title: { display: true, text: 'Authority & PageSpeed Scores' },
                                    grid: { drawOnChartArea: false },
                                    min: 0,
                                    max: 100,
                                }
                            }
                        }
                    });
                });
                </script>
            <?php else : ?>
                <div style="text-align: center; padding: 60px 20px; color: #666;">
                    <p style="font-size: 1.2em;">📊 No data available for the selected range.</p>
                    <p>Try selecting a different date range or check back after the next daily update.</p>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<?php get_footer(); ?>
