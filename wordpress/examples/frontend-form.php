<?php
/**
 * Plugin Name: Depersp – esempio di form pubblico
 * Description: Shortcode [depersp_form]: un form che carica una foto, la raddrizza e la salva nella libreria media. Esempio da adattare.
 *
 * Copy into wp-content/mu-plugins/ (or paste into your theme/plugin) with the
 * Depersp plugin active.
 */

defined('ABSPATH') || exit;

add_shortcode('depersp_form', static function (): string {
    if (!is_user_logged_in() || !current_user_can('upload_files')) {
        return '<p>' . esc_html__('Accedi per caricare una foto.', 'depersp') . '</p>';
    }

    $message = '';
    if (isset($_POST['depersp_form_nonce'])
        && wp_verify_nonce(sanitize_key(wp_unslash($_POST['depersp_form_nonce'])), 'depersp_form')
        && !empty($_FILES['foto']['name'])
    ) {
        // Like media_handle_upload(), but the photo is rectified before it is saved.
        $id = depersp_media_handle_upload('foto', (int) get_the_ID());

        if (is_wp_error($id)) {
            $message = '<p class="depersp-error">' . esc_html($id->get_error_message()) . '</p>';
        } else {
            $message = '<p class="depersp-ok">' . esc_html__('Foto caricata:', 'depersp') . '</p>'
                . wp_get_attachment_image($id, 'medium');
        }
    }

    ob_start();
    ?>
    <?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above ?>
    <form method="post" enctype="multipart/form-data" class="depersp-form">
        <?php wp_nonce_field('depersp_form', 'depersp_form_nonce'); ?>
        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" capture="environment" required>
        <button type="submit"><?php esc_html_e('Carica e raddrizza', 'depersp'); ?></button>
    </form>
    <?php
    return (string) ob_get_clean();
});

// Optional: refuse photos without a frame instead of storing them crooked.
add_filter('depersp_on_failure', static fn () => 'reject');
