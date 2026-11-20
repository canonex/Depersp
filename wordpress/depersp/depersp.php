<?php
/**
 * Plugin Name:       Depersp – raddrizza foto
 * Description:       Raddrizza automaticamente, eliminando la prospettiva, le foto di fogli con cornice scura al momento del caricamento. Nella libreria media viene salvata solo l'immagine corretta.
 * Version:           1.1.0
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
 *  - the "Raddrizza le foto" checkbox in any media uploader: it is a per-user
 *    preference saved on the server, so it applies to every upload by that
 *    user (Media > Add New, Media Library, "Add media" window, block editor);
 *  - request field `depersp=1` (forms of yours);
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
 *
 * Debug log: enabled when WP_DEBUG (or DEPERSP_DEBUG) is true. Entries go to
 * the PHP error log (wp-content/debug.log with WP_DEBUG_LOG) and to
 * Tools > Depersp.
 */
final class Depersp_WP
{
    public const VERSION = '1.1.0';
    public const FIELD = 'depersp';
    private const USER_META = 'depersp_enabled';
    private const LOG_OPTION = 'depersp_log';
    private const LOG_MAX = 100;
    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Set by depersp_media_handle_upload() to force processing. */
    private static bool $forced = false;

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'adminMenu']);
        add_action('admin_post_depersp_selftest', [self::class, 'handleSelfTest']);
        add_action('admin_post_depersp_clear_log', [self::class, 'handleClearLog']);

        $problem = self::environmentProblem();
        if ($problem !== null) {
            self::log('Plugin inactive: ' . $problem);
            add_action('admin_notices', [self::class, 'missingNotice']);

            return;
        }
        add_filter('wp_handle_upload_prefilter', [self::class, 'prefilter']);
        add_filter('wp_handle_sideload_prefilter', [self::class, 'prefilter']);
        add_action('post-plupload-upload-ui', [self::class, 'uploaderCheckbox']);
        add_action('post-html-upload-ui', [self::class, 'uploaderCheckbox']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueScripts']);
        add_action('wp_ajax_depersp_set_pref', [self::class, 'ajaxSetPreference']);
    }

    public static function environmentProblem(): ?string
    {
        if (!extension_loaded('imagick')) {
            return 'the PHP Imagick extension is not loaded in the web server PHP';
        }
        if (!class_exists(\Depersp\Rectifier::class)) {
            return 'Depersp library not found (lib/ folder missing from the plugin, and not installed with Composer)';
        }

        return null;
    }

    // ------------------------------------------------------------------ upload

    /**
     * Runs before WordPress moves the upload into wp-content/uploads: the
     * temporary file is replaced by the rectified image, so the crooked
     * original is never stored.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int|string,size:int} $file
     */
    public static function prefilter(array $file): array
    {
        $ctx = [
            'hook' => current_filter(),
            'name' => $file['name'] ?? '',
            'size' => $file['size'] ?? null,
            'user' => get_current_user_id(),
        ];
        if (!empty($file['error'])) {
            self::log('Skipped: WordPress already reports an upload error', $ctx + ['error' => $file['error']]);

            return $file;
        }

        [$process, $why] = self::decide($file);
        if (!$process) {
            self::log('Skipped: ' . $why, $ctx);

            return $file;
        }
        self::log('Processing (' . $why . ')', $ctx);

        try {
            $bytes = file_get_contents($file['tmp_name']);
            if ($bytes === false) {
                throw new \Depersp\InvalidImageException('Cannot read the uploaded file ' . $file['tmp_name']);
            }
            $format = (string) apply_filters('depersp_output_format', 'jpg', $file);
            $rectifier = apply_filters('depersp_rectifier', new \Depersp\Rectifier(maxOutputSide: 2560), $file);
            $result = $rectifier->rectifyBlob($bytes, $format);
            unset($bytes);

            if (file_put_contents($file['tmp_name'], $result->data) === false) {
                throw new \Depersp\DeperspException('Cannot write the temporary file ' . $file['tmp_name']);
            }
        } catch (\Throwable $e) {
            do_action('depersp_failed', $file, $e);
            $policy = apply_filters('depersp_on_failure', 'keep', $file, $e);
            self::log('FAILED: ' . get_class($e) . ': ' . $e->getMessage(), $ctx + [
                'policy' => $policy === 'reject' ? 'reject (upload refused)' : 'keep (original uploaded unchanged)',
                'at' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            if ($policy === 'reject') {
                $file['error'] = $e instanceof \Depersp\DetectionException
                    ? __('Cornice non trovata: fotografa il foglio inquadrando tutti e quattro gli angoli.', 'depersp')
                    : __('Impossibile raddrizzare l\'immagine.', 'depersp');
            }

            return $file;
        }

        $file['size'] = strlen($result->data);
        $file['type'] = $result->mimeType;
        // The extension must match the new content, or WordPress refuses the file.
        $file['name'] = pathinfo($file['name'], PATHINFO_FILENAME) . '.' . ($format === 'jpeg' ? 'jpg' : $format);

        self::log('Rectified', $ctx + [
            'saved_as' => $file['name'],
            'output' => $result->width . 'x' . $result->height,
            'seconds' => round($result->seconds, 2),
        ]);
        do_action('depersp_processed', $file, $result);

        return $file;
    }

    /** @return array{0:bool,1:string} decision and reason (for the log) */
    private static function decide(array $file): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'unknown';
        $isImage = in_array($mime, self::MIME_TYPES, true);

        // phpcs:ignore WordPress.Security.NonceVerification -- only a preference for the user's own, already authorised upload.
        $field = filter_var(wp_unslash($_REQUEST[self::FIELD] ?? ''), FILTER_VALIDATE_BOOLEAN);
        $preference = self::userEnabled();

        $sources = array_keys(array_filter([
            'depersp_media_handle_upload()' => self::$forced,
            'request field depersp=1' => $field,
            'user preference checkbox' => $preference,
        ]));
        $default = $isImage && $sources !== [];
        $process = (bool) apply_filters('depersp_should_process', $default, $file);

        if ($process && !$isImage) {
            return [false, "file type $mime is not JPEG/PNG/WebP"];
        }
        if ($process) {
            return [true, $default ? implode(', ', $sources) . ", $mime" : "depersp_should_process filter, $mime"];
        }
        if ($default) {
            return [false, 'the depersp_should_process filter returned false'];
        }
        if (!$isImage) {
            return [false, "file type $mime is not JPEG/PNG/WebP"];
        }

        return [false, 'not requested (checkbox not ticked for this user, no depersp=1 field, no depersp_should_process rule)'];
    }

    private static function userEnabled(): bool
    {
        $id = get_current_user_id();

        return $id > 0 && get_user_meta($id, self::USER_META, true) === '1';
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

    // ------------------------------------------------------------- uploader UI

    /**
     * Checkbox in the uploaders. This hook also runs inside the media modal
     * <script type="text/html"> template, so it must print plain HTML only
     * (no <script>, no unique id): the behaviour lives in assets/admin.js.
     */
    public static function uploaderCheckbox(): void
    {
        if (!current_user_can('upload_files')) {
            return;
        }
        printf(
            '<p class="depersp-option"><label><input type="checkbox" class="depersp-toggle" name="%s" value="1"%s> %s</label></p>',
            esc_attr(self::FIELD),
            self::userEnabled() ? ' checked' : '',
            esc_html__('Raddrizza le foto (foglio con cornice scura)', 'depersp')
        );
    }

    public static function enqueueScripts(): void
    {
        if (!current_user_can('upload_files')) {
            return;
        }
        wp_enqueue_script('depersp-admin', plugins_url('assets/admin.js', __FILE__), [], self::VERSION, true);
        wp_localize_script('depersp-admin', 'deperspSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('depersp_pref'),
            'field' => self::FIELD,
        ]);
    }

    public static function ajaxSetPreference(): void
    {
        check_ajax_referer('depersp_pref', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error('forbidden', 403);
        }
        $enabled = !empty($_POST['enabled']) && $_POST['enabled'] === '1';
        update_user_meta(get_current_user_id(), self::USER_META, $enabled ? '1' : '0');
        self::log('User preference changed', ['user' => get_current_user_id(), 'enabled' => $enabled]);
        wp_send_json_success(['enabled' => $enabled]);
    }

    // ------------------------------------------------------------------ logging

    public static function debugEnabled(): bool
    {
        return (defined('WP_DEBUG') && WP_DEBUG) || (defined('DEPERSP_DEBUG') && DEPERSP_DEBUG);
    }

    /** Write a debug entry (only when WP_DEBUG or DEPERSP_DEBUG is on). */
    public static function log(string $message, array $context = []): void
    {
        if (!self::debugEnabled()) {
            return;
        }
        $line = $message . ($context ? ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
        error_log('[Depersp] ' . $line); // phpcs:ignore WordPress.PHP.DevelopmentFunctions

        $entries = get_option(self::LOG_OPTION, []);
        $entries = is_array($entries) ? $entries : [];
        $entries[] = [gmdate('Y-m-d H:i:s'), $line];
        update_option(self::LOG_OPTION, array_slice($entries, -self::LOG_MAX), false);
    }

    // ------------------------------------------------------- Tools > Depersp

    public static function adminMenu(): void
    {
        add_management_page('Depersp', 'Depersp', 'manage_options', 'depersp', [self::class, 'renderPage']);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $rows = [
            'Plugin' => self::VERSION,
            'PHP' => PHP_VERSION . (version_compare(PHP_VERSION, '8.1', '<') ? ' ✗ (serve 8.1+)' : ''),
            'Estensione Imagick' => extension_loaded('imagick') ? phpversion('imagick') : '✗ non caricata',
            'ImageMagick' => class_exists(Imagick::class) ? Imagick::getVersion()['versionString'] : '—',
            'Formati JPEG/PNG/WebP' => class_exists(Imagick::class) ? self::formatsStatus() : '—',
            'Libreria Depersp' => class_exists(\Depersp\Rectifier::class)
                ? (new ReflectionClass(\Depersp\Rectifier::class))->getFileName()
                : '✗ non trovata',
            'memory_limit / upload_max_filesize' => ini_get('memory_limit') . ' / ' . ini_get('upload_max_filesize'),
            'La tua preferenza "Raddrizza le foto"' => self::userEnabled() ? 'attiva' : 'non attiva',
            'Log' => self::debugEnabled()
                ? 'attivo → ' . self::logDestination()
                : 'spento: aggiungete define(\'WP_DEBUG\', true); (o DEPERSP_DEBUG) in wp-config.php',
        ];

        echo '<div class="wrap"><h1>Depersp</h1>';
        if (isset($_GET['selftest'])) { // phpcs:ignore WordPress.Security.NonceVerification -- display only
            $ok = $_GET['selftest'] === 'ok'; // phpcs:ignore
            printf(
                '<div class="notice notice-%s"><p>%s</p></div>',
                $ok ? 'success' : 'error',
                esc_html((string) get_transient('depersp_selftest_' . get_current_user_id()))
            );
        }
        echo '<h2>Ambiente</h2><table class="widefat striped" style="max-width:60rem"><tbody>';
        foreach ($rows as $k => $v) {
            printf('<tr><th style="width:18rem">%s</th><td>%s</td></tr>', esc_html($k), esc_html((string) $v));
        }
        echo '</tbody></table>';

        echo '<h2>Autotest</h2><p>Genera in memoria la foto di un foglio in prospettiva e prova a raddrizzarla, senza salvare nulla.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('depersp_selftest');
        echo '<input type="hidden" name="action" value="depersp_selftest">';
        submit_button('Esegui autotest', 'secondary', 'submit', false);
        echo '</form>';

        echo '<h2>Log recenti</h2>';
        $entries = get_option(self::LOG_OPTION, []);
        if (!self::debugEnabled()) {
            echo '<p>I log sono spenti. Per attivarli, in <code>wp-config.php</code>:</p>'
                . '<pre>define(\'WP_DEBUG\', true);\ndefine(\'WP_DEBUG_LOG\', true);      // scrive anche in wp-content/debug.log\ndefine(\'WP_DEBUG_DISPLAY\', false); // non mostra gli errori ai visitatori</pre>'
                . '<p>Per i soli log di Depersp, senza WP_DEBUG: <code>define(\'DEPERSP_DEBUG\', true);</code></p>';
        }
        if (is_array($entries) && $entries) {
            echo '<table class="widefat striped"><thead><tr><th style="width:11rem">UTC</th><th>Evento</th></tr></thead><tbody>';
            foreach (array_reverse($entries) as [$time, $line]) {
                printf('<tr><td>%s</td><td><code style="white-space:pre-wrap">%s</code></td></tr>', esc_html($time), esc_html($line));
            }
            echo '</tbody></table>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:1em">';
            wp_nonce_field('depersp_clear_log');
            echo '<input type="hidden" name="action" value="depersp_clear_log">';
            submit_button('Svuota log', 'delete', 'submit', false);
            echo '</form>';
        } elseif (self::debugEnabled()) {
            echo '<p>Nessun evento registrato: caricate una foto e ricaricate questa pagina.</p>';
        }
        echo '</div>';
    }

    private static function formatsStatus(): string
    {
        $have = Imagick::queryFormats();
        $out = [];
        foreach (['JPEG', 'PNG', 'WEBP'] as $f) {
            $out[] = $f . (in_array($f, $have, true) ? ' ✓' : ' ✗');
        }

        return implode('  ', $out);
    }

    private static function logDestination(): string
    {
        $dest = 'questa pagina';
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $dest .= ' + ' . (is_string(WP_DEBUG_LOG) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log');
        } elseif (ini_get('error_log')) {
            $dest .= ' + ' . ini_get('error_log');
        }

        return $dest;
    }

    public static function handleSelfTest(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('forbidden', 403);
        }
        check_admin_referer('depersp_selftest');

        $ok = false;
        try {
            $problem = self::environmentProblem();
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }
            $result = (new \Depersp\Rectifier())->rectifyBlob(self::syntheticPhoto(), 'jpg');
            $ratio = $result->width / $result->height;
            $ok = abs($ratio / 0.7071 - 1) < 0.06;
            $message = sprintf(
                '%s: foglio A4 trovato e raddrizzato in %.2f s (%d×%d, proporzioni %.3f, attese 0.707).',
                $ok ? 'Autotest riuscito' : 'Autotest: proporzioni inattese',
                $result->seconds,
                $result->width,
                $result->height,
                $ratio
            );
        } catch (Throwable $e) {
            $message = 'Autotest fallito: ' . get_class($e) . ': ' . $e->getMessage();
        }
        self::log($message);
        set_transient('depersp_selftest_' . get_current_user_id(), $message, 120);
        wp_safe_redirect(admin_url('tools.php?page=depersp&selftest=' . ($ok ? 'ok' : 'fail')));
        exit;
    }

    /** A JPEG of an A4 sheet with a dark frame seen in perspective, built in memory. */
    private static function syntheticPhoto(): string
    {
        $img = new Imagick();
        $img->newImage(1200, 900, new ImagickPixel('#b9b4aa'));
        $draw = new ImagickDraw();
        $pt = static fn ($x, $y) => ['x' => $x, 'y' => $y];
        $draw->setFillColor(new ImagickPixel('#f4f2ee'));   // paper
        $draw->polygon([$pt(330, 60), $pt(880, 90), $pt(960, 860), $pt(250, 840)]);
        $draw->setFillColor(new ImagickPixel('#151515'));   // frame
        $draw->polygon([$pt(380, 110), $pt(830, 135), $pt(895, 800), $pt(310, 785)]);
        $draw->setFillColor(new ImagickPixel('#f4f2ee'));
        $draw->polygon([$pt(398, 128), $pt(813, 151), $pt(875, 782), $pt(330, 767)]);
        $img->drawImage($draw);
        $img->setImageFormat('JPEG');

        return $img->getImageBlob();
    }

    public static function handleClearLog(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('forbidden', 403);
        }
        check_admin_referer('depersp_clear_log');
        delete_option(self::LOG_OPTION);
        wp_safe_redirect(admin_url('tools.php?page=depersp'));
        exit;
    }

    public static function missingNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
            esc_html('Depersp non è attivo: ' . (self::environmentProblem() ?? '')),
            esc_url(admin_url('tools.php?page=depersp')),
            esc_html__('Diagnostica', 'depersp')
        );
    }
}

if (!function_exists('depersp_media_handle_upload')) {
    /**
     * Template helper: like media_handle_upload(), but the image is rectified.
     *
     * @return int|WP_Error
     */
    function depersp_media_handle_upload(string $fileKey, int $postId = 0, array $postData = [], array $overrides = ['test_form' => false])
    {
        return Depersp_WP::mediaHandleUpload($fileKey, $postId, $postData, $overrides);
    }
}

add_action('plugins_loaded', [Depersp_WP::class, 'boot']);
