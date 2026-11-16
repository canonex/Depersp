=== Depersp – raddrizza foto ===
Requires at least: 6.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-3.0-or-later

Raddrizza automaticamente le foto di fogli con cornice scura al caricamento.

== Description ==

Quando un caricamento è marcato per Depersp, il plugin trova la cornice,
elimina la prospettiva e salva in wp-content/uploads solo l'immagine corretta,
già senza dati EXIF. L'originale storto non viene mai salvato.

Quali caricamenti vengono raddrizzati:

* la casella "Raddrizza le foto" in Media > Aggiungi nuovo;
* qualsiasi form che invii il campo depersp=1;
* i caricamenti fatti con depersp_media_handle_upload();
* le regole definite con il filtro depersp_should_process.

Se la cornice non viene trovata, la foto viene caricata così com'è. Il filtro
depersp_on_failure permette invece di rifiutarla.

Richiede l'estensione PHP Imagick.
