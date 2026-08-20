<?php
# admin_did_optimizer_pool.php
#
# Admin page for managing the did_optimizer_pool table used by the custom
# DID Optimizer AGI (did_optimizer.agi). Not part of stock VICIdial - this
# table and this page are project-specific additions.
#
# Requires full admin login (vicidial_users, user_level > 7), same
# authorization mechanism used by admin.php.
#
# Features: add single DID, bulk-add via CSV upload, sync DIDs directly from
# VICIdial's own vicidial_inbound_dids table (spread across area codes).

require("dbconnect_mysqli.php");
require("functions.php");

# Match did_optimizer_pool / vicidial_* table collation (utf8_unicode_ci) so
# comparisons and joins against vicidial_inbound_dids don't hit "Illegal mix
# of collations" the way did_optimizer.agi originally did before it was fixed.
mysqli_query($link, "SET NAMES utf8 COLLATE utf8_unicode_ci");

$PHP_AUTH_USER=$_SERVER['PHP_AUTH_USER'];
$PHP_AUTH_PW=$_SERVER['PHP_AUTH_PW'];
$PHP_SELF=$_SERVER['PHP_SELF'];

$auth_message = user_authorization($PHP_AUTH_USER,$PHP_AUTH_PW,'',1,0);
if ($auth_message != 'GOOD')
	{
	Header("WWW-Authenticate: Basic realm=\"CONTACT-CENTER-ADMIN\"");
	Header("HTTP/1.0 401 Unauthorized");
	echo "Login incorrect, please try again: |$PHP_AUTH_USER|$auth_message|\n";
	exit;
	}

function diop_clean_digits($val, $max_len)
	{
	$val = preg_replace('/\D/', '', (string)$val);
	return substr($val, 0, $max_len);
	}

function diop_clean_campaign_id($val)
	{
	$val = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$val);
	return substr($val, 0, 20);
	}

function diop_local_key_from_did($did_number)
	{
	if (preg_match('/^1([2-9]\d{2})[2-9]\d{6}$/', $did_number, $m))
		{return $m[1];}
	if (preg_match('/^([2-9]\d{2})[2-9]\d{6}$/', $did_number, $m))
		{return $m[1];}
	return '';
	}

