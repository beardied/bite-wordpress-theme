<?php
/**
 * Template Name: BITE Opportunity Finder
 *
 * This is the template for our Keyword Opportunity Finder tool.
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
$source_site_id = ( isset( $_GET['source_site_id'] ) ) ? absint( $_GET['source_site_id'] ) : 0;
$target_site_id = ( isset( $_GET['target_site_id'] ) ) ? absint( $_GET['target_site_id'] ) : 0;
$selected_device = ( isset( $_GET['device'] ) ) ? sanitize_text_field( $_GET['device'] ) : 'all';
$display_start_date = ( isset( $_GET['start_date'] ) && ! empty($_GET['start_date']) ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'd-m-Y', strtotime( '-30 days' ) );
$display_end_date = ( isset( $_GET['end_date'] ) && ! empty($_GET['end_date']) ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'd-m-Y', strtotime( '-1 day' ) );

// --- Main Data Query ---
$opportunity_data = null;
$source_site_name = '';
$target_site_name = '';

if ( $source_site_id > 0 && $target_site_id > 0 ) {
    
    // Get site names for display
    $source_site_name = $site_name_lookup[ $source_site_id ] ?? 'Source Site';
    $target_site_name = $site_name_lookup[ $target_site_id ] ?? 'Target Site';
    
    // Convert display dates to SQL-safe Y-m-d format
    $sql_start_date = date( 'Y-m-d', strtotime( $display_start_date ) );
    $sql_end_date = date( 'Y-m-d', strtotime( $display_end_date ) );

    $opportunity_data = bite_get_opportunity_finder_data( $source_site_id, $target_site_id, $sql_start_date, $sql_end_date, $selected_device );
}

?>

<main id="main" class="bite-main-content" role="main">
	<div class="bite-page-header">
		<h1>Keyword Opportunity Finder</h1>
		<p>Find keywords that one site ranks for, but another site is missing.</p>
	</div>

    <div class="bite-filter-bar">
        <form method="GET" action="">
            
            <div class="bite-filter-group">
                <label for="source_site_id"><?php esc_html_e( 'Find keywords performing on:', 'bite-theme' ); ?></label>
                <select id="source_site_id" name="source_site_id" required>
                    <option value=""><?php esc_html_e( 'Select source site...', 'bite-theme' ); ?></option>
                    <?php foreach ( $all_sites as $site ) : ?>
                        <option value="<?php echo esc_attr( $site->site_id ); ?>" <?php selected( $site->site_id, $source_site_id ); ?>>
                            <?php echo esc_html( $site->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="bite-filter-group">
                <label for="target_site_id"><?php esc_html_e( '...that are MISSING from:', 'bite-theme' ); ?></label>
                <select id="target_site_id" name="target_site_id" required>
                    <option value=""><?php esc_html_e( 'Select target site...', 'bite-theme' ); ?></option>
                    <?php foreach ( $all_sites as $site ) : ?>
                        <option value="<?php echo esc_attr( $site->site_id ); ?>" <?php selected( $site->site_id, $target_site_id ); ?>>
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
                <button type="submit" class="bite-button"><?php esc_html_e( 'Find Opportunities', 'bite-theme' ); ?></button>
            </div>
            
        </form>
    </div>

	<div class="bite-dashboard-widgets">
        <?php if ( $opportunity_data !== null ) : ?>
            
            <?php if ( ! empty( $opportunity_data ) ) : ?>
                <div class="bite-widget-container">
                    <h2><?php esc_html_e( 'Found Opportunities', 'bite-theme' ); ?></h2>
                    <p>
                        <?php echo sprintf(
                            esc_html__( '%d keywords are performing well on %s but have no impressions on %s (%s, %s to %s).', 'bite-theme' ),
                            count( $opportunity_data ),
                            '<strong>' . esc_html( $source_site_name ) . '</strong>',
                            '<strong>' . esc_html( $target_site_name ) . '</strong>',
                            esc_html( $selected_device ),
                            esc_html( $display_start_date ),
                            esc_html( $display_end_date )
                        ); ?>
                    </p>
                    <table id="bite-opportunity-table" class="bite-data-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Missing Keyword', 'bite-theme' ); ?></th>
                                <th><?php esc_html_e( 'Source Site Clicks', 'bite-theme' ); ?></th>
                                <th><?php esc_html_e( 'Source Site Impressions', 'bite-theme' ); ?></th>
                                <th><?php esc_html_e( 'Source Site Avg. Position', 'bite-theme' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $opportunity_data as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $row['keyword'] ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['total_clicks'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['total_impressions'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['avg_position'], 1 ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p><strong><?php esc_html_e( 'No opportunities found.', 'bite-theme' ); ?></strong></p>
                <p><?php esc_html_e( 'The target site appears to already rank for all the top-performing keywords from the source site, or the source site had no keywords matching the criteria.', 'bite-theme' ); ?></p>
            <?php endif; ?>

        <?php else : ?>
            <p><strong><?php esc_html_e( 'Please select a source site and a target site to find opportunities.', 'bite-theme' ); ?></strong></p>
        <?php endif; ?>
	</div>

</main>

<?php
get_footer();
