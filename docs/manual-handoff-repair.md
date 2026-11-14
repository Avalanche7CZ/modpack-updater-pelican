# Oprava handoff lease od 552bd70 / v0.5.100

## Co bylo prokázáno

Původní `ManualStart::locked()` získal Laravel cache lease `arcturus-modpack-manager-deploy-{id}` s TTL **14 400 s** i pro handoff/verify dry-run, zaznamenání ručního Startu a každý observer poll. Stejný klíč používá deploy, restore, oba failure callbacky a ostatní recovery nástroje. Výsledek `release()` se zahazoval.

Na skutečném Laravel **FileStore/FileLock** z disposable Pelican RC2 byly reprodukovány tyto výsledky:

- Normální návrat a PHP výjimka: `finally` uvolní zámek, `release=true`, další acquire ihned projde.
- SIGTERM i SIGKILL během původního čtecího callbacku: proces skončí bez dokončení `finally`, FileStore záznam zůstane s téměř celým čtyřhodinovým TTL a další acquire se odmítne.
- Vynucené selhání `Filesystem::delete` při skutečném FileStore release: `release=false`; dříve o tom volající nevěděl. Test je injekce I/O chyby, nikoli tvrzení, že takto selhal disk maty.
- Nová čtecí kontrola a observer: nevytvoří mutation lease, souběžný Start ji může získat a ukončení čtení SIGTERM/SIGKILL po sobě zámek nezanechá.
- Přerušená **mutující** operace si zachová TTL 14 400 s. Ochrana aktivního deploye/restore se nezkracovala.

Časy expirací z maty při TTL 14 400 s odpovídají acquire v **15:18:45** a **15:43:17 CET**. Deploy ukončený ve 13:56:02 tedy sám nevysvětluje vznik těchto dvou záznamů. Starý handoff/observer/web nebo failure callback je mohl vytvořit později. Bez PID/akčního auditu ale nelze určit konkrétní proces ani důvod jeho skončení. OOM, PHP timeout ani produkční `release=false` nebyly prokázány. Pouhé health checky v queue logu nevylučují webový request nebo Artisan.

## Změna kódu

Čtení nemá deploy lease. Kontroluje identitu aktivního checkpointu před/po čtení a při změně odmítne výsledek. Handoff apply znovu získá původní mutation lease a pod ní ověří přesnou operaci, completed/locked backup a restore callback, původní scope proof, skutečné spravované soubory, současný launcher/nastavení a potvrzovací proof. Ani retry neověřené konfigurace neobchází kontrolu souborů a launcheru.

Observer čte Wings bez deploy lease i bez DB row locku přes síť. Ukládá jen konzolové důkazy pod krátkým DB row lockem, porovnává handoff ID a ID posledního Start pokusu. Start ukládá nový pokus atomicky stejným DB mechanismem. Při souběžném Startu observer zahodí výsledek starého pollu a pokračuje s novou hranicí; neobnoví přepsáním starého contextu zaniklý pokus.

Všechny pluginové cesty stejného mutation klíče používají `DeploymentLease`: loguje acquire/held/release/failure, ID operace a serveru, název akce, PID a UTC čas. `release=false` je chybový log s `owned_before_release` a skutečným výsledkem. Ruční apply po takovém výsledku hlásí zachovaný checkpoint a neúspěšné uvolnění; nevyvolává další restore. Owner token ani credentials se nelogují. Čtecí handoff má `read_begin/read_end/read_failure`; observer tyto události zapisuje jednou za běh, ne každou sekundu. Po SIGKILL nemůže proces zapsat release ani failure — samotná absence release neurčuje příčinu ukončení.

## Bezpečné převzetí již existujícího checkpointu na maty

Na maty nebyl proveden žádný příkaz. Použita je absolutní cesta **`/var/www/pelican/artisan`** z příkladu v repozitáři; skutečná cesta tohoto Panelu nebyla poskytnuta. Pokud je jiná, nahraď ji všude níže. Příkazy nepotřebují `cd`. `ACTOR_ID` musí být skutečné číselné ID Pelican root administrátora. Nový kód musí být nejprve dostupný CLI a webu; samotný ZIP na disku nestačí.

```bash
test -f /var/www/pelican/artisan
ACTOR_ID=...  # skutečné ID root administrátora
OP=a8557412-63ea-442f-8f86-c9e03e5acf3d
BACKUP=ebfd6a7e-be7f-4735-9a17-cbfd76a1a1cb
REFERENCE=043fb865-8e4a-4b2d-ab30-a5ded60a1d0b
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=diagnostics --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP"
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP" --reference="$REFERENCE"
```

