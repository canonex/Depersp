# Depersp per WordPress

Plugin che raddrizza le foto **durante il caricamento**. Nella cartella
`wp-content/uploads` viene salvata solo l'immagine corretta, con le sue
miniature; l'originale storto non viene mai scritto.

## Come funziona

Il plugin si aggancia ai filtri `wp_handle_upload_prefilter` e
`wp_handle_sideload_prefilter`, che WordPress esegue *prima* di spostare il
file caricato in `uploads`. Se il caricamento va raddrizzato, il plugin:

1. sostituisce il file temporaneo con l'immagine raddrizzata, elaborata in
   memoria con `Rectifier::rectifyBlob()` e già priva di EXIF;
2. aggiorna nome, tipo e dimensione del file, per esempio `foto.png` diventa
   `foto.jpg`.

Da lì in poi WordPress procede come sempre: salva il file, crea l'allegato e
genera le miniature.

## Installazione

Serve l'estensione PHP **Imagick** sul server.

```bash
php wordpress/build.php        # crea dist/depersp-wp-1.1.0.zip
```

In WordPress andate in *Plugin › Aggiungi nuovo › Carica plugin*, scegliete lo
zip e attivatelo.

Lo zip contiene una copia della libreria nella cartella `lib/`. Se il sito usa
già Composer (`composer require crickyx/depersp`), basta copiare in
`wp-content/plugins/depersp/` il solo file `depersp.php`.

## Quali caricamenti vengono raddrizzati

Solo quelli che lo richiedono esplicitamente. Tutti gli altri restano
invariati.

| Modo | Uso |
|---|---|
| Casella "Raddrizza le foto" | compare in ogni uploader: *Media › Aggiungi nuovo*, Libreria media, finestra "Aggiungi media". È una **preferenza dell'utente** salvata sul server: finché resta spuntata vale per tutti i suoi caricamenti, anche dall'editor a blocchi |
| Campo `depersp=1` nella richiesta | in qualsiasi form vostro che usi le funzioni di upload di WordPress |
| `depersp_media_handle_upload('campo')` | come `media_handle_upload()`, ma raddrizza sempre |
| Filtro `depersp_should_process` | qualsiasi regola vostra (vedi sotto) |

Vengono considerati solo JPEG, PNG e WebP: gli altri file passano senza
modifiche.

### Form sul sito pubblico

[examples/frontend-form.php](examples/frontend-form.php) è un esempio completo:
lo shortcode `[depersp_form]` carica la foto, la raddrizza, la salva nella
libreria media e la mostra. Il cuore è una riga:

```php
$id = depersp_media_handle_upload('foto', $post_id);   // int oppure WP_Error
```

### Regole personalizzate (`depersp_should_process`)

```php
// Tutte le immagini caricate in un certo tipo di contenuto
add_filter('depersp_should_process', function (bool $process, array $file): bool {
    $parent = (int) ($_REQUEST['post_id'] ?? $_REQUEST['post'] ?? 0);
    return $process || ($parent && get_post_type($parent) === 'scheda');
}, 10, 2);

// I file il cui nome inizia con "scan-"
add_filter('depersp_should_process', fn ($process, $file) =>
    $process || str_starts_with($file['name'], 'scan-'), 10, 2);

// Tutto ciò che caricano gli utenti con un certo ruolo
add_filter('depersp_should_process', fn ($process) =>
    $process || current_user_can('studente'));
```

La casella vale per l'utente che la spunta. Per raddrizzare i caricamenti di
tutti gli utenti, o in base al contesto, usate una regola come quelle sopra.

## Altri filtri e azioni

| Hook | Default | Uso |
|---|---|---|
| `depersp_on_failure` | `'keep'` | `'keep'` carica la foto così com'è se la cornice non viene trovata; `'reject'` blocca il caricamento con un messaggio per l'utente |
| `depersp_output_format` | `'jpg'` | `'jpg'`, `'png'` o `'webp'` |
| `depersp_rectifier` | `new Rectifier(maxOutputSide: 2560)` | per cambiare le opzioni della libreria, per esempio `outputWidth`/`outputHeight` o `quality` |
| `depersp_processed` (azione) | | `($file, Result $result)` dopo un raddrizzamento riuscito |
| `depersp_failed` (azione) | | `($file, Throwable $e)` quando non riesce, utile per il log |

```php
// Rifiuta le foto senza cornice e salva in WebP
add_filter('depersp_on_failure', fn () => 'reject');
add_filter('depersp_output_format', fn () => 'webp');

// Uscita sempre 1240×1754 (A4 a 150 dpi)
add_filter('depersp_rectifier', fn () => new Depersp\Rectifier(outputWidth: 1240, outputHeight: 1754));
```

## Log e diagnostica

I log si attivano solo in modalità debug. In `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);      // scrive in wp-content/debug.log
define('WP_DEBUG_DISPLAY', false); // non mostra errori ai visitatori
```

Se volete solo i log di Depersp, senza attivare il debug di tutto WordPress:
`define('DEPERSP_DEBUG', true);`

Con i log spenti il plugin non registra nulla.

Le righe iniziano con `[Depersp]` e compaiono anche, le ultime 100, in
**Strumenti › Depersp**. In quella pagina trovate anche:
- l'ambiente: versioni di PHP, Imagick e ImageMagick, formati supportati, posizione della libreria, la vostra preferenza;
- il pulsante **Esegui autotest**, che raddrizza una foto generata in memoria e serve a capire subito se il server è in grado di farlo.

| Riga di log | Significato e soluzione |
|---|---|
| `Skipped: not requested (…)` | nessuno ha chiesto di raddrizzare questo caricamento: spuntate la casella oppure aggiungete una regola `depersp_should_process` |
| `Skipped: file type … is not JPEG/PNG/WebP` | il formato non è supportato, per esempio HEIC dagli iPhone |
| `Processing (…)` seguito da `Rectified {…}` | tutto a posto; `saved_as` è il nome con cui il file viene salvato |
| `FAILED: …DetectionException: No closed frame…` con `keep` | cornice non trovata: la foto viene caricata così com'è. Controllate che la cornice sia chiusa e che tutti e quattro gli angoli siano nella foto |
| `FAILED: …InvalidImageException…` | immagine illeggibile o troppo grande (`maxPixels`, `maxFileBytes`) |
| `FAILED: ImagickException …` / `…not authorized…` | ImageMagick blocca qualcosa, di solito la `policy.xml` del server. L'autotest lo conferma |
| `Plugin inactive: …` | manca l'estensione Imagick nel PHP del web server, oppure manca la cartella `lib/` |

Se il log non riporta **nessuna** riga `[Depersp]` durante un caricamento, il
plugin non è attivo oppure quel caricamento non passa dalle funzioni di upload
di WordPress. Succede per esempio con alcuni plugin di form, che salvano i file
per conto loro: in quel caso usate `depersp_media_handle_upload()` o
`Rectifier::rectifyBlob()` direttamente.

## Note

- Il lato massimo predefinito è 2560 px, la stessa soglia oltre la quale
  WordPress ridimensiona comunque le immagini grandi.
- Il tempo di elaborazione (0,3–1,5 s per foto) si aggiunge alla richiesta di
  upload.
- Provato su WordPress 7.1.4 / PHP 8.4:
  - *Media › Aggiungi nuovo*, Libreria media ed editor a blocchi (REST API);
  - form pubblico con lo shortcode;
  - caricamenti via codice (sideload);
  - foto senza cornice (`keep` e `reject`), casella tolta, log spenti,
    libreria mancante, autotest.
