<?php
/**
 * The main template file (Our Dashboard Homepage).
 *
 * @package BITE-theme
 */

get_header(); 

global $wpdb;

// --- Get user's allowed sites (admins see all, users see assigned) ---
$current_user_id = get_current_user_id();
$user_site_ids = bite_get_user_sites( $current_user_id );

// Get site details for user's sites
$all_sites = array();
$site_name_lookup = array();
if ( ! empty( $user_site_ids ) ) {
    $sites_table = $wpdb->prefix . 'bite_sites';
    $placeholders = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
    $all_sites = $wpdb->get_results( $wpdb->prepare(
        "SELECT site_id, name FROM $sites_table WHERE site_id IN ($placeholders) ORDER BY name ASC",
        $user_site_ids
    ) );
    foreach ( $all_sites as $site ) {
        $site_name_lookup[ $site->site_id ] = $site->name;
    }
}

// --- Get current filter values from URL ---
$selected_site_id = ( isset( $_GET['site_id'] ) ) ? absint( $_GET['site_id'] ) : 0;
$selected_device = ( isset( $_GET['device'] ) ) ? sanitize_text_field( $_GET['device'] ) : 'all';
$display_start_date = ( isset( $_GET['start_date'] ) && ! empty($_GET['start_date']) ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'd-m-Y', strtotime( '-30 days' ) );
$display_end_date = ( isset( $_GET['end_date'] ) && ! empty($_GET['end_date']) ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'd-m-Y', strtotime( '-1 day' ) );

// --- Check access to selected site ---
$access_denied = false;
$table_data = null;
$chart_data = null;
$selected_site_name = '';

if ( $selected_site_id > 0 ) {
    // Verify user has access to this site
    if ( ! bite_user_has_site_access( $current_user_id, $selected_site_id ) ) {
        $access_denied = true;
    } else {
        $selected_site_name = ( isset( $site_name_lookup[ $selected_site_id ] ) ) ? $site_name_lookup[ $selected_site_id ] : 'Site ID ' . $selected_site_id;
        
        $sql_start_date = date( 'Y-m-d', strtotime( $display_start_date ) );
        $sql_end_date = date( 'Y-m-d', strtotime( $display_end_date ) );

        $table_data = bite_get_data_for_table( $selected_site_id, $sql_start_date, $sql_end_date, $selected_device );
        $chart_data = bite_get_data_for_chart( $selected_site_id, $sql_start_date, $sql_end_date, $selected_device );
    }
}

?>

<main id="main" class="bite-main-content" role="main">
	<div class="bite-page-header">
		<h1>BITE Dashboard</h1>
		<p>Select a site, device, and date range to view keyword data.</p>
	</div>

    <div class="bite-filter-bar">
        <form method="GET" action="<?php echo esc_url( home_url( '/' ) ); ?>">
            
            <div class="bite-filter-group">
                <label for="site_id"><?php esc_html_e( 'Select Site:', 'bite-theme' ); ?></label>
                <select id="site_id" name="site_id">
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
                <button type="submit" class="bite-button"><?php esc_html_e( 'View Data', 'bite-theme' ); ?></button>
            </div>

        </form>
    </div>

	<div class="bite-dashboard-widgets">
        <?php
        if ( $access_denied ) :
        ?>
            <div class="bite-widget-container bite-access-denied">
                <h2><?php esc_html_e( 'Access Denied', 'bite-theme' ); ?></h2>
                <p><?php esc_html_e( 'You do not have permission to view data for this site. Please contact your administrator if you believe this is an error.', 'bite-theme' ); ?></p>
            </div>
        <?php
        elseif ( $selected_site_id > 0 ) :
        ?>
            
            <?php if ( ! empty( $chart_data['labels'] ) ) : ?>
                
				<div class="bite-widget-container">
                    <h2><?php esc_html_e( 'Performance Overview', 'bite-theme' ); ?></h2>
                    <div class="bite-chart-wrapper">
                        <canvas id="bite-line-chart"></canvas>
                    </div>
                </div>

                <div class="bite-widget-container bite-table-container">
                    <h2><?php esc_html_e( 'Discoverable Keywords', 'bite-theme' ); ?></h2>
                    <p>
                        <?php echo sprintf(
                            esc_html__( 'Displaying keywords for %s (%s) from %s to %s.', 'bite-theme' ),
                            '<strong>' . esc_html( $selected_site_name ) . '</strong>',
                            esc_html( $selected_device ),
                            '<strong>' . esc_html( $display_start_date ) . '</strong>',
                            '<strong>' . esc_html( $display_end_date ) . '</strong>'
                        ); ?>
                        <br><small><?php esc_html_e('Note: Totals in this table may not match the chart above. The chart shows ALL data, while this table only shows data for discoverable (non-anonymized) keywords.', 'bite-theme'); ?></small>
                    </p>
                    
                    <?php if ( ! empty( $table_data ) ) : ?>
                        <table id="bite-data-table" class="bite-data-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Keyword', 'bite-theme' ); ?></th>
                                    <th><?php esc_html_e( 'Clicks', 'bite-theme' ); ?></th>
                                    <th><?php esc_html_e( 'Impressions', 'bite-theme' ); ?></th>
                                    <th><?php esc_html_e( 'Avg. CTR', 'bite-theme' ); ?></th>
                                    <th><?php esc_html_e( 'Avg. Position', 'bite-theme' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $table_data as $row ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $row['keyword'] ); ?></td>
                                        <td><?php echo esc_html( number_format( $row['total_clicks'] ) ); ?></td>
                                        <td><?php echo esc_html( number_format( $row['total_impressions'] ) ); ?></td>
                                        <td><?php echo esc_html( number_format( $row['avg_ctr'] * 100, 2 ) ); ?>%</td>
                                        <td><?php echo esc_html( number_format( $row['avg_position'], 1 ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                         <p><strong><?php esc_html_e( 'No discoverable keywords found for this period.', 'bite-theme' ); ?></strong></p>
                    <?php endif; ?>
                </div>
            
            <?php else : ?>
                <p><strong><?php esc_html_e( 'No data found for this site in this date range.', 'bite-theme' ); ?></strong></p>
                <p><?php esc_html_e( 'The backfill may still be in progress, or there may be no search data for this period.', 'bite-theme' ); ?></p>
            <?php endif; ?>

        <?php else : ?>
            <p><strong><?php esc_html_e( 'Please select a site to view data.', 'bite-theme' ); ?></strong></p>
        <?php endif; ?>
	</div>
</main>

<?php
// Pass data from PHP to our bite.js file
if ( $chart_data ) {
    echo '<script type="text/javascript">';
    // Renamed to biteChartData
    echo 'const biteChartData = ' . wp_json_encode( $chart_data ) . ';';
    echo '</script>';
}
?>

<?php
get_footer();
