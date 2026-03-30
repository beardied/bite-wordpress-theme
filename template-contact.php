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
            $name = sanitize_text_field( $_POST['bite_name'] ?? '' );
            $email = sanitize_email( $_POST['bite_email'] ?? '' );
            $company = sanitize_text_field( $_POST['bite_company'] ?? '' );
            $websites = sanitize_textarea_field( $_POST['bite_websites'] ?? '' );
            $message = sanitize_textarea_field( $_POST['bite_message'] ?? '' );
            
            // Validation
            if ( empty( $name ) || empty( $email ) ) {
                $form_error = 'Please fill in all required fields.';
            } elseif ( ! is_email( $email ) ) {
                $form_error = 'Please enter a valid email address.';
            } else {
                // Get recipient email from settings
                $recipient_email = get_option( 'bite_contact_email', get_option( 'admin_email' ) );
                
                // Build email
                $subject = 'BITE Access Request from ' . $name;
                
                $email_body = "New BITE Access Request\n\n";
                $email_body .= "Name: " . $name . "\n";
                $email_body .= "Email: " . $email . "\n";
                $email_body .= "Company: " . ( $company ? $company : 'Not provided' ) . "\n";
                $email_body .= "Websites: " . ( $websites ? $websites : 'Not provided' ) . "\n\n";
                $email_body .= "Message:\n" . ( $message ? $message : 'No additional message' ) . "\n\n";
                $email_body .= "Submitted from: " . home_url( '/contact/' ) . "\n";
                $email_body .= "Date: " . date( 'Y-m-d H:i:s' ) . "\n";
                $email_body .= "IP: " . ( $_SERVER['REMOTE_ADDR'] ?? 'Unknown' ) . "\n";
                
                $headers = array(
                    'From: BITE Contact Form <' . $recipient_email . '>',
                    'Reply-To: ' . $name . ' <' . $email . '>',
                    'Content-Type: text/plain; charset=UTF-8',
                );
                
                // Send email
                $sent = wp_mail( $recipient_email, $subject, $email_body, $headers );
                
                if ( $sent ) {
                    $form_message = 'Thank you for your request! We will review your application and get back to you soon.';
                    // Clear form data after successful submission
                    $name = $email = $company = $websites = $message = '';
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
                               value="<?php echo isset( $name ) ? esc_attr( $name ) : ''; ?>">
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
                               value="<?php echo isset( $email ) ? esc_attr( $email ) : ''; ?>">
                    </div>
                </div>
                
                <div class="bite-form-group">
                    <label for="bite_company">Company Name</label>
                    <input type="text" 
                           id="bite_company" 
                           name="bite_company" 
                           placeholder="Your company (optional)"
                           value="<?php echo isset( $company ) ? esc_attr( $company ) : ''; ?>">
                </div>
                
                <div class="bite-form-group">
                    <label for="bite_websites">
                        Website(s) You Want to Track
                    </label>
                    <textarea id="bite_websites" 
                              name="bite_websites" 
                              rows="3" 
                              placeholder="Enter the websites you'd like to analyze (one per line)"><?php echo isset( $websites ) ? esc_textarea( $websites ) : ''; ?></textarea>
                </div>
                
                <div class="bite-form-group">
                    <label for="bite_message">Additional Information</label>
                    <textarea id="bite_message" 
                              name="bite_message" 
                              rows="4" 
                              placeholder="Tell us about your needs, how many sites you manage, etc."><?php echo isset( $message ) ? esc_textarea( $message ) : ''; ?></textarea>
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
