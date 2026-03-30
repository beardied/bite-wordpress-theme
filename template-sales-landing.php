<?php
/**
 * Template Name: BITE Sales Landing Page
 *
 * The public-facing sales page for non-logged-in visitors.
 * Shows off BITE features and promotes OrangeWidow services.
 *
 * @package BITE-theme
 */

get_header();
?>

<main id="main" class="bite-landing-page" role="main">
    
    <!-- Hero Section -->
    <section class="bite-hero">
        <div class="bite-hero-container">
            <div class="bite-hero-content">
                <div class="bite-hero-badge">Exclusive to OrangeWidow</div>
                <h1 class="bite-hero-title">
                    <span class="bite-hero-title-main">B.I.T.E.</span>
                    <span class="bite-hero-title-sub">Bulk Insight Tracking Engine</span>
                </h1>
                <p class="bite-hero-description">
                    Unlock the power of your Google Search Console data. Track, analyze, and optimize 
                    your websites' performance across multiple properties with enterprise-grade analytics.
                </p>
                <div class="bite-hero-cta">
                    <a href="https://orangewidow.com/contact" class="bite-button bite-button-large" target="_blank">
                        Get Access Through OrangeWidow
                    </a>
                    <p class="bite-hero-note">Existing client? <a href="<?php echo esc_url( wp_login_url() ); ?>">Log in here</a></p>
                </div>
            </div>
            <div class="bite-hero-visual">
                <div class="bite-dashboard-preview">
                    <div class="bite-preview-header">
                        <span class="bite-preview-dot"></span>
                        <span class="bite-preview-dot"></span>
                        <span class="bite-preview-dot"></span>
                        <span class="bite-preview-title">BITE Dashboard</span>
                    </div>
                    <div class="bite-preview-chart">
                        <div class="bite-preview-bar" style="height: 40%"></div>
                        <div class="bite-preview-bar" style="height: 65%"></div>
                        <div class="bite-preview-bar" style="height: 50%"></div>
                        <div class="bite-preview-bar" style="height: 80%"></div>
                        <div class="bite-preview-bar" style="height: 70%"></div>
                        <div class="bite-preview-bar bite-preview-bar-highlight" style="height: 95%"></div>
                        <div class="bite-preview-bar" style="height: 60%"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Grid -->
    <section class="bite-features">
        <div class="bite-section-header">
            <h2>Powerful Features for SEO Professionals</h2>
            <p>Everything you need to monitor and optimize your search performance</p>
        </div>
        
        <div class="bite-features-grid">
            <div class="bite-feature-card">
                <div class="bite-feature-icon bite-icon-dashboard"></div>
                <h3>Performance Dashboard</h3>
                <p>Track clicks, impressions, CTR, and average position across all your websites in one unified view.</p>
            </div>
            
            <div class="bite-feature-card">
                <div class="bite-feature-icon bite-icon-opportunity"></div>
                <h3>Opportunity Finder</h3>
                <p>Discover keywords your competitors rank for that you're missing. Find quick wins and content gaps.</p>
            </div>
            
            <div class="bite-feature-card">
                <div class="bite-feature-icon bite-icon-champions"></div>
                <h3>Global Champions</h3>
                <p>Identify top-performing keywords across entire niches. See what's working in your industry.</p>
            </div>
            
            <div class="bite-feature-card">
                <div class="bite-feature-icon bite-icon-trends"></div>
                <h3>Emerging Trends</h3>
                <p>Spot keywords with rapid impression changes. Catch trends before your competitors do.</p>
            </div>
            
            <div class="bite-feature-card">
                <div class="bite-feature-icon bite-icon-explorer"></div>
                <h3>Keyword Explorer</h3>
                <p>Search and analyze keyword variations across your entire portfolio. Find long-tail opportunities.</p>
            </div>
            
            <div class="bite-feature-card">
                <div class="bite-feature-icon bite-icon-ctr"></div>
                <h3>CTR Efficiency Report</h3>
                <p>Understand your anonymized vs. discoverable click ratios. Optimize for hidden traffic.</p>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="bite-how-it-works">
        <div class="bite-section-header">
            <h2>How B.I.T.E. Works</h2>
            <p>Simple setup, powerful insights</p>
        </div>
        
        <div class="bite-steps">
            <div class="bite-step">
                <div class="bite-step-number">1</div>
                <h3>Connect</h3>
                <p>Link your Google Search Console properties to B.I.T.E. through secure API access.</p>
            </div>
            
            <div class="bite-step">
                <div class="bite-step-number">2</div>
                <h3>Track</h3>
                <p>We automatically pull and store your search data daily, building a comprehensive history.</p>
            </div>
            
            <div class="bite-step">
                <div class="bite-step-number">3</div>
                <h3>Analyze</h3>
                <p>Use our powerful tools to uncover insights, opportunities, and trends across your portfolio.</p>
            </div>
            
            <div class="bite-step">
                <div class="bite-step-number">4</div>
                <h3>Optimize</h3>
                <p>Make data-driven decisions to improve your search performance and grow your traffic.</p>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="bite-stats">
        <div class="bite-stats-grid">
            <div class="bite-stat">
                <span class="bite-stat-number">16 Months</span>
                <span class="bite-stat-label">Historical Data</span>
            </div>
            <div class="bite-stat">
                <span class="bite-stat-number">Unlimited</span>
                <span class="bite-stat-label">Websites</span>
            </div>
            <div class="bite-stat">
                <span class="bite-stat-number">Daily</span>
                <span class="bite-stat-label">Data Updates</span>
            </div>
            <div class="bite-stat">
                <span class="bite-stat-number">Real-time</span>
                <span class="bite-stat-label">Analytics</span>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="bite-cta-section">
        <div class="bite-cta-content">
            <h2>Ready to Unlock Your Search Data?</h2>
            <p>B.I.T.E. is exclusively available through OrangeWidow. Contact us today to get access to this powerful analytics platform.</p>
            <a href="https://orangewidow.com/contact" class="bite-button bite-button-large bite-button-white" target="_blank">
                Contact OrangeWidow
            </a>
        </div>
    </section>

</main>

<?php
get_footer();
