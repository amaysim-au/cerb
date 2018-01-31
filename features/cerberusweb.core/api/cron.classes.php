<?php
/***********************************************************************
| Cerb(tm) developed by Webgroup Media, LLC.
|-----------------------------------------------------------------------
| All source code & content (c) Copyright 2002-2017, Webgroup Media LLC
|   unless specifically noted otherwise.
|
| This source code is released under the Devblocks Public License.
| The latest version of this license can be found here:
| http://cerb.ai/license
|
| By using this software, you acknowledge having read this license
| and agree to be bound thereby.
| ______________________________________________________________________
|	http://cerb.ai	    http://webgroup.media
 ***********************************************************************/

/*
 * PARAMS (overloads):
 * parse_max=n (max tickets to parse)
 *
 */
class ParseCron extends CerberusCronPageExtension {
	function scanDirMessages($dir) {
		if(substr($dir,-1,1) != DIRECTORY_SEPARATOR) $dir .= DIRECTORY_SEPARATOR;
		$files = glob($dir . '*.msg');
		if ($files === false) return array();
		return $files;
	}

	function run() {
		$logger = DevblocksPlatform::services()->log();
		
		$logger->info("[Parser] Starting Parser Task");
		
		if (!extension_loaded("imap")) {
			$logger->err("[Parser] The 'IMAP' extension is not loaded.  Aborting!");
			return false;
		}
		
		if (!extension_loaded("mailparse")) {
			$logger->err("[Parser] The 'mailparse' extension is not loaded.  Aborting!");
			return false;
		}
		
		if (!extension_loaded("mbstring")) {
			$logger->err("[Parser] The 'mbstring' extension is not loaded.  Aborting!");
			return false;
		}

		$timeout = ini_get('max_execution_time');
		$runtime = microtime(true);
		
		// Allow runtime overloads (by host, etc.)
		@$opt_parse_max = DevblocksPlatform::importGPC($_REQUEST['parse_max'],'integer');
		
		$total = !empty($opt_parse_max) ? $opt_parse_max : $this->getParam('max_messages', 500);

		$mailDir = APP_MAIL_PATH . 'new' . DIRECTORY_SEPARATOR;
		$subdirs = glob($mailDir . '*', GLOB_ONLYDIR);
		if ($subdirs === false) $subdirs = array();
		$subdirs[] = $mailDir; // Add our root directory last

		$archivePath = sprintf("%sarchive/%04d/%02d/%02d/",
			APP_MAIL_PATH,
			date('Y'),
			date('m'),
			date('d')
		);
		
		if(defined('DEVELOPMENT_ARCHIVE_PARSER_MSGSOURCE') && DEVELOPMENT_ARCHIVE_PARSER_MSGSOURCE) {
			if(!file_exists($archivePath) && is_writable(APP_MAIL_PATH)) {
				if(false === mkdir($archivePath, 0755, true)) {
					$logger->error("[Parser] Can't write to the archive path: ". $archivePath. " ...skipping copy");
				}
			}
		}
		
		foreach($subdirs as $subdir) {
			if(!is_writable($subdir)) {
				$logger->error('[Parser] Write permission error, unable to parse messages inside: '. $subdir. " ...skipping");
				continue;
			}

			$files = $this->scanDirMessages($subdir);
			
			foreach($files as $file) {
				$filePart = basename($file);

				if(defined('DEVELOPMENT_ARCHIVE_PARSER_MSGSOURCE') && DEVELOPMENT_ARCHIVE_PARSER_MSGSOURCE) {
					if(!copy($file, $archivePath.$filePart)) {
						//...
					}
				}
				
				if(!is_readable($file)) {
					$logger->error('[Parser] Read permission error, unable to parse ' . $file . " ...skipping");
					continue;
				}

				if(!is_writable($file)) {
					$logger->error('[Parser] Write permission error, unable to parse ' . $file . " ...skipping");
					continue;
				}
				
				$parseFile = sprintf("%s/fail/%s",
					APP_MAIL_PATH,
					$filePart
				);
				rename($file, $parseFile);
				
				$this->_parseFile($parseFile);

				if(--$total <= 0) break;
			}
			if($total <= 0) break;
		}
		
		unset($files);
		unset($subdirs);
		
		$logger->info("[Parser] Total Runtime: ".number_format((microtime(true)-$runtime)*1000,2)." ms");
	}

