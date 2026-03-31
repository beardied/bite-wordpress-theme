<?php
/**
 * Template Name: BITE Contact Page
 *
 * Contact form page for requesting access to BITE.
 *
 * @package BITE-theme
 */

get_header();

// Process form submission
$form_message = '';
$form_error = '';

// Get plan from URL parameter if set
$selected_plan = isset( $_GET['plan'] ) ? sanitize_text_field( $_GET['plan'] ) : '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['bite_contact_submit'] ) ) {
    // Verify nonce
    if ( ! isset( $_POST['bite_contact_nonce'] ) || ! wp_verify_nonce( $_POST['bite_contact_nonce'], 'bite_contact_form' ) ) {
        $form_error = 'Security check failed. Please try again.';
    } else {
        // Honeypot check (hidden field should be empty)
        if ( ! empty( $_POST['bite_website'] ) ) {
            $form_error = 'Spam detected.';
        } else {
            // Get form data
            $bite_name = sanitize_text_field( $_POST['bite_name'] ?? '' );
            $bite_email = sanitize_email( $_POST['bite_email'] ?? '' );
            $bite_company = sanitize_text_field( $_POST['bite_company'] ?? '' );
            $bite_plan = sanitize_text_field( $_POST['bite_plan'] ?? '' );
            $bite_websites = sanitize_textarea_field( $_POST['bite_websites'] ?? '' );
            $bite_message = sanitize_textarea_field( $_POST['bite_message'] ?? '' );
            
            // Validation
            if ( empty( $bite_name ) || empty( $bite_email ) || empty( $bite_plan ) ) {
                $form_error = 'Please fill in all required fields.';
            } elseif ( ! is_email( $bite_email ) ) {
                $form_error = 'Please enter a valid email address.';
            } else {
                // Get recipient email from settings
                $recipient_email = get_option( 'bite_contact_email', get_option( 'admin_email' ) );
                
                // Build email
                $subject = 'BITE Access Request from ' . $bite_name;
                
                $email_body = "New BITE Access Request\n\n";
                $email_body .= "Name: " . $bite_name . "\n";
                $email_body .= "Email: " . $bite_email . "\n";
                $email_body .= "Company: " . ( $bite_company ? $bite_company : 'Not provided' ) . "\n";
                $email_body .= "Interested Plan: " . ( $bite_plan ? $bite_plan : 'Not specified' ) . "\n";
                $email_body .= "Websites: " . ( $bite_websites ? $bite_websites : 'Not provided' ) . "\n\n";
                $email_body .= "Message:\n" . ( $bite_message ? $bite_message : 'No additional message' ) . "\n\n";
                $email_body .= "Submitted from: " . home_url( '/contact/' ) . "\n";
                $email_body .= "Date: " . date( 'Y-m-d H:i:s' ) . "\n";
                $email_body .= "IP: " . ( $_SERVER['REMOTE_ADDR'] ?? 'Unknown' ) . "\n";
                
                $headers = array(
                    'From: BITE Contact Form <' . $recipient_email . '>',
                    'Reply-To: ' . $bite_name . ' <' . $bite_email . '>',
                    'Content-Type: text/plain; charset=UTF-8',
                );
                
                // Send email
                $sent = wp_mail( $recipient_email, $subject, $email_body, $headers );
                
                if ( $sent ) {
                    $form_message = 'Thank you for your request! We will review your application and get back to you soon.';
                    // Clear form data after successful submission
                    $bite_name = $bite_email = $bite_company = $bite_websites = $bite_message = '';
                } else {
                    $form_error = 'There was an error sending your message. Please try again later or contact us directly.';
                }
            }
        }
    }
}

?>

