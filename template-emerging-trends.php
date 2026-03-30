<?php
/**
 * Template Name: BITE Emerging Trends
 *
 * This is the template for our Emerging Trends Detector tool.
 *
 * @package BITE-theme
 */

get_header(); 

global $wpdb;

// --- Get data for filters ---
$sites_table = $wpdb->prefix . 'bite_sites';
$all_sites = $wpdb->get_results( "SELECT site_id, name FROM $sites_table ORDER BY name ASC" );
$site_name_lookup = array();
foreach ( $all_sites as $site ) {
    $site_name_lookup[ $site->site_id ] = $site->name;
}

// --- Get current filter values from URL ---
$selected_site_id = ( isset( $_GET['site_id'] ) ) ? absint( $_GET['site_id'] ) : 0;
$selected_device = ( isset( $_GET['device'] ) ) ? sanitize_text_field( $_GET['device'] ) : 'all';
$display_start_date = ( isset( $_GET['start_date'] ) && ! empty($_GET['start_date']) ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'd-m-Y', strtotime( '-60 days' ) );
$display_end_date = ( isset( $_GET['end_date'] ) && ! empty($_GET['end_date']) ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'd-m-Y', strtotime( '-1 day' ) );

// --- Main Data Query ---
$trends_data = null;
$selected_site_name = '';
if ( $selected_site_id > 0 ) {
    $selected_site_name = ( isset( $site_name_lookup[ $selected_site_id ] ) ) ? $site_name_lookup[ $selected_site_id ] : 'Site ID ' . $selected_site_id;
    
    // Convert display dates to SQL-safe Y-m-d format
    $sql_start_date = date( 'Y-m-d', strtotime( $display_start_date ) );
    $sql_end_date = date( 'Y-m-d', strtotime( $display_end_date ) );
    
    $trends_data = bite_get_emerging_trends_data( $selected_site_id, $sql_start_date, $sql_end_date, $selected_device );
}

?>

<div class="bite-dashboard-wrapper">
    
    <?php get_template_part( 'includes/dashboard-sidebar' ); ?>

    <main id="main" class="bite-dashboard-main-content" role="main">
	<div class="bite-page-header">
		<h1>Emerging Trends Detector</h1>
		<p>Find keywords with rapid changes in impressions. This tool compares the first half of your selected date range to the second half.</p>
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
                <button type="submit" class="bite-button"><?php esc_html_e( 'Find Trends', 'bite-theme' ); ?></button>
            </div>
            
        </form>
    </div>

	<div class="bite-dashboard-widgets">
        <?php if ( $trends_data !== null ) : ?>
            
            <?php if ( ! empty( $trends_data ) ) : ?>
                <div class="bite-widget-container">
                    <h2><?php esc_html_e( 'Keyword Trends', 'bite-theme' ); ?></h2>
                     <p>
                        <?php echo sprintf(
                            esc_html__( 'Showing keywords for %s (%s, %s to %s). Sorted by most impression growth.', 'bite-theme' ),
                            '<strong>' . esc_html( $selected_site_name ) . '</strong>',
                            esc_html( $selected_device ),
                            esc_html( $display_start_date ),
                            esc_html( $display_end_date )
                        ); ?>
                    </p>
                    
                    <table id="bite-trends-table" class="bite-data-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Keyword', 'bite-theme' ); ?></th>
                                <th><?php esc_html_e( 'Impressions (Old)', 'bite-theme' ); ?></th>
                                <th><?php esc_html_e( 'Impressions (New)', 'bite-theme' ); ?></th>
                                <th><?php esc_html_e( 'Imp. Change', 'bite-theme' ); ?></th>
                                <th><?php esc_html_e( 'Clicks (Old)', 'bite-theme' ); ?></th>
                                <th><?php esc_html_e( 'Clicks (New)', 'bite-theme' ); ?></th>
                                <th><?php esc_html_e( 'Clicks Change', 'bite-theme' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $trends_data as $row ) : 
                                $imp_change = (int) $row['impressions_change'];
                                $clk_change = (int) $row['clicks_change'];
                                
                                $imp_class = ( $imp_change > 0 ) ? 'bite-trend-up' : 'bite-trend-down';
                                $clk_class = ( $clk_change > 0 ) ? 'bite-trend-up' : 'bite-trend-down';
                            ?>
                                <tr>
                                    <td><?php echo esc_html( $row['keyword'] ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['old_impressions'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['new_impressions'] ) ); ?></td>
                                    <td class="<?php echo $imp_class; ?>"><?php echo ( $imp_change > 0 ? '+' : '' ) . esc_html( number_format( $imp_change ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['old_clicks'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['new_clicks'] ) ); ?></td>
                                    <td class="<?php echo $clk_class; ?>"><?php echo ( $clk_change > 0 ? '+' : '' ) . esc_html( number_format( $clk_change ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p><strong><?php esc_html_e( 'No trending data found.', 'bite-theme' ); ?></strong></p>
                <p><?php esc_html_e( 'The backfill may still be in progress, or no keywords showed a significant change in this period.', 'bite-theme' ); ?></p>
            <?php endif; ?>

        <?php else : ?>
            <p><strong><?php esc_html_e( 'Please select a site and date range to find trends.', 'bite-theme' ); ?></strong></p>
        <?php endif; ?>
	</div>

</main>
    
</div>

<?php
get_footer();
