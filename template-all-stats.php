<?php
/**
 * Template Name: BITE All Stats
 *
 * Overlay chart combining GSC data with domain authority metrics.
 * Users can toggle which stats to display and select date ranges.
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

// Default date range: last 90 days
$end_date   = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'Y-m-d' );
$start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'Y-m-d', strtotime( '-90 days' ) );

// Which stats to show
$show_clicks        = isset( $_GET['show_clicks'] ) ? true : ( ! isset( $_GET['submit'] ) ? true : false );
$show_impressions   = isset( $_GET['show_impressions'] ) ? true : false;
$show_auth_index    = isset( $_GET['show_auth_index'] ) ? true : ( ! isset( $_GET['submit'] ) ? true : false );
$show_moz_da        = isset( $_GET['show_moz_da'] ) ? true : false;
$show_srt_da        = isset( $_GET['show_srt_da'] ) ? true : false;
$show_opr_rank      = isset( $_GET['show_opr_rank'] ) ? true : false;
$show_backlinks     = isset( $_GET['show_backlinks'] ) ? true : false;

$chart_data = array();
if ( $selected_site ) {
    // GSC daily summary data
    $summary_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT date, SUM(total_clicks) as clicks, SUM(total_impressions) as impressions, AVG(total_position) as position
         FROM {$wpdb->prefix}bite_daily_summary
         WHERE site_id = %d AND date >= %s AND date <= %s
         GROUP BY date ORDER BY date ASC",
        $selected_site_id, $start_date, $end_date
    ) );

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
            'position'    => round( floatval( $row->position ), 1 ),
        );
    }
    foreach ( $dm_rows as $row ) {
        if ( ! isset( $dates[ $row->recorded_at ] ) ) {
            $dates[ $row->recorded_at ] = array();
        }
        $dates[ $row->recorded_at ]['auth_index'] = $row->authority_index ? round( floatval( $row->authority_index ), 2 ) : null;
        $dates[ $row->recorded_at ]['moz_da']     = $row->moz_da ? intval( $row->moz_da ) : null;
        $dates[ $row->recorded_at ]['srt_da']     = $row->srt_da ? intval( $row->srt_da ) : null;
        $dates[ $row->recorded_at ]['opr_rank']   = $row->opr_rank ? round( floatval( $row->opr_rank ), 2 ) : null;
        $dates[ $row->recorded_at ]['backlinks']  = $row->srt_backlinks ? intval( $row->srt_backlinks ) : null;
    }

    ksort( $dates );
    $chart_data = $dates;
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
            <form method="GET" action="" class="bite-stats-filters" style="background: #f8f9fa; padding: 20px; border-radius: 12px; margin-bottom: 25px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em;">Site</label>
                        <select name="site_id" class="bite-form-select" style="width: 100%;">
                            <?php foreach ( $all_sites as $s ) : ?>
                                <option value="<?php echo esc_attr( $s->site_id ); ?>" <?php selected( $selected_site_id, $s->site_id ); ?>>
                                    <?php echo esc_html( $s->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em;">Start Date</label>
                        <input type="date" name="start_date" value="<?php echo esc_attr( $start_date ); ?>" class="bite-form-input" style="width: 100%;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em;">End Date</label>
                        <input type="date" name="end_date" value="<?php echo esc_attr( $end_date ); ?>" class="bite-form-input" style="width: 100%;">
                    </div>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 15px;">
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer;">
                        <input type="checkbox" name="show_clicks" <?php checked( $show_clicks ); ?>> Clicks
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer;">
                        <input type="checkbox" name="show_impressions" <?php checked( $show_impressions ); ?>> Impressions
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer;">
                        <input type="checkbox" name="show_auth_index" <?php checked( $show_auth_index ); ?>> Authority Index
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer;">
                        <input type="checkbox" name="show_moz_da" <?php checked( $show_moz_da ); ?>> Moz DA
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer;">
                        <input type="checkbox" name="show_srt_da" <?php checked( $show_srt_da ); ?>> SRT DA
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer;">
                        <input type="checkbox" name="show_opr_rank" <?php checked( $show_opr_rank ); ?>> OpenPageRank
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer;">
                        <input type="checkbox" name="show_backlinks" <?php checked( $show_backlinks ); ?>> Backlinks
                    </label>
                </div>

                <button type="submit" name="submit" class="bite-button bite-button-primary">
                    <span class="material-icons" style="vertical-align: middle; margin-right: 6px;">refresh</span>
                    Update Chart
                </button>
            </form>

            <?php if ( ! empty( $chart_data ) ) : ?>
                <div style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <canvas id="all-stats-chart" style="max-height: 500px;"></canvas>
                </div>

                <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const labels = <?php echo wp_json_encode( array_keys( $chart_data ) ); ?>;
                    const datasets = [];
                    const hasLeftAxis = <?php echo json_encode( $show_clicks || $show_impressions ); ?>;
                    const hasRightAxis = <?php echo json_encode( $show_auth_index || $show_moz_da || $show_srt_da || $show_opr_rank || $show_backlinks ); ?>;

                    const colors = {
                        clicks:     { border: '#ff6b35', bg: 'rgba(255,107,53,0.1)' },
                        impressions:{ border: '#2271b1', bg: 'rgba(34,113,177,0.1)' },
                        auth_index: { border: '#00a32a', bg: 'rgba(0,163,42,0.1)' },
                        moz_da:     { border: '#9b51e0', bg: 'rgba(155,81,224,0.1)' },
                        srt_da:     { border: '#f0c33c', bg: 'rgba(240,195,60,0.1)' },
                        opr_rank:   { border: '#e91e63', bg: 'rgba(233,30,99,0.1)' },
                        backlinks:  { border: '#607d8b', bg: 'rgba(96,125,139,0.1)' },
                    };

                    <?php if ( $show_clicks ) : ?>
                    datasets.push({
                        label: 'Clicks',
                        data: <?php echo wp_json_encode( array_map( function($d){return $d['clicks'] ?? 0;}, $chart_data ) ); ?>,
                        borderColor: colors.clicks.border,
                        backgroundColor: colors.clicks.bg,
                        yAxisID: 'y',
                        tension: 0.3,
                        fill: true,
                        pointRadius: 2,
                    });
                    <?php endif; ?>

                    <?php if ( $show_impressions ) : ?>
                    datasets.push({
                        label: 'Impressions',
                        data: <?php echo wp_json_encode( array_map( function($d){return $d['impressions'] ?? 0;}, $chart_data ) ); ?>,
                        borderColor: colors.impressions.border,
                        backgroundColor: colors.impressions.bg,
                        yAxisID: 'y',
                        tension: 0.3,
                        fill: true,
                        pointRadius: 2,
                    });
                    <?php endif; ?>

                    <?php if ( $show_auth_index ) : ?>
                    datasets.push({
                        label: 'Authority Index',
                        data: <?php echo wp_json_encode( array_map( function($d){return $d['auth_index'] ?? null;}, $chart_data ) ); ?>,
                        borderColor: colors.auth_index.border,
                        backgroundColor: colors.auth_index.bg,
                        yAxisID: hasLeftAxis ? 'y1' : 'y',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 4,
                        spanGaps: true,
                    });
                    <?php endif; ?>

                    <?php if ( $show_moz_da ) : ?>
                    datasets.push({
                        label: 'Moz DA',
                        data: <?php echo wp_json_encode( array_map( function($d){return $d['moz_da'] ?? null;}, $chart_data ) ); ?>,
                        borderColor: colors.moz_da.border,
                        backgroundColor: colors.moz_da.bg,
                        yAxisID: hasLeftAxis ? 'y1' : 'y',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 4,
                        spanGaps: true,
                    });
                    <?php endif; ?>

                    <?php if ( $show_srt_da ) : ?>
                    datasets.push({
                        label: 'SRT DA',
                        data: <?php echo wp_json_encode( array_map( function($d){return $d['srt_da'] ?? null;}, $chart_data ) ); ?>,
                        borderColor: colors.srt_da.border,
                        backgroundColor: colors.srt_da.bg,
                        yAxisID: hasLeftAxis ? 'y1' : 'y',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 4,
                        spanGaps: true,
                    });
                    <?php endif; ?>

                    <?php if ( $show_opr_rank ) : ?>
                    datasets.push({
                        label: 'OpenPageRank',
                        data: <?php echo wp_json_encode( array_map( function($d){return $d['opr_rank'] ?? null;}, $chart_data ) ); ?>,
                        borderColor: colors.opr_rank.border,
                        backgroundColor: colors.opr_rank.bg,
                        yAxisID: hasLeftAxis ? 'y1' : 'y',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 4,
                        spanGaps: true,
                    });
                    <?php endif; ?>

                    <?php if ( $show_backlinks ) : ?>
                    datasets.push({
                        label: 'Backlinks',
                        data: <?php echo wp_json_encode( array_map( function($d){return $d['backlinks'] ?? null;}, $chart_data ) ); ?>,
                        borderColor: colors.backlinks.border,
                        backgroundColor: colors.backlinks.bg,
                        yAxisID: 'y',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 4,
                        spanGaps: true,
                    });
                    <?php endif; ?>

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
                                                label += context.parsed.y.toLocaleString();
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
                                    display: true,
                                    position: 'left',
                                    title: { display: true, text: 'GSC Metrics' },
                                    beginAtZero: true,
                                },
                                y1: {
                                    type: 'linear',
                                    display: hasRightAxis && hasLeftAxis,
                                    position: 'right',
                                    title: { display: true, text: 'Authority Scores' },
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
