<?php
/**
 * PDFMaster - Compress Form Template (FIXED VERSION)
 * 
 * Shortcode template dla narzędzia kompresji PDF
 * Używany w Elementor Pro kartach narzędzi
 * 
 * Available variables:
 * $attributes - tablica z parametrami shortcode
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit('Bezpośredni dostęp zabroniony.');
}

// Sanityzacja attributes z shortcode
$tool_title = esc_html($attributes['title'] ?? 'Kompresuj PDF');
$tool_description = esc_html($attributes['description'] ?? 'Zmniejsz rozmiar pliku PDF o 60-90% bez utraty jakości');
$button_text = esc_html($attributes['button_text'] ?? 'Kompresuj PDF');
$max_file_size = esc_html($attributes['max_size'] ?? '50MB');

// Pobranie aktualnych informacji o kredytach dla zalogowanych użytkowników
$user_credits = '';
$is_logged_in = is_user_logged_in();

if ($is_logged_in && class_exists('PDFMaster_User_Credits')) {
    $credits_manager = new PDFMaster_User_Credits();
    $credits_info = $credits_manager->get_remaining_credits();
    $user_credits = $credits_info['total_available'] ?? 3;
} else {
    // Dla gości - domyślny limit
    $user_credits = 2;
}

// Wygenerowanie unique ID dla tego instance narzędzia
$tool_instance_id = 'pdfmaster-tool-' . uniqid();
?>

<div class="pdfmaster-tool-container" id="<?php echo esc_attr($tool_instance_id); ?>">
    
    <!-- Tool Header - Opcjonalny -->
    <?php if (!empty($tool_title) && $tool_title !== 'none'): ?>
    <div class="tool-header">
        <div class="tool-icon" aria-hidden="true">🗜️</div>
        <h3 class="tool-title"><?php echo $tool_title; ?></h3>
        <?php if (!empty($tool_description) && $tool_description !== 'none'): ?>
        <p class="tool-description"><?php echo $tool_description; ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Hidden file input - OUTSIDE upload area to prevent event bubbling -->
    <input type="file"
           id="compress-file"
           name="pdf_file"
           accept=".pdf,application/pdf"
           aria-label="Wybierz plik PDF do kompresji"
           data-max-size="<?php echo esc_attr(str_replace('MB', '', $max_file_size)); ?>">

    <!-- Main Upload Interface -->
    <div class="pdf-upload-area"
         role="button"
         tabindex="0"
         aria-label="Kliknij lub przeciągnij plik PDF do kompresji"
         data-max-size="<?php echo esc_attr($max_file_size); ?>"
         style="cursor: pointer; user-select: none; -webkit-user-select: none;">

        <div class="upload-icon" aria-hidden="true">📁</div>
        <div class="upload-text">Przeciągnij PDF lub kliknij aby wybrać</div>
        <div class="upload-subtext">Maksymalny rozmiar: <?php echo $max_file_size; ?></div>
    </div>
    
    <!-- Compression Options -->
    <div class="compression-options">
        <div class="option-group">
            <label for="compression-level-<?php echo esc_attr($tool_instance_id); ?>" class="option-label">
                Poziom kompresji:
            </label>
            <select id="compression-level-<?php echo esc_attr($tool_instance_id); ?>" 
                    class="compression-level-select"
                    name="compression_level"
                    aria-describedby="compression-description-<?php echo esc_attr($tool_instance_id); ?>">
                <option value="1">Niska kompresja (najlepsza jakość)</option>
                <option value="2" selected>Średnia kompresja (zbalansowana)</option>
                <option value="3">Wysoka kompresja (najmniejszy rozmiar)</option>
            </select>
            <div id="compression-description-<?php echo esc_attr($tool_instance_id); ?>" 
                 class="compression-description">
                <strong>Średnia kompresja - zbalansowana</strong><br>
                <small>Oczekiwana redukcja: 40-60%</small>
            </div>
        </div>
    </div>
    
    <!-- Progress Bar (hidden initially) -->
    <div class="progress-bar" 
         role="progressbar" 
         aria-valuemin="0" 
         aria-valuemax="100" 
         aria-valuenow="0"
         aria-label="Postęp kompresji PDF">
        <div class="progress-fill"></div>
    </div>
    
    <!-- Process Button -->
    <button type="button" 
            class="process-btn" 
            disabled
            aria-describedby="credits-info-<?php echo esc_attr($tool_instance_id); ?>">
        Wybierz plik PDF
    </button>
    
    <!-- Credits Information -->
    <div class="credits-info" id="credits-info-<?php echo esc_attr($tool_instance_id); ?>">
        <div class="credits-text">
            <?php if ($is_logged_in): ?>
                Pozostało: <span class="credits-count"><?php echo esc_html($user_credits); ?></span> operacji
            <?php else: ?>
                Pozostało: <span class="credits-count"><?php echo esc_html($user_credits); ?></span> darmowych operacji
                <div class="guest-upgrade-hint">
                    <small><a href="<?php echo wp_registration_url(); ?>">Zarejestruj się</a> aby otrzymać więcej darmowych operacji</small>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Error Container (hidden initially) -->
    <div class="error-message" role="alert" aria-live="polite" style="display: none;">
        <!-- Error content will be inserted by JavaScript -->
    </div>
    
    <!-- Success Message Container (hidden initially) -->
    <div class="success-message" role="status" aria-live="polite" style="display: none;">
        <!-- Success content will be inserted by JavaScript -->
    </div>
    
    <!-- Accessibility helpers -->
    <div class="pdfmaster-sr-only" aria-live="polite" aria-atomic="true">
        <!-- Screen reader announcements will be inserted here by JavaScript -->
    </div>
    
</div>

<!-- Schema.org structured data for better SEO -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    "name": "PDFMaster - Kompresja PDF",
    "description": "<?php echo esc_js($tool_description); ?>",
    "applicationCategory": "UtilitiesApplication",
    "operatingSystem": "Web Browser",
    "offers": {
        "@type": "Offer",
        "price": "0",
        "priceCurrency": "PLN",
        "description": "Darmowe operacje PDF online"
    }
}
</script>

<?php
// WordPress Action Hooks dla rozszerzalności
do_action('pdfmaster_before_tool', 'compress', $attributes);
do_action('pdfmaster_after_tool', 'compress', $attributes);

// Debug info
if (defined('WP_DEBUG') && WP_DEBUG) {
    echo '<!-- PDFMaster Debug Info:';
    echo ' User ID: ' . (get_current_user_id() ?: 'guest');
    echo ', Credits: ' . esc_html($user_credits);
    echo ', Tool Instance: ' . esc_html($tool_instance_id);
    echo ' -->';
}
?>

<style>
/* Critical CSS - tylko najważniejsze elementy */
.pdfmaster-tool-container {
    position: relative;
    max-width: 100%;
}

/* CRITICAL: Hide file input */
#compress-file {
    display: none !important;
}

/* Graceful degradation jeśli main CSS nie załaduje się */
.pdf-upload-area {
    border: 2px dashed #ccc;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
}

.process-btn {
    width: 100%;
    padding: 15px;
    background: #667eea;
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
}

.process-btn:disabled {
    background: #ccc;
    cursor: not-allowed;
}

/* Responsive base */
@media (max-width: 600px) {
    .pdfmaster-tool-container {
        padding: 10px;
    }
}
</style>