# Nativní bulk staging a měření flow

Změna vychází z `339d3fe`. Produkce ani běžící operace na maty nebyly změněny. **GTNH 2.8.1 → 2.9.0 do 900 s od začátku celého flow po ověřený boot není ověřeno.** Lokálně není přístup k maty Wings, jejich konfiguraci/proxy, GTNH kopii ani produkčnímu typu DB. Panel RC2 neurčuje verzi Wings.

## Ověřený upstream

Byl přečten skutečný zdroj:

- [Wings cbce59e router](https://github.com/pelican-dev/wings/blob/cbce59e/router/router.go): autentizované serverové routes; POST write/compress/decompress, GET contents/list-directory, PUT rename.
- [Wings cbce59e file handlers](https://github.com/pelican-dev/wings/blob/cbce59e/router/router_server_files.go): write vyžaduje známý Content-Length, zapisuje request body; contents posílá velikost otevřeného souboru a omezený stream. Decompress přijímá `root`, `file`; compress `root`, `files`, `name`, `extension`. Rename spouští položky souběžně, může skončit částečně a chybějící zdroj/cíl může tolerovat. HTTP 204 proto nestačí.
- [Wings cbce59e filesystem](https://github.com/pelican-dev/wings/blob/cbce59e/server/filesystem/compress.go): ZIP je podporovaný; neznámá kompresní přípona znamená tar.gz, nikoliv nekomprimovaný TAR. Jméno výstupu se normalizuje na basename, při kolizi se mění. Komprese vynechává denylistované/chybějící položky. Přesný seznam a SHA-256 je proto nutné kontrolovat na readbacku. Kvóta se kontroluje před extrakcí i během zápisů. `root` extrakce není izolace vůči ostatním zapisovačům do serveru.
- [Panel f64a167 DaemonFileRepository](https://github.com/pelican-dev/panel/blob/f64a167/app/Repositories/Daemon/DaemonFileRepository.php): shodná pole a metody. Jeho getContent/putContent vrací/přijímá celé řetězce; implementace zde používá autentizovaný klient repozitáře a souborové streamy, ne tyto bufferující metody.

Compress i decompress používají `context.Background()`. PHP timeout neukončuje práci Wings. Neexistuje zde obecné archive-job status/cancel API; downloader status platí pro pull, nikoliv pro decompress.

## Potvrzená produkční příčina a časový model

Produkční evidence: upload 3 482 s; po 3 721 s od startu jobu se další staging chmod dávka odmítla na ověřené hranici. Původní výchozí výpočet byl 10 800 − 1 080 (boot) − 5 880 (recovery) = deadline Applying 3 840 s. Při zbývajících 119 s už 120s admission jednotka neprošla. Tím zdravý postup vyvolal rollback, přestože request ani worker ještě nevyčerpal svůj timeout.

Nativní deployment nyní používá `ApplyBudget::requestsOnly()`. Requesty mají své vlastní konečné limity (listing 30 s, běžná mutace/archivní endpoint 60 s, přenos a contents nejvýše 600 s). Není zde kumulativní deadline Applying ani odpočet rezerv na target boot či recovery. Worker zůstává na 10 800 s; jeho timeout se nezvyšoval. Výkonnostní cíl 900 s je diagnostická konstanta, nikoliv podmínka pro rollback nebo autorizaci mutace. Boot/backup/restore mají dál vlastní timeouty.

Po durabilně ověřené dávce tentýž worker pokračuje navazující dávkou nezávisle na součtu předchozích rezerv. Intent/verified checkpointy, oplocení execution ID a cache lease i samostatný recovery job zůstávají zachované. Restart přerušeného workeru neznamená automatický replay apply: nejasné mutace zůstávají pro nezávislé recovery z původní zamčené zálohy. Nové automatické přebírání či přehrávání rozpracovaných mutací se nezavádí. U archivního timeoutu stále platí blokace při `native_archive_pending`, protože Wings práce může pokračovat po PHP timeoutu.

Receipt a export `native-measurements` nyní explicitně ukazují použitou transfer strategii (`zip-bundles` / `per-file`), počet a bajty ZIP balíků, requestový časový model, worker timeout a oddělený výkonnostní cíl. GTNH staging tak používá omezené ZIP uploady místo tisíců sériových write requestů.

Podmínka Filament akce `syncRunningOriginal` čte context samostatně, s výchozím false a ošetřením nečitelného receiptu. Původní `a && context[key] ?? false` chránilo až celý booleovský výraz, ne samotný lookup chybějícího klíče. Běžný rollback bez `running_original_sync_pending` proto nově skryje tuto akci a neshodí stránku. Blade rovněž zobrazuje upozornění pouze pro čitelný receipt s explicitním true.

Tyto navazující úpravy a doplněné regrese nebyly podle pokynu pokračovat hlavně v kódu spuštěny. Produkční běžící operace se nezměnila.

## Implementace

`PreparedStaging` před Stop vytvoří Panel-private ZIPy (0700 adresář). Limit je 256 členů / běžně 64 MiB dat; jeden větší soubor tvoří vlastní balík, výsledný ZIP musí zůstat pod limitem Wings adaptéru 2 GiB. ZIP používá STORE, pevný timestamp a obyčejné 0644 regular členy. Členové mají numerické názvy; mapování uchovává schválenou cestu, velikost a SHA-256. Před i po vytvoření archivu se kontrolují skutečné bytes, včetně změny zdroje při lazy addFile. Celý archiv se nikdy nepřevádí do PHP stringu.

Vzdálený staging zůstává **až po maintenance a dokončení přesné zamčené zálohy**. Tím se nedostane do pre-update zálohy. Stock Wings zde neposkytují doloženou izolaci od běžícího Minecraftu a dalších API/SFTP zapisovačů, takže staging před Stop nebyl zapnut. Není vyžadován sidecar, ACL, SSH ani přístup k volume.

Každý balík má vlastní `.arcturus-update-ID/new/bN`; odložené soubory jsou také rozdělené po nejvýše 256 položkách. Nevznikají opakované listingy jednoho obrovského plochého stagingu. Upload je jeden stream s Content-Length, SHA-256 vzdáleného ZIPu se ověří před extrakcí. Decompress směřuje výhradně do adresáře balíku. Upstream ZIP se do živého rootu neposílá.

Dvě kontrolní cesty:

- `parallel` (výchozí): nejvýše osm souběžných contents requestů, součet běžných diskových sinků nejvýše 64 MiB; větší soubor se čte samostatně. Skutečný SHA-256 všech rozbalených souborů, metadata před/po. RC2 Laravel lazy promises jsou všechny explicitně spuštěné před čekáním; `stream=true` by zde souběh přes curl nezajistilo. Síť jde do dočasných souborů, hash se počítá po 1 MiB.
- `archive`: stock compress nad přesně vyjmenovanými numerickými členy, jeden streamovaný contents readback na disk a ZIP kontrola počtu, přesných názvů, duplicit, velikostí, regular typu a SHA-256 každého členu. Readback se nikdy nerozbaluje. Kontroluje se také přesná výsledná množina na Wings, takže extra soubor mimo allowlist compress nezůstane skrytý.

Volba je konfigurace `native_bulk_verification`, nikoliv fallback po selhání. Pro méně než 16 kandidátů zůstává původní cesta. Potvrzeně nepodporované Wings lze **před operací** nastavit přes `native_bulk_staging=false`. Po nejasném zápisu se neposílá stejná cesta znovu ani se nepřechází na per-file fallback.

Apply používá až 64 nezávislých přesunů v dávce; závislé a překrývající se přesuny odmítá. Jeden durable intent popisuje obě oddělené fáze retire/replace a očekávané hashe; verified následuje až po ověření. Částečná odpověď se nereplayuje. Zůstává ověření hashů a permissions živých souborů bezprostředně před přesunem, kandidátů před instalací, odložených originálů, instalovaných cest a nezávislé závěrečné ověření. Dvě další celoplošná čtení před/po stagingu byla u bulk cesty odstraněna; drift kontrolují pre/post-backup receipts a každá mutační dávka. Comparison/inventory rovněž používají omezený paralelní readback. Symlinky, světy, three-way plán, lokální úpravy, approvals, Schedules, rollback, runtime a boot včetně uptime opravy `6953188` zůstávají chráněné.

Každý bundle intent durabilně nastaví `native_archive_pending`. Pouze úspěšná kontrola celého balíku ho smaže. Pád workeru, nejasný upload/extract/compress nebo chyba ukládání checkpointu nechají flag zachovaný. Automatický restore/cleanup i běžné recovery se při něm zastaví v maintenance. Cache lease se kontroluje před mutacemi a při heartbeat; failure callback nesmí převzít cizí drženou lease. Progress je oddělený od recovery intentů.

## Reprodukce a maty

Běžné kontroly:

```sh
bash scripts/test.sh
PELICAN_SOURCE=/tmp/disposable-rc2 php tests/integration/native_bulk.php
PELICAN_SOURCE=/tmp/disposable-rc2 php tests/integration/native_deployment.php
```

Velký benchmark vytvoří 8 132 existujících souborů: 255 větších binárních JAR payloadů a 7 877 malých configů, dohromady přibližně 568 MB. Payloady jsou syntetické, nikoliv skutečný GTNH obsah. Fixture implementuje kontrakt nad soubory a skutečnými HTTP sockety; **není to Wings**. `/tmp` tohoto stroje je tmpfs. Dvoumilisekundová prodleva se přidává na každý request; další CPU/metadata režie Pythonu není ekvivalentem Go Wings. Baseline se načítá přes `git show 339d3fe`, bez checkout/reset. SQLite varianta skutečně zapisuje šifrované Eloquent DeploymentReceipt, není to MariaDB.

```sh
PELICAN_SOURCE=/tmp/disposable-rc2 \
BULK_FIXTURE_LATENCY_MS=2 BULK_EXISTING=1 BULK_COMPARE_BASELINE=1 \
BULK_BENCH_DB=1 BULK_BENCH_COUNT=8132 BULK_BENCH_JAR_BYTES=2224000 \
BULK_BENCH_OUTPUT=/tmp/native-bulk-8132.json \
php -d memory_limit=512M tests/integration/native_bulk.php
```

Pro měření na **zastavené a suspended jednorázové kopii** na maty, bez nasazení pluginu do produkce, připravte soubor čitelný pouze operátorem:

```json
{
  "disposable": true,
  "uuid": "UUID_JEDNORAZOVE_KOPIE",
  "confirm_uuid": "UUID_JEDNORAZOVE_KOPIE",
  "node": {
    "scheme": "https", "fqdn": "WINGS_HOST", "daemon_connect": 8080,
    "daemon_token": "NODE_TOKEN", "daemon_token_id": "NODE_TOKEN_ID"
  }
}
```

```sh
PELICAN_SOURCE=/tmp/disposable-rc2 WINGS_BENCH_CONFIG=/run/user/1000/maty-copy.json \
BULK_BENCH_DB=1 BULK_BENCH_COUNT=8132 BULK_BENCH_JAR_BYTES=2224000 \
php -d memory_limit=512M tests/integration/native_bulk.php > /tmp/maty-bulk.json
```

Harness ověří skutečný Wings offline/suspended stav, vytvoří vlastní náhodně označené config/mod cesty a staging, porovná obě verifikační cesty a smaže jen svůj dokončený testovací obsah. Na skutečném Wings se neprovádějí fault injections, stop/start, záloha ani boot. Při selhání zůstane staging k inspekci. Výchozí real benchmark přidává nové izolované cesty; `BULK_EXISTING` seeduje originály jen v lokálním fixture. Pro finální GTNH akceptaci je potřeba samostatný běh schváleného flow na kopii celého serveru.

Na maty je nutné samostatně zaznamenat `wings version` a konfiguraci kvóty/proxy. Úspěšný test velikosti balíku dokazuje pouze dolní mez propustného requestu; maximální limit proxy nelze odvodit z RC2 zdroje. Lokálně byl ověřen endpoint kontrakt a simulované odmítnutí pro nedostatek místa. Produkční limit requestu, kvóta, fyzický volný disk a čas extrakce na maty zůstávají nezměřené.

## Celé flow a skutečná odstávka

Deployment zaznamená čas začátku workeru, queue wait, wall time fází, endpointové počty/bytes a DB checkpointy. `stop_to_verified_boot_seconds` zahrnuje zastavování od požadavku Stop; původní `offline_to_verified_boot_seconds` měří kratší interval od potvrzeného offline stavu. Úspěšný rollback nemůže dokázat úspěšný update.

Read-only export s přesnými ID přípravy, comparison a deploymentu:

```sh
php artisan arcturus-modpack:native-measurements DEPLOY_UUID \
  --flow=PREPARE_UUID --flow=COMPARISON_UUID > /tmp/flow.json
php /path/to/reviewed-checkout/bin/native-flow-report.php /tmp/flow.json
```

Report počítá wall time od vytvoření první vybrané operace po recorded target-ready, tedy i fronty a mezery pro schválení. Nepředstírá neexistující historické subphase časy downloadu; chybějící měření zůstávají null. Při absenci prepare/comparison nevyhodnotí celou 900s akceptaci. Uvedená ID musí zahrnovat celý měřený flow; netýkají se jiné starší přípravy baseline. Čas created_at má rozlišení DB timestampu.

## Nejasná archivní operace

Nepokoušet se znovu o upload, extrakci, mazání stagingu ani restore, dokud může běžet původní Wings práce. Stock API nedokáže potvrdit její konec. Po operátorském ukončení této práce a restartu Wings lze přesně označený receipt zkontrolovat:

```sh
php artisan arcturus-modpack:confirm-archive-quiesced OPERATION_UUID
# Teprve po nezávislém restartu Wings a inspekci operátorem:
php artisan arcturus-modpack:confirm-archive-quiesced OPERATION_UUID \
  --apply --daemon-restarted --confirm=PROOF_Z_READ_ONLY_VYSTUPU
```

Druhá varianta zaznamená **operátorské prohlášení**, nikoliv automatický důkaz restartu. Vyžaduje nezměněný receipt, volnou worker lease a Wings-confirmed offline/suspended server. Neobnovuje, nemaže, nestartuje ani neuvolňuje maintenance. Následuje existující administrátorské recovery z přesné zamčené zálohy a ověřený původní boot. Žádný takový příkaz nebyl na produkci spuštěn.

## Dosavadní měření

Na pokyn uživatele byly další testy a rozběhnuté syntetické benchmarky ukončeny před dokončením celého srovnání. Nešlo o produkční worker ani update flow. Dokončená měření:

| Rozsah | Výsledek |
| --- | ---: |
| 32 existujících souborů, HTTP fixture s 2 ms/request, baseline `339d3fe`, bez DB | 2,230 s |
| Stejný malý případ, bulk + paralelní kontrola, bez DB | 1,013 s |
| Stejný malý případ, bulk + ZIP readback, bez DB | 1,044 s |
| 8 132 existujících souborů / přibližně 568 MB, bulk + paralelní kontrola, šifrované Eloquent receipts na SQLite | 381,784 s |

Poslední údaj je dokončený staging/apply včetně závěrečné kontroly a cleanupu, před spuštěním druhé verifikační varianty. Příprava balíků před Stop je mimo tento údaj. Běhy sdílely stroj s dalším syntetickým benchmarkem. Velký baseline, velká archivní varianta a jejich úplné endpointové/DB/subphase JSON výsledky nebyly dokončeny; nejsou dopočítané ani extrapolované. Zaznamenané counts/bytes a časy všech fází vydá výše uvedený benchmark po dokončení. Velká SQLite varianta narazila při limitu PHP 128 MiB na paměť plných šifrovaných receipts; uvedený dokončený apply běžel s limitem 512 MiB. To nedokládá RAM potřebnou na produkční DB ani kompletní GTNH.

## Kontroly a omezení výsledku

Testy zahrnují skutečný souběh maximálně osmi readbacků, změny metadata/symlinky a truncation, poškozené bytes stejné velikosti, chybějící/extra člen, přerušené rozbalení, opravdový HTTP timeout během pokračujícího requestu, částečný rename bez replay, ztrátu lease po uploadu, nedostatek místa a selhání zálohy. Testování zálohy, runtime, bootu, schvalování a Schedules používá skutečné RC2 služby proti HTTP fixtures. Nenahrazuje native Wings/Docker/Minecraft runtime test. Příkazy a JSON obsahují skutečné naměřené hodnoty; offline/boot z fixture benchmarku jsou null.

Zbývající produkční bottlenecky k měření: fronta/příprava a download releasu, síťová propustnost opakovaných integritních readbacků, nativní uzamčená záloha a GTNH startup. Žádné zvýšení timeoutu ani zabití workeru není součástí fast pathu.

Závěrečné úpravy přímého HTTP statusu mutací a zobrazování přeskočených kroků byly dokončeny po pokynu zastavit testování; následný testovací běh nebyl proveden.
