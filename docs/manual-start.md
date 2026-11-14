# Ruční převzetí startu (Native Pelican / Wings)

Tento postup nic neinstaluje ani automaticky nemění Crucible JAR. Předání nepovažuje obnovu souborů za úspěšný boot. Vyžaduje novou verzi pluginu v Panelu i načtenou queue workery; instalaci zde neprovádíme.

## Maty: existující dokončený restore bez další obnovy

Spouštěj v adresáři Panelu pod jeho uživatelem. `ACTOR_ID` nastav na ID skutečného Pelican root administrátora. UUID níže jsou pevné bezpečnostní podmínky, nikoli výběr nejnovější zálohy.

```bash
ACTOR_ID=...  # skutečné číselné ID root administrátora
OP=a8557412-63ea-442f-8f86-c9e03e5acf3d
BACKUP=ebfd6a7e-be7f-4735-9a17-cbfd76a1a1cb
REFERENCE=043fb865-8e4a-4b2d-ab30-a5ded60a1d0b
php artisan arcturus-modpack:manual-start "$OP" --actor="$ACTOR_ID" \
  --managed-server=2 --backup="$BACKUP" --reference="$REFERENCE"
```

První příkaz pouze čte. Vyžaduje zastavený Minecraft, totožnou aktivní operaci, dokončenou zamčenou zálohu, jednoznačný callback přesné obnovy, zachovaný původní doklad ověření souborů a aktuální kontrolu spravovaných souborů. Očekávaný runtime přebírá z úspěšného návratu stejné instalace 2.8.1. Nepoužije jeho starý log jako důkaz nového bootu. Launcher a argumenty jsou povolené ruční opravy, evidované odděleně od původního receipt. Svět se znovu neobnovuje ani rekurzivně nehashuje.

Zkontroluj vypsaný Startup, image, launcher/args a očekávaný runtime. Zkopíruj hodnotu `proof` a proveď předání:

```bash
php artisan arcturus-modpack:manual-start "$OP" --actor="$ACTOR_ID" \
  --managed-server=2 --backup="$BACKUP" --reference="$REFERENCE" \
  --apply --confirm=PROOF_Z_PREDCHOZIHO_VYSTUPU
```

Příkaz synchronizuje Wings a odstraní pouze suspend/power overlay pluginu. Skutečnou administrátorskou suspendaci nepřebíjí. Checkpoint zůstává aktivní, záloha zamčená a nový update i automatický Schedule blokované. Neposílá Start, Stop, Restart ani Restore. Při neúspěšné synchronizaci opakuj stejné předání se zachovaným proof; neprováděj nový restore. Nejasný archive write, neověřený file mutation intent nebo cizí recovery lease se odmítnou bez odemčení.

Poté v běžné Pelican Console použij **Start**. Lze normálně upravovat launcher/args a použít Stop/Restart v rámci Pelican oprávnění. Plugin před standardním Start/Restart zaznamená hranici bootu, aktuální Startup/image, hashe launcheru/argfiles a předchozí log. Ruční pád ani opakovaný Start nevyvolají rollback. Při úpravě launcheru po Start je třeba nový ruční Start před ověřením.

GTNH může vypisovat Java/FML/Crucible hlavičky jen na stdout. Pro jejich zachycení spusť po předání v druhém terminálu následující pozorování a poté klikni na Start v Panelu. Observer může běžet již před Start; při novém pokusu automaticky použije jeho vlastní hranici. Každý checkpoint krátce získá stejnou lease, během čekání ji nedrží. Nic nespouští, neobnovuje ani neuzavírá.

```bash
php artisan arcturus-modpack:manual-start "$OP" --action=observe \
  --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP" --seconds=1800
```

Observer uchovává jen rozpoznané boot fakty a digest v šifrovaném checkpointu. Pokud se začátek stdout již odroloval, nedoplňuje chybějící údaje ze starého logu. Lze buď znovu ručně nastartovat se spuštěným pozorováním, nebo ponechat operaci čekající. Úspěšný observer ještě neuzavírá recovery.

Po čerstvém úspěšném bootu:

```bash
php artisan arcturus-modpack:manual-start "$OP" --action=verify \
  --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP"
# Po kontrole vypsaného skutečného Startup/image/launcher/runtime:
php artisan arcturus-modpack:manual-start "$OP" --action=verify \
  --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP" \
  --apply --confirm=NOVY_PROOF_Z_VERIFY
```

Verify vyžaduje postupující uptime konkrétní instance po zaznamenaném Start, neměnné launch nastavení/bytes, očekávaný Minecraft/loader/Java, čerstvé seřazené runtime hlavičky a Done a ověření původních spravovaných souborů. Přijme nový/připsaný latest.log nebo během bootu zachycenou native Wings console. Starý Done, pouze running, chybějící uptime, změněné args či runtime nestačí. Vypíše konkrétní chybějící důkaz a nechá checkpoint čekat. Verify nikdy neposílá power ani restore. Teprve `--apply` s ověřeným proof uzavře tuto recovery, uvolní pouze její active_operation_id a ponechá zálohu i audit.

Pokud startuješ jiným nástrojem než běžnou Pelican Console/API, nejprve použij `--action=arm-start --apply` se stejným UUID a aktérem. To jen zaznamená hranici; Start musí přijít následně. Požadovaný start se musí vejít do stávajícího start ACK okna; spuštění pozorování samo tuto hranici netvoří.

## Nový ruční deploy

Ve stávajícím potvrzení **Update with backup** vyber **Po nasazení spustím server ručně**. Volba je uložena v konkrétní schválené operaci. Výchozí flow a automatické Schedules ji nemají. Záloha, staging, drift kontroly a finální ověření souborů proběhnou normálně. Teprve potom se předají ovládací prvky a zobrazí „Nasazeno, start neověřen“. Chyby zápisu/kontroly před tímto bodem stále používají původní recovery.

Dokud admin neprovede Verify running server, installed_version ani baseline se neposunou a další update zůstává blokovaný. Ověření očekává cílový pack/runtime. Akce v záhlaví **Hand over manual start** a **Verify running server** používají stejnou službu jako CLI. Pro stdout-only GTNH důkazy použij observer výše s UUID nové operace.

## Výslovný rollback

Po zastavení serveru lze z čekajícího checkpointu použít **Recover original installation**, případně:

```bash
php artisan arcturus-modpack:manual-start "$OP" --action=rollback \
  --actor="$ACTOR_ID" --managed-server=2 --backup="$BACKUP" --apply
```

To je samostatné, výslovné povolení nové obnovy přesné původní zálohy a původního ověření bootu. Může znovu vrátit původně rozbitý launcher ze zálohy. Ruční předání ani Verify tuto akci samy nevolají.

## Lokální regrese a omezení důkazu

```bash
PELICAN_SOURCE=/cesta/k/disposable/pelican-rc2 php tests/integration/native_deployment.php
```

Sada používá skutečné Pelican RC2 služby a izolovanou SQLite DB s Wings HTTP systémovým fixture. Pokrývá existující restore se změněným launcherem, přesný callback/backup, cizí lease, nejasný zápis/rename bez odemčení, skutečnou suspendaci, opakovaný ruční crash bez restore, starý Done i připsaný nebootový log, stdout-only nový boot a uzavření recovery, a nový manual deploy s odloženou baseline. Toto je framework/fixture důkaz; běžící Wings, Minecraft ani maty zde ověřeny nebyly.
