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
                    <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>" class="bite-button bite-button-large">
                        Request Access
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
                <div class="bite-feature-icon">
                    <span class="material-icons">dashboard</span>
                </div>
                <h3>Performance Dashboard</h3>
                <p>Track clicks, impressions, CTR, and average position across all your websites in one unified view.</p>
            </div>
            
            <div class="bite-feature-card">
                <div class="bite-feature-icon">
                    <span class="material-icons">search</span>
                </div>
                <h3>Opportunity Finder</h3>
                <p>Compare keywords across your own sites. Find what works on one site and apply it to another.</p>
            </div>
            
            <div class="bite-feature-card">
                <div class="bite-feature-icon">
                    <span class="material-icons">emoji_events</span>
                </div>
                <h3>Global Champions</h3>
                <p>Identify top-performing keywords across your own sites and niches. See what's working in your portfolio.</p>
            </div>
            
            <div class="bite-feature-card">
                <div class="bite-feature-icon">
                    <span class="material-icons">trending_up</span>
                </div>
                <h3>Emerging Trends</h3>
                <p>Spot keywords with rapid impression changes. Catch trends before your competitors do.</p>
            </div>
            
            <div class="bite-feature-card">
                <div class="bite-feature-icon">
                    <span class="material-icons">travel_explore</span>
                </div>
                <h3>Keyword Explorer</h3>
                <p>Search and analyze keyword variations across your entire portfolio. Find long-tail opportunities.</p>
            </div>
            
            <div class="bite-feature-card">
                <div class="bite-feature-icon">
                    <span class="material-icons">speed</span>
                </div>
                <h3>CTR Efficiency Report</h3>
                <p>Understand your anonymized vs. discoverable click ratios. Optimize for hidden traffic.</p>
            </div>
        </div>
    </section>

    <!-- Niche Feature Highlight -->
    <section class="bite-niche-highlight">
        <div class="bite-niche-content">
            <div class="bite-niche-text">
                <h2>Powerful Niche Intelligence</h2>
                <p>Organize your websites by niche and unlock powerful cross-site insights. Available on all plans, but especially powerful for agencies and enterprise users managing multiple properties in the same vertical. Leverage niche groupings to identify winning strategies and apply proven tactics across similar sites.</p>
                <ul class="bite-niche-benefits">
                    <li><span class="material-icons">check_circle</span> Compare performance across niche-specific portfolios</li>
                    <li><span class="material-icons">check_circle</span> Identify high-performing keywords within your niches</li>
                    <li><span class="material-icons">check_circle</span> Apply winning strategies from top sites to underperformers</li>
                    <li><span class="material-icons">check_circle</span> Discover content gaps across similar properties</li>
                </ul>
            </div>
            <div class="bite-niche-visual">
                <div class="bite-niche-network">
                    <div class="bite-network-node bite-node-central">
                        <span class="material-icons">hub</span>
                    </div>
                    <div class="bite-network-node bite-node-1"><span class="material-icons">language</span></div>
                    <div class="bite-network-node bite-node-2"><span class="material-icons">language</span></div>
                    <div class="bite-network-node bite-node-3"><span class="material-icons">language</span></div>
                    <div class="bite-network-node bite-node-4"><span class="material-icons">language</span></div>
                    <div class="bite-network-line bite-line-1"></div>
                    <div class="bite-network-line bite-line-2"></div>
                    <div class="bite-network-line bite-line-3"></div>
                    <div class="bite-network-line bite-line-4"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section class="bite-pricing" id="pricing">
        <div class="bite-section-header">
            <h2>Simple, Transparent Pricing</h2>
            <p>Choose the plan that fits your needs. All plans include full access to B.I.T.E. features.</p>
        </div>
        
        <!-- First Row: 3 Cards -->
        <div class="bite-pricing-row bite-pricing-row-3">
            <!-- Free for Hosting Customers -->
            <div class="bite-pricing-card bite-pricing-featured">
                <div class="bite-pricing-badge">Best Value</div>
                <div class="bite-pricing-header">
                    <h3>OrangeWidow Hosting</h3>
                    <div class="bite-pricing-price">
                        <span class="bite-price">Free</span>
                        <span class="bite-period">with hosting</span>
                    </div>
                </div>
                <div class="bite-pricing-features">
                    <ul>
                        <li><span class="material-icons">check</span> All websites on your hosting plan included</li>
                        <li><span class="material-icons">check</span> Full feature access</li>
                        <li><span class="material-icons">check</span> Priority support</li>
                        <li><span class="material-icons">check</span> Daily data updates</li>
                    </ul>
                </div>
                <div class="bite-pricing-cta">
                    <a href="https://orangewidow.com" class="bite-button" target="_blank">Get Hosting</a>
                </div>
            </div>
            
            <!-- Solo -->
            <div class="bite-pricing-card">
                <div class="bite-pricing-header">
                    <h3>Solo</h3>
                    <div class="bite-pricing-price">
                        <span class="bite-price">£29</span>
                        <span class="bite-period">/month</span>
                    </div>
                </div>
                <div class="bite-pricing-features">
                    <ul>
                        <li><span class="material-icons">check</span> Up to 3 websites</li>
                        <li><span class="material-icons">check</span> Full feature access</li>
                        <li><span class="material-icons">check</span> Email support</li>
                        <li><span class="material-icons">check</span> Daily data updates</li>
                    </ul>
                </div>
                <div class="bite-pricing-cta">
                    <a href="<?php echo esc_url( home_url( '/contact/?plan=solo' ) ); ?>" class="bite-button">Request Access</a>
                </div>
            </div>
            
            <!-- Pro -->
            <div class="bite-pricing-card">
                <div class="bite-pricing-header">
                    <h3>Pro</h3>
                    <div class="bite-pricing-price">
                        <span class="bite-price">£59</span>
                        <span class="bite-period">/month</span>
                    </div>
                </div>
                <div class="bite-pricing-features">
                    <ul>
                        <li><span class="material-icons">check</span> Up to 10 websites</li>
                        <li><span class="material-icons">check</span> Full feature access</li>
                        <li><span class="material-icons">check</span> Priority email support</li>
                        <li><span class="material-icons">check</span> Daily data updates</li>
                    </ul>
                </div>
                <div class="bite-pricing-cta">
                    <a href="<?php echo esc_url( home_url( '/contact/?plan=pro' ) ); ?>" class="bite-button">Request Access</a>
                </div>
            </div>
        </div>
        
        <!-- Second Row: 2 Cards (Centered) -->
        <div class="bite-pricing-row bite-pricing-row-2">
            <!-- Agency -->
            <div class="bite-pricing-card">
                <div class="bite-pricing-header">
                    <h3>Agency</h3>
                    <div class="bite-pricing-price">
                        <span class="bite-price">£119</span>
                        <span class="bite-period">/month</span>
                    </div>
                </div>
                <div class="bite-pricing-features">
                    <ul>
                        <li><span class="material-icons">check</span> Up to 25 websites</li>
                        <li><span class="material-icons">check</span> Full feature access</li>
                        <li><span class="material-icons">check</span> Priority email support</li>
                        <li><span class="material-icons">check</span> Daily data updates</li>
                    </ul>
                </div>
                <div class="bite-pricing-cta">
                    <a href="<?php echo esc_url( home_url( '/contact/?plan=agency' ) ); ?>" class="bite-button">Request Access</a>
                </div>
            </div>
            
            <!-- Enterprise -->
            <div class="bite-pricing-card bite-pricing-enterprise">
                <div class="bite-pricing-header">
                    <h3>Enterprise</h3>
                    <div class="bite-pricing-price">
                        <span class="bite-price">£199</span>
                        <span class="bite-period">/month</span>
                    </div>
                </div>
                <div class="bite-pricing-features">
                    <ul>
                        <li><span class="material-icons">check</span> Unlimited websites</li>
                        <li><span class="material-icons">check</span> Full feature access</li>
                        <li><span class="material-icons">check</span> Dedicated support</li>
                        <li><span class="material-icons">check</span> Daily data updates</li>
                        <li><span class="material-icons">check</span> Custom integrations available</li>
                    </ul>
                </div>
                <div class="bite-pricing-cta">
                    <a href="<?php echo esc_url( home_url( '/contact/?plan=enterprise' ) ); ?>" class="bite-button">Request Access</a>
                </div>
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
                <span class="bite-stat-number">Flexible</span>
                <span class="bite-stat-label">Website Plans</span>
            </div>
            <div class="bite-stat">
                <span class="bite-stat-number">Daily</span>
                <span class="bite-stat-label">Data Updates</span>
            </div>
            <div class="bite-stat">
                <span class="bite-stat-number">Always</span>
                <span class="bite-stat-label">Up-to-date</span>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="bite-cta-section">
        <div class="bite-cta-content">
            <h2>Ready to Unlock Your Search Data?</h2>
            <p>B.I.T.E. is exclusively available through OrangeWidow. Contact us today to get access to this powerful analytics platform.</p>
            <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>" class="bite-button bite-button-large bite-button-white">
                Request Access
            </a>
        </div>
    </section>

</main>

<?php
get_footer();
