<?php
/**
 * Template Name: BITE CTR Efficiency
 *
 * This is the template for our "Proportion of Anonymized Clicks" tool.
 *
 * @package BITE-theme
 */

get_header(); 

global $wpdb;

// --- Get data for filters ---
$sites_table = $wpdb->prefix . 'bite_sites';
$all_sites = $wpdb->get_results( "SELECT site_id, name FROM $sites_table ORDER BY name ASC" );

// --- Get current filter values from URL ---
$selected_site_id = ( isset( $_GET['site_id'] ) ) ? absint( $_GET['site_id'] ) : 0;
$selected_device = ( isset( $_GET['device'] ) ) ? sanitize_text_field( $_GET['device'] ) : 'all';
$display_start_date = ( isset( $_GET['start_date'] ) && ! empty($_GET['start_date']) ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'd-m-Y', strtotime( '-30 days' ) );
$display_end_date = ( isset( $_GET['end_date'] ) && ! empty($_GET['end_date']) ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'd-m-Y', strtotime( '-1 day' ) );

// --- Main Data Query ---
$ctr_report_data = null;
if ( $selected_site_id > 0 ) {
    $sql_start_date = date( 'Y-m-d', strtotime( $display_start_date ) );
    $sql_end_date = date( 'Y-m-d', strtotime( $display_end_date ) );

    $ctr_report_data = bite_get_ctr_efficiency_data( $selected_site_id, $sql_start_date, $sql_end_date, $selected_device );
}

?>

<div class="bite-dashboard-wrapper">
    
    <?php get_template_part( 'includes/dashboard-sidebar' ); ?>

    <main id="main" class="bite-dashboard-main-content" role="main">
	<div class="bite-page-header">
		<h1>Anonymized Clicks (%)</h1>
		<p>This chart shows the percentage of your total clicks that came from "Anonymized" (hidden) queries each day.</p>
	</div>

    <div class="bite-filter-bar">
        <form method="GET" action="">
            
            <div class="bite-filter-group">
                <label for="site_id"><?php esc_html_e( 'Select Site:', 'bite-theme' ); ?></label>
                <select id="site_id" name="site_id" required>
                    <option value=""><?php esc_html_e( 'Select a site...', 'bite-theme' ); ?></option>
                    <?php foreach ( $all_sites as $site ) : ?>
                        <option value="<?php echo esc_attr( $site->site_id ); ?>" <?php selected( $site->site_id, $selected_site_id ); ?>>
                            <?php echo esc_html( $site->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="bite-filter-group">
                <label for="device"><?php esc_html_e( 'Select Device:', 'bite-theme' ); ?></label>
                <select id="device" name="device">
                    <option value="all" <?php selected( 'all', $selected_device ); ?>><?php esc_html_e( 'All Devices', 'bite-theme' ); ?></option>
                    <option value="desktop" <?php selected( 'desktop', $selected_device ); ?>><?php esc_html_e( 'Desktop', 'bite-theme' ); ?></option>
                    <option value="mobile" <?php selected( 'mobile', $selected_device ); ?>><?php esc_html_e( 'Mobile', 'bite-theme' ); ?></option>
                    <option value="tablet" <?php selected( 'tablet', $selected_device ); ?>><?php esc_html_e( 'Tablet', 'bite-theme' ); ?></option>
                </select>
            </div>

            <div class="bite-filter-group">
                <label for="start_date"><?php esc_html_e( 'Start Date:', 'bite-theme' ); ?></label>
                <input type="text" id="start_date" name="start_date" class="bite-datepicker" value="<?php echo esc_attr( $display_start_date ); ?>">
            </div>

            <div class="bite-filter-group">
                <label for="end_date"><?php esc_html_e( 'End Date:', 'bite-theme' ); ?></label>
                <input type="text" id="end_date" name="end_date" class="bite-datepicker" value="<?php echo esc_attr( $display_end_date ); ?>">
            </div>

            <div class="bite-filter-group">
                <button type="submit" class="bite-button"><?php esc_html_e( 'Analyze', 'bite-theme' ); ?></button>
            </div>
            
        </form>
    </div>

	<div class="bite-dashboard-widgets">
        <?php if ( $ctr_report_data !== null ) : ?>
            
            <?php if ( ! empty( $ctr_report_data['labels'] ) ) : ?>
                
                <div class="bite-widget-container">
                    <h2><?php esc_html_e( 'Anonymized Clicks as % of Total Clicks', 'bite-theme' ); ?></h2>
                    <div class="bite-chart-wrapper">
                        <canvas id="bite-ctr-chart"></canvas>
                    </div>
                </div>

				<div class="bite-widget-container bite-insight-box">
					<h2><?php esc_html_e( 'Summary & Insight', 'bite-theme' ); ?></h2>
					<p><?php echo $ctr_report_data['insight_text']; ?></p>
				</div>

            <?php else : ?>
                <p><strong><?php esc_html_e( 'No data found for this site in this date range.', 'bite-theme' ); ?></strong></p>
            <?php endif; ?>

        <?php else : ?>
            <p><strong><?php esc_html_e( 'Please select a site to analyze.', 'bite-theme' ); ?></strong></p>
        <?php endif; ?>
	</div>

</main>

<?php
// Pass data from PHP to our bite.js file
if ( $ctr_report_data ) {
    echo '<script type="text/javascript">';
    echo 'const biteCtrChartData = ' . wp_json_encode( $ctr_report_data ) . ';';
    echo '</script>';
}
?>

</div>

<?php
get_footer();
