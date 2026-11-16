<?php
/**
 * Plugin Name:       Depersp – raddrizza foto
 * Description:       Raddrizza automaticamente, eliminando la prospettiva, le foto di fogli con cornice scura al momento del caricamento. Nella libreria media viene salvata solo l'immagine corretta.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * License:           GPL-3.0-or-later
 * Text Domain:       depersp
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// The library: bundled in lib/ (see build.php) or already loaded by Composer.
if (!class_exists(\Depersp\Rectifier::class) && is_readable(__DIR__ . '/lib/autoload.php')) {
    require_once __DIR__ . '/lib/autoload.php';
}

/**
 * Which uploads are rectified:
 *  - request field `depersp=1` (the checkbox on Media > Add New, or any form of yours);
 *  - uploads made through depersp_media_handle_upload();
 *  - anything you decide with the `depersp_should_process` filter.
 *
 * Hooks:
 *  filter depersp_should_process (bool $process, array $file)        decide per upload
 *  filter depersp_output_format  (string 'jpg', array $file)         jpg | png | webp
 *  filter depersp_rectifier      (Rectifier $r, array $file)         custom options
 *  filter depersp_on_failure     (string 'keep', array $file, Throwable $e)  keep | reject
 *  action depersp_processed      (array $file, Result $result)
 *  action depersp_failed         (array $file, Throwable $e)
 */
final class Depersp_WP
{
    public const FIELD = 'depersp';

    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Set by depersp_media_handle_upload() to force processing. */
    private static bool $forced = false;

    public static function boot(): void
    {
        if (!class_exists(\Depersp\Rectifier::class) || !extension_loaded('imagick')) {
            add_action('admin_notices', [self::class, 'missingNotice']);

            return;
        }
        add_filter('wp_handle_upload_prefilter', [self::class, 'prefilter']);
        add_filter('wp_handle_sideload_prefilter', [self::class, 'prefilter']);
        add_action('post-plupload-upload-ui', [self::class, 'uploaderCheckbox']);
        add_action('post-html-upload-ui', [self::class, 'uploaderCheckbox']);
    }

    /**
     * Runs before WordPress moves the upload into wp-content/uploads: the
     * temporary file is replaced by the rectified image, so the crooked
     * original is never stored.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int|string,size:int} $file
     */
    public static function prefilter(array $file): array
    {
        if (!empty($file['error']) || !self::shouldProcess($file)) {
            return $file;
        }

        try {
            $bytes = file_get_contents($file['tmp_name']);
            if ($bytes === false) {
                throw new \Depersp\InvalidImageException('Cannot read the uploaded file.');
            }
            $format = (string) apply_filters('depersp_output_format', 'jpg', $file);
            $rectifier = apply_filters('depersp_rectifier', new \Depersp\Rectifier(maxOutputSide: 2560), $file);
            $result = $rectifier->rectifyBlob($bytes, $format);
            unset($bytes);

            if (file_put_contents($file['tmp_name'], $result->data) === false) {
                throw new \Depersp\DeperspException('Cannot write the temporary file.');
            }
        } catch (\Throwable $e) {
            do_action('depersp_failed', $file, $e);
            if (apply_filters('depersp_on_failure', 'keep', $file, $e) === 'reject') {
                $file['error'] = $e instanceof \Depersp\DetectionException
                    ? __('Cornice non trovata: fotografa il foglio inquadrando tutti e quattro gli angoli.', 'depersp')
                    : __('Impossibile raddrizzare l\'immagine.', 'depersp');
            }

            return $file; // 'keep': the photo is uploaded unchanged
        }

        $file['size'] = strlen($result->data);
        $file['type'] = $result->mimeType;
        // The extension must match the new content, or WordPress refuses the file.
        $file['name'] = pathinfo($file['name'], PATHINFO_FILENAME) . '.' . ($format === 'jpeg' ? 'jpg' : $format);

        do_action('depersp_processed', $file, $result);

        return $file;
    }

    private static function shouldProcess(array $file): bool
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $requested = self::$forced
            // phpcs:ignore WordPress.Security.NonceVerification -- only a preference for the user's own, already authorised upload.
            || filter_var(wp_unslash($_REQUEST[self::FIELD] ?? ''), FILTER_VALIDATE_BOOLEAN);

        return (bool) apply_filters('depersp_should_process', $requested && in_array($mime, self::MIME_TYPES, true), $file);
    }

    /**
     * Upload a file from a form of yours, rectifying it, and create the
     * attachment. Same signature and return value as media_handle_upload().
     *
     * @return int|\WP_Error attachment ID
     */
    public static function mediaHandleUpload(string $fileKey, int $postId = 0, array $postData = [], array $overrides = ['test_form' => false])
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        self::$forced = true;
        try {
            return media_handle_upload($fileKey, $postId, $postData, $overrides);
        } finally {
            self::$forced = false;
        }
    }

    /** Checkbox on Media > Add New (multi-file and browser uploader). */
    public static function uploaderCheckbox(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
        ?>
        <p class="depersp-option">
            <label>
                <input type="checkbox" name="<?php echo esc_attr(self::FIELD); ?>" value="1" id="depersp-toggle">
                <?php esc_html_e('Raddrizza le foto (foglio con cornice scura)', 'depersp'); ?>
            </label>
        </p>
        <script>
        (function () {
            var box = document.getElementById('depersp-toggle');
            box && box.addEventListener('change', function () {
                // The multi-file uploader only sends its own multipart_params.
                if (window.uploader && window.uploader.settings) {
                    window.uploader.settings.multipart_params = window.uploader.settings.multipart_params || {};
                    window.uploader.settings.multipart_params.<?php echo esc_js(self::FIELD); ?> = box.checked ? '1' : '';
                }
            });
        })();
        </script>
        <?php
    }

    public static function missingNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Depersp: servono l\'estensione PHP Imagick e la libreria Depersp (cartella lib/ del plugin o Composer).', 'depersp')
            . '</p></div>';
    }
}

/**
 * Template helper: like media_handle_upload(), but the image is rectified.
 *
 * @return int|WP_Error
 */
if (!function_exists('depersp_media_handle_upload')) {
    function depersp_media_handle_upload(string $fileKey, int $postId = 0, array $postData = [], array $overrides = ['test_form' => false])
    {
        return Depersp_WP::mediaHandleUpload($fileKey, $postId, $postData, $overrides);
    }
}

add_action('plugins_loaded', [Depersp_WP::class, 'boot']);