Poslední příkaz je nyní čtecí a může projít i vedle osiřelého cache zámku. Zkontroluj obnovené spravované soubory, aktuální starý Crucible JAR/args/Startup, Java image a očekávaný runtime z posledního úspěšného návratu 2.8.1. Nemění se receipt původní obnovy, svět, plán 2.9.0 ani vedle odložený dev-283. Pokud apply lease volná je, přeskoč opravu zámku a pokračuj handoffem níže.

### Jen pokud mutation lease stále blokuje apply

Starý FileStore záznam obsahuje náhodný owner token a expiraci, **nikoli PID**. Jeho stáří, vlastník souboru www-data, zapisovatelný adresář ani absence Artisan procesu samy neprokazují osiření. Neprováděj obecný force-unlock.

Nejdříve nezávisle ověř, že GTNH zůstává offline, přesný restore je dokončený a žádný Wings zápis neběží. Přes běžný provozní správce nech doběhnout ostatní operace a dočasně odstav všechny Panel HTTP/PHP, queue, scheduler i ruční Artisan zapisovače používající tuto cache, na všech Panel hostech. Nekilluj probíhající deploy/restore. Obecné příkazy pro stop služeb zde nejsou: jejich skutečná jména a sdílení nebyly ověřeny. Zkontroluj procesy a nové lease logy. Při nejasném aktivním zapisovači nepokračuj.

Teprve při prokázaném klidu zapisovačů:

```bash
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=lease-inspect --actor="$ACTOR_ID" --managed-server=2 \
  --backup="$BACKUP" --reference="$REFERENCE"
# Zkopíruj proof z lease-inspect, nikoli z handoffu:
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=release-orphan --actor="$ACTOR_ID" --managed-server=2 \
  --backup="$BACKUP" --reference="$REFERENCE" --apply --writers-quiesced \
  --confirm=PROOF_Z_LEASE_INSPECT
```

`--writers-quiesced` je výslovné potvrzení nezávislého provozního ověření, ne detektor mrtvého procesu. Příkaz odmítne aktivní apply/restore, jiný checkpoint, nejasný Wings zápis a neověřenou obnovu. Pod DB row lockem a exkluzivním flockem přesného FileStore souboru znovu kontroluje checkpoint, obnovu, managed scope i launcher. HMAC proof zahrnuje nezměněný obsah zámku, expiraci, inode/device a identitu checkpointu. Při změně vlastníka/stavu odmítne opravu a vyžádá novou inspekci.

Pouze tento soubor lease pro ManagedServer 2 nastaví jako expirovaný. **Neunlinkuje ho**, aby neoddělil inode od čekajícího `FileStore::add`. Nemění jiné zámky, checkpoint, zamčenou zálohu ani serverové soubory a nic neobnovuje. Akci uloží do samostatného auditu. Původní obnovovací receipt zůstává beze změny. Zapisovače nech odstavené až do dokončení příkazu; pak obnov obvyklý provoz správcem služeb s novým načteným kódem.

### Handoff, ruční Start, Verify

Po opravě si vyžádej **nový handoff proof** a potvrď ho:

```bash
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP" --reference="$REFERENCE"
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP" --reference="$REFERENCE" \
  --apply --confirm=NOVY_PROOF_Z_HANDOFF
```

Po potvrzeném Wings sync je dostupný běžný Start/Stop/Restart se starým Crucible. Reálná administrátorská suspendace zůstává respektována. Checkpoint i zamčená záloha stále blokují nový deploy/Schedule. Pro GTNH stdout hlavičky spusť v druhém terminálu observer a pak ručně klikni na Start:

```bash
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=observe --seconds=1800 --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP"
```

Observer běží i před Startem, nezabírá mutation lease a nemůže způsobit jeho odmítnutí při souběžném pollu. Pád ručního startu nevyvolá rollback. Po čerstvém Done:

```bash
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=verify --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP"
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start "$OP" \
  --action=verify --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP" \
  --apply --confirm=PROOF_Z_VERIFY
```

Verify nic nerestartuje a neobnovuje. Ověří novou běžící instanci s postupujícím uptime, skutečný launcher/args/Startup/image, očekávaný runtime, čerstvé boot důkazy a obnovené spravované soubory. Starý Done nestačí. Až úspěšné apply uzavře recovery a uvolní pouze její active_operation_id; backup i audit ponechá.

## Proč může chybět „Hand over manual start“