	function _parseFile($full_filename) {
		$logger = DevblocksPlatform::services()->log('Parser');
		
		$fileparts = pathinfo($full_filename);
		$logger->info("Reading ".$fileparts['basename']."...");

		$time = microtime(true);

		if(false == ($message = CerberusParser::parseMimeFile($full_filename))) {
			$logger->error(sprintf("%s failed to parse and it has been saved to the storage/mail/fail/ directory.", $fileparts['basename']));
			return;
		}

		$time = microtime(true) - $time;
		$logger->info("Decoded! (".sprintf("%d",($time*1000))." ms)");

		$time = microtime(true);
		$ticket_id = CerberusParser::parseMessage($message);
		$time = microtime(true) - $time;
		
		$logger->info("Parsed! (".sprintf("%d",($time*1000))." ms) " .
			(!empty($ticket_id) ? ("(Ticket ID: ".$ticket_id.")") : ("(Local Delivery Rejected.)")));

		if(is_bool($ticket_id) && false === $ticket_id) {
			// Leave the message in storage/mail/fail
			$logger->error(sprintf("%s failed to parse and it has been saved to the storage/mail/fail/ directory.", $fileparts['basename']));
			
			// [TODO] Admin notification?
			
		} else {
			@unlink($full_filename);
			$logger->info("The message source has been removed.");
		}
	}

	function configure($instance) {
		$tpl = DevblocksPlatform::services()->template();

		$tpl->assign('max_messages', $this->getParam('max_messages', 500));

		$tpl->display('devblocks:cerberusweb.core::cron/parser/config.tpl');
	}

	function saveConfigurationAction() {
		@$max_messages = DevblocksPlatform::importGPC($_POST['max_messages'],'integer');
		$this->setParam('max_messages', $max_messages);
	}
};

/*
 * PARAMS (overloads):
 * maint_max_deletes=n (max tickets to purge)
 *
 */
// [TODO] Clear idle temp files (fileatime())
class MaintCron extends CerberusCronPageExtension {
	function run() {
		$logger = DevblocksPlatform::services()->log();
		
		$logger->info("[Maint] Starting Maintenance Task");
		
		$db = DevblocksPlatform::services()->database();

		// Platform
		DAO_Platform::maint();
		
		// Purge expired sessions
		Cerb_DevblocksSessionHandler::gc(0);
		
		// Purge Deleted Content
		$purge_waitdays = intval($this->getParam('purge_waitdays', 7));
		$purge_waitsecs = time() - (intval($purge_waitdays) * 86400);

		$sql = sprintf("DELETE FROM ticket ".
			"WHERE status_id = %d ".
			"AND updated_date < %d ",
			Model_Ticket::STATUS_DELETED,
			$purge_waitsecs
		);
		$db->ExecuteMaster($sql);
		
		$logger->info("[Maint] Purged " . $db->Affected_Rows() . " ticket records.");

		// Give plugins a chance to run maintenance (nuke NULL rows, etc.)
		$eventMgr = DevblocksPlatform::services()->event();
		$eventMgr->trigger(
			new Model_DevblocksEvent(
				'cron.maint',
				array()
			)
		);
		
		// Nuke orphaned words from the Bayes index
		// [TODO] Make this configurable from job
		$sql = "DELETE FROM bayes_words WHERE nonspam + spam < 2"; // only 1 occurrence
		$db->ExecuteMaster($sql);

		$logger->info('[Maint] Purged ' . $db->Affected_Rows() . ' obscure spam words.');
		
		// [mdf] Remove any empty directories inside storage/mail/new
		$mailDir = APP_MAIL_PATH . 'new' . DIRECTORY_SEPARATOR;
		$subdirs = glob($mailDir . '*', GLOB_ONLYDIR);
		if ($subdirs !== false) {
			foreach($subdirs as $subdir) {
				$directory_empty = count(glob($subdir. DIRECTORY_SEPARATOR . '*')) === 0;
				if($directory_empty && is_writeable($subdir)) {
					rmdir($subdir);
				}
			}
		}
		
		$logger->info('[Maint] Cleaned up mail directories.');
		
		// Clean up /tmp/php* files if ctime > 12 hours ago
		
		$tmp_dir = APP_TEMP_PATH . DIRECTORY_SEPARATOR;
		$tmp_deletes = 0;
		$tmp_ctime_max = time() - (60*60*12);
		
		if(false !== ($php_tmpfiles = glob($tmp_dir . 'php*', GLOB_NOSORT))) {
			// If created more than 12 hours ago
			foreach($php_tmpfiles as $php_tmpfile) {
				if(filectime($php_tmpfile) < $tmp_ctime_max) {
					unlink($php_tmpfile);
					$tmp_deletes++;
				}
			}
			
			$logger->info(sprintf('[Maint] Cleaned up %d temporary PHP files.', $tmp_deletes));
		}
		
		// Clean up /tmp/mime* files if ctime > 12 hours ago
		
		$tmp_dir = APP_TEMP_PATH . DIRECTORY_SEPARATOR;
		$tmp_deletes = 0;
		$tmp_ctime_max = time() - (60*60*12);
		
		if(false !== ($php_tmpmimes = glob($tmp_dir . 'mime*', GLOB_NOSORT))) {
			foreach($php_tmpmimes as $php_tmpmime) {
				// If created more than 12 hours ago
				if(filectime($php_tmpmime) < $tmp_ctime_max) {
					unlink($php_tmpmime);
					$tmp_deletes++;
				}
			}
			
			$logger->info(sprintf('[Maint] Cleaned up %d temporary MIME files.', $tmp_deletes));
		}
		
	}

