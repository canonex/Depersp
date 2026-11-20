=== Depersp – raddrizza foto ===
Requires at least: 6.0
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPL-3.0-or-later

Raddrizza automaticamente le foto di fogli con cornice scura al caricamento.

== Description ==

Quando un caricamento è marcato per Depersp, il plugin trova la cornice,
elimina la prospettiva e salva in wp-content/uploads solo l'immagine corretta,
già senza dati EXIF. L'originale storto non viene mai salvato.

Quali caricamenti vengono raddrizzati:

* la casella "Raddrizza le foto" negli uploader: è una preferenza dell'utente,
  valida per tutti i suoi caricamenti, editor a blocchi compreso;
* qualsiasi form che invii il campo depersp=1;
* i caricamenti fatti con depersp_media_handle_upload();
* le regole definite con il filtro depersp_should_process.

Se la cornice non viene trovata, la foto viene caricata così com'è. Il filtro
depersp_on_failure permette invece di rifiutarla.

Richiede l'estensione PHP Imagick.

Diagnostica in Strumenti > Depersp. I log si attivano con WP_DEBUG oppure con
DEPERSP_DEBUG.

== Changelog ==

= 1.1.0 =
* Corretto: nella Libreria media e nella finestra "Aggiungi media" la casella
  non aveva effetto e rompeva il template dell'uploader.
* La casella diventa una preferenza per utente, valida per ogni uploader.
* Log di debug (WP_DEBUG / DEPERSP_DEBUG) e pagina Strumenti > Depersp con
  autotest.
