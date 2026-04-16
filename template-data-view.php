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

// Get selected site
$selected_site_id = isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0;

// If no site selected, show site selector
if ( $selected_site_id === 0 || ! in_array( $selected_site_id, $user_site_ids ) ) {
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
    ?>
    <div class="bite-dashboard-wrapper">
        <?php get_template_part( 'includes/dashboard-sidebar' ); ?>
        <main id="main" class="bite-dashboard-main-content" role="main">
            <section class="bite-dashboard-welcome">
                <div class="bite-welcome-content">
                    <h1 class="bite-welcome-title">📊 View Data</h1>
                    <p class="bite-welcome-subtitle">Select a site to view detailed analytics</p>
                </div>
            </section>

            <section class="bite-dashboard-section">
                <?php if ( ! empty( $user_sites ) ) : ?>
                    <div class="bite-site-selector">
                        <?php foreach ( $user_sites as $site ) : 
                            $view_data_url = add_query_arg( 'site_id', $site->site_id, get_permalink() );
                        ?>
                            <a href="<?php echo esc_url( $view_data_url ); ?>" class="bite-site-selector-card">
                                <h3><?php echo esc_html( $site->name ); ?></h3>
                                <span class="bite-site-domain"><?php echo esc_html( $site->domain ); ?></span>
                                <span class="bite-button bite-button-primary">View Data →</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="bite-notice info">
                        <p>No sites available. <a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>">Add a site first →</a></p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <?php
    get_footer();
    exit;
}

// Site is selected - show data view
$selected_site = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}bite_sites WHERE site_id = %d",
    $selected_site_id
) );

if ( ! $selected_site ) {
    wp_redirect( get_permalink() );
    exit;
}

// Get filter values
$selected_device = isset( $_GET['device'] ) ? sanitize_text_field( $_GET['device'] ) : 'all';
$display_start_date = isset( $_GET['start_date'] ) && ! empty( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'd-m-Y', strtotime( '-30 days' ) );
$display_end_date = isset( $_GET['end_date'] ) && ! empty( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'd-m-Y', strtotime( '-1 day' ) );

// Convert to SQL dates
$sql_start_date = date( 'Y-m-d', strtotime( $display_start_date ) );
$sql_end_date = date( 'Y-m-d', strtotime( $display_end_date ) );

// Fetch data
$table_data = bite_get_data_for_table( $selected_site_id, $sql_start_date, $sql_end_date, $selected_device );
$chart_data = bite_get_data_for_chart( $selected_site_id, $sql_start_date, $sql_end_date, $selected_device );
?>

<div class="bite-dashboard-wrapper">
    <?php get_template_part( 'includes/dashboard-sidebar' ); ?>

    <main id="main" class="bite-dashboard-main-content" role="main">
        
        <section class="bite-dashboard-welcome">
            <div class="bite-welcome-content">
                <h1 class="bite-welcome-title">📊 <?php echo esc_html( $selected_site->name ); ?></h1>
                <p class="bite-welcome-subtitle">
                    <a href="<?php echo esc_url( get_permalink() ); ?>" class="bite-button bite-button-secondary" style="font-size: 0.9em; padding: 6px 16px;">
                        ← Change Site
                    </a>
                </p>
            </div>
        </section>

        <section class="bite-dashboard-section">
            <div class="bite-filter-bar">
                <form method="GET" action="<?php echo esc_url( get_permalink() ); ?>">
                    <input type="hidden" name="site_id" value="<?php echo esc_attr( $selected_site_id ); ?>">
                    
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

            <div class="bite-dashboard-widgets">
                <?php if ( ! empty( $chart_data['labels'] ) ) : ?>
                    
                    <div class="bite-widget-container">
                        <h2>Performance Overview</h2>
                        <div class="bite-chart-wrapper">
                            <canvas id="bite-line-chart"></canvas>
                        </div>
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

                <?php else : ?>
                    <div class="bite-widget-container">
                        <h2>No Data Found</h2>
                        <p>No search data available for this site in the selected date range.</p>
                        <p>The backfill may still be in progress, or there may be no search data for this period.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ( $chart_data ) : ?>
            <script type="text/javascript">
                const biteChartData = <?php echo wp_json_encode( $chart_data ); ?>;
            </script>
        <?php endif; ?>

    </main>
</div>

<?php get_footer(); ?>
