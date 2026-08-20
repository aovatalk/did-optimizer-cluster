# Cluster DID Optimizer for VICIdial

DID Optimizer selects an outbound caller ID from a campaign-owned DID pool using
local area-code matching, availability limits, Bayesian-smoothed call performance,
and least-recently-used balancing among similarly performing numbers.

## Architecture

This build supports one shared database and any number of Asterisk/web nodes.
Selection counters, assignment history, and the per-campaign concurrency lock
live in the shared database. Each call is also tagged with the originating
VICIdial `VARserver_ip`, and live-call discovery is restricted to that server.

The package contains no server addresses or database credentials. Each dialer
uses its existing VICIdial configuration, while schema installation connects
through the database node's local MySQL socket.

## Manual cluster install (recommended)

Copy the complete repository to every server. Install the database schema first,
then install the runtime files on each dialer/web node.

### 1. Database node

Run this once on the database-only server:

```bash
chmod +x install_did_optimizer.sh
sudo ./install_did_optimizer.sh --role database
```

Expected result:

```text
Shared database schema ready (5 tables).
```

The database role connects through the local MySQL socket. It does not require
or discover a database IP address and does not store database credentials.

### 2. Dialer/web nodes

Run this separately on every Asterisk/web node:

```bash
chmod +x install_did_optimizer.sh
sudo ./install_did_optimizer.sh --role dialer
```

The dialer role installs:

- `/var/lib/asterisk/agi-bin/did_optimizer.agi`;
- `admin_did_optimizer_pool.php` in the detected VICIdial web directory; and
- `/usr/local/share/did-optimizer/quick-test.sh` with its verification sources.

No server address or database credential is passed to the installer. Dialer
nodes read `VARserver_ip` and all `VARDB_*` settings from their existing
`/etc/astguiclient.conf`; the PHP page uses VICIdial's existing database layer.

### Data safety

Normal installation creates missing tables and upgrades the original schema
without deleting optimizer data. Do not pass `--clean` during an upgrade.
The `--clean` option intentionally drops all optimizer tables, DID pools,
assignment history, and campaign state before recreating them.

## Dialplan

Add the optimizer after VICIdial's `call_log` AGI and before the carrier `Dial()`:

```asterisk
exten => _YOURPATTERN,1,AGI(agi://127.0.0.1:4577/call_log)
exten => _YOURPATTERN,2,AGI(did_optimizer.agi,${campaign_id},${dialed_number},${UNIQUEID},${lead_id})
exten => _YOURPATTERN,3,NoOp(DIDOPT server=${DIDOPT_SERVER_IP} status=${DIDOPT_STATUS} did=${DIDOPT_SELECTED} reason=${DIDOPT_REASON})
exten => _YOURPATTERN,4,Dial(...)
```

Persist the lines in the VICIdial carrier Dialplan Entry rather than editing a
generated Asterisk configuration file directly. Rebuild and reload the
dialplan on every Asterisk node in the cluster.

## Verify

```bash
sudo /usr/local/share/did-optimizer/quick-test.sh
```

Run the test on every dialer/web node. It reads the shared database connection
and local server identity from `/etc/astguiclient.conf`, then validates the Perl
AGI and PHP deployments, all five tables and indexes, and active plus persistent
dialplan integration.

The database node does not need the dialer health test. Its schema is verified
during `sudo ./install_did_optimizer.sh --role database`.

## Runtime configuration

The AGI reads VICIdial database settings from `/etc/astguiclient.conf`. The
installer deploys `did_optimizer.agi` to
`/var/lib/asterisk/agi-bin/did_optimizer.agi`.

Required dialer configuration keys are:

```text
VARserver_ip
VARDB_server
VARDB_database
VARDB_user
VARDB_pass
VARDB_port
```

Each dialer has its own `VARserver_ip`; all dialers use the shared database
settings already maintained by VICIdial. Do not add credentials to the
optimizer scripts.
