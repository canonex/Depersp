# Depersp

Raddrizza automaticamente la foto di un foglio con una cornice scura,
eliminando la prospettiva. PHP puro + estensione **Imagick**: nessuno script
shell, nessun `exec()`.

![In](0-test.jpeg "Input file") ![Out](0-test.jpeg-dp.png "Output file")

## Requisiti

- PHP ≥ 8.1 con le estensioni `imagick` e `fileinfo`
- ImageMagick con i coder JPEG/PNG/WebP abilitati (la `policy.xml` restrittiva
  di default va benissimo: non servono MVG, SVG, XC, GRADIENT…)

## Uso

### Da riga di comando

```bash
bin/depersp foto.jpg                       # -> foto.jpg-dp.png
bin/depersp foto.jpg out.jpg --size=1024x1024 --json
php index.php foto.jpg                     # compatibilità con la versione precedente
```

Codici di uscita: `0` ok, `1` errore, `2` cornice non trovata, `3` file non valido.

### Da PHP

```php
require 'src/autoload.php';        // oppure l'autoload di Composer

use Depersp\{Rectifier, DetectionException, InvalidImageException};

Rectifier::applyResourceLimits();  // una volta, nei worker web
$r = new Rectifier(maxOutputSide: 2048);

try {
    $res = $r->rectifyFile($upload, $dest . '.jpg');
    // $res->width, $res->height, $res->corners, $res->seconds
} catch (DetectionException $e) {
    // foto valida ma senza cornice riconoscibile
} catch (InvalidImageException $e) {
    // non è un'immagine ammessa, troppo grande o corrotta
}
```

Opzioni del costruttore: `outputWidth`/`outputHeight` (dimensione fissa),
`maxOutputSide`, `margin` (bordo rifilato, default 1%), `quality`,
`maxFileBytes`, `maxPixels`, `linearLight`. Il rilevatore si regola con
`new QuadDetector(workSize, inkRatio, closeRadius, minAreaRatio, minFill)`.

### Upload su server

`public/` contiene un esempio pronto: `index.html` (form, anche da smartphone)
e `upload.php` (endpoint JSON). Impostate `public/` come document root. Gli
originali finiscono in `storage/uploads/`, che non è pubblica; le immagini
raddrizzate vanno in `public/rectified/`. Per provarlo in locale:

```bash
php -S 127.0.0.1:8000 -t public
```

e aprite <http://127.0.0.1:8000/>.

#### Risposte di `upload.php`

`POST upload.php`, `multipart/form-data`, campo `image`.

| HTTP | `error` | Significato |
|---|---|---|
| 200 | — | `{"ok":true,"id":"…","rectified":"rectified/<id>.jpg","width":…,"height":…,"seconds":…}` |
| 400 | `missing_file`, `upload_failed` | campo assente o upload interrotto |
| 405 | `method_not_allowed` | la richiesta non è un POST |
| 413 | `too_large` | supera `MAX_BYTES` o `upload_max_filesize` |
| 415 | `invalid_image` | non è JPEG/PNG/WebP, oppure è corrotto o troppo grande in pixel; l'originale viene cancellato |
| 422 | `no_frame` | immagine valida ma cornice non trovata; l'originale resta in `storage/uploads/<id>` |
| 500 | `server_error` | dettagli nel log degli errori di PHP |

Esempio con curl:

```bash
curl -F image=@0-test.jpeg http://127.0.0.1:8000/upload.php
```

#### Installazione in produzione

1. Copiate il progetto fuori dalla document root, per esempio in
   `/var/www/depersp`, e puntate il virtual host su `/var/www/depersp/public`.
   In questo modo `src/`, `tests/` e `storage/` non sono raggiungibili dal web.

   Apache:

   ```apache
   DocumentRoot /var/www/depersp/public
   <Directory /var/www/depersp/public/rectified>
       # le immagini prodotte non devono mai essere eseguite come script (mod_php;
       # con php-fpm escludete la cartella dal SetHandler/ProxyPassMatch)
       php_admin_flag engine off
       Options -Indexes
   </Directory>
   ```

   nginx:

   ```nginx
   root /var/www/depersp/public;
   location ^~ /rectified/ { try_files $uri =404; }   # niente PHP qui
   location = /upload.php {
       client_max_body_size 16m;
       include fastcgi_params;
       fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
       fastcgi_pass unix:/run/php/php8.4-fpm.sock;
   }
   ```

