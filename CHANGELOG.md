# Changelog

## 1.1.0 – unreleased

First public release (library and WordPress plugin share the version).


- Rewritten as a PHP library (`Depersp\Rectifier`, `Depersp\QuadDetector`):
  pure PHP + Imagick, no shell scripts, no `exec()`.
- New contour-based frame detection with several increasingly tolerant passes
  (thin, faint or slightly broken frames); sub-pixel corners.
- Real aspect ratio of the sheet estimated from the perspective.
- `rectifyBlob()`: fully in-memory processing, nothing written to disk.
- Hardened input handling: content-sniffed MIME, forced decoder, size and
  pixel limits, ImageMagick resource limits; EXIF stripped from the output.
- `bin/depersp` command line tool.
- WordPress plugin (`wordpress/`): rectifies selected uploads before they are
  stored in `uploads` (checkbox, form field, `depersp_media_handle_upload()`,
  `depersp_should_process` filter); `php wordpress/build.php` builds the zip.
- GitHub Actions: tests on every push; pushing a `v*` tag publishes a GitHub
  Release with the plugin zip attached.
- WordPress plugin 1.1.0: the uploader checkbox is now a per-user preference
  that works in every uploader (it had no effect in the Media Library and
  "Add media" window, and its inline script broke the uploader template);
  debug log with WP_DEBUG / DEPERSP_DEBUG; Tools > Depersp diagnostics page
  with a self-test.
- Removed: `A-discoverEdges.sh`, `B-undistort.sh`, `index.php`,
  `intersection.php` (legacy `depersp()` function).
