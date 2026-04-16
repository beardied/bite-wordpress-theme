// BITE Custom JavaScript
jQuery(document).ready(function($) {
    
    // 1. Initialize Datepickers
    $('.bite-datepicker').datepicker({
        dateFormat: 'dd-mm-yy' // Use the d-m-y format
    });

    // 2. Initialize DataTables
    if( $('#bite-data-table').length ) {
        $('#bite-data-table').DataTable({
            "order": [[ 1, "desc" ]], // Default sort by Clicks
            "responsive": true,
            "autoWidth": false
        });
    }
    if( $('#bite-opportunity-table').length ) {
        $('#bite-opportunity-table').DataTable({
            "order": [[ 2, "desc" ]] // Default sort by Source Impressions
        });
    }
    if( $('#bite-champions-table').length ) {
        $('#bite-champions-table').DataTable({
            "order": [[ 1, "desc" ]] // Default sort by Total Clicks
        });
    }
    if( $('#bite-trends-table').length ) {
        $('#bite-trends-table').DataTable({
            "order": [[ 3, "desc" ]] // Default sort by Impression Change
        });
    }
    if( $('#bite-explorer-table').length ) {
        $('#bite-explorer-table').DataTable({
            "order": [[ 1, "desc" ]] // <-- CHANGED to sort by Total Clicks
        });
    }

    // 3. Initialize Chart.js (Total vs. Anonymized Line Chart)
    if (typeof biteChartData !== 'undefined' && biteChartData.labels.length > 0) {
        const ctx = document.getElementById('bite-line-chart');
        
        // Define our colors
        const clicksColor = 'rgb(52, 152, 219)';
        const clicksColorFill = 'rgba(52, 152, 219, 0.1)';
        const impressionsColor = 'rgb(44, 62, 80)';
        const impressionsColorFill = 'rgba(44, 62, 80, 0.1)';
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: biteChartData.labels,
                datasets: [
                    {
                        label: 'Total Clicks',
                        data: biteChartData.total_clicks,
                        borderColor: clicksColor,
                        backgroundColor: clicksColorFill,
                        yAxisID: 'yClicks',
                        fill: false,
                        tension: 0.1
                    },
                    {
                        label: 'Anonymized Clicks',
                        data: biteChartData.anonymized_clicks,
                        borderColor: clicksColor, 
                        backgroundColor: clicksColorFill,
                        yAxisID: 'yClicks',
                        fill: false,
                        borderDash: [5, 5], 
                        tension: 0.1
                    },
                    {
                        label: 'Total Impressions',
                        data: biteChartData.total_impressions,
                        borderColor: impressionsColor,
                        backgroundColor: impressionsColorFill,
                        yAxisID: 'yImpressions',
                        fill: false,
                        tension: 0.1
                    },
                    {
                        label: 'Anonymized Impressions',
                        data: biteChartData.anonymized_impressions,
                        borderColor: impressionsColor, 
                        backgroundColor: impressionsColorFill,
                        yAxisID: 'yImpressions',
                        fill: false,
                        borderDash: [5, 5], 
                        tension: 0.1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, 
                scales: {
                    yClicks: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Total Clicks',
                            color: clicksColor
                        },
                        ticks: {
                            color: clicksColor
                        }
                    },
                    yImpressions: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Total Impressions',
                            color: impressionsColor
                        },
                        grid: {
                            drawOnChartArea: false, 
                        },
                        ticks: {
                            color: impressionsColor
                        }
                    }
                },
                plugins: {
                    tooltip: {
                         callbacks: {
                            footer: function(tooltipItems) {
                                let totalClicks = 0;
                                let totalImpressions = 0;
                                
                                tooltipItems.forEach(function(tooltipItem) {
                                    if (tooltipItem.dataset.label === 'Total Clicks') {
                                        totalClicks = tooltipItem.raw;
                                    }
                                    if (tooltipItem.dataset.label === 'Total Impressions') {
                                        totalImpressions = tooltipItem.raw;
                                    }
                                });
                                if (totalClicks > 0 || totalImpressions > 0) {
                                    return 'Total Clicks: ' + totalClicks + '\nTotal Impressions: ' + totalImpressions;
                                }
                                return '';
                            }
                        }
                    }
                }
            }
        });
    }

    // 4. NEW: Initialize "Proportional Analysis" Chart (Simplified)
    if (typeof biteCtrChartData !== 'undefined' && biteCtrChartData.labels.length > 0) {
        const ctxCtr = document.getElementById('bite-ctr-chart');
        
        new Chart(ctxCtr, {
            type: 'line',
            data: {
                labels: biteCtrChartData.labels,
                datasets: [
                    {
                        label: 'Anonymized % of Clicks',
                        data: biteCtrChartData.anonymized_clicks_pct,
                        borderColor: 'rgb(231, 76, 60)', // Red
                        backgroundColor: 'rgba(231, 76, 60, 0.1)',
                        fill: true, // Fill this line
                        tension: 0.1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, 
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Anonymized Clicks (%)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value + '%'
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw + '%';
                            }
                        }
                    }
                }
            }
        });
    }

    // 5. Live Server Time Clock
    (function() {
        var $serverTime = $('.bite-server-time');
        if ( !$serverTime.length ) return;
        
        // Get initial server timestamp (in seconds)
        var serverTimestamp = parseInt( $serverTime.data('server-timestamp'), 10 );
        if ( !serverTimestamp ) return;
        
        // Get local time offset to calculate server time accurately
        var localStartTime = Math.floor( Date.now() / 1000 );
        
        function updateClock() {
            var now = Math.floor( Date.now() / 1000 );
            var elapsed = now - localStartTime;
            var currentServerTime = serverTimestamp + elapsed;
            
            // Convert to Date object (UTC)
            var date = new Date( currentServerTime * 1000 );
            
            // Format: dd-mm-yyyy HH:mm:ss
            var day = String( date.getUTCDate() ).padStart( 2, '0' );
            var month = String( date.getUTCMonth() + 1 ).padStart( 2, '0' );
            var year = date.getUTCFullYear();
            var hours = String( date.getUTCHours() ).padStart( 2, '0' );
            var minutes = String( date.getUTCMinutes() ).padStart( 2, '0' );
            var seconds = String( date.getUTCSeconds() ).padStart( 2, '0' );
            
            var formattedTime = day + '-' + month + '-' + year + ' ' + hours + ':' + minutes + ':' + seconds + ' UTC';
            
            $serverTime.text( formattedTime );
        }
        
        // Update immediately, then every second
        updateClock();
        setInterval( updateClock, 1000 );
        
    })();

    // Scroll Progress Bar
    (function() {
        var $progressBar = $('.bite-scroll-progress');
        if ( !$progressBar.length ) return;
        
        function updateScrollProgress() {
            var scrollTop = $(window).scrollTop();
            var docHeight = $(document).height() - $(window).height();
            var scrollPercent = (scrollTop / docHeight) * 100;
            $progressBar.css('width', scrollPercent + '%');
        }
        
        // Update on scroll
        $(window).on('scroll', updateScrollProgress);
        
        // Initial update
        updateScrollProgress();
    })();

});