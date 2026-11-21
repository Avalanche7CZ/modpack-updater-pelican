# Potvrdit běžící nasazení bez restartu

V kartě čekajícího nasazení je po kontrole dostupná akce **Confirm running server**. Použije ji root admin, který se připojil k cílovému Minecraft serveru a výslovně potvrzuje běh nasazené verze. Nejde o automatický důkaz bootu. Akce neodesílá Start, Stop, Restart, Restore, sync ani nový deploy.

## Jedno potvrzení celé aktuální konfigurace

Root admin může v kartě aktivního ověřeného deploye zvolit **Review current configuration**. Akce nejprve zobrazí celý seznam odlišných cest s původním ověřeným a aktuálním SHA-256, cílovou verzí, ID operace a manifestem. Zahrnuje nasazený rozsah, cílové configy pod `config/` včetně dříve zachovaných lokálních configů a přesně kořenový `server.properties` z původního world-properties receipt. Nezahrnuje obecnou výjimku pro jiné cesty pojmenované `server.properties`.

Po kontrole seznamu stačí jednou zvolit **Accept configuration and finish update** a potvrdit běh cílové verze. Otevírání jednotlivých diffů není potřeba. Všechny přijímané rozdíly musí být běžné UTF-8 textové configy do 1 MiB; JARy, runtime závislosti/launcher, chybějící soubory, očekávané odstraněné cesty a ostatní cesty zůstávají striktní. Blokující rozdíly se zobrazí ve stejném přehledu a dokončení nepovolí.

Závěrečná akce během webového požadavku uloží schválení a zobrazí ID potvrzení. Použije přesný přehled, aktivní checkpoint a ID root administrátora. Stáří přehledu se ověří při odeslání do fronty (15 minut). Čekání ve frontě a pomalé čtení Wings platnost včas podaného schválení nezkrátí.

Queue worker znovu ověří aktivní checkpoint, autentizovaný cílový manifest, zamčenou zálohu, spouštěcí konfiguraci, běžící instanci a přesnou sadu hashů ze schváleného přehledu. Wings soubory ověřuje po dávkách a UTF-8 kontroluje ze stejných omezených diskových bufferů. Při změně obsahu nebo instance vytvoř a schval nový přehled. Karta ukazuje stav fronty, kontroly souborů a dokončení.

Lease pro deploy/recovery se získá až při dokončení v databázi. Přijetí lokálních hashů, audit, verze a baseline se zapíší v téže transakci jako stav **Confirmation completed**. Zůstane zachovaný původní upstream manifest, souborový receipt i zamčená záloha. Worker posílá na Wings pouze čtecí požadavky. Audit odlišuje potvrzení běhu administrátorem a automatický boot.

Pro upgrade spusť migrace pluginu a načti nový kód v queue workeru. Potvrzení používá stávající asynchronní databázovou nebo Redis frontu. Timeout jobu je 3 600 sekund a fronta musí mít `retry_after` nad 3 660 sekund. Běžná konfigurace pro Native přípravu používá 14 520 sekund. Opakovaný klik použije stejný čekající záznam. Při ztraceném dispatchi nebo přerušení čtecího pokusu použije plánované `arcturus-modpack:recover` původní schválení a znovu ověří checkpoint. Při aktivním deploy/recovery lease karta nabídne původnímu schvalovateli **Retry confirmation**, až se lease uvolní.

Při tvrdém ukončení procesu v krátké fázi dokončení může zůstat původní čtyřhodinový lease. V diagnostice je ID potvrzení, pokus, checkpoint a PID. Starý PHP-FPM lease i lease jiné operace podléhá stávající ochraně a přirozenému vypršení. Potvrzení nemaže cache ani nevynucuje uvolnění lease.

## Individuální postup