function diop_insert_did($link, $did_number, $campaign_id, $local_key, $enabled, $admin_priority, $daily_limit, $country_code='1')
	{
	if (strlen($did_number) < 7 || strlen($did_number) > 32) {return 'invalid_did';}
	if ($campaign_id == '') {return 'invalid_campaign';}
	if ($local_key == '') {$local_key = diop_local_key_from_did($did_number);}
	$local_key = substr($local_key, 0, 16);
	$enabled = ($enabled == 'N') ? 'N' : 'Y';
	$admin_priority = (int)$admin_priority;
	$daily_limit = max(0, (int)$daily_limit);

	$stmt = mysqli_prepare($link,
		"INSERT INTO did_optimizer_pool
		    (did_number, campaign_id, country_code, local_key, enabled, admin_priority, daily_limit)
		 VALUES (?, ?, ?, ?, ?, ?, ?)");
	mysqli_stmt_bind_param($stmt, 'sssssii',
		$did_number, $campaign_id, $country_code, $local_key, $enabled, $admin_priority, $daily_limit);
	$ok = mysqli_stmt_execute($stmt);
	$err = mysqli_errno($link);
	mysqli_stmt_close($stmt);
	if ($ok) {return 'added';}
	if ($err == 1062) {return 'duplicate';}
	return 'error';
	}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$message = '';
$message_class = 'diop-ok';

if ($action == 'add')
	{
	$did_number  = diop_clean_digits($_POST['did_number'], 32);
	$campaign_id = diop_clean_campaign_id($_POST['campaign_id']);
	$local_key   = diop_clean_digits($_POST['local_key'], 16);
	$enabled     = ($_POST['enabled'] == 'N') ? 'N' : 'Y';
	$admin_priority = (int)$_POST['admin_priority'];
	$daily_limit    = max(0, (int)$_POST['daily_limit']);
	$country_code   = diop_clean_digits(isset($_POST['country_code']) ? $_POST['country_code'] : '1', 8);
	if ($country_code == '') {$country_code = '1';}

	$result = diop_insert_did($link, $did_number, $campaign_id, $local_key, $enabled, $admin_priority, $daily_limit, $country_code);
	if ($result == 'added')
		{$message = "Added DID $did_number to campaign $campaign_id.";}
	elseif ($result == 'duplicate')
		{$message = "That DID already exists in this campaign's pool."; $message_class = 'diop-err';}
	elseif ($result == 'invalid_did')
		{$message = "DID number must be 7-32 digits."; $message_class = 'diop-err';}
	elseif ($result == 'invalid_campaign')
		{$message = "Campaign is required."; $message_class = 'diop-err';}
	else
		{$message = "Could not add DID: " . mysqli_error($link); $message_class = 'diop-err';}
	}
elseif ($action == 'toggle')
	{
	$did_id = (int)$_POST['did_id'];
	$stmt = mysqli_prepare($link,
		"UPDATE did_optimizer_pool SET enabled = IF(enabled='Y','N','Y') WHERE did_id = ?");
	mysqli_stmt_bind_param($stmt, 'i', $did_id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$message = "Updated DID #$did_id.";
	}
elseif ($action == 'update')
	{
	$did_id = (int)$_POST['did_id'];
	$daily_limit = max(0, (int)$_POST['daily_limit']);
	$admin_priority = (int)$_POST['admin_priority'];
	$stmt = mysqli_prepare($link,
		"UPDATE did_optimizer_pool SET daily_limit = ?, admin_priority = ? WHERE did_id = ?");
	mysqli_stmt_bind_param($stmt, 'iii', $daily_limit, $admin_priority, $did_id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$message = "Updated DID #$did_id.";
	}
elseif ($action == 'delete')
	{
	$did_id = (int)$_POST['did_id'];
	$stmt = mysqli_prepare($link, "DELETE FROM did_optimizer_pool WHERE did_id = ?");
	mysqli_stmt_bind_param($stmt, 'i', $did_id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$message = "Deleted DID #$did_id.";
	}
elseif ($action == 'upload_csv')
	{
	$default_campaign = diop_clean_campaign_id($_POST['csv_campaign_id']);
	$default_enabled  = ($_POST['csv_enabled'] == 'N') ? 'N' : 'Y';
	$default_priority = (int)$_POST['csv_admin_priority'];
	$default_limit    = max(0, (int)$_POST['csv_daily_limit']);

	if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK)
		{
		$message = "No CSV file uploaded, or upload failed.";
		$message_class = 'diop-err';
		}
	else
		{
		$fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
		$added = 0; $dup = 0; $invalid = 0; $rows_seen = 0;
		$max_rows = 5000;
		while ($fh && ($row = fgetcsv($fh)) !== false && $rows_seen < $max_rows)
			{
			$rows_seen++;
			if (count($row) < 1) {continue;}
			$raw_did = isset($row[0]) ? $row[0] : '';
			$did_number = diop_clean_digits($raw_did, 32);
			if ($did_number == '') {continue;} # blank / header row
			if (!preg_match('/^\d+$/', preg_replace('/\D/','',$raw_did)) && strlen($did_number) < 7)
				{$invalid++; continue;}

			$campaign_id = isset($row[1]) && trim($row[1]) != '' ? diop_clean_campaign_id($row[1]) : $default_campaign;
			$local_key   = isset($row[2]) ? diop_clean_digits($row[2], 16) : '';
			$enabled     = isset($row[3]) && trim($row[3]) != '' ? (strtoupper(trim($row[3]))=='N' ? 'N' : 'Y') : $default_enabled;
			$admin_priority = isset($row[4]) && trim($row[4]) != '' ? (int)$row[4] : $default_priority;
			$daily_limit    = isset($row[5]) && trim($row[5]) != '' ? max(0,(int)$row[5]) : $default_limit;

			$result = diop_insert_did($link, $did_number, $campaign_id, $local_key, $enabled, $admin_priority, $daily_limit);
			if ($result == 'added') {$added++;}
			elseif ($result == 'duplicate') {$dup++;}
			else {$invalid++;}
			}
		if ($fh) {fclose($fh);}
		$message = "CSV processed: $added added, $dup already existed (skipped), $invalid invalid/skipped rows.";
		}
	}
elseif ($action == 'sync')
	{
	$sync_campaign  = diop_clean_campaign_id($_POST['sync_campaign_id']);
	$sync_npas_raw  = trim($_POST['sync_npas']);
	$sync_npa_count = max(1, min(300, (int)$_POST['sync_npa_count']));
	$sync_per_npa   = max(1, min(50, (int)$_POST['sync_per_npa']));
	$sync_enabled   = ($_POST['sync_enabled'] == 'N') ? 'N' : 'Y';
	$sync_priority  = (int)$_POST['sync_admin_priority'];
	$sync_limit     = max(0, (int)$_POST['sync_daily_limit']);

	if ($sync_campaign == '')
		{
		$message = "Target campaign is required for sync.";
		$message_class = 'diop-err';
		}
	else
		{
		$npas = array();
		if ($sync_npas_raw != '')
			{
			foreach (preg_split('/[,\s]+/', $sync_npas_raw) as $n)
				{
				$n = diop_clean_digits($n, 3);
				if (strlen($n) == 3) {$npas[] = $n;}
				}
			}
		else
			{
			$rslt = mysqli_query($link,
				"SELECT SUBSTRING(did_pattern,2,3) AS npa, COUNT(*) AS cnt
				   FROM vicidial_inbound_dids
				  WHERE did_pattern REGEXP '^1[0-9]{10}$'
				  GROUP BY npa
				  ORDER BY cnt DESC
				  LIMIT " . (int)$sync_npa_count . ";");
			while ($row = mysqli_fetch_assoc($rslt))
				{$npas[] = $row['npa'];}
			}

		$added = 0; $dup = 0; $skipped_no_candidate = 0;
		foreach ($npas as $npa)
			{
			$stmt = mysqli_prepare($link,
				"SELECT did_pattern FROM vicidial_inbound_dids
				  WHERE did_pattern LIKE ?
				    AND did_pattern REGEXP '^1[0-9]{10}$'
				    AND did_pattern NOT IN (
				        SELECT did_number FROM did_optimizer_pool WHERE campaign_id = ?
				    )
				  ORDER BY did_id
				  LIMIT ?;");
			$like = '1' . $npa . '%';
			mysqli_stmt_bind_param($stmt, 'ssi', $like, $sync_campaign, $sync_per_npa);
			mysqli_stmt_execute($stmt);
			$res = mysqli_stmt_get_result($stmt);
			$found_any = false;
			while ($row = mysqli_fetch_assoc($res))
				{
				$found_any = true;
				$result = diop_insert_did($link, $row['did_pattern'], $sync_campaign, $npa, $sync_enabled, $sync_priority, $sync_limit);
				if ($result == 'added') {$added++;}
				elseif ($result == 'duplicate') {$dup++;}
				}
			mysqli_stmt_close($stmt);
			if (!$found_any) {$skipped_no_candidate++;}
			}
		$message = "Sync complete: $added DIDs added across " . count($npas) . " area code(s) ($dup duplicates skipped, $skipped_no_candidate area codes had no available DIDs).";
		}
	}
elseif ($action == 'bulk_limit')
	{
	$bulk_campaign = diop_clean_campaign_id($_POST['bulk_campaign_id']);
	$bulk_limit = max(0, (int)$_POST['bulk_daily_limit']);
	if ($bulk_campaign == '')
		{
		$message = "Campaign is required for bulk limit update.";
		$message_class = 'diop-err';
		}
	else
		{
		$stmt = mysqli_prepare($link, "UPDATE did_optimizer_pool SET daily_limit = ? WHERE campaign_id = ?");
		mysqli_stmt_bind_param($stmt, 'is', $bulk_limit, $bulk_campaign);
		mysqli_stmt_execute($stmt);
		$affected = mysqli_stmt_affected_rows($stmt);
		mysqli_stmt_close($stmt);
		$message = "Set daily limit to $bulk_limit for $affected DID(s) in campaign $bulk_campaign.";
		}
	}

$filter_campaign = isset($_GET['campaign_id']) ? diop_clean_campaign_id($_GET['campaign_id']) : '';
$filter_search   = isset($_GET['q']) ? diop_clean_digits($_GET['q'], 32) : '';
$filter_status   = isset($_GET['status']) && in_array($_GET['status'], array('Y','N')) ? $_GET['status'] : '';

$campaign_rows = array();
$rslt = mysqli_query($link, "SELECT campaign_id, campaign_name FROM vicidial_campaigns ORDER BY campaign_id;");
while ($row = mysqli_fetch_assoc($rslt))
	{$campaign_rows[] = $row;}

$where_parts = array();
if ($filter_campaign != '')
	{$where_parts[] = "campaign_id = '" . mysqli_real_escape_string($link, $filter_campaign) . "'";}
if ($filter_search != '')
	{$where_parts[] = "did_number LIKE '%" . mysqli_real_escape_string($link, $filter_search) . "%'";}
if ($filter_status != '')
	{$where_parts[] = "enabled = '" . mysqli_real_escape_string($link, $filter_status) . "'";}
$where = count($where_parts) ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

# Whitelisted sortable columns only - never interpolate $_GET['sort'] directly into SQL.
$sort_map = array(
	'did'         => 'did_number',
	'campaign'    => 'campaign_id',
	'npa'         => 'local_key',
	'status'      => 'enabled',
	'priority'    => 'admin_priority',
	'limit'       => 'daily_limit',
	'calls_today' => 'calls_today_effective',
	'total'       => 'total_assignments',
	'last_used'   => 'last_used',
);
$sort_key = isset($_GET['sort']) && isset($sort_map[$_GET['sort']]) ? $_GET['sort'] : '';
$sort_dir = (isset($_GET['dir']) && $_GET['dir'] == 'desc') ? 'desc' : 'asc';
$order_by = $sort_key
	? ($sort_map[$sort_key] . ' ' . $sort_dir . ', did_id ' . $sort_dir)
	: 'campaign_id, local_key, did_number';

$per_page = 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$count_rslt = mysqli_query($link,
	"SELECT COUNT(*) AS cnt,
	        COALESCE(SUM(enabled='Y'), 0) AS enabled_cnt,
	        COALESCE(SUM(CASE WHEN usage_date=CURDATE() THEN calls_today ELSE 0 END), 0) AS calls_today_sum,
	        COALESCE(SUM(total_assignments), 0) AS assignments_sum,
	        COALESCE(SUM(daily_limit>0 AND (CASE WHEN usage_date=CURDATE() THEN calls_today ELSE 0 END)>=daily_limit), 0) AS limit_reached_cnt
	   FROM did_optimizer_pool $where;");
$count_row = mysqli_fetch_assoc($count_rslt);
$total_filtered = (int)$count_row['cnt'];
$total_filtered_enabled = (int)$count_row['enabled_cnt'];
$total_calls_today = (int)$count_row['calls_today_sum'];
$total_assignments_sum = (int)$count_row['assignments_sum'];
$total_limit_reached = (int)$count_row['limit_reached_cnt'];
$total_pages = max(1, (int)ceil($total_filtered / $per_page));
if ($page > $total_pages) {$page = $total_pages;}
$offset = ($page - 1) * $per_page;

$pool_rows = array();
$rslt = mysqli_query($link,
	"SELECT did_id, did_number, campaign_id, local_key, enabled, admin_priority,
	        total_assignments,
	        CASE WHEN usage_date = CURDATE() THEN calls_today ELSE 0 END AS calls_today_effective,
	        daily_limit, last_used, created_at
	   FROM did_optimizer_pool
	   $where
	  ORDER BY $order_by
	  LIMIT $per_page OFFSET $offset;");
while ($row = mysqli_fetch_assoc($rslt))
	{$pool_rows[] = $row;}

$total_pool = $total_filtered;
$total_enabled = $total_filtered_enabled;

function diop_sort_link($PHP_SELF, $label, $key, $sort_key, $sort_dir, $qs_base)
	{
	$next_dir = ($sort_key == $key && $sort_dir == 'asc') ? 'desc' : 'asc';
	$arrow = '';
	if ($sort_key == $key) {$arrow = ($sort_dir == 'asc') ? ' &uarr;' : ' &darr;';}
	$url = htmlspecialchars($PHP_SELF) . '?' . $qs_base . '&sort=' . urlencode($key) . '&dir=' . urlencode($next_dir);
	return '<a href="'.$url.'" class="hover:text-blue-600">'.htmlspecialchars($label).$arrow.'</a>';
	}

$qs_parts = array();
if ($filter_campaign != '') {$qs_parts[] = 'campaign_id=' . urlencode($filter_campaign);}
if ($filter_search != '') {$qs_parts[] = 'q=' . urlencode($filter_search);}
if ($filter_status != '') {$qs_parts[] = 'status=' . urlencode($filter_status);}
$qs_base = implode('&', $qs_parts);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DID Optimizer Pool</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
:root {
	--ink: #172033;
	--muted: #68738a;
	--line: #dfe5ee;
	--surface: #ffffff;
	--canvas: #f3f6fa;
	--brand: #3157d5;
	--brand-dark: #213c9a;
	--teal: #0d9488;
}
* { box-sizing: border-box; }
body.diop-body {
	margin: 0; color: var(--ink); background: var(--canvas);
	font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.diop-hero { position: relative; overflow: hidden; color: white; background: #111a2e; }
.diop-hero:after {
	content: ""; position: absolute; width: 32rem; height: 32rem; right: -8rem; top: -20rem;
	border-radius: 999px; background: radial-gradient(circle, rgba(79,110,232,.65), rgba(79,110,232,0) 68%);
}
.diop-hero-inner { position: relative; z-index: 1; max-width: 80rem; margin: auto; padding: 2rem 1.5rem 4.4rem; }
.diop-eyebrow { font-size: .68rem; letter-spacing: .16em; text-transform: uppercase; color: #aebcf5; font-weight: 800; }
.diop-title { margin: .35rem 0 .3rem; font-size: clamp(1.6rem, 3vw, 2.35rem); line-height: 1.1; font-weight: 750; }
.diop-subtitle { max-width: 42rem; color: #aeb8cb; font-size: .86rem; }
.diop-shell { max-width: 80rem; margin: -2.8rem auto 0; padding: 0 1.5rem 3rem; position: relative; z-index: 2; }
.diop-stats { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: .8rem; margin-bottom: 1rem; }
.diop-stat { background: rgba(255,255,255,.98); border: 1px solid rgba(223,229,238,.8); border-radius: 1rem; padding: 1rem 1.1rem; box-shadow: 0 12px 35px rgba(24,35,66,.08); }
.diop-stat-label { color: var(--muted); font-size: .66rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 800; }
.diop-stat-value { margin-top: .25rem; font-size: 1.5rem; line-height: 1; font-weight: 750; font-variant-numeric: tabular-nums; }
.diop-stat-note { color: #98a1b3; font-size: .69rem; margin-top: .35rem; }
.diop-notice { border: 1px solid #f1d59d; background: #fffaf0; color: #7a5210; border-radius: .85rem; padding: .8rem 1rem; margin-bottom: 1rem; font-size: .74rem; line-height: 1.55; }
.diop-actions { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: .7rem; margin: 1rem 0; }
.diop-action { cursor: pointer; display: block; min-height: 5rem; border: 1px solid var(--line); border-radius: .9rem; padding: .9rem 1rem; background: white; box-shadow: 0 4px 16px rgba(24,35,66,.04); transition: .16s ease; }
.diop-action:hover { transform: translateY(-2px); border-color: #9dafef; box-shadow: 0 10px 24px rgba(49,87,213,.12); }
.diop-action strong { display: block; color: var(--ink); font-size: .8rem; }
.diop-action span { display: block; color: var(--muted); font-size: .68rem; margin-top: .3rem; line-height: 1.35; }
.diop-action-primary { background: linear-gradient(135deg,var(--brand),#5578ea); border-color: transparent; }
.diop-action-primary strong, .diop-action-primary span { color: white; }
.diop-action-primary span { opacity: .76; }
.diop-filter-card, .diop-table-card { background: white; border: 1px solid var(--line); border-radius: 1rem; box-shadow: 0 5px 22px rgba(24,35,66,.045); }
.diop-filter-card { padding: 1rem; margin-bottom: .85rem; }
.diop-table-card { overflow: auto; }
.diop-table-card table { border-collapse: separate; border-spacing: 0; min-width: 920px; }
.diop-table-card thead th { position: sticky; top: 0; z-index: 1; background: #f8fafc; border-bottom: 1px solid var(--line); }
.diop-table-card tbody tr { transition: background .12s ease; }
.diop-table-card tbody tr:hover { background: #f5f8ff; }
input, select { transition: border-color .15s, box-shadow .15s; }
input:focus, select:focus { outline: none; border-color: #7890e9 !important; box-shadow: 0 0 0 3px rgba(49,87,213,.12); }
.diop-modal-overlay { background: rgba(12,18,32,.66); backdrop-filter: blur(4px); padding-left: 1rem; padding-right: 1rem; }
.diop-modal-overlay > div { border: 1px solid rgba(255,255,255,.7); }
.diop-empty { padding: 3.5rem 1rem; text-align: center; color: var(--muted); }
.diop-empty strong { display: block; color: var(--ink); font-size: .9rem; margin-bottom: .3rem; }
@media (max-width: 850px) {
	.diop-stats, .diop-actions { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 520px) {
	.diop-hero-inner { padding: 1.5rem 1rem 4rem; }
	.diop-shell { padding-left: .8rem; padding-right: .8rem; }
	.diop-stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
	.diop-actions { grid-template-columns: 1fr; }
}
/*
 * Exclusive CSS-only modal state. Tailwind's default `peer-checked:` variant
 * compiles to the GENERAL sibling combinator (":checked ~ .peer-checked\:X"),
 * which matches every later sibling, not just the paired one - that caused
 * multiple modals to appear stacked at once. This uses the ADJACENT sibling
 * combinator ("+") instead, combined with a single shared radio group so the
 * browser guarantees only one modal state can ever be checked at a time.
 */
.diop-modal-state { display: none; }
.diop-modal-state:checked + .diop-modal-overlay { display: flex; }
</style>
</head>
<body class="diop-body text-sm">

<header class="diop-hero">
<div class="diop-hero-inner">
<div class="diop-eyebrow">Outbound identity operations</div>
<h1 class="diop-title">DID Optimizer</h1>
<div class="diop-subtitle">Control local-presence inventory, traffic limits, and caller-ID performance from one workspace.</div>
</div>
</header>

<main class="diop-shell">

<section class="diop-stats" aria-label="Pool summary">
<div class="diop-stat"><div class="diop-stat-label">DID inventory</div><div class="diop-stat-value"><?php echo $total_pool; ?></div><div class="diop-stat-note"><?php echo $total_enabled; ?> enabled in current view</div></div>
<div class="diop-stat"><div class="diop-stat-label">Calls today</div><div class="diop-stat-value"><?php echo $total_calls_today; ?></div><div class="diop-stat-note">Across filtered DIDs</div></div>
<div class="diop-stat"><div class="diop-stat-label">All assignments</div><div class="diop-stat-value"><?php echo $total_assignments_sum; ?></div><div class="diop-stat-note">Lifetime optimizer selections</div></div>
<div class="diop-stat"><div class="diop-stat-label">At daily limit</div><div class="diop-stat-value"><?php echo $total_limit_reached; ?></div><div class="diop-stat-note"><?php echo $filter_campaign != '' ? 'Campaign '.htmlspecialchars($filter_campaign) : 'All visible campaigns'; ?></div></div>
</section>

<?php if ($message != '') {
	$is_err = ($message_class == 'diop-err');
	$box_class = $is_err
		? 'bg-red-50 border-red-300 text-red-800'
		: 'bg-green-50 border-green-300 text-green-800';
	?>
<div class="border text-xs rounded-md px-4 py-3 mb-4 <?php echo $box_class; ?>">
<?php echo htmlspecialchars($message); ?>
</div>
<?php } ?>

<input type="radio" name="diop-modal" id="modal-none" class="diop-modal-state" checked>

<section class="diop-actions" aria-label="Pool actions">
<label for="modal-add" class="diop-action diop-action-primary"><strong>＋ Add a single DID</strong><span>Create one campaign-owned caller ID.</span></label>
<label for="modal-csv" class="diop-action"><strong>Upload CSV</strong><span>Import a prepared inventory in bulk.</span></label>
<label for="modal-sync" class="diop-action"><strong>Sync VICIdial inventory</strong><span>Bring authorized inbound DIDs into the pool.</span></label>
<label for="modal-bulk" class="diop-action"><strong>Set campaign limits</strong><span>Apply one daily cap across a campaign.</span></label>
</section>

<!-- Modal: Add a single DID -->
<input type="radio" name="diop-modal" id="modal-add" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-4 pr-8">Add a single DID</h2>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="space-y-3">
<input type="hidden" name="action" value="add">
<div>
<label class="block text-xs text-gray-500 mb-1">DID Number</label>
<input type="text" name="did_number" placeholder="12125550101" required
       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Campaign</label>
<select name="campaign_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="">-- select --</option>
<?php foreach ($campaign_rows as $c) {
	$len_warn = (strlen($c['campaign_id']) > 8) ? ' (over 8 chars!)' : '';
	echo '<option value="'.htmlspecialchars($c['campaign_id']).'">'
		.htmlspecialchars($c['campaign_id']).' - '.htmlspecialchars($c['campaign_name']).$len_warn.'</option>';
} ?>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Local Key (area code, optional)</label>
<input type="text" name="local_key" placeholder="auto-detected if blank"
       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<div class="grid grid-cols-2 gap-3">
<div>
<label class="block text-xs text-gray-500 mb-1">Enabled</label>
<select name="enabled" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="Y">Y</option><option value="N">N</option>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Admin Priority</label>
<input type="number" name="admin_priority" value="0" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Daily Limit (0 = unlimited)</label>
<input type="number" name="daily_limit" value="0" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Add DID</button>
</form>
</div>
</div>

<!-- Modal: Upload CSV -->
<input type="radio" name="diop-modal" id="modal-csv" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-4 pr-8">Upload CSV</h2>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" enctype="multipart/form-data" class="space-y-3">
<input type="hidden" name="action" value="upload_csv">
<div>
<label class="block text-xs text-gray-500 mb-1">CSV File</label>
<input type="file" name="csv_file" accept=".csv,text/csv" required
       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm bg-white">
<div class="text-xs text-gray-400 mt-1">Columns: did_number, campaign_id, local_key, enabled, admin_priority, daily_limit.
Only did_number is required per row &mdash; blank columns fall back to the defaults below. No header row needed.</div>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Default Campaign (used when a row has no campaign_id)</label>
<select name="csv_campaign_id" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="">-- none --</option>
<?php foreach ($campaign_rows as $c) {
	echo '<option value="'.htmlspecialchars($c['campaign_id']).'">'.htmlspecialchars($c['campaign_id']).'</option>';
} ?>
</select>
</div>
<div class="grid grid-cols-2 gap-3">
<div>
<label class="block text-xs text-gray-500 mb-1">Default Enabled</label>
<select name="csv_enabled" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="Y">Y</option><option value="N">N</option>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Default Priority</label>
<input type="number" name="csv_admin_priority" value="0" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Default Daily Limit</label>
<input type="number" name="csv_daily_limit" value="0" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Upload &amp; Import</button>
</form>
</div>
</div>

<!-- Modal: Sync from VICIdial -->
<input type="radio" name="diop-modal" id="modal-sync" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-2 pr-8">Sync from VICIdial DID inventory</h2>
<div class="text-xs text-gray-400 mb-4">
Pulls real leased/owned numbers already in VICIdial's own <code class="font-mono">vicidial_inbound_dids</code> table
into this pool, spread across area codes for local-presence coverage. Numbers already in the target
campaign's pool are skipped automatically.
</div>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="space-y-3">
<input type="hidden" name="action" value="sync">
<div>
<label class="block text-xs text-gray-500 mb-1">Target Campaign</label>
<select name="sync_campaign_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="">-- select --</option>
<?php foreach ($campaign_rows as $c) {
	echo '<option value="'.htmlspecialchars($c['campaign_id']).'">'.htmlspecialchars($c['campaign_id']).'</option>';
} ?>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Specific area codes (optional, comma-separated)</label>
<input type="text" name="sync_npas" placeholder="e.g. 442, 626, 212 - leave blank to auto-pick"
       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<div class="text-xs text-gray-400 mt-1">If blank, the area codes with the most available numbers are picked automatically.</div>
</div>
<div class="grid grid-cols-2 gap-3">
<div>
<label class="block text-xs text-gray-500 mb-1"># of area codes (if auto-picking)</label>
<input type="number" name="sync_npa_count" value="50" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">DIDs per area code</label>
<input type="number" name="sync_per_npa" value="1" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
</div>
<div class="grid grid-cols-2 gap-3">
<div>
<label class="block text-xs text-gray-500 mb-1">Enabled</label>
<select name="sync_enabled" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="Y">Y</option><option value="N">N</option>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Admin Priority</label>
<input type="number" name="sync_admin_priority" value="0" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Daily Limit (0 = unlimited)</label>
<input type="number" name="sync_daily_limit" value="10" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Sync DIDs</button>
</form>
</div>
</div>

<!-- Modal: Bulk-set daily limit -->
<input type="radio" name="diop-modal" id="modal-bulk" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-2 pr-8">Bulk-set daily limit for a campaign</h2>
<div class="text-xs text-gray-400 mb-4">
Applies one daily limit to every DID currently in the selected campaign's pool
(overwrites each DID's individual limit &mdash; enabled and disabled DIDs alike).
</div>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="space-y-3">
<input type="hidden" name="action" value="bulk_limit">
<div>
<label class="block text-xs text-gray-500 mb-1">Campaign</label>
<select name="bulk_campaign_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="">-- select --</option>
<?php foreach ($campaign_rows as $c) {
	echo '<option value="'.htmlspecialchars($c['campaign_id']).'">'.htmlspecialchars($c['campaign_id']).'</option>';
} ?>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Daily Limit (0 = unlimited)</label>
<input type="number" name="bulk_daily_limit" value="10" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Apply to Campaign</button>
</form>
</div>
</div>

<div class="diop-filter-card">
<form method="get" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="flex flex-wrap items-end gap-3 text-xs">
<div>
<label class="block text-gray-500 mb-1">Search DID</label>
<input type="text" name="q" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="digits..."
       class="border border-gray-300 rounded-md px-2 py-1.5 text-xs w-40">
</div>
<div>
<label class="block text-gray-500 mb-1">Campaign</label>
<select name="campaign_id" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs">
<option value="">-- all campaigns --</option>
<?php foreach ($campaign_rows as $c) {
	$sel = ($c['campaign_id'] == $filter_campaign) ? ' selected' : '';
	echo '<option value="'.htmlspecialchars($c['campaign_id']).'"'.$sel.'>'.htmlspecialchars($c['campaign_id']).'</option>';
} ?>
</select>
</div>
<div>
<label class="block text-gray-500 mb-1">Status</label>
<select name="status" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs">
<option value="">-- all --</option>
<option value="Y" <?php echo ($filter_status=='Y')?'selected':''; ?>>Enabled</option>
<option value="N" <?php echo ($filter_status=='N')?'selected':''; ?>>Disabled</option>
</select>
</div>
<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-md font-medium">Filter</button>
<?php if ($filter_campaign != '' || $filter_search != '' || $filter_status != '') { ?>
<a href="<?php echo htmlspecialchars($PHP_SELF); ?>" class="text-gray-400 hover:text-gray-700 underline">Clear filters</a>
<?php } ?>
<div class="ml-auto text-gray-400"><?php echo $total_filtered; ?> matching DID(s)</div>
</form>
</div>

<div class="diop-table-card">
<table class="w-full text-xs">
<thead>
<tr class="bg-gray-50 text-gray-500 uppercase text-[11px] tracking-wide">
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'DID','did',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Campaign','campaign',$sort_key,$sort_dir,$qs_base); ?></th><th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Status','status',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Priority','priority',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Daily Limit','limit',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Calls Today','calls_today',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Total Assignments','total',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Last Used','last_used',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left">Actions</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php if (count($pool_rows) == 0) { ?>
<tr><td colspan="9" class="diop-empty"><strong>No DIDs found</strong>Adjust the filters or add inventory to this pool.</td></tr>
<?php } ?>
<?php foreach ($pool_rows as $r) {
	$row_bg = ($r['enabled']=='N') ? 'bg-gray-50 text-gray-400' : '';
	$badge = ($r['enabled']=='Y')
		? '<span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-green-100 text-green-700">Enabled</span>'
		: '<span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-red-100 text-red-700">Disabled</span>';
	?>
<tr class="<?php echo $row_bg; ?> hover:bg-blue-50/40">
<td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($r['did_number']); ?></td>
<td class="px-3 py-2"><?php echo htmlspecialchars($r['campaign_id']); ?></td>
<td class="px-3 py-2"><?php echo $badge; ?></td>
<td class="px-3 py-2"><?php echo (int)$r['admin_priority']; ?></td>
<td class="px-3 py-2"><?php echo (int)$r['daily_limit']; ?></td>
<td class="px-3 py-2"><?php echo (int)$r['calls_today_effective']; ?></td>
<td class="px-3 py-2"><?php echo (int)$r['total_assignments']; ?></td>
<td class="px-3 py-2"><?php echo htmlspecialchars($r['last_used'] ? $r['last_used'] : '-'); ?></td>
<td class="px-3 py-2">
<div class="flex items-center gap-1.5">
<label for="modal-view-<?php echo (int)$r['did_id']; ?>" title="View calls"
       class="cursor-pointer w-7 h-7 flex items-center justify-center rounded-md bg-gray-500 hover:bg-gray-600 text-white">
<svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
<path d="M10 3.5c-4.5 0-8 3.6-8 6.5s3.5 6.5 8 6.5 8-3.6 8-6.5-3.5-6.5-8-6.5zm0 10.5a4 4 0 110-8 4 4 0 010 8z"/>
<path d="M10 8a2 2 0 100 4 2 2 0 000-4z"/>
</svg>
</label>
<label for="modal-row-<?php echo (int)$r['did_id']; ?>" title="Edit"
       class="cursor-pointer w-7 h-7 flex items-center justify-center rounded-md bg-blue-600 hover:bg-blue-700 text-white">
<svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
<path d="M13.586 3.586a2 2 0 112.828 2.828l-9.9 9.9-3.535.707.707-3.535 9.9-9.9z"/>
</svg>
</label>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="inline"
      onsubmit="return confirm('Delete this DID from the pool?');">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="did_id" value="<?php echo (int)$r['did_id']; ?>">
<button type="submit" title="Delete"
        class="w-7 h-7 flex items-center justify-center rounded-md bg-red-600 hover:bg-red-700 text-white">
<svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
<path fill-rule="evenodd" d="M8 2a1 1 0 00-1 1v1H4a1 1 0 000 2h12a1 1 0 100-2h-3V3a1 1 0 00-1-1H8zM5 7a1 1 0 011 1v8a2 2 0 002 2h4a2 2 0 002-2V8a1 1 0 112 0v8a4 4 0 01-4 4H8a4 4 0 01-4-4V8a1 1 0 011-1z" clip-rule="evenodd"/>
</svg>
</button>
</form>
</div>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>

<div class="flex items-center justify-between mt-3 text-xs text-gray-500">
<div>Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total_filtered; ?> total)</div>
<div class="flex items-center gap-1">
<?php
$pg_qs = $qs_base != '' ? $qs_base . '&' : '';
if ($sort_key) {$pg_qs .= 'sort=' . urlencode($sort_key) . '&dir=' . urlencode($sort_dir) . '&';}
if ($page > 1) {
	echo '<a href="'.htmlspecialchars($PHP_SELF).'?'.$pg_qs.'page='.($page-1).'" class="px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">Prev</a>';
} else {
	echo '<span class="px-3 py-1.5 border border-gray-200 rounded-md text-gray-300">Prev</span>';
}
$window_start = max(1, $page - 3);
$window_end = min($total_pages, $page + 3);
for ($p = $window_start; $p <= $window_end; $p++) {
	if ($p == $page) {
		echo '<span class="px-3 py-1.5 border border-blue-600 bg-blue-600 text-white rounded-md">'.$p.'</span>';
	} else {
		echo '<a href="'.htmlspecialchars($PHP_SELF).'?'.$pg_qs.'page='.$p.'" class="px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">'.$p.'</a>';
	}
}
if ($page < $total_pages) {
	echo '<a href="'.htmlspecialchars($PHP_SELF).'?'.$pg_qs.'page='.($page+1).'" class="px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">Next</a>';
} else {
	echo '<span class="px-3 py-1.5 border border-gray-200 rounded-md text-gray-300">Next</span>';
}
?>
</div>
</div>

<?php foreach ($pool_rows as $r) { ?>
<input type="radio" name="diop-modal" id="modal-row-<?php echo (int)$r['did_id']; ?>" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-4 pr-8">
<?php echo htmlspecialchars($r['did_number']); ?>
<span class="text-xs text-gray-400 font-normal">(<?php echo htmlspecialchars($r['campaign_id']); ?>)</span>
</h2>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="space-y-3">
<input type="hidden" name="action" value="update">
<input type="hidden" name="did_id" value="<?php echo (int)$r['did_id']; ?>">
<div class="grid grid-cols-2 gap-3">
<div>
<label class="block text-xs text-gray-500 mb-1">Daily Limit</label>
<input type="number" name="daily_limit" value="<?php echo (int)$r['daily_limit']; ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Admin Priority</label>
<input type="number" name="admin_priority" value="<?php echo (int)$r['admin_priority']; ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Save</button>
</form>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="mt-3">
<input type="hidden" name="action" value="toggle">
<input type="hidden" name="did_id" value="<?php echo (int)$r['did_id']; ?>">
<button type="submit" class="w-full bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium rounded-md py-2">
<?php echo ($r['enabled']=='Y') ? 'Disable this DID' : 'Enable this DID'; ?>
</button>
</form>
</div>
</div>

<?php
$calls = array();
$stmt = mysqli_prepare($link,
	"SELECT a.unique_call_id, a.server_ip, a.lead_id, a.destination, a.selection_reason, a.callerid_applied, a.assigned_at,
	        v.status, v.length_in_sec
	   FROM did_optimizer_assignments a
	   LEFT JOIN vicidial_log v ON v.uniqueid = a.unique_call_id AND v.campaign_id = a.campaign_id
	  WHERE a.did_number = ? AND a.campaign_id = ?
	  ORDER BY a.assigned_at DESC
	  LIMIT 100;");
mysqli_stmt_bind_param($stmt, 'ss', $r['did_number'], $r['campaign_id']);
mysqli_stmt_execute($stmt);
$calls_res = mysqli_stmt_get_result($stmt);
while ($crow = mysqli_fetch_assoc($calls_res)) {$calls[] = $crow;}
mysqli_stmt_close($stmt);
?>
<input type="radio" name="diop-modal" id="modal-view-<?php echo (int)$r['did_id']; ?>" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[95%] max-w-3xl max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-1 pr-8">
Calls placed using <?php echo htmlspecialchars($r['did_number']); ?>
<span class="text-xs text-gray-400 font-normal">(<?php echo htmlspecialchars($r['campaign_id']); ?>)</span>
</h2>
<div class="text-xs text-gray-400 mb-3"><?php echo count($calls); ?> call(s) shown (most recent 100).</div>
<?php if (count($calls) == 0) { ?>
<div class="text-xs text-gray-400 italic py-6 text-center">No calls have used this DID yet.</div>
<?php } else { ?>
<div class="border border-gray-200 rounded-md overflow-x-auto">
<table class="w-full text-xs">
<thead>
<tr class="bg-gray-50 text-gray-500 uppercase text-[10px] tracking-wide">
<th class="px-2 py-1.5 text-left">Lead ID</th>
<th class="px-2 py-1.5 text-left">Server</th>
<th class="px-2 py-1.5 text-left">Destination</th>
<th class="px-2 py-1.5 text-left">Reason</th>
<th class="px-2 py-1.5 text-left">CID Applied</th>
<th class="px-2 py-1.5 text-left">Status</th>
<th class="px-2 py-1.5 text-left">Duration</th>
<th class="px-2 py-1.5 text-left">Assigned At</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php foreach ($calls as $c) { ?>
<tr class="hover:bg-blue-50/40">
<td class="px-2 py-1.5"><?php echo (int)$c['lead_id']; ?></td>
<td class="px-2 py-1.5 font-mono"><?php echo htmlspecialchars($c['server_ip']); ?></td>
<td class="px-2 py-1.5 font-mono"><?php echo htmlspecialchars($c['destination']); ?></td>
<td class="px-2 py-1.5"><?php echo htmlspecialchars($c['selection_reason']); ?></td>
<td class="px-2 py-1.5"><?php echo ($c['callerid_applied']=='Y') ? '<span class="text-green-600 font-semibold">Y</span>' : '<span class="text-red-600 font-semibold">N</span>'; ?></td>
<td class="px-2 py-1.5"><?php echo $c['status'] !== null ? htmlspecialchars($c['status']) : '<span class="text-gray-300">pending</span>'; ?></td>
<td class="px-2 py-1.5"><?php echo $c['length_in_sec'] !== null ? (int)$c['length_in_sec'].'s' : '-'; ?></td>
<td class="px-2 py-1.5"><?php echo htmlspecialchars($c['assigned_at']); ?></td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<?php } ?>
</div>
</div>
<?php } ?>

</main>
</body>
</html>
