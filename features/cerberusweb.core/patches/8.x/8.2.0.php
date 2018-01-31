<?php
$db = DevblocksPlatform::services()->database();
$logger = DevblocksPlatform::services()->log();
$tables = $db->metaTables();

// ===========================================================================
// Remove plugins from ./storage/plugins/ that moved to ./features/

$recursive_delete = function($dir) use (&$recursive_delete) {
	$dir = rtrim($dir,"/\\") . '/';
	
	if(!file_exists($dir) || !is_dir($dir))
		return false;
	
	// Ignore development directories
	if(file_exists($dir . '.git/'))
		return false;
	
	$storage_path = APP_STORAGE_PATH . '/plugins/';
	
	// Make sure the file is in the ./storage/plugins path
	if(0 != substr_compare($storage_path, $dir, 0, strlen($storage_path)))
		return false;
	
	$files = glob($dir . '*', GLOB_MARK);
	foreach($files as $file) {
		if(is_dir($file)) {
			$recursive_delete($file);
		} else {
			unlink($file);
		}
	}
	
	if(file_exists($dir) && is_dir($dir))
		rmdir($dir);
	
	return true;
};

$dirs = [
	'cerb.bots.portal.widget',
	'cerb.project_boards',
	'cerb.webhooks',
];

$plugin_dir = APP_STORAGE_PATH . '/plugins';

foreach($dirs as $dir) {
	$recursive_delete($plugin_dir . '/' . $dir);
}

// ===========================================================================
// Add `custom_record`

