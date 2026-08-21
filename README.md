# Cluster DID Optimizer for VICIdial

DID Optimizer selects an outbound caller ID from a campaign-owned DID pool using
local area-code matching, availability limits, Bayesian-smoothed call performance,
and least-recently-used balancing among similarly performing numbers.

## Architecture

This build supports one shared database and any number of Asterisk/web nodes.
Each dialer runs a localhost FastAGI service with 16 pre-forked workers. Every
worker reuses one database connection and maintains bounded performance and
geography caches, avoiding a new Perl process, database login, and full history
scan for every call.

Selection counters, assignment history, and the per-campaign concurrency lock
live in the shared database. FastAGI live-call discovery, idempotency, and
performance correlation are scoped by the originating VICIdial `VARserver_ip`,
preventing identical Asterisk unique IDs from different dialers from crossing
node boundaries during selection.

The package contains no server addresses or database credentials. Each dialer
uses its existing VICIdial configuration, while schema installation connects
through the database node's local MySQL socket.

## Manual cluster install (recommended)

Download only the bootstrap installer on every server. It automatically fetches
the files required for the selected role. Install the database schema first,
then install the runtime files on each dialer/web node.

### Download with verified HTTPS (recommended)

```bash
sudo mkdir -p /usr/src/did-optimizer-cluster
cd /usr/src/did-optimizer-cluster
sudo curl -fLO \
  https://raw.githubusercontent.com/aovatalk/did-optimizer-cluster/refs/heads/main/install_did_optimizer.sh
sudo chmod 0755 install_did_optimizer.sh
```

### Download without SSL certificate verification

Use this only when the server's CA certificates are unavailable or broken:

```bash
sudo mkdir -p /usr/src/did-optimizer-cluster
cd /usr/src/did-optimizer-cluster
sudo curl -kfLO \
  https://raw.githubusercontent.com/aovatalk/did-optimizer-cluster/refs/heads/main/install_did_optimizer.sh
sudo chmod 0755 install_did_optimizer.sh
```

`--insecure` disables TLS certificate verification and can expose the download
to interception or modification. Repairing the server's CA trust and using the
verified HTTPS command is strongly preferred. GitHub redirects plain HTTP to
HTTPS, so an HTTP URL is not a true non-SSL download method.

When CA verification is unavailable, pass `DIDOPT_CURL_INSECURE=1` to the
installer so its role-specific file downloads use the same fallback:

```bash
sudo DIDOPT_CURL_INSECURE=1 ./install_did_optimizer.sh --role database
```

or, on a dialer/web node:

```bash
sudo DIDOPT_CURL_INSECURE=1 ./install_did_optimizer.sh --role dialer
```

### 1. Database node

Run this once on the database-only server:

```bash
chmod +x install_did_optimizer.sh
sudo ./install_did_optimizer.sh --role database
```

Expected result:

```text
Shared database schema ready (7 tables).
```

The database role connects through the local MySQL socket. It does not require
or discover a database IP address and does not store database credentials. It
also downloads and imports `NPA_dataset.zip` into
`did_optimizer_geo_prefixes` for NPA-NXX, city, state, and area-code matching.
It rebuilds `did_optimizer_geo_npa_centroids` after the import so live calls can
rank nearby area codes without aggregating the large postal-level table.

### 2. Dialer/web nodes

Run this separately on every Asterisk/web node:

```bash
chmod +x install_did_optimizer.sh
sudo ./install_did_optimizer.sh --role dialer
```

The dialer role installs:

- `/var/lib/asterisk/agi-bin/did_optimizer.agi`;
- `/etc/systemd/system/did-optimizer-fastagi.service`;
- `admin_did_optimizer_pool.php` in the detected VICIdial web directory; and
- `/usr/local/share/did-optimizer/quick-test.sh` with its verification sources;
  and
- `/usr/local/sbin/uninstall-did-optimizer`.

The installer enables and starts `did-optimizer-fastagi.service`. Its listener
is restricted to `127.0.0.1:4578`; it is not exposed to other cluster nodes.

No server address or database credential is passed to the installer. Dialer
nodes read `VARserver_ip` and all `VARDB_*` settings from their existing
`/etc/astguiclient.conf`; the PHP page uses VICIdial's existing database layer.

## Admin features

The cluster-compatible VICIdial admin page provides:

- single and CSV DID imports;
- full synchronization of outbound DIDs from a selected VICIdial CID group
  (`vicidial_campaign_cid_areacodes`), defaulting to `NORMAL`;