V `552bd70` je podmínka přesně: transport `native`, existující `active_operation_id` patřící tomuto ManagedServer a phase `manual_intervention` nebo `awaiting_manual_start`. `handoff_state=null`, `boot_verification_failed` ani Completed timestamp čekajícího recovery tuto akci neskrývají. Pro popsaný stav je akce v lokálním testu viditelná. Z existence akce v release ZIPu **nelze určit**, co načetl web na maty; konkrétní produkční příčina absence bez webového důkazu potvrzena není.

Nově je stejná akce také přímo v sekci Updates u čekající recovery, aby se nespoléhalo jen na umístění/collapse header actions. Zobrazení už neříká „An operation is running“ pro `manual_intervention` ani `awaiting_manual_start`; přehled serverů uvádí čekání na recovery/boot.

Jako přihlášený root admin otevři na **stejném Panel hostu**:

```text
/arcturus-modpack/console/handoff/2/diagnostics
```

Odpověď je private/no-store. Obsahuje skutečnou DB podmínku viditelnosti, SAPI, PID, načtené page/service code markers, cestu a SHA-256 souborů na disku a verzi package JSON. Pro tento patch oba loaded markers musí být `manual-handoff-leases-v2`. CLI diagnostika je samostatný proces a **není důkazem o FPM**. Starý marker vedle nového disk hash ukazuje starý načtený kód/autoload/OPcache. Rozdíly mezi požadavky mohou odhalit smíšené webové workery/hosty. 404 může znamenat nenačtené nové routes nebo starou route cache; samo o sobě neurčuje, který případ nastal.

Porovnej obsah vlastního ověřeného release ZIPu s cestou a disk hash z webové odpovědi:

```bash
unzip -p /ABSOLUTNI/CESTA/arcturus-modpack-manager.zip \
  arcturus-modpack-manager/src/Filament/Admin/Resources/ManagedServers/Pages/ViewManagedServer.php | sha256sum
unzip -p /ABSOLUTNI/CESTA/arcturus-modpack-manager.zip \
  arcturus-modpack-manager/src/Native/ManualStart.php | sha256sum
```

`package_version_on_disk` dokazuje jen JSON na disku; zdrojový `plugin.json` je šablona a release builder do něj zapisuje konkrétní verzi. `disk_sha256` není hash načteného bytecode. Marker dokládá generaci příslušné načtené třídy, nikoli úplnou identitu každého opcode. Pokud je podmínka true a správná generace načtená, zkontroluj konkrétní stránku/host a inline akci. Při prokázaném starém webovém kódu použij běžný řízený reload příslušného FPM/web runtime po doběhnutí requestů a načti stránku znovu; samotné `opcache_reset()` v CLI nedokazuje reload FPM. Kvůli UI nemaž recovery cache/checkpoint.

## Reprodukce testů

```bash
PELICAN_SOURCE=/tmp/disposable-rc2 php tests/integration/manual_lease.php
PELICAN_SOURCE=/tmp/disposable-rc2 php tests/integration/native_deployment.php
PELICAN_SOURCE=/tmp/disposable-rc2 php tests/integration/livewire.php
```

`manual_lease.php` používá skutečný FileStore, samostatné PHP procesy a signály, nikoli array cache. Native recovery fixture přepíná cache na skutečný FileStore a Wings HTTP systémový fixture testuje opravený launcher, odmítnutí změněného proof, přesnou podmíněnou opravu orphan zámku, revalidaci apply, webovou viditelnost/loaded markers, Start uprostřed observer pollu se zahozením starých důkazů, staré logy, opakovaný ruční crash, fresh boot a finální uzavření. Živé Wings/Minecraft ani produkční databáze maty se nepoužívají. Hostovaný release ZIP a web maty nebyly z tohoto prostředí načteny.

Závěrečně lokálně prošly `manual_lease.php`, `native_deployment.php`, `livewire.php` a `scripts/test.sh`. Disposable Panel je přesně `f64a167`, instalovaný framework odpovídá jeho `composer.lock` (`laravel/framework v13.38.0`). Native recovery regrese používala skutečný FileStore; Wings zůstaly HTTP fixture a DB izolovaná SQLite. Test průběžné změny diagnostických metrik neblokuje čtení, změna recovery journalu ano.

Také byl lokálně znovu sestaven v0.5.100 ZIP z čistého `552bd70`. Jeho page soubor obsahuje `Action::make('handoffManualStart')`, odpovídá zdroji commitu a má SHA-256 `ac29b75e3e7122c9831bbb95376654ea242888aa297b93f8d44720776cd927f6`. Jde o reprodukovaný balíček z daného commitu, nikoli stažení či ověření produkčního ZIPu nebo PHP runtime maty.
