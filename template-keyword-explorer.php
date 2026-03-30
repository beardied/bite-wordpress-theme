<?php
/**
 * Template Name: BITE Keyword Explorer
 *
 * This is the template for our Keyword Explorer tool.
 *
 * @package BITE-theme
 */

get_header(); 

global $wpdb;

// --- Get data for filters ---
$niches_table = $wpdb->prefix . 'bite_niches';
$all_niches = $wpdb->get_results( "SELECT niche_id, niche_name FROM $niches_table ORDER BY niche_name ASC" );

// --- Get current filter values from URL ---
$selected_niche_id = ( isset( $_GET['niche_id'] ) ) ? absint( $_GET['niche_id'] ) : 0;
$selected_device = ( isset( $_GET['device'] ) ) ? sanitize_text_field( $_GET['device'] ) : 'all';
$display_start_date = ( isset( $_GET['start_date'] ) && ! empty($_GET['start_date']) ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'd-m-Y', strtotime( '-30 days' ) );
$display_end_date = ( isset( $_GET['end_date'] ) && ! empty($_GET['end_date']) ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'd-m-Y', strtotime( '-1 day' ) );
$seed_keyword = ( isset( $_GET['seed_keyword'] ) ) ? sanitize_text_field( $_GET['seed_keyword'] ) : '';

// --- Main Data Query ---
$explorer_data = null;
if ( $selected_niche_id > 0 && ! empty( $seed_keyword ) ) {
    // Convert display dates to SQL-safe Y-m-d format
    $sql_start_date = date( 'Y-m-d', strtotime( $display_start_date ) );
    $sql_end_date = date( 'Y-m-d', strtotime( $display_end_date ) );

    $explorer_data = bite_get_keyword_explorer_data( $selected_niche_id, $sql_start_date, $sql_end_date, $selected_device, $seed_keyword );
}

?>

<main id="main" class="bite-main-content" role="main">
	<div class="bite-page-header">
		<h1>Keyword Explorer</h1>
		<p>Enter a "seed" keyword to find all related long-tail variations and their stats across an entire niche.</p>
	</div>

    <div class="bite-filter-bar">
        <form method="GET" action="">

            <div class="bite-filter-group">
                <label for="seed_keyword"><?php esc_html_e( 'Seed Keyword:', 'bite-theme' ); ?></label>
                <input type="text" id="seed_keyword" name="seed_keyword" value="<?php echo esc_attr( $seed_keyword ); ?>" placeholder="e.g., car hire" required>
            </div>
            
            <div class="bite-filter-group">
                <label for="niche_id"><?php esc_html_e( 'Select Niche:', 'bite-theme' ); ?></label>
                <select id="niche_id" name="niche_id" required>
                    <option value=""><?php esc_html_e( 'Select a niche...', 'bite-theme' ); ?></option>
                    <?php foreach ( $all_niches as $niche ) : ?>
                        <option value="<?php echo esc_attr( $niche->niche_id ); ?>" <?php selected( $niche->niche_id, $selected_niche_id ); ?>>
                            <?php echo esc_html( $niche->niche_name ); ?>
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
                <button type="submit" class="bite-button"><?php esc_html_e( 'Explore', 'bite-theme' ); ?></button>
            </div>
            
        </form>
    </div>

	<div class="bite-dashboard-widgets">
        <?php if ( $explorer_data !== null ) : ?>
            
            <?php if ( ! empty( $explorer_data ) ) : ?>
                <div class="bite-widget-container">
                    <h2><?php echo count( $explorer_data ); ?> <?php esc_html_e( 'Keywords found matching', 'bite-theme' ); ?> "<?php echo esc_html( $seed_keyword ); ?>"</h2>
                    
                    <table id="bite-explorer-table" class="bite-data-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Keyword Variation', 'bite-theme' ); ?></th>
								<th><?php esc_html_e( 'Total Clicks', 'bite-theme' ); ?></th>
								<th><?php esc_html_e( 'Total Impressions', 'bite-theme' ); ?></th>
								<th>
									<span title="Niche-Wide Average Position: A 'blended' average of the daily positions for this keyword, across all sites in the niche.">
										<?php esc_html_e( 'Niche Avg. Pos.', 'bite-theme' ); ?>
									</span>
								</th>
								<th>
									<span title="Site Count: The total number of unique sites in this niche that are ranking for this keyword.">
										<?php esc_html_e( 'Site Count', 'bite-theme' ); ?>
									</span>
								</th>
							</tr>
						</thead>
                        <tbody>
                            <?php foreach ( $explorer_data as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $row['keyword'] ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['total_clicks'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['total_impressions'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['avg_position'], 1 ) ); ?></td>
                                    <td><?php echo esc_html( $row['site_count'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p><strong><?php esc_html_e( 'No keywords found.', 'bite-theme' ); ?></strong></p>
                <p><?php esc_html_e( 'No keywords in your database (for this niche and date range) match this term.', 'bite-theme' ); ?></p>
            <?php endif; ?>

        <?php else : ?>
            <p><strong><?php esc_html_e( 'Please enter a seed keyword and select a niche to explore.', 'bite-theme' ); ?></strong></p>
        <?php endif; ?>
	</div>

</main>

<?php
get_footer();
