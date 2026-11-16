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
php wordpress/build.php        # crea dist/depersp-wp-1.0.0.zip
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
| Casella in *Media › Aggiungi nuovo* | spuntate "Raddrizza le foto" prima di caricare |
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

L'editor a blocchi e la finestra "Aggiungi media" caricano tramite REST API o
`async-upload.php` senza la casella: per quei caricamenti usate una regola come
quelle sopra.

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

## Note

- Il lato massimo predefinito è 2560 px, la stessa soglia oltre la quale
  WordPress ridimensiona comunque le immagini grandi.
- Il tempo di elaborazione (0,3–1,5 s per foto) si aggiunge alla richiesta di
  upload.
- Provato su WordPress 7.1.4 / PHP 8.4:
  - caricamento da *Media › Aggiungi nuovo* con l'uploader multi-file;
  - form pubblico con lo shortcode, sia con la foto raddrizzata sia con il
    rifiuto di una foto senza cornice;
  - caricamenti via codice (sideload): senza flag, con flag, uscita WebP,
    `keep`, `reject` e regola personalizzata.

  Non ho provato l'"uploader del browser", cioè il form classico di
  *Media › Aggiungi nuovo*: la casella fa parte dello stesso form, quindi
  dovrebbe funzionare senza modifiche.