	function configure($instance) {
		$tpl = DevblocksPlatform::services()->template();

		$tpl->assign('purge_waitdays', $this->getParam('purge_waitdays', 7));

		$tpl->display('devblocks:cerberusweb.core::cron/maint/config.tpl');
	}

	function saveConfigurationAction() {
		@$purge_waitdays = DevblocksPlatform::importGPC($_POST['purge_waitdays'],'integer');
		$this->setParam('purge_waitdays', $purge_waitdays);
	}
};

/**
 * Plugins can implement an event listener on the heartbeat to do any kind of
 * time-dependent or interval-based events.  For example, doing a workflow
 * action every 5 minutes.
 */
class HeartbeatCron extends CerberusCronPageExtension {
	function run() {
		// Heartbeat Event
		$eventMgr = DevblocksPlatform::services()->event();
		$eventMgr->trigger(
			new Model_DevblocksEvent(
				'cron.heartbeat',
				array(
				)
			)
		);
	}

	function configure($instance) {
		$tpl = DevblocksPlatform::services()->template();
		$tpl->display('devblocks:cerberusweb.core::cron/heartbeat/config.tpl');
	}
};

/*
 * PARAMS (overloads):
 * mailbox_max=n (max messages to download at once)
 *
 */
class MailboxCron extends CerberusCronPageExtension {
	function run() {
		$logger = DevblocksPlatform::services()->log();
		
		$logger->info("[Mailboxes] Started Mailbox Checker job");
		
		if (!extension_loaded("imap")) {
			$logger->err("[Mailboxes] The 'IMAP' extension is not loaded. Aborting!");
			return false;
		}
		
		if (!extension_loaded("mailparse")) {
			$logger->err("[Mailboxes] The 'mailparse' extension is not loaded. Aborting!");
			return false;
		}
		
		@set_time_limit(600); // 10m

		if(false == ($accounts = DAO_Mailbox::getAll())) {
			$logger->err("[Mailboxes] There are no mailboxes to check. Aborting!");
			return false;
		}
		
		// Sort by the least recently checked mailbox
		DevblocksPlatform::sortObjects($accounts, 'checked_at');
		
		$timeout = ini_get('max_execution_time');
		
		// Allow runtime overloads (by host, etc.)
		@$opt_max_messages = DevblocksPlatform::importGPC($_REQUEST['max_messages'],'integer');
		@$opt_max_mailboxes = DevblocksPlatform::importGPC($_REQUEST['max_mailboxes'],'integer');
		
		$max_downloads = !empty($opt_max_messages) ? $opt_max_messages : $this->getParam('max_messages', (($timeout) ? 20 : 50));
		
		// [JAS]: Make sure our output directory is writeable
		if(!is_writable(APP_MAIL_PATH . 'new' . DIRECTORY_SEPARATOR)) {
			$logger->error("[Mailboxes] The mail storage directory is not writeable.  Skipping mailbox download.");
			return;
		}

		$runtime = microtime(true);
		$mailboxes_checked = 0;
		
		if(is_array($accounts))
		foreach ($accounts as $account) { /* @var $account Model_Mailbox */
			if(!$account->enabled)
				continue;
			
			if($account->delay_until > time()) {
				$logger->info(sprintf("[Mailboxes] Delaying failing mailbox '%s' check for %d more seconds (%s)", $account->name, $account->delay_until - time(), date("h:i a", $account->delay_until)));
				continue;
			}
			
			if($opt_max_mailboxes && $mailboxes_checked >= $opt_max_mailboxes) {
				$logger->info(sprintf("[Mailboxes] We're limited to checking %d mailboxes per invocation. Stopping early.", $opt_max_mailboxes));
				break;
			}
			
			// Per-account IMAP timeouts
			$imap_timeout = !empty($account->timeout_secs) ? $account->timeout_secs : 30;
			
			imap_timeout(IMAP_OPENTIMEOUT, $imap_timeout);
			imap_timeout(IMAP_READTIMEOUT, $imap_timeout);
			imap_timeout(IMAP_CLOSETIMEOUT, $imap_timeout);
			
			$imap_timeout_read_ms = imap_timeout(IMAP_READTIMEOUT) * 1000; // ms
			$imap_options = array();
			
			// [TODO] Also allow disabling GSSAPI, NTLM from UI (requires patch)
			$disable_authenticators = [];
			
			if($account->auth_disable_plain)
				$disable_authenticators[] = 'PLAIN';
			
			if(defined('APP_MAIL_IMAP_DISABLE_NTLM') && APP_MAIL_IMAP_DISABLE_NTLM)
				$disable_authenticators[] = 'NTLM';
			
			if(defined('APP_MAIL_IMAP_DISABLE_GSSAPI') && APP_MAIL_IMAP_DISABLE_GSSAPI)
				$disable_authenticators[] = 'GSSAPI';
			
			if(!empty($disable_authenticators))
				$imap_options['DISABLE_AUTHENTICATOR'] = $disable_authenticators;
			
			$mailboxes_checked++;

			$logger->info('[Mailboxes] Account being parsed is '. $account->name);
			
			$imap_connect = $account->getImapConnectString();

			$mailbox_runtime = microtime(true);
			
			if(false === ($mailbox = @imap_open($imap_connect,
				!empty($account->username)?$account->username:"",
				!empty($account->password)?$account->password:"",
				null,
				0,
				$imap_options
				))) {
				
				$logger->error("[Mailboxes] Failed with error: ".imap_last_error());
				
				// Increment fails
				$num_fails = $account->num_fails + 1;
				$delay_until = time() + (min($num_fails, 15) * 120);
				
				$fields = array(
					DAO_Mailbox::CHECKED_AT => time(),
					DAO_Mailbox::NUM_FAILS => $num_fails,
					DAO_Mailbox::DELAY_UNTIL => $delay_until, // Delay 2 mins per consecutive failure
				);
				
				$logger->error("[Mailboxes] Delaying next mailbox check until ".date('h:i a', $delay_until));
				
				// Notify admins about consecutive mailbox failures at an interval
				if(in_array($num_fails, array(2,5,10,20))) {
					$logger->info(sprintf("[Mailboxes] Sending notification about %d consecutive failures on this mailbox", $num_fails));
					
					$url_writer = DevblocksPlatform::services()->url();
					$admin_workers = DAO_Worker::getAllAdmins();
					
					/*
					 * Log activity (mailbox.check.error)
					 */
					$entry = array(
						//Mailbox {{target}} has failed to download mail on {{count}} consecutive attempts: {{error}}
						'message' => 'activities.mailbox.check.error',
						'variables' => array(
							'target' => $account->name,
							'count' => $num_fails,
							'error' => imap_last_error(),
							),
						'urls' => array(
							'target' => sprintf("ctx://%s:%s/%s", CerberusContexts::CONTEXT_MAILBOX, $account->id, DevblocksPlatform::strToPermalink($account->name)),
							)
					);
					CerberusContexts::logActivity('mailbox.check.error', CerberusContexts::CONTEXT_MAILBOX, $account->id, $entry, null, null, array_keys($admin_workers));
				}
				
				DAO_Mailbox::update($account->id, $fields);
				continue;
			}
			
			$messages = array();
			$mailbox_stats = imap_check($mailbox);
			
			// [TODO] Make this an account setting?
			$total = min($max_downloads, $mailbox_stats->Nmsgs);
			
			$logger->info("[Mailboxes] Connected to mailbox '".$account->name."' (".number_format((microtime(true)-$mailbox_runtime)*1000,2)." ms)");

			$mailbox_runtime = microtime(true);
			
			$msgs_stats = imap_fetch_overview($mailbox, sprintf("1:%d", $total));
			
			foreach($msgs_stats as &$msg_stats) {
				$time = microtime(true);

				do {
					$unique = sprintf("%s.%04d",
					time(),
					mt_rand(0,9999)
					);
					$filename = APP_MAIL_PATH . 'new' . DIRECTORY_SEPARATOR . $unique;
				} while(file_exists($filename));

				$fp = fopen($filename,'w+');

				if($fp) {
					$mailbox_xheader = "X-Cerberus-Mailbox: " . $account->name . "\r\n";
					fwrite($fp, $mailbox_xheader);

					// If the message is too big, save a message stating as much
					if($account->max_msg_size_kb && $msg_stats->size >= $account->max_msg_size_kb * 1000) {
						$logger->warn(sprintf("[Mailboxes] This message is %s which exceeds the mailbox limit of %s",
							DevblocksPlatform::strPrettyBytes($msg_stats->size),
							DevblocksPlatform::strPrettyBytes($account->max_msg_size_kb*1000)
						));
						
						$error_msg = sprintf("This %s message exceeded the mailbox limit of %s",
							DevblocksPlatform::strPrettyBytes($msg_stats->size),
							DevblocksPlatform::strPrettyBytes($account->max_msg_size_kb*1000)
						);
						
						$truncated_message = sprintf(
							"X-Cerb-Parser-Error: message-size-limit-exceeded\r\n".
							"X-Cerb-Parser-ErrorMsg: %s\r\n".
							"From: %s\r\n".
							"To: %s\r\n".
							"Subject: %s\r\n".
							"Date: %s\r\n".
							"Message-Id: %s\r\n".
							"\r\n".
							"(%s)\r\n",
							$error_msg,
							$msg_stats->from,
							$msg_stats->to,
							$msg_stats->subject,
							$msg_stats->date,
							$msg_stats->message_id,
							$error_msg
						);
						
						fwrite($fp, $truncated_message);
						
					// Otherwise, save the message like normal
					} else {
						$result = imap_savebody($mailbox, $fp, $msg_stats->msgno); // Write the message directly to the file handle
					}

					@fclose($fp);
				}
				
				$time = microtime(true) - $time;
				
				// If this message took a really long time to download, skip it and retry later
				// [TODO] We may want to keep track if the same message does this repeatedly
				if(($time*1000) > (0.95 * $imap_timeout_read_ms)) {
					$logger->warn("[Mailboxes] This message took more than 95% of the IMAP_READTIMEOUT value to download. We probably timed out. Aborting to retry later...");
					unlink($filename);
					break;
				}
				
				/*
				 * [JAS]: We don't add the .msg extension until we're done with the file,
				 * since this will safely be ignored by the parser until we're ready
				 * for it.
				 */
				rename($filename, dirname($filename) . DIRECTORY_SEPARATOR . basename($filename) . '.msg');

				$logger->info("[Mailboxes] Downloaded message ".$msg_stats->msgno." (".sprintf("%d",($time*1000))." ms)");
				
				imap_delete($mailbox, $msg_stats->msgno);
			}
			
			// Clear the fail count if we had past fails
			DAO_Mailbox::update(
				$account->id,
				array(
					DAO_Mailbox::CHECKED_AT => time(),
					DAO_Mailbox::NUM_FAILS => 0,
					DAO_Mailbox::DELAY_UNTIL => 0,
				)
			);
			
			imap_expunge($mailbox);
			imap_close($mailbox);
			@imap_errors();
			
			$logger->info("[Mailboxes] Closed mailbox (".number_format((microtime(true)-$mailbox_runtime)*1000,2)." ms)");
		}
		
		if(empty($mailboxes_checked))
			$logger->info('[Mailboxes] There are no mailboxes ready to be checked.');
		
		$logger->info("[Mailboxes] Finished Mailbox Checker job (".number_format((microtime(true)-$runtime)*1000,2)." ms)");
	}

