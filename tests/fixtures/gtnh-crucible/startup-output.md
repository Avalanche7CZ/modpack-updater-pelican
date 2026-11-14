# GTNH / Crucible startup evidence regression

`startup-output.txt` is a minimal normalized fixture for the reported ordering:
FML → Java 25 → Starting minecraft server version 1.7.10 → Done. It retains the
Forge initialization and real Crucible marker; no production timestamps, player
output, credentials or full production log are copied into this fixture.

The marker `Crucible DummyMod injected successfully!` is emitted by
[`CrucibleModContainer`](https://github.com/CrucibleMC/Crucible/blob/staging/src/main/java/io/github/crucible/CrucibleModContainer.java).
The generic `Starting minecraft server version 1.7.10` banner also appears in
[Crucible's DedicatedServer patch](https://github.com/CrucibleMC/Crucible/blob/staging/patches/net/minecraft/server/dedicated/DedicatedServer.java.patch),
so that banner cannot identify Vanilla. These upstream messages were checked
when adding this regression; this is not a captured full live-server log.
