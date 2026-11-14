#!/usr/bin/env python3
"""Operator-only collector. Run manually as root on the Wings node, never from PHP."""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import uuid


def now():
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="microseconds").replace("+00:00", "Z")


def inspect(server):
    raw = subprocess.run(["docker", "container", "inspect", server], check=True, stdout=subprocess.PIPE,
                         stderr=subprocess.PIPE, timeout=20)
    rows = json.loads(raw.stdout)
    if len(rows) != 1:
        raise ValueError("Expected exactly one container")
    item = rows[0]
    state = item["State"]
    result = {"Id": item["Id"], "Name": item["Name"], "RestartCount": item["RestartCount"],
              "State": {k: state[k] for k in ["Running", "StartedAt", "Pid"]}}
    if (result["Name"] != "/" + server or state["Running"] is not True or state["Pid"] <= 0
            or not re.fullmatch(r"[a-f0-9]{64}", result["Id"])):
        raise ValueError("Exact server container is not running")
    return result


def collect(operation, server, output):
    # Input validation also prevents Docker option injection. No shell command is constructed.
    operation = str(uuid.UUID(operation))
    server = str(uuid.UUID(server))
    output = Path(output)
    if not output.is_absolute() or output.resolve() != output:
        raise ValueError("Use a canonical absolute output directory")
    output.mkdir(mode=0o700)  # Refuse reuse/overwrite of an earlier evidence directory.
    before = inspect(server)
    until = now()
    digest = hashlib.sha256()
    total = 0
    with (output / "console.log").open("xb") as log:
        os.chmod(log.name, 0o600)
        with subprocess.Popen(["docker", "container", "logs", "--timestamps", "--since",
                               before["State"]["StartedAt"], "--until", until, before["Id"]],
                              stdout=subprocess.PIPE, stderr=subprocess.STDOUT) as process:
            try:
                # A hung Docker CLI cannot keep an operator blocked indefinitely.
                import selectors
                import time
                deadline = time.monotonic() + 120
                with selectors.DefaultSelector() as selector:
                    selector.register(process.stdout, selectors.EVENT_READ)
                    while True:
                        remaining = deadline - time.monotonic()
                        if remaining <= 0 or not selector.select(remaining):
                            raise ValueError("Docker log collection timed out")
                        chunk = os.read(process.stdout.fileno(), 65536)
                        if not chunk:
                            break
                        total += len(chunk)
                        if total > 536870912:
                            raise ValueError("Docker log exceeds the 512 MiB evidence limit")
                        digest.update(chunk)
                        log.write(chunk)
                if process.wait(timeout=10) != 0:
                    raise ValueError("Docker log collection failed; incomplete bundle must not be imported")
            except BaseException:
                process.kill()
                process.wait()
                raise
    after = inspect(server)  # Check the UUID still resolves to the SAME ID, start time, PID and restart count.
    if before != after:
        raise ValueError("Container changed during collection; incomplete bundle must not be imported")
    meta = {"schema": 1, "operation_id": operation, "server_uuid": server,
            "collected_at": now(), "inspect_before": before, "inspect_after": after,
            "log_sha256": digest.hexdigest()}
    with (output / "metadata.json").open("x") as file:
        os.chmod(file.name, 0o600)
        json.dump(meta, file, sort_keys=True)
        file.write("\n")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation")
    parser.add_argument("server_uuid")
    parser.add_argument("output_directory")
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error("Run as root on the Wings node. Do not grant the Panel Docker socket access.")
    os.umask(0o077)
    try:
        collect(args.operation, args.server_uuid, args.output_directory)
    except Exception:
        # Docker errors can contain private data. Preserve incomplete files privately, never print their content.
        print("Evidence collection failed; no complete bundle was produced. Check the node privately.", file=sys.stderr)
        return 1
    print("Private evidence bundle created. Transfer securely to the Panel host and run the audited dry run promptly.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