	function configure($instance) {
		$tpl = DevblocksPlatform::services()->template();

		$timeout = ini_get('max_execution_time');
		$tpl->assign('max_messages', $this->getParam('max_messages', (($timeout) ? 20 : 50)));

		$tpl->display('devblocks:cerberusweb.core::cron/mailbox/config.tpl');
	}

	function saveConfigurationAction() {

		@$max_messages = DevblocksPlatform::importGPC($_POST['max_messages'],'integer');
		$this->setParam('max_messages', $max_messages);

		DevblocksPlatform::setHttpResponse(new DevblocksHttpResponse(array('config','jobs')));
	}
};

class StorageCron extends CerberusCronPageExtension {
	function run() {
		$logger = DevblocksPlatform::services()->log();
		
		$runtime = microtime(true);
		
		$logger->info("[Storage] Starting...");

		$max_runtime = time() + 30; // [TODO] Make configurable
		
		// Run any pending batch DELETEs
		$pending_profiles = DAO_DevblocksStorageQueue::getPendingProfiles();
		
		if(is_array($pending_profiles))
		foreach($pending_profiles as $pending_profile) {
			if($max_runtime < time())
				continue;
			
			// Use a profile or a base extension
			$engine =
				!empty($pending_profile['storage_profile_id'])
				? $pending_profile['storage_profile_id']
				: $pending_profile['storage_extension']
				;
			
			if(false == ($storage = DevblocksPlatform::getStorageService($engine)))
				continue;
			
			// Get one page of 500 pending delete keys for this profile
			$keys = DAO_DevblocksStorageQueue::getKeys($pending_profile['storage_namespace'], $pending_profile['storage_extension'], $pending_profile['storage_profile_id'], 500);
			
			$logger->info(sprintf("[Storage] Batch deleting %d %s object(s) for %s:%d",
				count($keys),
				$pending_profile['storage_namespace'],
				$pending_profile['storage_extension'],
				$pending_profile['storage_profile_id']
			));
			
			// Pass the keys to the storage engine
			if(false !== ($keys = $storage->batchDelete($pending_profile['storage_namespace'], $keys))) {

				// Remove the entries on success
				if(is_array($keys) && !empty($keys))
					DAO_DevblocksStorageQueue::purgeKeys($keys, $pending_profile['storage_namespace'], $pending_profile['storage_extension'], $pending_profile['storage_profile_id']);
			}
		}
		
		// Synchronize storage schemas (active+archive)
		$storage_schemas = DevblocksPlatform::getExtensions('devblocks.storage.schema', true);
		
		if(is_array($storage_schemas))
		foreach($storage_schemas as $schema) { /* @var $schema Extension_DevblocksStorageSchema */
			if($max_runtime > time())
				$schema->unarchive($max_runtime);
			if($max_runtime > time())
				$schema->archive($max_runtime);
		}
		
		$logger->info("[Storage] Total Runtime: ".number_format((microtime(true)-$runtime)*1000,2)." ms");
	}
	
