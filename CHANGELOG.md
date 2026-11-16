# Changelog

## 1.0.0 – unreleased

- Rewritten as a PHP library (`Depersp\Rectifier`, `Depersp\QuadDetector`):
  pure PHP + Imagick, no shell scripts, no `exec()`.
- New contour-based frame detection with several increasingly tolerant passes
  (thin, faint or slightly broken frames); sub-pixel corners.
- Real aspect ratio of the sheet estimated from the perspective.
- `rectifyBlob()`: fully in-memory processing, nothing written to disk.
- Hardened input handling: content-sniffed MIME, forced decoder, size and
  pixel limits, ImageMagick resource limits; EXIF stripped from the output.
- `bin/depersp` command line tool.
- Removed: `A-discoverEdges.sh`, `B-undistort.sh`, `index.php`,
  `intersection.php` (legacy `depersp()` function).
