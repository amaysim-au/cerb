<?php
/***********************************************************************
| Cerb(tm) developed by Webgroup Media, LLC.
|-----------------------------------------------------------------------
| All source code & content (c) Copyright 2014, Webgroup Media LLC
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

class PageSection_ProfilesCustomRecord extends Extension_PageSection {
	function render() {
		$tpl = DevblocksPlatform::services()->template();
		$visit = CerberusApplication::getVisit();
		$translate = DevblocksPlatform::getTranslationService();
		$active_worker = CerberusApplication::getActiveWorker();
		
		$response = DevblocksPlatform::getHttpResponse();
		$stack = $response->path;
		@array_shift($stack); // profiles
		@array_shift($stack); // custom_record 
		$id = array_shift($stack); // 123
		
		@$id = intval($id);
		
		if(null == ($custom_record = DAO_CustomRecord::get($id))) {
			return;
		}
		$tpl->assign('custom_record', $custom_record);
		
		// Tab persistence
		
		$point = 'profiles.custom_record.tab';
		$tpl->assign('point', $point);
		
		if(null == (@$tab_selected = $stack[0])) {
			$tab_selected = $visit->get($point, '');
		}
		$tpl->assign('tab_selected', $tab_selected);
		
		// Properties
		
		$properties = array();
		
		$properties['id'] = array(
			'label' => mb_ucfirst($translate->_('common.id')),
			'type' => Model_CustomField::TYPE_NUMBER,
			'value' => $custom_record->id,
		);
		
		$properties['updated'] = array(
			'label' => DevblocksPlatform::translateCapitalized('common.updated'),
			'type' => Model_CustomField::TYPE_DATE,
			'value' => $custom_record->updated_at,
		);
		
		// Custom Fields
		
		@$values = array_shift(DAO_CustomFieldValue::getValuesByContextIds(CerberusContexts::CONTEXT_CUSTOM_RECORD, $custom_record->id)) or array();
		$tpl->assign('custom_field_values', $values);
		
		$properties_cfields = Page_Profiles::getProfilePropertiesCustomFields(CerberusContexts::CONTEXT_CUSTOM_RECORD, $values);
		
		if(!empty($properties_cfields))
			$properties = array_merge($properties, $properties_cfields);
		
		// Custom Fieldsets
		
		$properties_custom_fieldsets = Page_Profiles::getProfilePropertiesCustomFieldsets(CerberusContexts::CONTEXT_CUSTOM_RECORD, $custom_record->id, $values);
		$tpl->assign('properties_custom_fieldsets', $properties_custom_fieldsets);
		
		// Link counts
		
		$properties_links = array(
			CerberusContexts::CONTEXT_CUSTOM_RECORD => array(
				$custom_record->id => 
					DAO_ContextLink::getContextLinkCounts(
						CerberusContexts::CONTEXT_CUSTOM_RECORD,
						$custom_record->id,
						array(CerberusContexts::CONTEXT_CUSTOM_FIELDSET)
					),
			),
		);
		
		$tpl->assign('properties_links', $properties_links);
		
		// Properties
		
		$tpl->assign('properties', $properties);
		
		// Tabs
		$tab_manifests = Extension_ContextProfileTab::getExtensions(false, CerberusContexts::CONTEXT_CUSTOM_RECORD);
		$tpl->assign('tab_manifests', $tab_manifests);
		
		// Template
		$tpl->display('devblocks:cerberusweb.core::profiles/custom_record.tpl');
	}
	
	function savePeekJsonAction() {
		@$view_id = DevblocksPlatform::importGPC($_REQUEST['view_id'], 'string', '');
		
		@$id = DevblocksPlatform::importGPC($_REQUEST['id'], 'integer', 0);
		@$do_delete = DevblocksPlatform::importGPC($_REQUEST['do_delete'], 'string', '');
		
		$active_worker = CerberusApplication::getActiveWorker();
		
		header('Content-Type: application/json; charset=utf-8');
		
		if(!$active_worker->is_superuser)
			throw new Exception_DevblocksAjaxValidationError("Only administrators can modify custom records.");
		
		try {
			if(!empty($id) && !empty($do_delete)) { // Delete
				if(!$active_worker->hasPriv(sprintf("contexts.%s.delete", CerberusContexts::CONTEXT_CUSTOM_RECORD)))
					throw new Exception_DevblocksAjaxValidationError(DevblocksPlatform::translate('error.core.no_acl.delete'));
				
				// Make sure it's empty
				if(!Context_CustomRecord::isDeleteableByActor($id, $active_worker))
					throw new Exception_DevblocksAjaxValidationError("You must delete all records of this type first.");
				
				DAO_CustomRecord::delete($id);
				
				echo json_encode(array(
					'status' => true,
					'id' => $id,
					'view_id' => $view_id,
				));
				return;
				
			} else {
				@$name = DevblocksPlatform::importGPC($_REQUEST['name'], 'string', '');
				@$name_plural = DevblocksPlatform::importGPC($_REQUEST['name_plural'], 'string', '');
				@$uri = DevblocksPlatform::importGPC($_REQUEST['uri'], 'string', '');
				@$params = DevblocksPlatform::importGPC($_REQUEST['params'], 'array', []);
				
				if(empty($id)) { // New
					@$role_privs = DevblocksPlatform::importGPC($_REQUEST['role_privs'], 'array', []);
					
					$fields = array(
						DAO_CustomRecord::NAME => $name,
						DAO_CustomRecord::NAME_PLURAL => $name_plural,
						DAO_CustomRecord::PARAMS_JSON => json_encode($params),
						DAO_CustomRecord::UPDATED_AT => time(),
						DAO_CustomRecord::URI => $uri,
					);
					
					if(!DAO_CustomRecord::validate($fields, $error))
						throw new Exception_DevblocksAjaxValidationError($error);
					
					if(!DAO_CustomRecord::onBeforeUpdateByActor($active_worker, $fields, null, $error))
						throw new Exception_DevblocksAjaxValidationError($error);
					
					$id = DAO_CustomRecord::create($fields);
					DAO_CustomRecord::onUpdateByActor($active_worker, $fields, $id);
					
					if(!empty($view_id) && !empty($id))
						C4_AbstractView::setMarqueeContextCreated($view_id, CerberusContexts::CONTEXT_CUSTOM_RECORD, $id);
					
					// Are we updating any role privileges?
					if(!empty($role_privs) && is_array($role_privs)) {
						$priv_prefix = sprintf('contexts.contexts.custom_record.%d.', $id);
						
						foreach($role_privs as $role_id => $privs) {
							if(false == ($role = DAO_WorkerRole::get($role_id)))
								continue;
							
							if(!isset($role->params['what']) || 'itemized' != $role->params['what'])
								continue;
							
							foreach($privs as $priv)
								$role->privs[] = $priv_prefix . $priv;
							
							$role->privs = array_unique($role->privs);
							sort($role->privs);
							
							DAO_WorkerRole::update($role_id, [
								DAO_WorkerRole::PRIVS_JSON => json_encode($role->privs),
							]);
						}
					}
					
				} else { // Edit
					$fields = array(
						DAO_CustomRecord::NAME => $name,
						DAO_CustomRecord::NAME_PLURAL => $name_plural,
						DAO_CustomRecord::PARAMS_JSON => json_encode($params),
						DAO_CustomRecord::UPDATED_AT => time(),
						DAO_CustomRecord::URI => $uri,
					);
					
					if(!DAO_CustomRecord::validate($fields, $error, $id))
						throw new Exception_DevblocksAjaxValidationError($error);
					
					if(!DAO_CustomRecord::onBeforeUpdateByActor($active_worker, $fields, $id, $error))
						throw new Exception_DevblocksAjaxValidationError($error);
					
					DAO_CustomRecord::update($id, $fields);
					DAO_CustomRecord::onUpdateByActor($active_worker, $fields, $id);
					
					@$owners = $params['owners']['contexts'] ?: [];
					
					$dao_class = 'DAO_AbstractCustomRecord_' . $id;
					$dao_class::clearOtherOwners($owners);
				}
				
				// Custom fields
				@$field_ids = DevblocksPlatform::importGPC($_REQUEST['field_ids'], 'array', array());
				DAO_CustomFieldValue::handleFormPost(CerberusContexts::CONTEXT_CUSTOM_RECORD, $id, $field_ids);
				
				echo json_encode(array(
					'status' => true,
					'id' => $id,
					'label' => $name,
					'view_id' => $view_id,
				));
				return;
			}
			
		} catch (Exception_DevblocksAjaxValidationError $e) {
			echo json_encode(array(
				'status' => false,
				'error' => $e->getMessage(),
				'field' => $e->getFieldName(),
			));
			return;
			
		} catch (Exception $e) {
			echo json_encode(array(
				'status' => false,
				'error' => 'An error occurred.',
			));
			return;
			
		}
	
	}
	
	function viewExploreAction() {
		@$view_id = DevblocksPlatform::importGPC($_REQUEST['view_id'],'string');
		
		$active_worker = CerberusApplication::getActiveWorker();
		$url_writer = DevblocksPlatform::services()->url();
		
		// Generate hash
		$hash = md5($view_id.$active_worker->id.time());
		
		// Loop through view and get IDs
		$view = C4_AbstractViewLoader::getView($view_id);
		$view->setAutoPersist(false);

		// Page start
		@$explore_from = DevblocksPlatform::importGPC($_REQUEST['explore_from'],'integer',0);
		if(empty($explore_from)) {
			$orig_pos = 1+($view->renderPage * $view->renderLimit);
		} else {
			$orig_pos = 1;
		}

		$view->renderPage = 0;
		$view->renderLimit = 250;
		$pos = 0;
		
		do {
			$models = array();
			list($results, $total) = $view->getData();

			// Summary row
			if(0==$view->renderPage) {
				$model = new Model_ExplorerSet();
				$model->hash = $hash;
				$model->pos = $pos++;
				$model->params = array(
					'title' => $view->name,
					'created' => time(),
//					'worker_id' => $active_worker->id,
					'total' => $total,
					'return_url' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : $url_writer->writeNoProxy('c=search&type=custom_record', true),
					'toolbar_extension_id' => 'cerberusweb.contexts.custom_record.explore.toolbar',
				);
				$models[] = $model;
				
				$view->renderTotal = false; // speed up subsequent pages
			}
			
			if(is_array($results))
			foreach($results as $opp_id => $row) {
				if($opp_id==$explore_from)
					$orig_pos = $pos;
				
				$url = $url_writer->writeNoProxy(sprintf("c=profiles&type=custom_record&id=%d-%s", $row[SearchFields_CustomRecord::ID], DevblocksPlatform::strToPermalink($row[SearchFields_CustomRecord::NAME])), true);
				
				$model = new Model_ExplorerSet();
				$model->hash = $hash;
				$model->pos = $pos++;
				$model->params = array(
					'id' => $row[SearchFields_CustomRecord::ID],
					'url' => $url,
				);
				$models[] = $model;
			}
			
			DAO_ExplorerSet::createFromModels($models);
			
			$view->renderPage++;
			
		} while(!empty($results));
		
		DevblocksPlatform::redirect(new DevblocksHttpResponse(array('explore',$hash,$orig_pos)));
	}
};