	function configure($instance) {
		$tpl = DevblocksPlatform::services()->template();

//		$timeout = ini_get('max_execution_time');
//		$tpl->assign('max_messages', $this->getParam('max_messages', (($timeout) ? 20 : 50)));

		//$tpl->display('devblocks:cerberusweb.core::cron/storage/config.tpl');
	}

	function saveConfigurationAction() {
//		@$max_messages = DevblocksPlatform::importGPC($_POST['max_messages'],'integer');
//		$this->setParam('max_messages', $max_messages);

		DevblocksPlatform::setHttpResponse(new DevblocksHttpResponse(array('config','jobs')));
	}
};

class MailQueueCron extends CerberusCronPageExtension {
	function run() {
		$logger = DevblocksPlatform::services()->log();
		$runtime = microtime(true);

		$stop_time = time() + 30; // [TODO] Make configurable
		$last_id = 0;
		
		$logger->info("[Mail Queue] Starting...");
		
		if (!extension_loaded("mailparse")) {
			$logger->err("[Parser] The 'mailparse' extension is not loaded.  Aborting!");
			return false;
		}
		
		// Drafts->SMTP
		
		do {
			$messages = DAO_MailQueue::getWhere(
				sprintf("%s = %d AND %s <= %d AND %s > %d AND %s < %d",
					DAO_MailQueue::IS_QUEUED,
					1,
					DAO_MailQueue::QUEUE_DELIVERY_DATE,
					time(),
					DAO_MailQueue::ID,
					$last_id,
					DAO_MailQueue::QUEUE_FAILS,
					10
				),
				array(DAO_MailQueue::QUEUE_DELIVERY_DATE, DAO_MailQueue::UPDATED),
				array(true, true),
				25
			);
	
			if(!empty($messages)) {
				$message_ids = array_keys($messages);
				
				foreach($messages as $message) { /* @var $message Model_MailQueue */
					if(!$message->send()) {
						$logger->error(sprintf("[Mail Queue] Failed sending message %d", $message->id));
						DAO_MailQueue::update($message->id, array(
							DAO_MailQueue::QUEUE_FAILS => min($message->queue_fails+1,255),
							DAO_MailQueue::QUEUE_DELIVERY_DATE => time() + 900, // retry in 15 min
						));
						
					} else {
						$logger->info(sprintf("[Mail Queue] Sent message %d", $message->id));
					}
				}
				
				$last_id = end($message_ids);
			}
			
		} while(!empty($messages) && $stop_time > time());
		
		$logger->info("[Mail Queue] Total Runtime: ".number_format((microtime(true)-$runtime)*1000,2)." ms");
	}