<main id="main" class="bite-contact-page" role="main">
    
    <!-- Contact Header -->
    <section class="bite-contact-header">
        <div class="bite-contact-header-content">
            <h1 class="bite-contact-title">Request Access to B.I.T.E.</h1>
            <p class="bite-contact-subtitle">
                Interested in unlocking the power of your Google Search Console data? 
                Fill out the form below and our team will review your request.
            </p>
        </div>
    </section>

    <!-- Contact Form Section -->
    <section class="bite-contact-section">
        <div class="bite-contact-container">
            
            <?php if ( $form_message ) : ?>
                <div class="bite-contact-success">
                    <span class="material-icons">check_circle</span>
                    <p><?php echo esc_html( $form_message ); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ( $form_error ) : ?>
                <div class="bite-contact-error">
                    <span class="material-icons">error</span>
                    <p><?php echo esc_html( $form_error ); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="bite-contact-form">
                <?php wp_nonce_field( 'bite_contact_form', 'bite_contact_nonce' ); ?>
                
                <!-- Honeypot field (hidden from humans) -->
                <div class="bite-honeypot">
                    <input type="text" name="bite_website" value="" tabindex="-1" autocomplete="off">
                </div>
                
                <div class="bite-form-row">
                    <div class="bite-form-group bite-form-group-half">
                        <label for="bite_name">
                            Full Name <span class="bite-required">*</span>
                        </label>
                        <input type="text" 
                               id="bite_name" 
                               name="bite_name" 
                               required 
                               placeholder="Your full name"
                               value="<?php echo isset( $bite_name ) ? esc_attr( $bite_name ) : ''; ?>">
                    </div>
                    
                    <div class="bite-form-group bite-form-group-half">
                        <label for="bite_email">
                            Email Address <span class="bite-required">*</span>
                        </label>
                        <input type="email" 
                               id="bite_email" 
                               name="bite_email" 
                               required 
                               placeholder="your@email.com"
                               value="<?php echo isset( $bite_email ) ? esc_attr( $bite_email ) : ''; ?>">
                    </div>
                </div>
                
                <div class="bite-form-group">
                    <label for="bite_company">Company Name</label>
                    <input type="text" 
                           id="bite_company" 
                           name="bite_company" 
                           placeholder="Your company (optional)"
                           value="<?php echo isset( $bite_company ) ? esc_attr( $bite_company ) : ''; ?>"
                           autocomplete="organization">
                </div>
                
                <div class="bite-form-group">
                    <label for="bite_plan">Interested Plan <span class="bite-required">*</span></label>
                    <select id="bite_plan" name="bite_plan" required>
                        <option value="">Select a plan...</option>
                        <option value="hosting" <?php selected( ( isset( $bite_plan ) && $bite_plan === 'hosting' ) || ( ! isset( $bite_plan ) && $selected_plan === 'hosting' ) ); ?>>OrangeWidow Hosting Customer (Free)</option>
                        <option value="solo" <?php selected( ( isset( $bite_plan ) && $bite_plan === 'solo' ) || ( ! isset( $bite_plan ) && $selected_plan === 'solo' ) ); ?>>Solo - £29/month (3 websites)</option>
                        <option value="pro" <?php selected( ( isset( $bite_plan ) && $bite_plan === 'pro' ) || ( ! isset( $bite_plan ) && $selected_plan === 'pro' ) ); ?>>Pro - £59/month (10 websites)</option>
                        <option value="agency" <?php selected( ( isset( $bite_plan ) && $bite_plan === 'agency' ) || ( ! isset( $bite_plan ) && $selected_plan === 'agency' ) ); ?>>Agency - £119/month (25 websites)</option>
                        <option value="enterprise" <?php selected( ( isset( $bite_plan ) && $bite_plan === 'enterprise' ) || ( ! isset( $bite_plan ) && $selected_plan === 'enterprise' ) ); ?>>Enterprise - £199/month (Unlimited)</option>
                        <option value="custom" <?php selected( ( isset( $bite_plan ) && $bite_plan === 'custom' ) || ( ! isset( $bite_plan ) && $selected_plan === 'custom' ) ); ?>>Custom Requirements</option>
                    </select>
                </div>
                
                <div class="bite-form-group">
                    <label for="bite_websites">
                        Website(s) You Want to Track
                    </label>
                    <textarea id="bite_websites" 
                              name="bite_websites" 
                              rows="3" 
                              placeholder="Enter the websites you'd like to analyze (one per line)"><?php echo isset( $bite_websites ) ? esc_textarea( $bite_websites ) : ''; ?></textarea>
                </div>
                
                <div class="bite-form-group">
                    <label for="bite_message">Additional Information</label>
                    <textarea id="bite_message" 
                              name="bite_message" 
                              rows="4" 
                              placeholder="Tell us about your needs, how many sites you manage, etc."><?php echo isset( $bite_message ) ? esc_textarea( $bite_message ) : ''; ?></textarea>
                </div>
                
                <div class="bite-form-submit">
                    <button type="submit" name="bite_contact_submit" class="bite-button bite-button-large">
                        <span class="material-icons">send</span>
                        Submit Request
                    </button>
                </div>
                
                <p class="bite-form-note">
                    <span class="material-icons">info</span>
                    We respect your privacy. Your information will only be used to process your access request.
                </p>
            </form>
            
        </div>
    </section>

    <!-- Contact Info Section -->
    <section class="bite-contact-info-section">
        <div class="bite-contact-info-container">
            <div class="bite-contact-info-item">
                <span class="material-icons">email</span>
                <h3>Email Us</h3>
                <p>For general inquiries, contact us at <a href="mailto:info@orangewidow.com">info@orangewidow.com</a></p>
            </div>
            
            <div class="bite-contact-info-item">
                <span class="material-icons">language</span>
                <h3>Visit OrangeWidow</h3>
                <p>Learn more about our services at <a href="https://orangewidow.com" target="_blank">orangewidow.com</a></p>
            </div>
        </div>
    </section>

</main>

<?php
get_footer();