if(!isset($tables['custom_record'])) {
	$sql = sprintf("
	CREATE TABLE `custom_record` (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(255) DEFAULT '',
		name_plural VARCHAR(255) DEFAULT '',
		uri VARCHAR(255) DEFAULT '',
		params_json TEXT,
		updated_at INT UNSIGNED NOT NULL DEFAULT 0,
		primary key (id),
		index (updated_at)
	) ENGINE=%s;
	", APP_DB_ENGINE);
	$db->ExecuteMaster($sql) or die("[MySQL Error] " . $db->ErrorMsgMaster());

	$tables['custom_record'] = 'custom_record';
	
} else {
	list($columns, $indexes) = $db->metaTable('custom_record');
	
	if(isset($columns['uri_profile'])) {
		$sql = "ALTER TABLE custom_record CHANGE COLUMN uri_profile uri varchar(255) not null default ''";
		$db->ExecuteMaster($sql);
		
	} else if(!isset($columns['uri'])) {
		$sql = "ALTER TABLE custom_record ADD COLUMN uri varchar(255) not null default '' after name_plural";
		$db->ExecuteMaster($sql);
	}
}

// ===========================================================================
// Create `email_signature` table

if(!isset($tables['email_signature'])) {
	$sql = sprintf("
	CREATE TABLE `email_signature` (
		id int unsigned auto_increment,
		name varchar(255) default '',
		owner_context varchar(255) not null default '',
		owner_context_id int unsigned not null default 0,
		signature text,
		is_default tinyint(3) unsigned not null default 0,
		updated_at int unsigned not null default 0,
		primary key (id),
		index (updated_at)
	) ENGINE=%s;
	", APP_DB_ENGINE);
	$db->ExecuteMaster($sql) or die("[MySQL Error] " . $db->ErrorMsgMaster());

	$tables['email_signature'] = 'email_signature';
	
} else {
	list($columns, $indexes) = $db->metaTable('email_signature');
	
	if(!isset($columns['owner_context'])) {
		$sql = "ALTER TABLE email_signature ADD COLUMN owner_context varchar(255) not null default '', ADD COLUMN owner_context_id int unsigned not null default 0";
		$db->ExecuteMaster($sql);
		
		$db->ExecuteMaster("UPDATE email_signature SET owner_context = 'cerberusweb.contexts.app'");
	}
}

// ===========================================================================
// Add `reply_signature_id` to the `bucket` table

if(!isset($tables['bucket'])) {
	$logger->error("The 'bucket' table does not exist.");
	return FALSE;
}

list($columns, $indexes) = $db->metaTable('bucket');

if(!isset($columns['reply_signature_id'])) {
	$sql = 'ALTER TABLE bucket ADD COLUMN reply_signature_id int(10) unsigned NOT NULL DEFAULT 0 AFTER reply_signature';
	$db->ExecuteMaster($sql);
}

if(isset($columns['reply_signature'])) {
	$sql = "SELECT b.id AS bucket_id, b.name AS bucket_name, b.group_id, g.name AS group_name, b.reply_signature FROM bucket b INNER JOIN worker_group g ON (g.id=b.group_id) WHERE b.reply_signature != ''";
	$results = $db->GetArrayMaster($sql);
	
	if(is_array($results))
	foreach($results as $result) {
		$sql = sprintf("INSERT INTO email_signature (name, signature, owner_context, owner_context_id, updated_at) VALUES (%s, %s, %s, %d, %d)",
			$db->qstr(sprintf('%s: %s', $result['group_name'], $result['bucket_name'])),
			$db->qstr($result['reply_signature']),
			$db->qstr('cerberusweb.contexts.group'),
			$result['group_id'],
			time()
		);
		$db->ExecuteMaster($sql);
		$sig_id = $db->LastInsertId();
		
		$db->ExecuteMaster(sprintf("UPDATE bucket SET reply_signature_id = %d WHERE id = %d",
			$sig_id,
			$result['bucket_id']
		));
	}
	
	$sql = 'ALTER TABLE bucket DROP COLUMN reply_signature';
	$db->ExecuteMaster($sql);
}

// ===========================================================================
// Add `reply_signature_id` to the `address_outgoing` table

if(isset($tables['address_outgoing'])) {
	list($columns, $indexes) = $db->metaTable('address_outgoing');
	
	if(!isset($columns['reply_signature_id'])) {
		$sql = 'ALTER TABLE address_outgoing ADD COLUMN reply_signature_id int(10) unsigned NOT NULL DEFAULT 0 AFTER reply_signature';
		$db->ExecuteMaster($sql);
	}
	
	if(isset($columns['reply_signature'])) {
		$sql = "SELECT ao.address_id, a.email, ao.is_default, ao.reply_signature FROM address_outgoing ao INNER JOIN address a ON (a.id=ao.address_id) WHERE ao.reply_signature != ''";
		$results = $db->GetArrayMaster($sql);
		
		if(is_array($results))
		foreach($results as $result) {
			$sql = sprintf("INSERT INTO email_signature (name, signature, is_default, owner_context, owner_context_id, updated_at) VALUES (%s, %s, %d, %s, %d, %d)",
				$db->qstr($result['email']),
				$db->qstr($result['reply_signature']),
				$result['is_default'] ? 1 : 0,
				$db->qstr('cerberusweb.contexts.app'),
				0,
				time()
			);
			$db->ExecuteMaster($sql);
			$sig_id = $db->LastInsertId();
			
			$db->ExecuteMaster(sprintf("UPDATE address_outgoing SET reply_signature_id = %d WHERE address_id = %d",
				$sig_id,
				$result['address_id']
			));
		}
	}
}

// ===========================================================================
// Add `reply_*` field defaults to the `worker_group` table

if(!isset($tables['worker_group'])) {
	$logger->error("The 'worker_group' table does not exist.");
	return FALSE;
}

list($columns, $indexes) = $db->metaTable('worker_group');

$changes = [];

if(!isset($columns['reply_address_id'])) {
	$changes[] = 'ADD COLUMN reply_address_id int(10) unsigned NOT NULL DEFAULT 0';
}

if(!isset($columns['reply_personal'])) {
	$changes[] = "ADD COLUMN reply_personal varchar(255) not null default ''";
}

if(!isset($columns['reply_signature_id'])) {
	$changes[] = 'ADD COLUMN reply_signature_id int(10) unsigned NOT NULL DEFAULT 0';
}

if(!isset($columns['reply_html_template_id'])) {
	$changes[] = 'ADD COLUMN reply_html_template_id int(10) unsigned NOT NULL DEFAULT 0';
}

if(!empty($changes))
	$db->ExecuteMaster("ALTER TABLE worker_group " . implode(', ', $changes));

if(!isset($columns['reply_address_id'])) {
	// Copy the inbox defaults to the group
	$db->ExecuteMaster("UPDATE worker_group AS g INNER JOIN bucket AS b ON (b.group_id=g.id AND b.is_default=1) SET g.reply_address_id=b.reply_address_id, g.reply_personal=b.reply_personal, g.reply_signature_id=b.reply_signature_id, g.reply_html_template_id=b.reply_html_template_id");
	
	// Copy the replyto defaults to the group
	$db->ExecuteMaster("UPDATE worker_group AS g INNER JOIN address_outgoing AS ao ON (ao.address_id=g.reply_address_id) SET g.reply_personal=ao.reply_personal WHERE g.reply_personal = ''");
	$db->ExecuteMaster("UPDATE worker_group AS g INNER JOIN address_outgoing AS ao ON (ao.address_id=g.reply_address_id) SET g.reply_signature_id=ao.reply_signature_id WHERE g.reply_signature_id = 0");
	$db->ExecuteMaster("UPDATE worker_group AS g INNER JOIN address_outgoing AS ao ON (ao.address_id=g.reply_address_id) SET g.reply_html_template_id=ao.reply_html_template_id WHERE g.reply_html_template_id = 0");
	
	// Finally, use the old default replyto for everything else
	$db->ExecuteMaster("UPDATE worker_group AS g INNER JOIN address_outgoing AS ao ON (ao.is_default=1) SET g.reply_address_id=ao.address_id WHERE g.reply_address_id = 0");
	$db->ExecuteMaster("UPDATE worker_group AS g INNER JOIN address_outgoing AS ao ON (ao.is_default=1) SET g.reply_personal=ao.reply_personal WHERE g.reply_personal = ''");
	$db->ExecuteMaster("UPDATE worker_group AS g INNER JOIN address_outgoing AS ao ON (ao.is_default=1) SET g.reply_signature_id=ao.reply_signature_id WHERE g.reply_signature_id = 0");
	$db->ExecuteMaster("UPDATE worker_group AS g INNER JOIN address_outgoing AS ao ON (ao.is_default=1) SET g.reply_html_template_id=ao.reply_html_template_id WHERE g.reply_html_template_id = 0");
}

// ===========================================================================
// Add `mail_transport_id` to the `address` table

if(!isset($tables['address'])) {
	$logger->error("The 'address' table does not exist.");
	return FALSE;
}

list($columns, $indexes) = $db->metaTable('address');

$changes = [];

if(!isset($columns['mail_transport_id'])) {
	$changes[] = 'ADD COLUMN mail_transport_id int(10) unsigned NOT NULL DEFAULT 0';
	$changes[] = 'ADD INDEX (mail_transport_id)';
}

if(!empty($changes))
	$db->ExecuteMaster("ALTER TABLE address " . implode(', ', $changes));

// Move the email<->transport link to address records
if(!isset($columns['mail_transport_id'])) {
	$db->ExecuteMaster("UPDATE address a INNER JOIN address_outgoing ao ON (ao.address_id=a.id) SET a.mail_transport_id=ao.reply_mail_transport_id");
	$db->ExecuteMaster("UPDATE address SET mail_transport_id = (SELECT id FROM mail_transport WHERE is_default = 1) WHERE id IN (SELECT address_id FROM address_outgoing WHERE reply_mail_transport_id = 0)");
	$db->ExecuteMaster("REPLACE INTO devblocks_setting (plugin_id, setting, value) VALUES ('cerberusweb.core','mail_default_from_id', (SELECT address_id FROM address_outgoing WHERE is_default = 1 LIMIT 1))");
}

// ===========================================================================
// Drop the now-redundant sender address table

if(isset($tables['address_outgoing'])) {
	$db->ExecuteMaster("DROP TABLE address_outgoing");
}

// ===========================================================================
// From `mail_transport.is_default`

if(!isset($tables['mail_transport'])) {
	$logger->error("The 'mail_transport' table does not exist.");
	return FALSE;
}

list($columns, $indexes) = $db->metaTable('mail_transport');

if(isset($columns['is_default'])) {
	$sql = 'ALTER TABLE mail_transport DROP COLUMN is_default';
	$db->ExecuteMaster($sql);
}

// ===========================================================================
// Increase `bucket.reply_personal` to varchar(255)

if(!isset($tables['bucket'])) {
	$logger->error("The 'bucket' table does not exist.");
	return FALSE;
}

list($columns, $indexes) = $db->metaTable('bucket');

if(!isset($columns['reply_personal'])) {
	$logger->error("The 'bucket.reply_personal' column does not exist.");
	return FALSE;
}

if(0 == strcasecmp('varchar(128)', $columns['reply_personal']['type'])) {
	$sql = "ALTER TABLE bucket MODIFY COLUMN reply_personal varchar(255) not null default ''";
	$db->ExecuteMaster($sql);
}

// ===========================================================================
// Create `reminder`

if(!isset($tables['reminder'])) {
	$sql = sprintf("
	CREATE TABLE `reminder` (
		id int(10) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(255) NOT NULL DEFAULT '',
		worker_id int(10) unsigned NOT NULL DEFAULT '0',
		remind_at int(10) unsigned NOT NULL DEFAULT '0',
		params_json text,
		is_closed tinyint(1) unsigned NOT NULL DEFAULT '0',
		updated_at int(10) unsigned NOT NULL DEFAULT '0',
		primary key (id),
		index closed_remind (is_closed, remind_at),
		index worker_closed (worker_id, is_closed),
		index (remind_at),
		index (updated_at)
	) ENGINE=%s;
	", APP_DB_ENGINE);
	$db->ExecuteMaster($sql) or die("[MySQL Error] " . $db->ErrorMsgMaster());

	$tables['reminder'] = 'reminder';
}

// ===========================================================================
// Enable reminder scheduled task and give defaults

if(null != ($cron = DevblocksPlatform::getExtension('cron.reminders', true, true))) {
	$cron->setParam(CerberusCronPageExtension::PARAM_ENABLED, true);
	$cron->setParam(CerberusCronPageExtension::PARAM_DURATION, '1');
	$cron->setParam(CerberusCronPageExtension::PARAM_TERM, 'm');
	$cron->setParam(CerberusCronPageExtension::PARAM_LASTRUN, strtotime('Yesterday 23:59'));
}

// ===========================================================================
// Add `updated_at` field to the `custom_field` table

if(!isset($tables['custom_field'])) {
	$logger->error("The 'custom_field' table does not exist.");
	return FALSE;
}

list($columns, $indexes) = $db->metaTable('custom_field');

if(!isset($columns['updated_at'])) {
	$sql = 'ALTER TABLE custom_field ADD COLUMN updated_at int(10) unsigned NOT NULL DEFAULT 0';
	$db->ExecuteMaster($sql);
	
	$db->ExecuteMaster(sprintf("UPDATE custom_field SET updated_at = %d", time()));
}

// ===========================================================================
// Reset cached models

$db->ExecuteMaster("DELETE FROM worker_view_model WHERE view_id = 'search_cerberusweb_contexts_bucket'");
$db->ExecuteMaster("DELETE FROM worker_view_model WHERE view_id = 'search_cerberusweb_contexts_file_bundle'");
$db->ExecuteMaster("DELETE FROM worker_view_model WHERE view_id = 'search_cerberusweb_contexts_group'");

// ===========================================================================
// Finish up

return TRUE;