	function configure($instance) {
		//$tpl = DevblocksPlatform::services()->template();
		//$tpl->display('devblocks:cerberusweb.core::cron/mail_queue/config.tpl');
	}
};

class Cron_BotScheduledBehavior extends CerberusCronPageExtension {
	function run() {
		$logger = DevblocksPlatform::services()->log('Bot Scheduler');
		$runtime = microtime(true);

		$stop_time = time() + 20; // [TODO] Make configurable

		$logger->info("Starting...");
		
		// Run recurrent behaviors
		
		$this->_runRecurrentBehaviors();
		
		// Run scheduled behaviors
		
		$this->_runScheduledBehaviors($stop_time);
		
		$logger->info("Total Runtime: ".number_format((microtime(true)-$runtime)*1000,2)." ms");
	}

	function configure($instance) {
	}
	
	private function _runRecurrentBehaviors() {
		$recurrent_behaviors = Event_RecurrentBehavior::getReadyBehaviors();
		
		foreach($recurrent_behaviors as $behavior) { /* @var $behavior Model_TriggerEvent */
			if(false == ($event = $behavior->getEvent()))
				continue;
			
			$event_model = new Model_DevblocksEvent();
			$event_model->id = Event_RecurrentBehavior::ID;
			$event_model->params = [];
			
			$event->setEvent($event_model, $behavior);
			
			$values = $event->getValues();
			$dict = DevblocksDictionaryDelegate::instance($values);
			
			$result = $behavior->runDecisionTree($dict, false, $event);
			
			// Update the next runtime timestamp
			@$patterns = DevblocksPlatform::parseCrlfString($behavior->event_params['repeat_patterns']);
			@$timezone = $behavior->event_params['timezone'];
			@$history = $behavior->event_params['repeat_history'];
			
			if(!is_array($history))
				$history = [];
			
			if(is_array($patterns)) {
				$run_at = Event_RecurrentBehavior::getNextOccurrence($patterns, $timezone);
				$behavior->event_params['repeat_run_at'] = $run_at;
				
				$history[] = time();
				$behavior->event_params['repeat_history'] = array_slice($history, -25);
				
				DAO_TriggerEvent::update($behavior->id, [
					DAO_TriggerEvent::EVENT_PARAMS_JSON => json_encode($behavior->event_params),
				]);
			}
		}
	}
	
