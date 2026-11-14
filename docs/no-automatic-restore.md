# Ruční Native update bez automatického Restore

Ve stávajícím **Update with backup** je volba **Při chybě neobnovovat automaticky**, výchozí vypnutá. Pro připravovaný GTNH 2.9.0 ji lze kombinovat s **Po nasazení spustím server ručně**. Obě volby se ukládají do konkrétní schválené operace; restart workeru je nemění. Není to globální nastavení a automatické Schedules ani staré operace bez této volby se nemění. Crucible kompatibilita, JARy a schválený souborový plán zůstávají beze změny.

Při chybě po zahájení změn se zapnutou volbou zůstává Minecraft pod maintenance guardem, přesná záloha zamčená a checkpoint aktivní. UI ukazuje **Nasazení nedokončeno, čeká na admina**. Zůstávají soubory, staging/checkpoint i boot diagnostika; Restore se nevolá a installed_version/baseline se neposouvají. Platí to pro zápis, kontrolu nasazených souborů, automatický boot i selhání workeru. Nejasný Wings zápis se tím nepovažuje za dokončený ani bezpečný pro Start.

Úspěšné ověření souborů s volbou ručního Startu nadále předá ovládání existujícím handoff mechanismem a čeká na čerstvý boot. Další update je blokovaný do Verify running server. Ruční start ani jeho pád nevyvolává automatický rollback.

**Recover original installation** zůstává výslovným povolením Restore. Admin potvrzení vytváří samostatný doklad pro přesné operation ID, backup UUID, root aktéra a nové recovery execution ID. Recovery job s pouhým `recovery_actor_id` nebo se starým souhlasem jiného běhu volbu neobejde. Také opakovaně spuštěný nebo po restartu workeru obnovený job musí mít správný doklad. Výchozí operace bez opt-out používají původní chování.

## Běžící GTNH 2.8.1: čerstvý boot bez restartu

Aktuální produkční latest.log ani export checkpointu nebyly v pracovním prostoru dostupné. Chyba „no fresh ordered Done“ sama nedokazuje chybný běh Minecraftu, ale ani nedokládá úspěšný boot. Znamená, že z aktuálně použitého důkazu nebyl prokázán nový seřazený runtime/Done po zaznamenané hranici. Referenční starý rollback ani pouhý stav running nesmí tento důkaz nahradit.

Existující `observe` lze spustit i **nad již běžící instancí**, bez Stop/Start/Restart/Restore. Použije zachovanou hranici před posledním ručním Startem, porovná současný Wings console tail s původním a připoutá rozpoznaná fakta k aktuálnímu uptime oknu. Pokud v současném tailu zůstaly potřebné runtime hlavičky i nový Done, může důkaz zachytit dodatečně. Pokud se již odrolovaly a úplný fresh latest.log je neobsahuje, žádné údaje nedoplňuje ze starého bootu.

Přesný další čtecí krok pro operátora (cesta `/var/www/pelican/artisan` je příklad z repozitáře, skutečná cesta na maty nebyla potvrzena; `ACTOR_ID` je ID root administrátora):

```bash
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start \
  a8557412-63ea-442f-8f86-c9e03e5acf3d --action=observe --seconds=30 \
  --managed-server=2 --backup=ebfd6a7e-be7f-4735-9a17-cbfd76a1a1cb --actor=ACTOR_ID
sudo -u www-data php /var/www/pelican/artisan arcturus-modpack:manual-start \
  a8557412-63ea-442f-8f86-c9e03e5acf3d --action=verify \
  --managed-server=2 --backup=ebfd6a7e-be7f-4735-9a17-cbfd76a1a1cb --actor=ACTOR_ID
```

Tyto příkazy nic nerestartují ani neobnovují a neuzavírají checkpoint. Observer zapisuje pouze čerstvé rozpoznané důkazy. Teprve pokud read-only verify projde, lze potvrdit jeho proof přes `--action=verify --apply --confirm=PROOF` se stejným ID operace a aktérem. Pokud důkaz zůstane neúplný, checkpoint ponech aktivní a předej k analýze aktuální latest.log a související uložené boot důkazy. Bez těchto dat nelze tvrdit, že se tento běh dá uzavřít bez nového startu; žádný restart zde není automaticky požadován ani spuštěn.

## Cílená regrese

```bash
PELICAN_SOURCE=/cesta/k/disposable/rc2 php tests/integration/native_restore_policy.php
```

Používá reálné Pelican služby a izolovanou DB/Wings HTTP fixture: chyba po částečném zápisu, poškozený nasazený soubor, boot failure a přerušený worker; pro všechny bez přímého i následného Restore, s maintenance a zachovanou baseline. Ověřuje také explicitní rollback, původní chování bez volby, idempotenci schválení a kombinaci obou ručních voleb. Produkce ani živý GTNH se tím netestují.