1. Jako root admin klikni **Review files individually**. Přehled obsahuje všechny odlišné nasazené cesty a jejich očekávané/skutečné SHA-256. U `config/Botania.cfg` (a každého dalšího configu, který chceš přijmout) klikni **Ověřit text a zobrazit diff**, zkontroluj změnu proti ověřenému cíli a výslovně zvol **Přijmout tuto cestu a hash jako lokální změnu**. Přijetí samo deploy neuzavírá.
2. Po vyřešení všech rozdílů klikni **Confirm running server** a potvrď, že ses připojil a ověřil běh této cílové verze.
3. Po doběhnutí potvrzení ve frontě, pokud kontroly projdou, historie uvede „Běh nasazení potvrdil admin; boot nebyl automaticky ověřen“, installed_version bude 2.9.0 a baseline bude přesný původní upstream manifest připravené 2.9.0. Pouze tento checkpoint se uzavře. Záloha a audit zůstanou.

Akce vyžaduje aktivní Native deploy v `awaiting_manual_start`, `native_deploy_files_verified=true`, předané ovládání v roli deploy, žádný nejasný Wings zápis a přesnou dokončenou zamčenou zálohu. Kontroluje aktuální běžící instanci včetně postupujícího uptime, Startup/image/proměnné Wings, obsah aktivního launcheru a argumentů, očekávaný cílový runtime, identitu operace a neměnný autentizovaný cílový manifest. Nepotřebuje původní použitelný Start záznam ani Done log.

Přehled vypíše všechny odlišné cesty v původním `native_after`, včetně očekávané nepřítomnosti odstraněných souborů a explicitních runtime dependencies. JARy, runtime dependencies, chybějící soubory, očekávané odstraněné cesty a nesouhlas autentizovaného cíle zůstávají blokující; nelze je přijmout jako lokální config.

Individuální přijetí je dostupné jen pro textové configy pod `config/` s povolenou konfigurační příponou. Obě verze musí být UTF-8 bez binárních/control bajtů, nejvýše 1 MiB každá. Cílové bajty se čtou z lokálně připraveného balíčku a musí odpovídat ověřenému upstream SHA-256. Bez dostupného ověřeného cíle se config nepřijme. Diff se zobrazuje jako escapovaný text; audit ukládá pouze jeho digest a hashe, nikoli obsah configu.

Přijetí se váže na ID operace, cílový manifest, přesný původní rozsah nasazení, cestu, očekávaný a aktuální hash, review a root administrátora. Další změna obsahu zneplatní přijetí i závěrečný proof; cesta se musí znovu zkontrolovat. Finální potvrzení všechny nasazené cesty znovu čte a ověřuje. Nic nepřepisuje na živém serveru a nevytváří falešný důkaz bootu.

Lokální `railcraft.cfg`, který byl v původním plánu zachován, zůstává místní změnou. Pokud byl nasazen a následně změněn, platí pro něj stejné individuální review jako pro Botania config. Původní `native_after` receipt ani čistý upstream manifest se nemění. Ručně opravený launcher mimo tuto množinu se znovu obsahově kontroluje a zaznamená odděleně. Závěrečný audit uchová konkrétní přijaté lokální hashe; baseline se nikdy neodvozuje ze současných živých souborů.

## CLI

`OP` je UUID **aktuálního čekajícího deploye 2.9.0 z karty**, `BACKUP` jeho přesné zálohy a `ACTOR_ID` skutečné ID root administrátora. Cesta `/var/www/pelican/artisan` je příklad z repozitáře; skutečná absolutní cesta na maty nebyla sdělena. Pokud je jiná, nahraď ji. Příkazy nepotřebují `cd` ani ruční SQL.

```bash
OP=UUID_AKTUALNIHO_DEPLOYE_2_9_0
BACKUP=UUID_ZAMKENE_ZALOHY_TOHOTO_DEPLOYE
ACTOR_ID=ID_ROOT_ADMINA
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=confirm-running --managed-server=2 --backup="$BACKUP" \
  --target-version=2.9.0 --actor="$ACTOR_ID"
```

Pokud přehled vrátí rozdíly, převezmi **přesný aktuální hash z přehledu**. Review a přijetí jedné cesty:

```bash
CONFIG_SHA=AKTUALNI_SHA256_Z_PREHLEDU
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=review-config --path=config/Botania.cfg --sha256="$CONFIG_SHA" \
  --managed-server=2 --backup="$BACKUP" --target-version=2.9.0 --actor="$ACTOR_ID"
# Po kontrole vypsaného diffu použij jeho proof:
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=accept-config --path=config/Botania.cfg --sha256="$CONFIG_SHA" \
  --managed-server=2 --backup="$BACKUP" --target-version=2.9.0 --actor="$ACTOR_ID" \
  --apply --confirm=PROOF_Z_CONFIG_REVIEW
```

Totéž proveď zvlášť pro každý přijatý config. Striktní rozdíly nelze tímto mechanismem obejít. Poté znovu spusť první `confirm-running` příkaz: až přehled dovolí dokončení, vrátí závěrečný proof.

Čtecí příkazy pouze čtou server a uloží krátký audit review; nemění operaci ani napájení a nezískává mutation lease. Zkontroluj vypsané operation ID, cílovou verzi, zálohu, šablonu Startupu, image, efektivní SERVER_JARFILE a hashe launcheru/args. Pak do 15 minut:

```bash
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=confirm-running --managed-server=2 --backup="$BACKUP" \
  --target-version=2.9.0 --actor="$ACTOR_ID" \
  --apply --admin-confirms-running --confirm=PROOF_Z_REVIEW
```

Apply získá existující deploy/recovery lease a ještě před prvním čtením z Wings vybere přesné review podle proof, operace a admina. Jeho stáří ověří jednou při zahájení apply. Včas přijaté ANO neztratí platnost jen proto, že následná kontrola překročí 15 minut. Po kontrole musí nové hashe, launcher, manifest, nastavení a běžící instance odpovídat tomu samému přehledu; nepoužije se jiný novější přehled. Chyby rozlišují „Potvrzení vypršelo před spuštěním kontroly“ a „Od přehledu se něco změnilo“. Při změně souboru, proměnné, launcheru, manifestu nebo operace odmítne starý proof. Baseline/verze a uzavření se provedou ve stávající DB transakci. Audit ukládá aktuální konfiguraci/launcher, root aktéra, running instance a manifest digest; `startup_verified=false`, `running_confirmed_by_admin=true`. Normální **Verify running server** nadále vyžaduje skutečný čerstvý boot a automatické Schedules tuto admin akci nepoužívají.

## SERVER_JARFILE a upstream kontrakt

Povolena je pouze celá `{{SERVER_JARFILE}}` bez uvozovek přímo za `-jar`. Efektivní hodnota používá stejné pravidlo jako Pelican: serverový override, nebo při null/absenci override default eggu. Prázdný override není default. Název musí být jeden root `Crucible*.jar` podle původní přísné gramatiky, následně se ověřuje skutečný obsah JAR manifestu, SHA-256 a permissions. Cesty, extra argumenty, shell, jiné proměnné, obecná expanze a vlastní classpath se nepovolují. Doslovný název nadále funguje.

DB Startup zůstává šablonou. Efektivní hodnota je součástí runtime comparison proof a snapshotu Pelican proměnných; změna override/defaultu zneplatní původní schválení. Stejný resolver používají Compare, Keep existing Crucible, zaznamenání ručního Startu, Verify i admin potvrzení.