	private function _runScheduledBehaviors($stop_time) {
		$logger = DevblocksPlatform::services()->log();
		
		$last_behavior_id = 0;

		do {
			$behaviors = DAO_ContextScheduledBehavior::getWhere(
				sprintf("%s < %d AND %s > %d",
					DAO_ContextScheduledBehavior::RUN_DATE,
					time(),
					DAO_ContextScheduledBehavior::ID,
					$last_behavior_id
				),
				array(DAO_ContextScheduledBehavior::RUN_DATE),
				array(true),
				25
			);

			if(!empty($behaviors)) {
				foreach($behaviors as $behavior) {
					/* @var $behavior Model_ContextScheduledBehavior */
					try {
						if(empty($behavior->context) || empty($behavior->context_id) || empty($behavior->behavior_id))
							throw new Exception("Incomplete macro.");
					
						// Load context
						if(null == ($context_ext = Extension_DevblocksContext::get($behavior->context)))
							throw new Exception("Invalid context.");
					
						// [TODO] ACL: Ensure access to the context object
							
						// Load macro
						if(null == ($macro = DAO_TriggerEvent::get($behavior->behavior_id))) /* @var $macro Model_TriggerEvent */
							throw new Exception("Invalid macro.");
						
						if($macro->is_disabled)
							throw new Exception("Macro disabled.");
							
						// [TODO] ACL: Ensure the worker owns the macro
					
						// Load event manifest
						if(null == ($ext = Extension_DevblocksEvent::get($macro->event_point, false))) /* @var $ext DevblocksExtensionManifest */
							throw new Exception("Invalid event.");

						// Execute
						$behavior->run();

						// Log
						$logger->info(sprintf("Executed behavior %d", $behavior->id));
						
					} catch (Exception $e) {
						$logger->error(sprintf("Failed executing behavior %d: %s", $behavior->id, $e->getMessage()));

						DAO_ContextScheduledBehavior::delete($behavior->id);
					}
					
					$last_behavior_id = $behavior->id;
				}
			}
			
		} while(!empty($behaviors) && $stop_time > time());
	}
};