2. Permessi: l'utente del web server (es. `www-data`) deve poter scrivere in
   `storage/` e in `public/rectified/`. Le cartelle vengono create al primo
   upload; per crearle a mano:

   ```bash
   mkdir -p storage/uploads public/rectified && chown -R www-data: storage public/rectified
   ```

3. `php.ini`: `upload_max_filesize = 16M`, `post_max_size = 16M`,
   `memory_limit = 256M` (ne bastano meno di 64M, ma il margine serve con foto
   molto grandi), `max_execution_time = 30`.

4. Le costanti in cima a `public/upload.php` (`UPLOAD_DIR`, `OUTPUT_DIR`,
   `OUTPUT_URL`, `MAX_BYTES`) si adattano al vostro sito. Per un formato di uscita
   diverso basta cambiare l'estensione `.jpg` nella chiamata a `rectifyFile`.

5. Verifica: `php -m | grep -E 'imagick|fileinfo'` deve mostrare entrambe le
   estensioni, poi lanciate `php -d memory_limit=512M tests/run.php`.

#### Integrazione nel vostro codice di upload

Se avete già un vostro gestore degli upload vi basta una riga, dopo aver
salvato il file:

```php
$res = (new Depersp\Rectifier())->rectifyFile($salvato, $salvato . '-dp.jpg');
```

Gestite `DetectionException` mostrando all'utente un messaggio del tipo
"inquadra tutti e quattro gli angoli" e conservando l'originale.

## Come funziona

1. L'immagine viene letta forzando il coder dedotto dal MIME reale (un file
   SVG/MVG rinominato `.jpg` viene rifiutato) e poi orientata secondo l'EXIF.
2. Una copia ridotta (800 px) in scala di grigi viene confrontata con lo sfondo
   locale stimato: i pixel di "inchiostro" si riconoscono anche con luce non
   uniforme o in ombra.
3. Un flood fill dal bordo dell'immagine trova lo sfondo; la regione chiara
   più grande racchiusa dall'inchiostro è l'interno della cornice. Si scarta se
   è troppo piccola o non è un quadrilatero (es. un cerchio).
4. Sul contorno esterno di quella regione si cerca il quadrilatero inscritto
   di area massima; ogni lato viene rifinito con una regressione robusta e gli
   angoli diventano le intersezioni, con precisione sub-pixel.
5. Il rapporto larghezza/altezza reale del foglio viene stimato dalla
   geometria proiettiva (Zhang & He, 2007). Se la focale non è ricavabile
   (inclinazione a trapezio pura) si usa quella tipica di uno smartphone.
6. `distortImage(PERSPECTIVE)` produce l'immagine finale; i metadati EXIF
   (GPS compreso) vengono rimossi.

Il file di input non viene mai modificato.

## Vincoli

- Sfondo chiaro, cornice scura e **chiusa**, con tutti e quattro gli angoli
  inquadrati.
- Nessun vincolo di dimensione dell'immagine: il limite fisso di 1024×1024
  non c'è più.

## Test

```bash
php -d memory_limit=512M tests/run.php     # oppure: composer test
```

La suite (20 test, senza dipendenze) comprende: la foto di riferimento
confrontata con `0-test.jpeg-dp.png`; foto sintetiche proiettate con una
fotocamera pinhole a verità nota (inclinazioni, rotazioni fino a 170°, formati
A4 e quadrato); casi negativi (foglio bianco, cornice aperta, cerchio, file
non immagine, SVG/MVG camuffati, immagini troppo grandi); orientamento EXIF;
tempo e memoria su una foto da 12 MP.

## Prestazioni indicative

| Input | Tempo totale |
|---|---|
| 512×512 (foto di test) | ~0,25–0,35 s |
| 4000×3000 (12 MP) | ~1,5 s (rilevamento ~0,5 s) |

La memoria PHP è inferiore a 30 MB. Con molti upload contemporanei conviene
mettere l'elaborazione in una coda (cron/worker) e lasciare a `upload.php` solo
il salvataggio dell'originale.