Skutečný Wings RC2 `cbce59e` vrací v `ToAPIResponse` neexpandovanou `Config.Invocation`; `parseInvocation` sestavuje rozvinuté STARTUP zvlášť až pro kontejner. Proto se konfigurace porovnává jako **šablona + efektivní environment**, nikoli jako dosazený příkaz proti šabloně. Zdroje: [Wings server.go](https://github.com/pelican/wings/blob/cbce59e/server/server.go), [Wings router_server.go](https://github.com/pelican/wings/blob/cbce59e/router/router_server.go), [Panel EnvironmentService](https://github.com/pelican/panel/blob/f64a167/app/Services/Servers/EnvironmentService.php).

## Panel RC3

Oficiální Panel [v1.0.0-rc.3](https://github.com/pelican/panel/releases/tag/v1.0.0-rc.3), commit `6095cea`, je přidán do CI vedle RC2. Startup configuration a EnvironmentService mají proti RC2 stejný kontrakt, vztah Server::variables nadále vrací `server_value ?? default_value`. RC3 také změnil `TaskSchemaInterface::canCreate` o volitelné user/payload argumenty; plugin implementuje podpis kompatibilní s RC2 i RC3 a zachovává root-only oprávnění a whitelist původních payloadů. Samotná verze Panelu nedokazuje verzi Wings na maty. Rozsah pluginu `^1.0.0-rc.2` zahrnuje RC3.

Cílená lokální reprodukce (pouze disposable checkouty):

```bash
php tests/crucible_startup.php
PELICAN_SOURCE=/tmp/disposable-rc2 php tests/integration/native_config_set.php
PELICAN_SOURCE=/tmp/disposable-rc2 php tests/integration/native_config_acceptance.php
PELICAN_SOURCE=/tmp/disposable-rc2 php tests/integration/native_running_confirmation.php
PELICAN_SOURCE=/tmp/disposable-rc3 php tests/integration/native_config_set.php
PELICAN_SOURCE=/tmp/disposable-rc3 php tests/integration/native_config_acceptance.php
PELICAN_SOURCE=/tmp/disposable-rc3 php tests/integration/native_running_confirmation.php
```

Lokálně prošly cílené regrese na oficiálním RC2 `f64a167` i RC3 `6095cea`, s jejich vlastními Composer lockfile závislostmi. Pokrývají doslovný JAR, egg default/server override, neplatné názvy i obsah JARu, změnu proměnné po Compare, normální Start/Verify s placeholderem a oddělené admin dokončení bez boot logu přes CLI i kartu. Ověřují přesný odmítnutý souborový drift, zachování místního railcraft.cfg a upstream manifestu, historii s `startup_verified=false`, zamčenou zálohu a nulová power/restore/sync volání při potvrzení. Také prošel původní automatický boot s `startup_verified=true`, parser/console jednotkové regrese a kontrola kompatibility append patche a plugin API na RC3. Wings v runtime testech jsou HTTP fixture, databáze izolovaná SQLite; živé Minecraft/Wings ani maty nebyly testovány či měněny.

Regrese individuálního přijetí configů navíc pokrývá souhrn šesti rozdílů najednou, diff proti ověřeným cílovým bajtům, odmítnutí binárního configu, striktní JAR/runtime/removal kontroly, vazbu na konkrétní cestu/operaci/manifest/hash, odmítnutí zastaralého review a opětovné kontroly při dokončení. Přes CLI i kartu dokončí testovací GTNH 2.9.0 bez boot logu, změny živých souborů a power/restore/sync požadavků; původní receipt, čistý upstream manifest, zamčená záloha a jednotlivá přijetí zůstanou v auditu. Tato cílená regrese prošla na RC2 i RC3 se stejnými lokálními fixture/SQLite omezeními.

Hromadné potvrzení (`native_config_set.php`) prošlo na RC2 i RC3: společný přehled Botania/Another/railcraft a kořenového server.properties bez individuálních diffů, root autorizace, striktní JAR/runtime/odstraněné cesty a binární config, odmítnutí jiné než kořenové properties cesty, změny hashe/seznamu/aktivní operace/manifestu/běžící instance a odmítnutí neviděného nového hashe v UI. Test potvrzuje dokončení 2.9.0 s lokálními receipt záznamy, původním upstream manifestem i zamčenou zálohou a jen GET požadavky na Wings. Produkční maty nebylo kontaktováno.

## Ověření fronty

Na Pelican RC2 i RC3 s PHP 8.4 a izolovanou SQLite byly spuštěny regrese fronty, hromadného i individuálního přijetí, ručního potvrzení, FileStore lease, Wings batch hashů, receiptů, obnovy a Livewire karet. Nový scénář používá více než 200 změněných configů včetně velkého UTF-8 souboru při limitu 128 MiB. Nastaví 30sekundový PHP limit pro kliknutí a ověří nulový počet Wings požadavků v tomto požadavku. Pomalou odpověď Wings modeluje posunem času ve fixture. Scénář také ověří přerušení workeru během čtení, převzetí stejného schválení po prodlevě a FileStore lease při dokončení. Používá skutečnou databázovou frontu Laravelu a HTTP fixture Wings.