class Cron_Reminders extends CerberusCronPageExtension {
	function run() {
		$logger = DevblocksPlatform::services()->log();
		$runtime = microtime(true);
		
		$logger->info("[Reminders] Starting...");
		
		$reminders = DAO_Reminder::getWhere(
			sprintf("%s = %d AND %s < %d",
				DAO_Reminder::IS_CLOSED,
				0,
				DAO_Reminder::REMIND_AT,
				time()
			),
			null,
			true,
			25
		);
		
		if(is_array($reminders))
		foreach($reminders as $reminder) {
			$reminder->run();
		}
		
		$logger->info("[Reminders] Total Runtime: ".number_format((microtime(true)-$runtime)*1000,2)." ms");
	}
	
	function configure($instance) {
	}
};

class SearchCron extends CerberusCronPageExtension {
	function run() {
		$logger = DevblocksPlatform::services()->log();
		$runtime = microtime(true);
		
		$logger->info("[Search] Starting...");
		
		// Loop through search schemas and batch index by ID or timestamp
		
		$schemas = DevblocksPlatform::getExtensions('devblocks.search.schema', true);

		$stop_time = time() + 30; // [TODO] Make configurable
		
		foreach($schemas as $schema) {
			if($stop_time > time()) {
				if($schema instanceof Extension_DevblocksSearchSchema)
					$schema->index($stop_time);
			}
		}
		
		$logger->info("[Search] Total Runtime: ".number_format((microtime(true)-$runtime)*1000,2)." ms");
	}
	
	function configure($instance) {
	}
};