- simple all-DID import with no area-code filtering or import quantity limit;
- campaign-wide and per-DID daily limits;
- a 30-second cooldown before the same DID can be selected again;
- reputation filtering and cached provider results;
- Bayesian DID performance scores weighted by good-call rate (40%), human-answer
  rate (24%), average answered duration (16%), and reputation (20%);
- cluster-node visibility for assignment history;
- browser-persisted automatic refresh intervals; and
- dismissible success and error toast notifications.

### Completed-call correlation

Call history and the PHP Bayesian score first match an optimizer assignment to
`vicidial_log` by exact call `uniqueid`. VICIdial can write the completed call
under a different Asterisk channel-leg ID, so unmatched assignments fall back
to the same campaign, lead, destination, and closest call time within a bounded
window. A row is shown as pending only when neither correlation finds a
completed log record.

### Reputation configuration

Open **Reputation settings** from the DID Optimizer admin page. Enter the API
URL, API key, and cache lifetime once. They are stored in the shared
`did_optimizer_settings` table, so all web nodes use the same configuration.
No `/etc/did_optimizer_reputation.json` file is used, and the API key is never
rendered back into the page.

For stale or missing entries, the page sends an HTTP POST with an `x-api-key`
header and a JSON body such as:

```json
{"numbers":["+12125550101","+13125550102"]}
```

The provider response must contain a `results` array. Each result can include
`number`, `rk_reputation`, `rk_status`, and `error`. Results are shared through
`did_optimizer_reputation_cache`; the AGI uses a neutral reputation component
when the provider is not configured or a DID has no result.

### Data safety

Normal installation creates missing tables and upgrades the original schema
without deleting optimizer data. Do not pass `--clean` during an upgrade.
The `--clean` option intentionally drops all optimizer tables, DID pools,
assignment history, and campaign state before recreating them.

For an existing installation, always run the database-role upgrade first and
then upgrade every dialer. This ensures the centroid table and composite
cluster identity index exist before the new FastAGI workers receive calls.

## Dialplan

Add the optimizer after VICIdial's `call_log` AGI and before the carrier `Dial()`:

```asterisk
exten => _YOURPATTERN,1,AGI(agi://127.0.0.1:4577/call_log)
exten => _YOURPATTERN,2,AGI(agi://127.0.0.1:4578/did_optimizer,${campaign_id},${dialed_number},${UNIQUEID},${lead_id})
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
AGI and PHP deployments, FastAGI systemd service, uninstaller, all seven tables
and indexes, centroid population, and active plus persistent dialplan
integration.

The database node does not need the dialer health test. Its schema is verified
during `sudo ./install_did_optimizer.sh --role database`.

## Scalability

FastAGI workers keep database connections open and ping them only periodically.
Raw per-DID performance metrics are cached for 30 seconds, while geography and
database-version data are cached per worker. Candidate eligibility, reputation
data, the 30-second DID cooldown, daily limits, LRU order, and the final
transactional recheck still run against current shared-database state for every
call.

The default service starts 16 workers and accepts a backlog of 256 connections.
This is a safe starting point, not a guaranteed CPS rating. Traffic near 126
calls per second must be load-tested with the actual campaign distribution,
database latency, pool sizes, and call-history volume. Calls in one campaign
still serialize briefly through that campaign's concurrency row; distributing
traffic across campaigns allows independent locks.

The one-shot `AGI(did_optimizer.agi,...)` entrypoint remains available for
diagnostics and backwards compatibility, but it cannot reuse connections or
in-memory caches and is not recommended for high call rates.

## Uninstall

The installer places the uninstaller at
`/usr/local/sbin/uninstall-did-optimizer`. Removing one dialer preserves all
shared optimizer data:

```bash
sudo uninstall-did-optimizer --role dialer
```

Permanently deleting the shared schema requires the explicit `--purge-data`
option and a typed `DROP asterisk` confirmation:

```bash
sudo uninstall-did-optimizer --role database --purge-data
```

For a combined dialer/database node:

```bash
sudo uninstall-did-optimizer --role all --purge-data
```

Use `--yes` with `--purge-data` only for deliberate non-interactive automation.
The uninstaller stops FastAGI and removes only known optimizer files. It never
edits Asterisk configuration; remove the optimizer line from the VICIdial
carrier Dialplan Entry and rebuild/reload the dialplan separately.

The NPA-NXX dataset is redistributed by the original DID Optimizer project
from [djbelieny/geoinfo-dataset](https://github.com/djbelieny/geoinfo-dataset)
under the MIT License.

## Runtime configuration

Each FastAGI worker reads VICIdial database settings from
`/etc/astguiclient.conf` and reuses its connection. The installer deploys the
runtime to `/var/lib/asterisk/agi-bin/did_optimizer.agi` and manages it through
`did-optimizer-fastagi.service`.

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
