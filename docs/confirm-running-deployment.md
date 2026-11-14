# Potvrdit běžící nasazení bez restartu

Po aktualizaci pluginu je přímo v kartě čekajícího nasazení akce **Potvrdit běžící nasazení**. Použije ji root admin, který se připojil k cílovému Minecraft serveru a výslovně potvrzuje běh nasazené verze. Nejde o automatický důkaz bootu. Akce neodesílá Start, Stop, Restart, Restore, sync ani nový deploy.

Pro již běžící GTNH 2.9.0 s chybějícím boot záznamem:

1. Načti nový kód pluginu také ve webovém PHP runtime podle běžného upgrade postupu. Nic na maty zde nebylo provedeno.
2. Otevři ManagedServer 2 a kartu **konkrétního aktivního nasazení do 2.9.0**. Ponech původní checkpoint i zamčenou zálohu. Nepoužívej staré UUID rollbacku 2.8.1.
3. Jako root admin klikni **Potvrdit běžící nasazení** a potvrď, že ses připojil a ověřil běh této cílové verze.
4. Pokud kontroly projdou, historie uvede „Běh nasazení potvrdil admin; boot nebyl automaticky ověřen“, installed_version bude 2.9.0 a baseline bude přesný původní upstream manifest připravené 2.9.0. Pouze tento checkpoint se uzavře. Záloha a audit zůstanou.

Akce vyžaduje aktivní Native deploy v `awaiting_manual_start`, `native_deploy_files_verified=true`, předané ovládání v roli deploy, žádný nejasný Wings zápis a přesnou dokončenou zamčenou zálohu. Kontroluje aktuální běžící instanci včetně postupujícího uptime, Startup/image/proměnné Wings, obsah aktivního launcheru a argumentů, očekávaný cílový runtime, identitu operace a neměnný autentizovaný cílový manifest. Nepotřebuje původní použitelný Start záznam ani Done log.

Při nesouladu nasazeného souboru vypíše **přesnou cestu a očekávaný/skutečný SHA-256**, neuzavře operaci a nic neopravuje automaticky. Kontroluje všechny cesty v původním `native_after`, včetně očekávané nepřítomnosti odstraněných souborů a explicitních runtime dependencies. Žádná nasazená cesta není plošně vyjmuta jako „config“ nebo „launcher“.

Lokální `railcraft.cfg`, který byl v původním plánu zachován, zůstává místní změnou a nepřepisuje baseline. Pokud je ale `railcraft.cfg` součástí ověřované nasazené množiny a jeho hash se změnil po deployi, potvrzení ho odmítne a uvede rozdíl. To platí i pro launcher/args, pokud byly přímo součástí schválených mutací. Ručně opravený launcher mimo tuto množinu se znovu obsahově kontroluje a zaznamená odděleně; upstream manifest se nikdy neodvozuje ze současného živého serveru.

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

První příkaz pouze čte server a uloží krátký audit review; nemění operaci ani napájení a nezískává mutation lease. Zkontroluj vypsané operation ID, cílovou verzi, zálohu, šablonu Startupu, image, efektivní SERVER_JARFILE a hashe launcheru/args. Pak do 15 minut:

```bash
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=confirm-running --managed-server=2 --backup="$BACKUP" \
  --target-version=2.9.0 --actor="$ACTOR_ID" \
  --apply --admin-confirms-running --confirm=PROOF_Z_REVIEW
```

Apply získá existující deploy/recovery lease, zopakuje kontroly a požaduje vlastní platné review stejného admina i stejnou běžící instanci. Při změně souboru, proměnné, launcheru, manifestu nebo operace odmítne starý proof. Baseline/verze a uzavření se provedou ve stávající DB transakci. Audit ukládá aktuální konfiguraci/launcher, root aktéra, running instance a manifest digest; `startup_verified=false`, `running_confirmed_by_admin=true`. Normální **Verify running server** nadále vyžaduje skutečný čerstvý boot a automatické Schedules tuto admin akci nepoužívají.

## SERVER_JARFILE a upstream kontrakt

Povolena je pouze celá `{{SERVER_JARFILE}}` bez uvozovek přímo za `-jar`. Efektivní hodnota používá stejné pravidlo jako Pelican: serverový override, nebo při null/absenci override default eggu. Prázdný override není default. Název musí být jeden root `Crucible*.jar` podle původní přísné gramatiky, následně se ověřuje skutečný obsah JAR manifestu, SHA-256 a permissions. Cesty, extra argumenty, shell, jiné proměnné, obecná expanze a vlastní classpath se nepovolují. Doslovný název nadále funguje.

DB Startup zůstává šablonou. Efektivní hodnota je součástí runtime comparison proof a snapshotu Pelican proměnných; změna override/defaultu zneplatní původní schválení. Stejný resolver používají Compare, Keep existing Crucible, zaznamenání ručního Startu, Verify i admin potvrzení.

Skutečný Wings RC2 `cbce59e` vrací v `ToAPIResponse` neexpandovanou `Config.Invocation`; `parseInvocation` sestavuje rozvinuté STARTUP zvlášť až pro kontejner. Proto se konfigurace porovnává jako **šablona + efektivní environment**, nikoli jako dosazený příkaz proti šabloně. Zdroje: [Wings server.go](https://github.com/pelican/wings/blob/cbce59e/server/server.go), [Wings router_server.go](https://github.com/pelican/wings/blob/cbce59e/router/router_server.go), [Panel EnvironmentService](https://github.com/pelican/panel/blob/f64a167/app/Services/Servers/EnvironmentService.php).

## Panel RC3

Oficiální Panel [v1.0.0-rc.3](https://github.com/pelican/panel/releases/tag/v1.0.0-rc.3), commit `6095cea`, je přidán do CI vedle RC2. Startup configuration a EnvironmentService mají proti RC2 stejný kontrakt, vztah Server::variables nadále vrací `server_value ?? default_value`. RC3 také změnil `TaskSchemaInterface::canCreate` o volitelné user/payload argumenty; plugin implementuje podpis kompatibilní s RC2 i RC3 a zachovává root-only oprávnění a whitelist původních payloadů. Samotná verze Panelu nedokazuje verzi Wings na maty. Rozsah pluginu `^1.0.0-rc.2` zahrnuje RC3.

Cílená lokální reprodukce (pouze disposable checkouty):

```bash
php tests/crucible_startup.php
PELICAN_SOURCE=/tmp/disposable-rc2 php tests/integration/native_running_confirmation.php
PELICAN_SOURCE=/tmp/disposable-rc3 php tests/integration/native_running_confirmation.php
```

Lokálně prošly cílené regrese na oficiálním RC2 `f64a167` i RC3 `6095cea`, s jejich vlastními Composer lockfile závislostmi. Pokrývají doslovný JAR, egg default/server override, neplatné názvy i obsah JARu, změnu proměnné po Compare, normální Start/Verify s placeholderem a oddělené admin dokončení bez boot logu přes CLI i kartu. Ověřují přesný odmítnutý souborový drift, zachování místního railcraft.cfg a upstream manifestu, historii s `startup_verified=false`, zamčenou zálohu a nulová power/restore/sync volání při potvrzení. Také prošel původní automatický boot s `startup_verified=true`, parser/console jednotkové regrese a kontrola kompatibility append patche a plugin API na RC3. Wings v runtime testech jsou HTTP fixture, databáze izolovaná SQLite; živé Minecraft/Wings ani maty nebyly testovány či měněny.
