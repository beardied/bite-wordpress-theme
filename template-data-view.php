<?php
/**
 * Template Name: BITE Data View
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

// Get all user sites for dropdown
$user_sites = array();
if ( ! empty( $user_site_ids ) ) {
    $sites_table = $wpdb->prefix . 'bite_sites';
    $placeholders = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
    $user_sites = $wpdb->get_results( $wpdb->prepare(
        "SELECT site_id, name, domain FROM $sites_table WHERE site_id IN ($placeholders) ORDER BY name ASC",
        $user_site_ids
    ) );
}

// Get selected site
$selected_site_id = isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0;
$selected_site = null;
$table_data = null;
$chart_data = null;

// Get filter values (default to last 30 days)
$selected_device = isset( $_GET['device'] ) ? sanitize_text_field( $_GET['device'] ) : 'all';
$display_start_date = isset( $_GET['start_date'] ) && ! empty( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'd-m-Y', strtotime( '-30 days' ) );
$display_end_date = isset( $_GET['end_date'] ) && ! empty( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'd-m-Y', strtotime( '-1 day' ) );

// If site is selected, fetch data
if ( $selected_site_id > 0 && in_array( $selected_site_id, $user_site_ids ) ) {
    $sites_table = $wpdb->prefix . 'bite_sites';
    $selected_site = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $sites_table WHERE site_id = %d",
        $selected_site_id
    ) );
    
    if ( $selected_site ) {
        // Convert to SQL dates
        $sql_start_date = date( 'Y-m-d', strtotime( $display_start_date ) );
        $sql_end_date = date( 'Y-m-d', strtotime( $display_end_date ) );
        
        // Fetch data
        $table_data = bite_get_data_for_table( $selected_site_id, $sql_start_date, $sql_end_date, $selected_device );
        $chart_data = bite_get_data_for_chart( $selected_site_id, $sql_start_date, $sql_end_date, $selected_device );
    }
}

?>

<div class="bite-dashboard-wrapper">
    <?php get_template_part( 'includes/dashboard-sidebar' ); ?>

    <main id="main" class="bite-dashboard-main-content" role="main">
        
        <section class="bite-dashboard-welcome">
            <div class="bite-welcome-content">
                <h1 class="bite-welcome-title">📊 View Data</h1>
                <p class="bite-welcome-subtitle">Analyze your site performance</p>
            </div>
        </section>

        <section class="bite-dashboard-section">
            <div class="bite-filter-bar">
                <form method="GET" action="<?php echo esc_url( get_permalink() ); ?>">
                    
                    <div class="bite-filter-group">
                        <label for="site_id">Site:</label>
                        <select id="site_id" name="site_id" required onchange="this.form.submit()">
                            <option value="">-- Select a site --</option>
                            <?php foreach ( $user_sites as $site ) : ?>
                                <option value="<?php echo esc_attr( $site->site_id ); ?>" <?php selected( $selected_site_id, $site->site_id ); ?>>
                                    <?php echo esc_html( $site->name ); ?> (<?php echo esc_html( $site->domain ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="bite-filter-group">
                        <label for="device">Device:</label>
                        <select id="device" name="device">
                            <option value="all" <?php selected( 'all', $selected_device ); ?>>All Devices</option>
                            <option value="desktop" <?php selected( 'desktop', $selected_device ); ?>>Desktop</option>
                            <option value="mobile" <?php selected( 'mobile', $selected_device ); ?>>Mobile</option>
                            <option value="tablet" <?php selected( 'tablet', $selected_device ); ?>>Tablet</option>
                        </select>
                    </div>

                    <div class="bite-filter-group">
                        <label for="start_date">Start Date:</label>
                        <input type="text" id="start_date" name="start_date" class="bite-datepicker" value="<?php echo esc_attr( $display_start_date ); ?>">
                    </div>

                    <div class="bite-filter-group">
                        <label for="end_date">End Date:</label>
                        <input type="text" id="end_date" name="end_date" class="bite-datepicker" value="<?php echo esc_attr( $display_end_date ); ?>">
                    </div>

                    <div class="bite-filter-group">
                        <button type="submit" class="bite-button">Update View</button>
                    </div>
                </form>
            </div>

            <?php if ( $selected_site ) : ?>
                <div class="bite-dashboard-widgets">
                    <div class="bite-widget-container">
                        <h2>Performance Overview - <?php echo esc_html( $selected_site->name ); ?></h2>
                        <?php if ( ! empty( $chart_data['labels'] ) ) : ?>
                            <div class="bite-chart-wrapper">
                                <canvas id="bite-line-chart"></canvas>
                            </div>
                        <?php else : ?>
                            <p>No chart data available for this date range.</p>
                        <?php endif; ?>
                    </div>

                    <div class="bite-widget-container bite-table-container">
                        <h2>Discoverable Keywords</h2>
                        <p>
                            Showing data for <strong><?php echo esc_html( $selected_site->name ); ?></strong> 
                            (<?php echo esc_html( $selected_device ); ?>) 
                            from <strong><?php echo esc_html( $display_start_date ); ?></strong> 
                            to <strong><?php echo esc_html( $display_end_date ); ?></strong>
                            <br><small>Note: Totals may not match the chart. The chart shows ALL data, while this table shows only discoverable (non-anonymized) keywords.</small>
                        </p>
                        
                        <?php if ( ! empty( $table_data ) ) : ?>
                            <table id="bite-data-table" class="bite-data-table">
                                <thead>
                                    <tr>
                                        <th>Keyword</th>
                                        <th>Clicks</th>
                                        <th>Impressions</th>
                                        <th>Avg. CTR</th>
                                        <th>Avg. Position</th>
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
                            <p><strong>No discoverable keywords found for this period.</strong></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else : ?>
                <div class="bite-widget-container">
                    <h2>Select a Site</h2>
                    <p>Please select a site from the dropdown above to view detailed analytics.</p>
                </div>
            <?php endif; ?>
        </section>

        <?php if ( $chart_data ) : ?>
            <script type="text/javascript">
                const biteChartData = <?php echo wp_json_encode( $chart_data ); ?>;
            </script>
        <?php endif; ?>

    </main>
</div>

<?php get_footer(); ?>
